<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Tapo\Auth;
use Tapo\DeviceRegistry;
use Tapo\Gateway;
use Tapo\WorkerClient;

$port     = (int) ($argv[1] ?? 9090);
$registry = new DeviceRegistry();

(new Gateway($port, $registry, new Auth(), new WorkerClient($registry), $registry->spoofGroups()))->run();
