<?php

declare(strict_types=1);

$context = trim((string) getenv('SAND_AI_RUNTIME_CONTEXT'));
$baseUrl = rtrim((string) getenv('SAND_IAM_BASE_URL'), '/');
if ($baseUrl === '' || $context === '') throw new RuntimeException('SAND_IAM_BASE_URL 和短期 SAND_AI_RUNTIME_CONTEXT 必须由运行环境注入');
$body = json_encode(['context' => $context, 'audience' => getenv('SAND_IAM_AUDIENCE') ?: 'sand-ai', 'action' => getenv('SAND_IAM_SERVICE_ACTION') ?: 'inference.chat'], JSON_THROW_ON_ERROR);
$response = file_get_contents($baseUrl . '/app/sand-iam/runtime/context/verify', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nCache-Control: no-store\r\nX-Request-Id: sandai-verify-" . bin2hex(random_bytes(8)) . "\r\n", 'content' => $body, 'ignore_errors' => true]]));
if ($response === false) throw new RuntimeException('SandIAM context.verify 网络请求失败');
echo $response . PHP_EOL;
