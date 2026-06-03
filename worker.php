<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Tapo\Config;
use Tapo\DeviceRegistry;
use Tapo\Worker;

(new Worker(
    $argv[1] ?? '',
    Config::fromEnvFile(__DIR__ . '/.env'),
    new DeviceRegistry(),
))->run();
