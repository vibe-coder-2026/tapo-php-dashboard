<?php

declare(strict_types=1);

/**
 * Usage: php launcher.php [--port N]   (default: first free port from 9090)
 */

require_once __DIR__ . '/vendor/autoload.php';

use Tapo\DeviceRegistry;
use Tapo\Launcher;

$port = null;
foreach ($argv as $arg) {
    if (preg_match('/^--port=(\d+)$/', $arg, $m)) {
        $port = (int) $m[1];
    }
}
if ($port === null) {
    $idx = array_search('--port', $argv, true);
    if ($idx !== false && isset($argv[$idx + 1])) {
        $port = (int) $argv[$idx + 1];
    }
}
if ($port === null) {
    $port = Launcher::firstFreePort(9090, 10000);
}

$env    = parse_ini_file(__DIR__ . '/.env');
$dbPath = $env['DB_PATH'] ?? null;
if ($dbPath !== null && !str_starts_with($dbPath, '/')) {
    $dbPath = __DIR__ . '/' . $dbPath;
}

(new Launcher($port, __DIR__, new DeviceRegistry(), $dbPath))->run();
