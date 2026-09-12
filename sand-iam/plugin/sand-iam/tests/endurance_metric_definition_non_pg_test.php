<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$guide = (string) file_get_contents($root . '/docs/development/sand-iam-endurance-acceptance.md');
$generator = (string) file_get_contents($root . '/tools/prepare-external-acceptance.php');
$runner = (string) file_get_contents($root . '/tools/run-endurance-acceptance.php');
$verifier = (string) file_get_contents($root . '/tools/verify-endurance-evidence.php');

function enduranceMetricDefinitionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "endurance metric definition failed: {$message}\n");
        exit(1);
    }
}

foreach (['security_operation_retention_backlog', 'auth_rate_limit_retention_backlog'] as $metric) {
    enduranceMetricDefinitionAssert(str_contains($generator, "'{$metric}'"), "generator omits {$metric}");
    enduranceMetricDefinitionAssert(str_contains($runner, "'{$metric}'"), "runner omits {$metric}");
    enduranceMetricDefinitionAssert(str_contains($verifier, "'{$metric}'"), "verifier omits {$metric}");
    enduranceMetricDefinitionAssert(str_contains($guide, "`{$metric}`"), "guide omits {$metric}");
}

enduranceMetricDefinitionAssert(
    str_contains($guide, '十二个资源、队列、安全与 Worker 指标')
        && str_contains($guide, '两个完整维护间隔')
        && str_contains($guide, 'make_interval(days => :retention_days, secs => :interval_seconds * 2)')
        && str_contains($guide, 'make_interval(hours => :retention_hours, secs => :interval_seconds * 2)'),
    'retention grace or exact PostgreSQL clock formula drifted',
);
enduranceMetricDefinitionAssert(
    str_contains($guide, "state = 'succeeded'")
        && str_contains($guide, 'pending 永不计入')
        && str_contains($guide, 'delete_time IS NULL')
        && str_contains($guide, '不得回填为零'),
    'backlog scope or fail-closed sampling rule drifted',
);
foreach (['max_security_operation_retention_backlog', 'max_auth_rate_limit_retention_backlog'] as $threshold) {
    enduranceMetricDefinitionAssert(
        str_contains($generator, "'{$threshold}' => 0") && str_contains($runner, "'{$threshold}'"),
        "zero retention threshold drifted for {$threshold}",
    );
}
foreach ([
    "status IN (1, 2)",
    "state IN ('pending', 'sending')",
    "state = 'pending' AND status = 1",
    "state = 'running'",
    "state = 'dead'",
    'client.backchannel_logout_uri IS NULL',
    'session.status <> 2',
    'session.revoked_time IS NULL',
    'delivery.status = 4',
    "outbox.state = 'failed'",
    'connector.status <> 1',
    "connector.direction NOT IN ('outbound', 'bidirectional')",
    'application.status <> 1',
    'organization.status <> 1',
] as $queueRule) {
    enduranceMetricDefinitionAssert(str_contains($guide, $queueRule), "queue state formula omits {$queueRule}");
}
enduranceMetricDefinitionAssert(
    substr_count($guide, 'AS queue_depth') === 1 && substr_count($guide, 'AS unrecoverable_backlog') === 1,
    'queue metrics are not bound to one exact SQL formula',
);

echo "SandIAM endurance metric definition checks passed\n";
