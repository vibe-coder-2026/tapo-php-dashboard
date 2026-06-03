<?php

declare(strict_types=1);

namespace Tapo;

/**
 * Supervisor: forks one worker per device plus the gateway, restarts any that
 * exit, and shuts the whole tree down cleanly on SIGTERM / SIGINT.
 *
 * Usage: (new Launcher($port, __DIR__, new DeviceRegistry()))->run()
 */
class Launcher
{
    private array $roster    = [];
    private array $pids      = [];
    private array $lastSpawn = [];
    private bool  $running   = true;

    public function __construct(
        private readonly int            $port,
        private readonly string         $baseDir,
        private readonly DeviceRegistry $registry,
        private readonly ?string        $dbPath = null,
    ) {}

    public function run(): void
    {
        @mkdir($this->registry->socketDir(), 0700, true);

        $php = PHP_BINARY;

        foreach (array_keys($this->registry->devices()) as $id) {
            $this->roster["worker:{$id}"] = ["{$this->baseDir}/worker.php", $id];
        }
        $this->roster['gateway'] = ["{$this->baseDir}/gateway.php", (string) $this->port];
        if ($this->dbPath !== null) {
            $this->roster['collector'] = ["{$this->baseDir}/collect.php", '--daemon'];
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () { $this->running = false; });
        pcntl_signal(SIGINT,  function () { $this->running = false; });

        foreach ($this->roster as $key => $args) {
            $pid = $this->spawn($key, $args, $php);
            $this->pids[$pid]      = $key;
            $this->lastSpawn[$key] = microtime(true);
            fwrite(STDOUT, "[launcher] started {$key} (pid {$pid})\n");
        }

        fwrite(STDOUT, "[launcher] dashboard at http://work-vm:{$this->port}/  (" . count($this->roster) . " processes)\n");

        while ($this->running) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0 && isset($this->pids[$pid])) {
                $key = $this->pids[$pid];
                unset($this->pids[$pid]);
                if (!$this->running) {
                    break;
                }
                if (microtime(true) - ($this->lastSpawn[$key] ?? 0) < 3.0) {
                    usleep(1_000_000);
                }
                $newPid = $this->spawn($key, $this->roster[$key], $php);
                $this->pids[$newPid]   = $key;
                $this->lastSpawn[$key] = microtime(true);
                fwrite(STDOUT, "[launcher] respawned {$key} (pid {$newPid})\n");
            } else {
                usleep(200_000);
            }
        }

        $this->shutdown();
    }

    public static function firstFreePort(int $from = 9090, int $to = 10000): int
    {
        for ($p = $from; $p <= $to; $p++) {
            $s = @stream_socket_server("tcp://0.0.0.0:{$p}", $errno, $errstr);
            if ($s !== false) {
                fclose($s);
                return $p;
            }
        }
        fwrite(STDERR, "No free port in {$from}-{$to}\n");
        exit(1);
    }

    private function spawn(string $key, array $args, string $php): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            fwrite(STDERR, "fork failed for {$key}\n");
            exit(1);
        }
        if ($pid === 0) {
            pcntl_exec($php, $args);
            fwrite(STDERR, "exec failed for {$key}\n");
            exit(1);
        }
        return $pid;
    }

    private function shutdown(): void
    {
        fwrite(STDOUT, "[launcher] stopping " . count($this->pids) . " children…\n");
        foreach (array_keys($this->pids) as $pid) {
            @posix_kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + 5.0;
        while ($this->pids && microtime(true) < $deadline) {
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid > 0) {
                unset($this->pids[$pid]);
            } else {
                usleep(100_000);
            }
        }
        foreach (array_keys($this->pids) as $pid) {
            @posix_kill($pid, SIGKILL);
        }

        foreach (glob($this->registry->socketDir() . '/*.sock') ?: [] as $f) {
            @unlink($f);
        }

        fwrite(STDOUT, "[launcher] done\n");
    }
}
