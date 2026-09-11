<?php

declare(strict_types=1);

$baseUrl = rtrim((string) getenv('SAND_IAM_BASE_URL'), '/');
$credential = trim((string) getenv('SAND_IAM_WORKLOAD_CREDENTIAL'));
$audience = trim((string) (getenv('SAND_IAM_AUDIENCE') ?: 'sand-ai'));
$serviceCode = trim((string) (getenv('SAND_IAM_SERVICE_CODE') ?: 'sand-ai'));
$action = trim((string) (getenv('SAND_IAM_SERVICE_ACTION') ?: 'inference.chat'));
if ($baseUrl === '' || $credential === '') throw new RuntimeException('SAND_IAM_BASE_URL 和 SAND_IAM_WORKLOAD_CREDENTIAL 必须由部署环境注入');
$requestId = 'sandai-context-' . bin2hex(random_bytes(8));
$body = json_encode(['service_code' => $serviceCode, 'audience' => $audience, 'actions' => [$action]], JSON_THROW_ON_ERROR);
$response = file_get_contents($baseUrl . '/app/sand-iam/runtime/context/issue', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Authorization: Bearer {$credential}\r\nContent-Type: application/json\r\nCache-Control: no-store\r\nX-Request-Id: {$requestId}\r\n", 'content' => $body, 'ignore_errors' => true]]));
if ($response === false) throw new RuntimeException('SandIAM context.issue 网络请求失败');
$decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
if (!is_array($decoded) || !is_array($decoded['data'] ?? null) || !is_string($decoded['data']['context'] ?? null)) throw new RuntimeException('SandIAM 未签发机器运行上下文；检查服务授权、audience、action 和凭证状态');
// Pass data.context directly to SandAI over an internal trusted channel; never log it.
echo json_encode(['request_id' => $requestId, 'context_id' => $decoded['data']['context_id'] ?? null, 'expire_time' => $decoded['data']['expire_time'] ?? null], JSON_UNESCAPED_UNICODE) . PHP_EOL;
