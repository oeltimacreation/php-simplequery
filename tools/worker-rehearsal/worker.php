<?php

declare(strict_types=1);

use Oeltima\SimpleQuery\Tools\WorkerRehearsal\RehearsalApp;
use Oeltima\SimpleQuery\Tools\WorkerRehearsal\RehearsalConfig;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/src/RehearsalConfig.php';
require __DIR__ . '/src/RehearsalHolder.php';
require __DIR__ . '/src/RehearsalApp.php';

if (!function_exists('frankenphp_handle_request')) {
    fwrite(STDERR, "worker.php must run under FrankenPHP worker mode.\n");
    exit(2);
}

$app = new RehearsalApp(RehearsalConfig::fromEnvironment());
$handler = static function () use ($app): void {
    $requested = $_SERVER['REQUEST_URI'] ?? '/';
    $path = is_string($requested) ? parse_url($requested, PHP_URL_PATH) : '/';
    $result = $app->handle(is_string($path) ? $path : '/');
    http_response_code($result['status']);
    header('Content-Type: application/json');
    echo json_encode($result['payload'], JSON_THROW_ON_ERROR), PHP_EOL;
};

$maxRequestsValue = $_SERVER['MAX_REQUESTS'] ?? 0;
$maxRequests = is_numeric($maxRequestsValue) ? (int) $maxRequestsValue : 0;
for ($handled = 0; $maxRequests === 0 || $handled < $maxRequests; ++$handled) {
    $keepRunning = frankenphp_handle_request($handler);
    gc_collect_cycles();
    if ($keepRunning !== true) {
        break;
    }
}

$app->shutdown();
