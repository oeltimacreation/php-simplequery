<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\WorkerRehearsal\RehearsalApp;
use Oeltima\SimpleQuery\Tools\WorkerRehearsal\RehearsalConfig;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/RehearsalConfig.php';
require dirname(__DIR__) . '/src/RehearsalHolder.php';
require dirname(__DIR__) . '/src/RehearsalApp.php';

// Classic-mode control: one fresh application and owner per request.
$app = new RehearsalApp(RehearsalConfig::fromEnvironment());
$requested = $_SERVER['REQUEST_URI'] ?? '/';
$path = is_string($requested) ? parse_url($requested, PHP_URL_PATH) : '/';
$result = $app->handle(is_string($path) ? $path : '/');
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['payload'], JSON_THROW_ON_ERROR), PHP_EOL;
$app->shutdown();
