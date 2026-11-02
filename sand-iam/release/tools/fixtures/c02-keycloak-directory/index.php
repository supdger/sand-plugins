<?php

declare(strict_types=1);

require __DIR__ . '/C02KeycloakDirectory.php';

if (getenv('SAND_IAM_C02_DIRECTORY_ENABLED') !== '1') {
    http_response_code(503);
    header('Content-Type: application/json');
    echo '{"error":"directory_disabled"}';
    exit;
}

$headers = [];
foreach (getallheaders() ?: [] as $name => $value) $headers[strtolower((string) $name)] = (string) $value;

try {
    $directory = new C02KeycloakDirectory(
        (string) getenv('SAND_IAM_C02_DIRECTORY_STATE_DIR'),
        (string) getenv('SAND_IAM_C02_DIRECTORY_CONTROL_TOKEN'),
    );
    $result = $directory->handle(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
        $_GET,
        $headers,
        (string) file_get_contents('php://input'),
    );
    http_response_code($result['status']);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    foreach ($result['headers'] ?? [] as $name => $value) header($name . ': ' . $value);
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo '{"error":"request_rejected"}';
}
