<?php
declare(strict_types=1);
use Sand\Iam\Example\ProviderB\{FailureAuditWriter,PdoProcessStore,ProviderApplication,ProviderConfig,ProviderException,RouteInput,SandIamContextVerifier};
require_once dirname(__DIR__) . '/vendor/autoload.php';
$requestId = requestId();
$routeMatched = false; $documentId = null; $context = ''; $key = ''; $auditConfig = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && pathOnly() === '/health') respond(200, ['status'=>'ok','request_id'=>$requestId]);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || preg_match('#^/provider/v1/documents/([A-Za-z0-9][A-Za-z0-9._-]{0,63})/process$#', pathOnly(), $match) !== 1) throw new ProviderException('PROVIDER_B_ROUTE_NOT_FOUND', 404);
    $routeMatched = true; $documentId = $match[1];
    $config = ProviderConfig::fromEnvironment(); $auditConfig = $config; // Configuration only: no PDO connection.
    $context = value('X-Sand-Iam-Context'); $key = value('Idempotency-Key');
    RouteInput::validate(file_get_contents('php://input'), $context, $key);
    $db = new PDO($config->databaseDsn, $config->databaseUser, $config->databasePassword, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
    $result = (new ProviderApplication(new SandIamContextVerifier($config), new PdoProcessStore($db)))->process($match[1], $context, $key, $requestId);
    respond(200, ['data'=>$result,'request_id'=>$requestId]);
} catch (ProviderException $e) {
    failureAudit($routeMatched, $auditConfig, $documentId, $context, $key, $requestId, $e->errorCode, $e->httpStatus);
    respond($e->httpStatus, ['code'=>$e->errorCode,'request_id'=>$requestId]);
} catch (JsonException) {
    failureAudit($routeMatched, $auditConfig, $documentId, $context, $key, $requestId, 'PROVIDER_B_INVALID_REQUEST', 400);
    respond(400, ['code'=>'PROVIDER_B_INVALID_REQUEST','request_id'=>$requestId]);
} catch (Throwable) {
    failureAudit($routeMatched, $auditConfig, $documentId, $context, $key, $requestId, 'PROVIDER_B_UNAVAILABLE', 503);
    respond(503, ['code'=>'PROVIDER_B_UNAVAILABLE','request_id'=>$requestId]);
}
function failureAudit(bool $route, ?ProviderConfig $config, ?string $document, string $context, string $key, string $request, string $code, int $status): void
{
    if ($route && $config !== null) (new FailureAuditWriter($config))->write($document, $context, $key, $request, $code, $status);
}
function pathOnly(): string { return (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH); }
function value(string $name): string { return trim((string)($_SERVER['HTTP_'.strtoupper(str_replace('-', '_', $name))] ?? '')); }
function requestId(): string
{
    $id = value('X-Request-Id');
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,95}$/', $id) === 1 ? $id : 'provider-b-'.bin2hex(random_bytes(12));
}
/** @param array<string,mixed> $payload */
function respond(int $status, array $payload): never
{
    http_response_code($status); header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
}
