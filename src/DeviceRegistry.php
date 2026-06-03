<?php

declare(strict_types=1);

namespace Tapo;

/**
 * Loads device configuration from devices.json in the project root.
 *
 * JSON schema:
 * {
 *   "devices": {
 *     "<id>": {
 *       "type":      "tapo"|"kasa"|"bulb",
 *       "host":      "<dns-or-ip>",
 *       "groups":    ["admin", ...],   // optional, default: ["admin"]
 *       "is_public": true|false        // optional, default: true
 *     }
 *   },
 *   "groups": {
 *     "<group-name>": ["<device-id>", ...]
 *   },
 *   "spoof_groups": ["admin"],   // optional — forces all requests into these groups (dev only)
 *   "min_max": {                 // optional — i3block colour thresholds, keyed by device nickname
 *     "My Device": { "min": 10, "max": 200 }
 *   }
 * }
 *
 * The id doubles as the Unix socket name (/tmp/tapo-dash/<id>.sock) and the
 * DOM card id — keep it filesystem/HTML safe.
 *
 * Access model (enforced in Gateway from the X-Forwarded-Groups header):
 *   visible      = is_public OR (userGroups ∩ device.groups)
 *   controllable = (userGroups ∩ device.groups)
 */
class DeviceRegistry
{
    private static ?array $cache = null;

    public function devices(): array
    {
        return $this->load()['devices'];
    }

    public function groups(): array
    {
        return $this->load()['groups'];
    }

    /** Returns the spoof_groups value, or null if the key is absent from devices.json. */
    public function spoofGroups(): ?array
    {
        return $this->load()['spoof_groups'];
    }

    /** Returns [min, max] watts for the given device nickname, or null if not configured. */
    public function minMax(string $deviceName): ?array
    {
        return $this->load()['min_max'][$deviceName] ?? null;
    }

    public function socketDir(): string
    {
        return sys_get_temp_dir() . '/tapo-dash';
    }

    public function socketPath(string $id): string
    {
        return $this->socketDir() . '/' . $id . '.sock';
    }

    private function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = dirname(__DIR__) . '/devices.json';
        if (!file_exists($path)) {
            fwrite(STDERR, "devices.json not found at {$path} — copy devices.example.json and edit it\n");
            exit(1);
        }

        $raw = json_decode(file_get_contents($path), true);
        if (!is_array($raw)) {
            fwrite(STDERR, "devices.json is not valid JSON\n");
            exit(1);
        }

        $devices = [];
        foreach ($raw['devices'] ?? [] as $id => $d) {
            $devices[(string) $id] = [
                'type'      => (string) ($d['type'] ?? 'tapo'),
                'host'      => (string) ($d['host'] ?? $id),
                'groups'    => (array) ($d['groups'] ?? ['admin']),
                'is_public' => (bool) ($d['is_public'] ?? true),
            ];
        }

        $minMax = [];
        foreach ($raw['min_max'] ?? [] as $name => $mm) {
            $minMax[(string) $name] = [(int) ($mm['min'] ?? 0), (int) ($mm['max'] ?? 0)];
        }

        return self::$cache = [
            'devices'      => $devices,
            'groups'       => (array) ($raw['groups'] ?? []),
            'spoof_groups' => array_key_exists('spoof_groups', $raw) ? (array) $raw['spoof_groups'] : null,
            'min_max'      => $minMax,
        ];
    }
}
