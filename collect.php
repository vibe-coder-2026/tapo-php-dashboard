<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Tapo\Collector;
use Tapo\Config;
use Tapo\DeviceRegistry;

$env    = parse_ini_file(__DIR__ . '/.env');
$dbPath = $env['DB_PATH'] ?? null;

if (!$dbPath) {
    echo "DB_PATH not set in .env — skipping collection\n";
    exit(0);
}

if (!str_starts_with($dbPath, '/')) {
    $dbPath = __DIR__ . '/' . $dbPath;
}

$collector = new Collector(
    Config::fromEnvFile(__DIR__ . '/.env'),
    new DeviceRegistry(),
    $dbPath,
);

in_array('--daemon', $argv) ? $collector->daemon() : $collector->run();
