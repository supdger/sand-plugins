<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

require_once __DIR__ . '/isolated_sandiam_fixture.php';

$root = dirname(__DIR__, 3);
$tests = [
    __DIR__ . '/failed_upgrade_recovery_descriptor_non_pg_test.php',
    __DIR__ . '/package_integrity_contract_non_pg_test.php',
];

foreach ($tests as $test) {
    $before = sandIamAuthorityPayloadSnapshot($root);
    $pipes = [];
    $process = proc_open([PHP_BINARY, $test], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, 'cannot start authority immutability target: ' . basename($test) . PHP_EOL);
        exit(1);
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $after = sandIamAuthorityPayloadSnapshot($root);
    if ($before !== $after) {
        fwrite(STDERR, 'authority payload or descriptor SHA changed after ' . basename($test) . PHP_EOL);
        exit(1);
    }
    echo '[PASS] authority SHA snapshot unchanged after ' . basename($test) . '; target_exit=' . $exitCode . PHP_EOL;
}

echo "authority payload immutability gate passed\n";
