<?php

declare(strict_types=1);

namespace Tapo;

class WorkerClient
{
    private const TIMEOUT = 3.0;

    public function __construct(private readonly DeviceRegistry $registry) {}

    public function query(string $id, string $action = 'status', array $extra = []): array
    {
        $offline = $this->offline($id);
        $sock    = @stream_socket_client('unix://' . $this->registry->socketPath($id), $errno, $errstr, self::TIMEOUT);
        if ($sock === false) {
            return $offline;
        }
        stream_set_timeout($sock, (int) self::TIMEOUT);
        fwrite($sock, json_encode(['action' => $action] + $extra) . "\n");
        $line = fgets($sock);
        fclose($sock);

        if ($line === false) {
            return $offline;
        }
        $state = json_decode(trim($line), true);
        return is_array($state) ? $state : $offline;
    }

    public function collectAll(): array
    {
        $states = [];
        foreach (array_keys($this->registry->devices()) as $id) {
            $states[] = $this->query($id, 'status');
        }
        return $states;
    }

    private function offline(string $id): array
    {
        $type = $this->registry->devices()[$id]['type'] ?? 'tapo';
        return [
            'id' => $id, 'type' => $type, 'name' => $id,
            'on' => false, 'power_w' => null,
            'brightness' => null, 'color_temp' => null,
            'online' => false, 'ts' => 0,
        ];
    }
}
