<?php

declare(strict_types=1);

require_once __DIR__ . '/MachineServiceHttpClient.php';

$baseUrl = rtrim((string) getenv('SAND_IAM_BASE_URL'), '/');
$credential = trim((string) getenv('SAND_IAM_WORKLOAD_CREDENTIAL'));
$audience = trim((string) (getenv('SAND_IAM_AUDIENCE') ?: 'document-service'));
$serviceCode = trim((string) (getenv('SAND_IAM_SERVICE_CODE') ?: 'document-service'));
$action = trim((string) (getenv('SAND_IAM_SERVICE_ACTION') ?: 'document.read'));
if ($baseUrl === '' || $credential === '') throw new RuntimeException('SAND_IAM_BASE_URL 和 SAND_IAM_WORKLOAD_CREDENTIAL 必须由部署环境注入');
$requestId = 'machine-context-' . bin2hex(random_bytes(8));
$data = MachineServiceHttpClient::postJson(
    $baseUrl . '/app/sand-iam/runtime/context/issue',
    ['service_code' => $serviceCode, 'audience' => $audience, 'actions' => [$action]],
    ['Authorization' => 'Bearer ' . $credential, 'X-Request-Id' => $requestId],
);
if (!is_string($data['context'] ?? null) || $data['context'] === '' || !is_string($data['context_id'] ?? null) || $data['context_id'] === '' || !is_string($data['expire_time'] ?? null) || $data['expire_time'] === '') {
    throw new RuntimeException('SandIAM 未签发完整的机器运行上下文；检查服务授权、audience、action 和凭证状态');
}
// Pass data.context directly to the target service over a trusted channel; never log it.
echo json_encode(['request_id' => $requestId, 'context_id' => $data['context_id'], 'expire_time' => $data['expire_time']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
