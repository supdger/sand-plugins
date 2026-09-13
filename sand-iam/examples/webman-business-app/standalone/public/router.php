<?php

declare(strict_types=1);

use Example\Standalone\Controller;

try {
    /** @var Controller $controller */
    $controller = require dirname(__DIR__) . '/bootstrap.php';
    $headers = [];
    foreach (getallheaders() ?: [] as $name => $value) {
        $headers[strtolower($name)] = trim((string) $value);
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $result = $controller->handle(
        strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
        is_string($path) ? $path : '/',
        $headers,
        (string) file_get_contents('php://input'),
    );
} catch (\Throwable) {
    $result = ['status' => 503, 'body' => ['error' => 'unavailable']];
}

http_response_code($result['status']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
