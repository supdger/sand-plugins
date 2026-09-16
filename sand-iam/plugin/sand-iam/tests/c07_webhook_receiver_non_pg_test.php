<?php

declare(strict_types=1);

// behavior-test-gate: unit

require dirname(__DIR__, 3) . '/tools/fixtures/c07-webhook-receiver/C07WebhookReceiver.php';

function c07ReceiverFail(string $message): never
{
    fwrite(STDERR, "c07 webhook receiver non-PG test failed: {$message}\n");
    exit(1);
}

$directory = sys_get_temp_dir() . '/sand_iam_c07_receiver_' . bin2hex(random_bytes(8));
$token = str_repeat('control-token-', 4);
$scope = 'sand_iam_acceptance_0123456789abcdef_';
$receiver = new C07WebhookReceiver($directory, $token);
$control = ['x-c07-control-token' => $token];
$secret = 'siwh_' . str_repeat('s', 40);
$requestId = $scope . 'chain7-credential-issue';

try {
    $configured = $receiver->handle('POST', '/webhook/control/configure', [], $control, json_encode([
        'scope' => $scope,
        'secret' => $secret,
        'application_id' => 20,
        'endpoint_id' => 30,
        'credential_issue_request_id' => $requestId,
    ], JSON_THROW_ON_ERROR));
    $configId = $configured['body']['receiver_config_id'] ?? null;
    if ($configured['status'] !== 200 || !is_string($configId) || strlen($configId) !== 64) c07ReceiverFail('configuration was not accepted');

    $event = [
        'id' => 'evt_' . str_repeat('a', 32),
        'type' => 'credential.changed',
        'schema_version' => 1,
        'occurred_at' => gmdate('c'),
        'application_id' => 20,
        'data' => [
            'action' => 'credential.issue',
            'outcome' => 'succeeded',
            'resource_type' => 'credential',
            'resource_id' => 40,
            'request_id' => $requestId,
        ],
    ];
    $body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $headers = [
        'x-sandiam-event-id' => $event['id'],
        'x-sandiam-event-type' => $event['type'],
        'x-sandiam-timestamp' => $timestamp,
        'x-sandiam-secret-version' => '1',
        'x-sandiam-signature' => 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
    ];
    $invalid = $headers;
    $invalid['x-sandiam-signature'] = 'v1=' . str_repeat('0', 64);
    if ($receiver->handle('POST', '/webhook/receive', ['scope' => $scope], $invalid, $body)['status'] !== 401) c07ReceiverFail('invalid signature was accepted');
    if ($receiver->handle('POST', '/webhook/receive', ['scope' => $scope], $headers, $body)['status'] !== 500) c07ReceiverFail('first valid attempt did not return 500');
    if ($receiver->handle('POST', '/webhook/receive', ['scope' => $scope], $headers, $body)['status'] !== 204) c07ReceiverFail('second valid attempt did not return 204');

    $proof = $receiver->handle('GET', '/webhook/proof', ['scope' => $scope], [], '');
    if ($proof['status'] !== 200
        || ($proof['body']['signature_verified'] ?? false) !== true
        || ($proof['body']['attempt_count'] ?? 0) !== 2
        || ($proof['body']['first_status'] ?? 0) !== 500
        || ($proof['body']['last_status'] ?? 0) !== 204
        || ($proof['body']['credential_id'] ?? 0) !== 40
        || str_contains(json_encode($proof, JSON_THROW_ON_ERROR), $secret)) {
        c07ReceiverFail('proof does not preserve the required minimal evidence');
    }

    $stateFiles = glob($directory . '/*.json') ?: [];
    if (count($stateFiles) !== 1 || (fileperms($stateFiles[0]) & 0777) !== 0600) c07ReceiverFail('temporary secret state is not mode 0600');
    $cleanup = $receiver->handle('POST', '/webhook/control/cleanup', [], $control, json_encode([
        'scope' => $scope,
        'receiver_config_id' => $configId,
    ], JSON_THROW_ON_ERROR));
    $status = $receiver->handle('GET', '/webhook/control/status', ['scope' => $scope], $control, '');
    if ($cleanup['status'] !== 200 || ($cleanup['body']['residual'] ?? -1) !== 0 || ($status['body']['residual'] ?? -1) !== 0) {
        c07ReceiverFail('cleanup did not prove zero residual');
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}

echo "c07 webhook receiver non-PG test passed\n";
