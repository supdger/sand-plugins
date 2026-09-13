<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/tools/consumer-acceptance/Runtime.php';

$root = dirname(__DIR__, 3);
$fixturePath = $root . '/tools/fixtures/consumer-acceptance-plan.example.json';
$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void { if (!$condition) throw new RuntimeException($message); ++$passed; };
$reject = static function (callable $call, string $message) use ($assert): void { try { $call(); } catch (Throwable) { $assert(true, $message); return; } throw new RuntimeException($message . ' was accepted'); };
$copy = static fn (): array => json_decode(json_encode($fixture, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

ConsumerAcceptanceContract::plan($fixture);
$assert(true, 'fixture plan validates');
foreach ([
    static function (array $p): array { unset($p['gate_receipts']['cleanup_gate']); return $p; },
    static function (array $p): array { $p['candidate']['consumer_tree_sha256'] = str_repeat('0', 64); return $p; },
    static function (array $p): array { $p['gate_receipts']['authorization_gate']['run_id'] = 'old_run'; return $p; },
    static function (array $p): array { $p['gate_receipts']['fixture_ownership_gate']['host_sha256'] = str_repeat('0', 64); return $p; },
    static function (array $p): array { $p['gate_receipts']['cleanup_gate']['cleanup_manifest_sha256'] = str_repeat('0', 64); return $p; },
    static function (array $p): array { $p['fixture_scope_sha256'] = str_repeat('0', 64); return $p; },
    static function (array $p): array { $p['fixtures']['identity_id'] = 'consumer_l04_other_identity'; return $p; },
    static function (array $p): array { $p['fixtures']['identity_id'] = 'consumer_l04_other_identity'; $p['fixture_scope_sha256'] = ConsumerAcceptanceContract::scopeHash($p['run_id'], $p['fixtures']); return $p; },
    static function (array $p): array { $p['run_id'] = 'consumer_l04_new_run'; return $p; },
    static function (array $p): array { $p['profile'] = 'other'; return $p; },
    static function (array $p): array { $p['steps'][1]['id'] = 'audit'; return $p; },
    static function (array $p): array { $p['origins']['consumer'] = 'http://localhost:8088'; return $p; },
    static function (array $p): array { $p['origins']['sandiam'] = 'HTTPS://iam.example.test'; return $p; },
    static function (array $p): array { $p['origins']['sandiam'] = 'https://user@iam.example.test'; return $p; },
    static function (array $p): array { $p['origins']['sandiam'] = 'https://iam%2eexample.test'; return $p; },
    static function (array $p): array { $p['origins']['consumer'] = 'http://[::1]:8088'; return $p; },
    static function (array $p): array { $p['origins']['consumer'] = 'http://127.0.0.1:65536'; return $p; },
    static function (array $p): array { $p['origins']['sandiam'] = 'https:\\\\iam.example.test'; return $p; },
    static function (array $p): array { $p['live_credentials']['consumer']['origin'] = $p['origins']['sandiam']; return $p; },
    static function (array $p): array { $p['sql'] = 'select 1'; return $p; },
    static function (array $p): array { $p['headers'] = ['x-any' => 'value']; return $p; },
    static function (array $p): array { $p['path'] = '/anything'; return $p; },
] as $mutation) $reject(static fn () => ConsumerAcceptanceContract::plan($mutation($copy())), 'strict plan negative');
$reordered = $copy(); $reordered['fixtures'] = array_reverse($reordered['fixtures'], true);
ConsumerAcceptanceContract::plan($reordered);
$assert(true, 'fixture key order does not change canonical scope hash');

$runtime = new ConsumerAcceptanceRuntime();
$report = $runtime->validate($fixture, hash('sha256', (string) file_get_contents($fixturePath)));
ConsumerAcceptanceContract::report($report);
$assert($report['real_l04'] === false && $report['business_attempted'] === false && $report['failure_stops_business'] === true, 'validate mode is fail-closed');
$expectedFixtureIds = [$report['fixtures']['organization_id'], $report['fixtures']['application_id'], $report['fixtures']['identity_id'], $report['fixtures']['business_object_id']];
$assert($report['cleanup_boundary']['state'] === 'not_attempted' && $report['cleanup_boundary']['fixture_ids'] === $expectedFixtureIds, 'validate cleanup boundary remains named-fixture-bound');
foreach ([
    static function (array $r): array { $r['real_l04'] = true; return $r; },
    static function (array $r): array { $r['generated_at'] = '2026-02-30T12:00:00Z'; return $r; },
    static function (array $r): array { $r['steps'][1]['id'] = 'cleanup'; return $r; },
    static function (array $r): array { $r['gate_receipts']['cleanup_gate']['fixture_scope_sha256'] = str_repeat('0', 64); return $r; },
    static function (array $r): array { $r['fixtures']['business_object_id'] = 'consumer_l04_other_object'; return $r; },
    static function (array $r): array { $r['run_id'] = 'consumer_l04_old_report'; return $r; },
    static function (array $r): array { $r['cleanup_boundary']['fixture_ids'][] = 'consumer_l04_expanded'; return $r; },
] as $mutation) $reject(static fn () => ConsumerAcceptanceContract::report($mutation($report)), 'strict report negative');

$temporary = sys_get_temp_dir() . '/sand-iam-consumer-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
try {
    $secret = $temporary . '/live-secrets'; file_put_contents($secret, "placeholder\n"); chmod($secret, 0644);
    $reject(static fn () => ConsumerAcceptanceRuntime::liveSecrets($secret, $root), '0644 secrets file');
    chmod($secret, 0600); ConsumerAcceptanceRuntime::liveSecrets($secret, $root); $assert(true, '0600 owned secrets file is eligible only for live mode');
    $secretLink = $temporary . '/linked-secrets'; symlink($secret, $secretLink);
    $reject(static fn () => ConsumerAcceptanceRuntime::liveSecrets($secretLink, $root), 'symlink secrets file');
    $live = $runtime->live($fixture, hash('sha256', 'fixture'), $secret, $root);
    $assert($live['status'] === 'unsupported' && $live['business_attempted'] === false && $live['cleanup_boundary']['state'] === 'not_started', 'unsupported live skeleton stops business before cleanup');

    $planFile = $temporary . '/plan.json'; $reportFile = $temporary . '/report.json'; file_put_contents($planFile, json_encode($fixture, JSON_THROW_ON_ERROR));
    $runner = $root . '/tools/run-consumer-acceptance.php';
    $run = static function (string $plan, string $report) use ($runner): int {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' --mode=validate --plan=' . escapeshellarg($plan) . ' --report=' . escapeshellarg($report), $unused, $exit);
        return $exit;
    };
    $assert($run($planFile, $reportFile) === 0 && is_file($reportFile), 'validate runner atomically publishes report');
    foreach (['relative-plan.json', 'http://127.0.0.1:9/plan', 'https://iam.example.test/plan', 'data://text/plain,{}', 'php://filter/resource=/tmp/plan', $temporary] as $badPlan) {
        $badReport = $temporary . '/bad-' . bin2hex(random_bytes(3)) . '.json';
        $assert($run($badPlan, $badReport) !== 0 && !file_exists($badReport), 'CLI rejects non-local plan input before parse');
    }
    $planLink = $temporary . '/plan-link.json'; symlink($planFile, $planLink);
    $assert($run($planLink, $temporary . '/linked-report.json') !== 0, 'CLI rejects plan symlink');
    $parentLink = $temporary . '-link'; symlink($temporary, $parentLink);
    $assert($run($parentLink . '/plan.json', $temporary . '/parent-link-report.json') !== 0, 'CLI rejects parent path symlink');
    $planSchema = json_decode((string) file_get_contents($root . '/tools/schemas/consumer-acceptance-plan.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $reportSchema = json_decode((string) file_get_contents($root . '/tools/schemas/consumer-acceptance-report.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(array_keys($planSchema['properties']['gate_receipts']['properties']) === ['authorization_gate', 'fixture_ownership_gate', 'cleanup_gate'], 'plan schema has exact three gates');
    $assert(array_column($reportSchema['properties']['steps']['prefixItems'], '$ref') === ['#/$defs/preflight', '#/$defs/allow', '#/$defs/deny', '#/$defs/revoke', '#/$defs/audit', '#/$defs/cleanup'], 'report schema freezes each step position');
    $source = (string) file_get_contents($root . '/tools/consumer-acceptance/Runtime.php');
    $assert(!str_contains($source, 'curl_init(') && !str_contains($source, 'new PDO'), 'validate runtime contains no transport or PDO initialization');
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) unlink($file);
    if (is_link($temporary . '-link')) unlink($temporary . '-link');
    rmdir($temporary);
}
echo "consumer acceptance runner non-PG tests passed={$passed}\n";
