<?php

declare(strict_types=1);

require_once __DIR__ . '/MachineServiceHttpClient.php';

$context = trim((string) getenv('SAND_IAM_RUNTIME_CONTEXT'));
$baseUrl = rtrim((string) getenv('SAND_IAM_BASE_URL'), '/');
if ($baseUrl === '' || $context === '') throw new RuntimeException('SAND_IAM_BASE_URL 和短期 SAND_IAM_RUNTIME_CONTEXT 必须由运行环境注入');
$serviceCode = trim((string) (getenv('SAND_IAM_SERVICE_CODE') ?: 'document-service'));
$audience = trim((string) (getenv('SAND_IAM_AUDIENCE') ?: 'document-service'));
$action = trim((string) (getenv('SAND_IAM_SERVICE_ACTION') ?: 'document.read'));
$requestId = 'machine-verify-' . bin2hex(random_bytes(8));
$data = MachineServiceHttpClient::postJson(
    $baseUrl . '/app/sand-iam/runtime/context/verify',
    ['context' => $context, 'audience' => $audience, 'action' => $action],
    ['X-Request-Id' => $requestId],
);
$actions = $data['actions'] ?? null;
if (
    !is_string($data['context_id'] ?? null) || $data['context_id'] === ''
    || !is_string($data['service_code'] ?? null) || !hash_equals($serviceCode, $data['service_code'])
    || !is_string($data['audience'] ?? null) || !hash_equals($audience, $data['audience'])
    || !is_array($actions) || !array_is_list($actions) || !in_array($action, $actions, true)
) {
    throw new RuntimeException('SandIAM 返回的上下文与目标服务、受众或动作不一致');
}
echo json_encode([
    'request_id' => $requestId,
    'context_id' => $data['context_id'],
    'service_code' => $serviceCode,
    'audience' => $audience,
    'action' => $action,
    'allowed' => true,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
