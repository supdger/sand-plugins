<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** IAM-T11 RADIUS Server source contract. No UDP socket or database is used. */
$package = dirname(__DIR__);
$root = dirname(__DIR__, 3);
$checks = [
    $package . '/app/radius/RadiusPacketCodec.php' => [
        'MESSAGE_AUTHENTICATOR = 80',
        "hash_hmac('md5'",
        "hash('md5', \$secret . \$previous, true)",
        "hash('md5', \$prefix . \$requestAuthenticator . \$wire . \$secret, true)",
        'hash_equals($expected',
        'Callers must never log packet bodies',
    ],
    $package . '/app/service/RadiusAccessService.php' => [
        "RadiusNas::where('status', 1)",
        'count($matches) !== 1',
        'verifyMessageAuthenticator',
        'claimReplay',
        'verifyPasswordForProtocol',
        'ACCESS_ACCEPT',
        'ACCESS_REJECT',
        'return null',
    ],
    $package . '/app/process/RadiusServerWorker.php' => [
        'stream_socket_server',
        'stream_socket_recvfrom',
        'stream_socket_sendto',
        "Log::error('SandIAM RADIUS packet handling failed'",
    ],
    $package . '/app/service/RadiusAccountingService.php' => [
        'verifyAccountingAuthenticator',
        "1 => 'start', 2 => 'stop', 3 => 'interim'",
        "outcome = 'orphaned'",
        "outcome = 'conflict'",
        'RadiusAccountingEvent::create',
        'ACCOUNTING_RESPONSE',
    ],
    $package . '/app/process/RadiusAccountingWorker.php' => ['radius_accounting_port', 'RadiusAccountingService'],
    $package . '/app/admin/controller/RadiusNasController.php' => [
        'RADIUS 网络设备',
        'RadiusNetwork::validCidr',
        'SAND_IAM_RADIUS_NAS_NETWORK_OVERLAP',
        '共享密钥已保存',
    ],
    $package . '/app/middleware/RadiusSensitiveMiddleware.php' => ['shared secrets out of host request logs'],
    $package . '/app/service/HumanAuthService.php' => ['verifyPasswordForProtocol', "['radius']", 'SAND_IAM_RADIUS_MFA_REQUIRED'],
    $package . '/config/process.php' => ['SAND_IAM_RADIUS_WORKER_ENABLED', 'RadiusServerWorker::class', 'RadiusAccountingWorker::class'],
    $package . '/config/app.php' => ["SAND_IAM_RADIUS_SERVER_ENABLED', 0", 'SAND_IAM_RADIUS_ENCRYPTION_KEY', 'SAND_IAM_RADIUS_REPLAY_KEY'],
];
foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) { fwrite(STDERR, "unreadable {$file}\n"); exit(1); }
    foreach ($fragments as $fragment) if (!str_contains($content, $fragment)) { fwrite(STDERR, "missing {$fragment} in {$file}\n"); exit(1); }
}

$name = '018_radius_server.pgsql';
$source = $root . '/migrations/' . $name;
$copy = $package . '/migrations/' . $name;
if (!is_file($source) || !is_file($copy) || hash_file('sha256', $source) !== hash_file('sha256', $copy)) { fwrite(STDERR, "018 root/plugin copies differ\n"); exit(1); }
$sql = (string) file_get_contents($source);
foreach (['sand_iam_radius_nas', 'source_cidr cidr', 'encrypted_shared_secret', 'accounting_enabled', 'sand_iam_radius_replay', 'sand_iam_radius_accounting_session', 'sand_iam_radius_accounting_event', 'request_fingerprint'] as $fragment) if (!str_contains($sql, $fragment)) { fwrite(STDERR, "018 missing {$fragment}\n"); exit(1); }
foreach (['ENGINE=', 'AUTO_INCREMENT', '`'] as $mysqlFragment) if (str_contains($sql, $mysqlFragment)) { fwrite(STDERR, "MySQL syntax {$mysqlFragment} found in 018\n"); exit(1); }

echo "RADIUS Server non-PG contract checks passed\n";
