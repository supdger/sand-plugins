<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

require_once dirname(__DIR__, 3) . '/tools/consumer-acceptance-v2/LiveContract.php';

$pass = 0;
$assert = static function (bool $ok, string $message) use (&$pass): void { if (!$ok) throw new RuntimeException($message); ++$pass; };
$reject = static function (callable $call, string $message) use ($assert): void { try { $call(); } catch (Throwable) { $assert(true, $message); return; } throw new RuntimeException($message . ' was accepted'); };
$copy = static fn (array $value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$hash = static fn (string $value): string => hash('sha256', $value);
$now = new DateTimeImmutable('2026-09-13T10:00:00Z');
$plan = [
    'schema' => 'sand-iam.consumer-acceptance-v2-plan/v1', 'run_id' => 'consumer_l04_v2_run',
    'candidate' => ['candidate_sha256' => $hash('candidate'), 'host_sha256' => $hash('host'), 'clean_host_revision_sha256' => $hash('clean-host'), 'tree_a_sha256' => $hash('tree-a'), 'tree_b_sha256' => $hash('tree-b')],
    'origins' => ['iam' => 'https://iam.example.test', 'a' => 'https://a.example.test', 'b' => 'https://b.example.test'],
    'database' => ['iam' => ['role' => 'iam_role', 'schema_fingerprint_sha256' => $hash('iam-schema')], 'a' => ['role' => 'a_role', 'schema_fingerprint_sha256' => $hash('a-schema')], 'b' => ['role' => 'b_role', 'schema_fingerprint_sha256' => $hash('b-schema')]],
    'scope_cleanup' => ['scope_sha256' => $hash('scope'), 'cleanup_sha256' => $hash('cleanup')],
    'fixture_manifest_sha256' => $hash('fixtures'), 'actions' => ConsumerAcceptanceV2ActionRegistry::steps(),
    'secret_slots' => ['iam_admin' => 'SAND_IAM_ADMIN_TOKEN', 'a_user' => 'CONSUMER_A_USER_TOKEN', 'b_workload' => 'PROVIDER_B_WORKLOAD_CREDENTIAL'],
];
$temporary = sys_get_temp_dir() . '/sand-iam-consumer-v2-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
try {
    $keys = sodium_crypto_sign_keypair();
    $trustedKey = $temporary . '/trusted.ed25519.pub';
    file_put_contents($trustedKey, base64_encode(sodium_crypto_sign_publickey($keys)) . "\n");
    $receipt = static function (string $type) use ($plan, $keys): array {
        $publicKey = sodium_crypto_sign_publickey($keys);
        $value = ['schema' => 'sand-iam.consumer-acceptance-v2-receipt/v1', 'type' => $type, 'algorithm' => 'Ed25519', 'key_id' => 'test_key', 'public_key_sha256' => hash('sha256', $publicKey), 'issued_at' => '2026-09-13T09:00:00Z', 'expires_at' => '2026-09-13T11:00:00Z', 'run_id' => $plan['run_id'], 'plan_sha256' => hash('sha256', ConsumerAcceptanceV2ReceiptVerifier::canonical($plan)), 'candidate' => $plan['candidate'], 'origins' => $plan['origins'], 'database' => $plan['database'], 'scope_cleanup' => $plan['scope_cleanup'], 'fixture_manifest_sha256' => $plan['fixture_manifest_sha256'], 'action_registry_sha256' => ConsumerAcceptanceV2ActionRegistry::manifestHash(), 'authorizer' => 'independent_authorizer'];
        $value['signature'] = base64_encode(sodium_crypto_sign_detached(ConsumerAcceptanceV2ReceiptVerifier::canonical($value), sodium_crypto_sign_secretkey($keys)));
        return $value;
    };
    $resign = static function (array $value) use ($keys): array {
        unset($value['signature']);
        $value['signature'] = base64_encode(sodium_crypto_sign_detached(ConsumerAcceptanceV2ReceiptVerifier::canonical($value), sodium_crypto_sign_secretkey($keys)));
        return $value;
    };
    $receipts = [$receipt('authorization_gate'), $receipt('fixture_ownership_gate'), $receipt('cleanup_gate')];
    $publicKey = sodium_crypto_sign_publickey($keys);
    $observed = ['schema' => 'sand-iam.consumer-acceptance-v2-capability/v1', 'algorithm' => 'Ed25519', 'key_id' => 'test_key', 'public_key_sha256' => hash('sha256', $publicKey), 'issued_at' => '2026-09-13T09:00:00Z', 'expires_at' => '2026-09-13T11:00:00Z', 'host_revision_sha256' => $plan['candidate']['host_sha256'], 'plan_sha256' => hash('sha256', ConsumerAcceptanceV2ReceiptVerifier::canonical($plan)), 'capability' => 'consumer_l04_cleanup_v1', 'version' => 'v1', 'enabled' => true, 'scope_sha256' => $plan['scope_cleanup']['scope_sha256']];
    $observed['signature'] = base64_encode(sodium_crypto_sign_detached(ConsumerAcceptanceV2ReceiptVerifier::canonical($observed), sodium_crypto_sign_secretkey($keys)));
    $report = ConsumerAcceptanceV2LiveContract::authorize($plan, $receipts, $trustedKey, $now, $observed, $trustedKey);
    $assert($report['status'] === 'authorized_offline' && $report['real_l04'] === false && $report['before_first_write'] === true && $report['transport_initialized'] === false && $report['database_factory_initialized'] === false, 'complete trusted receipts authorize offline only');
    $observedFile = $temporary . '/observed.json'; file_put_contents($observedFile, json_encode($observed, JSON_THROW_ON_ERROR));
    $assert(ConsumerAcceptanceV2ReceiptVerifier::readLocalJson($observedFile)['plan_sha256'] === $observed['plan_sha256'], 'safe local read permits current-user file beneath sticky temporary ancestor');
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson('//' . ltrim($observedFile, '/')), 'leading repeated slash blocks before source-tree boundary checks');
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson(dirname($observedFile) . '//observed.json'), 'parent repeated slash blocks before filesystem traversal');
    $validator = dirname(__DIR__, 3) . '/tools/validate-consumer-acceptance-v2-capability.php';
    $runObserved = static function (string $file) use ($validator, $trustedKey, $plan): int {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($validator) . ' --observed-capability-file=' . escapeshellarg($file) . ' --trusted-key-file=' . escapeshellarg($trustedKey) . ' --host-revision-sha256=' . escapeshellarg($plan['candidate']['host_sha256']) . ' --scope-sha256=' . escapeshellarg($plan['scope_cleanup']['scope_sha256']) . ' --now=2026-09-13T10:00:00Z', $unused, $exit);
        return $exit;
    };
    $assert($runObserved($observedFile) === 2, 'partial CLI inputs cannot validate a full-plan-bound capability');
    chmod($observedFile, 0666); $assert($runObserved($observedFile) === 2, '0666 observed file preflight blocks'); chmod($observedFile, 0644);
    $observedLink = $temporary . '/observed-link.json'; symlink($observedFile, $observedLink); $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson($observedLink), 'observed file symlink blocks'); $assert($runObserved($observedLink) === 2, 'observed symlink preflight blocks');
    $hardlinkedObserved = $temporary . '/observed-hardlink.json'; link($observedFile, $hardlinkedObserved); $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson($hardlinkedObserved), 'hardlinked observed file preflight blocks'); unlink($hardlinkedObserved);
    $unsafeParent = $temporary . '/unsafe-parent'; mkdir($unsafeParent, 0700); chmod($unsafeParent, 0777); $unsafeObserved = $unsafeParent . '/observed.json'; copy($observedFile, $unsafeObserved); $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson($unsafeObserved), 'non-sticky world-writable parent preflight blocks'); chmod($unsafeParent, 0700); unlink($unsafeObserved); rmdir($unsafeParent);
    $safeParent = $temporary . '/safe-parent'; mkdir($safeParent, 0700); copy($observedFile, $safeParent . '/observed.json'); symlink($safeParent, $temporary . '/parent-link'); $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson($temporary . '/parent-link/observed.json'), 'symlink parent preflight blocks'); unlink($temporary . '/parent-link'); unlink($safeParent . '/observed.json'); rmdir($safeParent);
    $sourceObserved = dirname(__DIR__, 3) . '/tools/consumer-acceptance-v2/LiveContract.php'; $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson($sourceObserved), 'source-tree observed file blocks'); $assert($runObserved($sourceObserved) === 2, 'source-tree observed file preflight blocks');
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::readLocalJson('data://text/plain,{}'), 'observed wrapper blocks'); $assert($runObserved('data://text/plain,{}') === 2, 'observed wrapper preflight blocks');
    $signatureTamper = $copy($receipts[0]); $signatureTamper['authorizer'] = 'other_authorizer';
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::verify($signatureTamper, $trustedKey, $now), 'signature covers receipt authorizer');
    $expiredAtBoundary = $copy($receipts[0]); $expiredAtBoundary['expires_at'] = '2026-09-13T10:00:00Z';
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::verify($resign($expiredAtBoundary), $trustedKey, $now), 'receipt rejects at its expiry boundary after re-signing');
    $invalidIssuedAt = $copy($receipts[0]); $invalidIssuedAt['issued_at'] = '2026-99-99T09:00:00Z';
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::verify($resign($invalidIssuedAt), $trustedKey, $now), 'receipt rejects an invalid UTC date after re-signing');
    $offsetExpiresAt = $copy($receipts[0]); $offsetExpiresAt['expires_at'] = '2026-09-13T11:00:00+00:00';
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::verify($resign($offsetExpiresAt), $trustedKey, $now), 'receipt rejects an offset timestamp after re-signing');
    $shortSignature = $copy($receipts[0]); $shortSignature['signature'] = 'YQ==';
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::verify($shortSignature, $trustedKey, $now), 'receipt rejects a short base64 signature');
    $invalidSignature = $copy($receipts[0]); $invalidSignature['signature'] = str_repeat('!', 86) . '==';
    $reject(static fn () => ConsumerAcceptanceV2ReceiptVerifier::verify($invalidSignature, $trustedKey, $now), 'receipt rejects an invalid base64 signature');
    $reorderedPlan = $copy($plan);
    $reorderedPlan['origins'] = ['b' => $plan['origins']['b'], 'iam' => $plan['origins']['iam'], 'a' => $plan['origins']['a']];
    $reorderedPlan['database'] = ['b' => $plan['database']['b'], 'iam' => $plan['database']['iam'], 'a' => $plan['database']['a']];
    $reorderedPlan['secret_slots'] = ['b_workload' => $plan['secret_slots']['b_workload'], 'iam_admin' => $plan['secret_slots']['iam_admin'], 'a_user' => $plan['secret_slots']['a_user']];
    $reorderedReport = ConsumerAcceptanceV2LiveContract::authorize($reorderedPlan, $receipts, $trustedKey, $now, $observed, $trustedKey);
    $assert($reorderedReport['status'] === 'authorized_offline', 'canonical map comparison accepts reordered map keys');
    $reorderedActions = $copy($plan); [$reorderedActions['actions'][0], $reorderedActions['actions'][1]] = [$reorderedActions['actions'][1], $reorderedActions['actions'][0]];
    $reject(static fn () => ConsumerAcceptanceV2LiveContract::authorize($reorderedActions, $receipts, $trustedKey, $now, $observed, $trustedKey), 'ordered action list rejects reordering');
    foreach ([
        static function (array $p): array { $p['candidate']['host_sha256'] = hash('sha256', 'other-host'); return $p; },
        static function (array $p): array { $p['run_id'] = 'other_consumer_l04_run'; return $p; },
        static function (array $p): array { $p['origins']['a'] = 'https://other-a.example.test'; return $p; },
        static function (array $p): array { $p['scope_cleanup']['cleanup_sha256'] = hash('sha256', 'other-cleanup'); return $p; },
    ] as $mutatePlan) {
        $blocked = ConsumerAcceptanceV2LiveContract::authorize($mutatePlan($copy($plan)), [], $trustedKey, $now, $observed, $trustedKey);
        $assert($blocked['status'] === 'preflight_blocked' && $blocked['database_factory_initialized'] === false, 'old capability blocks changed complete plan before factory');
    }
    foreach ([
        static function (array $p, array $r): array { $r[0]['signature'] = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)); return [$p, $r]; },
        static function (array $p, array $r): array { $r[0]['expires_at'] = '2026-09-13T09:30:00Z'; return [$p, $r]; },
        static function (array $p, array $r): array { $r[0]['issued_at'] = '2026-09-13T10:30:00Z'; return [$p, $r]; },
        static function (array $p, array $r): array { $r[0]['origins']['consumer'] = 'https://other.example.test'; return [$p, $r]; },
        static function (array $p, array $r): array { $r[0]['database']['role'] = 'other_role'; return [$p, $r]; },
        static function (array $p, array $r): array { $r[0]['scope_cleanup']['scope_sha256'] = str_repeat('0', 64); return [$p, $r]; },
        static function (array $p, array $r): array { $r[0]['action_registry_sha256'] = str_repeat('0', 64); return [$p, $r]; },
        static function (array $p, array $r): array { $p['actions'][] = 'outside_action'; return [$p, $r]; },
        static function (array $p, array $r): array { $p['public_key'] = 'forbidden'; return [$p, $r]; },
        static function (array $p, array $r): array { $r[0]['public_key'] = 'forbidden'; return [$p, $r]; },
    ] as $mutation) {
        [$badPlan, $badReceipts] = $mutation($copy($plan), $copy($receipts));
        $reject(static fn () => ConsumerAcceptanceV2LiveContract::authorize($badPlan, $badReceipts, $trustedKey, $now, $observed, $trustedKey), 'v2 signed contract negative');
    }
    $otherKey = $temporary . '/other.ed25519.pub';
    $otherPair = sodium_crypto_sign_keypair(); file_put_contents($otherKey, base64_encode(sodium_crypto_sign_publickey($otherPair)) . "\n");
    $wrongKeyReport = ConsumerAcceptanceV2LiveContract::authorize($plan, $receipts, $otherKey, $now, $observed, $otherKey);
    $assert($wrongKeyReport['status'] === 'preflight_blocked' && $wrongKeyReport['before_first_write'] === true, 'wrong capability trusted key blocks before first write');
    $blockedReport = ConsumerAcceptanceV2LiveContract::authorize($plan, [], $trustedKey, $now);
    $assert($blockedReport['status'] === 'preflight_blocked' && $blockedReport['before_first_write'] === true && $blockedReport['transport_initialized'] === false && $blockedReport['database_factory_initialized'] === false, 'missing cleanup capability blocks before first write');
    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/tools/consumer-acceptance-v2/LiveContract.php') . (string) file_get_contents(dirname(__DIR__, 3) . '/tools/consumer-acceptance-v2/ReceiptVerifier.php');
    $assert(!str_contains($source, 'curl_init(') && !str_contains($source, 'new PDO') && !str_contains($source, 'DatabaseFactory'), 'v2 offline layer cannot initialize transport or DB factory');
    $routes = (string) file_get_contents(dirname(__DIR__, 3) . '/plugin/sand-iam/config/route.php');
    $sdk = (string) file_get_contents(dirname(__DIR__, 3) . '/sdk/php/src/SandIamClient.php') . (string) file_get_contents(dirname(__DIR__, 3) . '/sdk/php/src/SandIamManagementClient.php');
    $actions = ConsumerAcceptanceV2ActionRegistry::actions();
    $url = static fn (array $action): string => rtrim($plan['origins'][$action['origin']], '/') . $action['path'];
    $assert(str_contains($routes, "Route::group('/app/sand-iam/admin'") && str_contains($routes, "Route::post('/credential/revoke'") && str_contains($routes, "Route::post('/grant/revoke'") && str_contains($routes, "Route::get('/audit/index'") && str_contains($sdk, 'public function credentialRevoke') && str_contains($sdk, "'/credential/revoke'") && str_contains($sdk, "'/app/sand-iam/runtime/context/issue'") && str_contains($sdk, "'/app/sand-iam/runtime/context/verify'"), 'registry paths are checked against source route grouping and SDK methods');
    foreach (['a_decide', 'a_consumer_read', 'b_issue', 'b_verify'] as $id) $assert($actions[$id]['effect'] === 'audit_side_effect' && $actions[$id]['authorization_order'] === 'after_authorization', 'audit-side-effect action is post-authorization');
    $assert($actions['iam_login']['effect'] === 'business_write' && $url($actions['admin_credential_revoke']) === 'https://iam.example.test/app/sand-iam/admin/credential/revoke' && $url($actions['admin_grant_revoke']) === 'https://iam.example.test/app/sand-iam/admin/grant/revoke' && $url($actions['admin_audit']) === 'https://iam.example.test/app/sand-iam/admin/audit/index' && $actions['admin_audit']['effect'] === 'strict_read_only', 'fixed origin plus path produces real admin route URLs');
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) unlink($file);
    rmdir($temporary);
}
echo "consumer acceptance v2 non-PG tests passed={$pass}\n";
