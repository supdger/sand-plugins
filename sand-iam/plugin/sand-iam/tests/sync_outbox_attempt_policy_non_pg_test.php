<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/service/SyncOutboxAttemptPolicy.php';

use plugin\SandIam\app\service\SyncOutboxAttemptPolicy;

function syncOutboxPolicyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "sync outbox attempt policy failed: {$message}\n");
        exit(1);
    }
}

$policy = new SyncOutboxAttemptPolicy(3);
$first = $policy->failure(0, 'SAND_IAM_SYNC_OUTBOUND_NOT_ACCEPTED');
$third = $policy->failure(2, 'SAND_IAM_SYNC_REMOTE_UNAVAILABLE');
$safe = $policy->failure(0, 'remote password=secret');
$retry = $policy->retry();

syncOutboxPolicyAssert($first['state'] === 'pending' && $first['attempt_count'] === 1 && $first['status'] === 1, 'first failure did not remain retryable');
syncOutboxPolicyAssert($third['state'] === 'failed' && $third['attempt_count'] === 3 && $third['status'] === 2, 'maximum attempt did not become terminal');
syncOutboxPolicyAssert($safe['error_code'] === 'SAND_IAM_SYNC_OUTBOUND_FAILED', 'unsafe driver text reached persisted error state');
syncOutboxPolicyAssert($retry === ['state' => 'pending', 'attempt_count' => 0, 'error_code' => null, 'delivered_time' => null, 'status' => 1], 'operator retry transition drifted');
syncOutboxPolicyAssert((new SyncOutboxAttemptPolicy(0))->maxAttempts() === 1 && (new SyncOutboxAttemptPolicy(101))->maxAttempts() === 100, 'configured attempt bounds drifted');

echo "sync outbox attempt policy tests passed\n";
