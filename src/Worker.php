<?php

declare(strict_types=1);

namespace Tapo;

/**
 * Per-device worker process.
 *
 * Owns the ONLY connection to a single plug. Keeps a warm session, polls the
 * device on a timer to refresh an in-memory cache, and answers commands from
 * the gateway over a Unix domain socket.
 *
 * Usage: (new Worker($id, $config, $registry))->run()
 */
class Worker
{
    private const POLL_INTERVAL  = 5.0;
    private const CLIENT_TIMEOUT = 2;

    private string $type;
    private string $host;
    private mixed  $device;
    private array  $state;
    private mixed  $server;
    private float  $nextPoll;

    public function __construct(
        private readonly string         $id,
        private readonly Config         $config,
        private readonly DeviceRegistry $registry,
    ) {
        $meta = $registry->devices()[$id] ?? null;
        if ($meta === null) {
            fwrite(STDERR, "Unknown device id: {$id}\n");
            exit(1);
        }
        $this->type  = $meta['type'];
        $this->host  = $meta['host'];
        $this->state = [
            'id'         => $id,
            'type'       => $this->type,
            'name'       => $id,
            'on'         => false,
            'power_w'    => null,
            'brightness' => null,
            'color_temp' => null,
            'online'     => false,
            'ts'         => 0,
        ];
    }

    public function run(): void
    {
        $this->device = $this->buildDevice();
        $this->bindSocket();

        fwrite(STDOUT, "[{$this->id}] worker up ({$this->type} @ {$this->host}) on " . $this->registry->socketPath($this->id) . "\n");

        $this->poll();
        $this->nextPoll = microtime(true) + self::POLL_INTERVAL;

        while (true) {
            $timeout = max(0, $this->nextPoll - microtime(true));
            $read    = [$this->server];
            $write   = $except = null;
            $sec     = (int) $timeout;
            $usec    = (int) (($timeout - $sec) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) {
                continue;
            }

            if ($ready > 0) {
                $conn = @stream_socket_accept($this->server, 0);
                if ($conn !== false) {
                    $this->handleCommand($conn);
                }
            }

            if (microtime(true) >= $this->nextPoll) {
                $this->poll();
                $this->nextPoll = microtime(true) + self::POLL_INTERVAL;
            }
        }
    }

    private function buildDevice(): mixed
    {
        return match ($this->type) {
            'kasa'  => new Kasa\HS110($this->host),
            'bulb'  => new L530($this->host, $this->config->email(), $this->config->password()),
            default => new P110($this->host, $this->config->email(), $this->config->password()),
        };
    }

    private function bindSocket(): void
    {
        $sockPath = $this->registry->socketPath($this->id);
        @mkdir($this->registry->socketDir(), 0700, true);
        if (file_exists($sockPath)) {
            @unlink($sockPath);
        }

        $this->server = stream_socket_server("unix://{$sockPath}", $errno, $errstr);
        if ($this->server === false) {
            fwrite(STDERR, "[{$this->id}] failed to bind {$sockPath}: {$errstr}\n");
            exit(1);
        }
        @chmod($sockPath, 0600);

        $cleanup = function () use ($sockPath) {
            if (file_exists($sockPath)) {
                @unlink($sockPath);
            }
            exit(0);
        };
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, $cleanup);
        pcntl_signal(SIGINT, $cleanup);
    }

    private function poll(): void
    {
        try {
            $isKasa = $this->type === 'kasa';
            $info   = $this->device->getDeviceInfo();

            $this->state['name'] = $isKasa ? $info['alias'] : base64_decode($info['nickname']);
            $this->state['on']   = $isKasa ? (bool) $info['relay_state'] : (bool) $info['device_on'];

            $power = null;
            try {
                $energy = $this->device->getEnergyUsage();
                $power  = $isKasa
                    ? round($energy['power'], 1)
                    : round($energy['current_power'] / 1000, 1);
            } catch (\Throwable) {}

            $this->state['power_w']    = $power;
            $this->state['brightness'] = $isKasa ? null : ($info['brightness'] ?? null);
            $this->state['color_temp'] = $isKasa ? null : ($info['color_temp'] ?? null);
            $this->state['online']     = true;
        } catch (\Throwable) {
            $this->state['online'] = false;
            $this->device          = $this->buildDevice();
        }
        $this->state['ts'] = time();
    }

    private function handleCommand(mixed $conn): void
    {
        stream_set_timeout($conn, self::CLIENT_TIMEOUT);
        $line = fgets($conn);

        if ($line !== false) {
            $cmd    = json_decode(trim($line), true);
            $action = is_array($cmd) ? ($cmd['action'] ?? 'status') : 'status';

            try {
                switch ($action) {
                    case 'on':
                        $this->device->turnOn();
                        $this->poll();
                        break;
                    case 'off':
                        $this->device->turnOff();
                        $this->poll();
                        break;
                    case 'toggle':
                        $this->device->toggleState();
                        $this->poll();
                        break;
                    case 'preset':
                        if ($this->type === 'bulb') {
                            $brightness = max(1, min(100, (int) ($cmd['brightness'] ?? 100)));
                            $colorTemp  = max(2500, min(6500, (int) ($cmd['color_temp'] ?? 4000)));
                            $this->device->request('set_device_info', [
                                'device_on'  => true,
                                'brightness' => $brightness,
                                'color_temp' => $colorTemp,
                            ]);
                            $this->poll();
                        }
                        break;
                    case 'rename':
                        $name = trim((string) ($cmd['name'] ?? ''));
                        if ($name !== '') {
                            for ($attempt = 1; ; $attempt++) {
                                try {
                                    if ($this->type === 'kasa') {
                                        $this->device->request(['system' => ['set_dev_alias' => ['alias' => $name]]]);
                                    } else {
                                        $this->device->request('set_device_info', ['nickname' => base64_encode($name)]);
                                    }
                                    break;
                                } catch (\Throwable $e) {
                                    if ($attempt >= 2) {
                                        throw $e;
                                    }
                                    usleep(250_000);
                                }
                            }
                            $this->poll();
                        }
                        break;
                    case 'status':
                    default:
                        break;
                }
            } catch (\Throwable) {
                $this->state['online'] = false;
                $this->state['ts']     = time();
                $this->device          = $this->buildDevice();
            }
        }

        fwrite($conn, json_encode($this->state) . "\n");
        fclose($conn);
    }
}
