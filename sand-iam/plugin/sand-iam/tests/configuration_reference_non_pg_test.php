<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
$app = (string) file_get_contents(dirname(__DIR__) . '/config/app.php');
$process = (string) file_get_contents(dirname(__DIR__) . '/config/process.php');
$guide = (string) file_get_contents($root . '/docs/user-guide/configuration-reference.md');
$preflight = (string) file_get_contents(dirname(__DIR__) . '/bin/check-runtime-configuration.php');

preg_match_all("/env\\('(?<key>SAND_IAM_[A-Z0-9_]+)'/", $app . "\n" . $process, $matches);
$keys = array_values(array_unique($matches['key'] ?? []));
sort($keys, SORT_STRING);
$missing = array_values(array_filter($keys, static fn (string $key): bool => !str_contains($guide, '`' . $key . '`')));
$missingFromPreflight = array_values(array_filter($keys, static fn (string $key): bool => !str_contains($preflight . (string) file_get_contents(dirname(__DIR__) . '/bin/RuntimeConfigurationPreflight.php'), "'" . $key . "'")));

$checks = [
    'every runtime environment key is documented' => $keys !== [] && $missing === [],
    'secret formats and non-reuse are explicit' => str_contains($guide, '32 个随机字节的标准 base64')
        && str_contains($guide, '至少使用 32 个随机字节')
        && str_contains($guide, '不要让不同用途共用密钥'),
    'versioned keyring rotation retains old decryptors' => str_contains($guide, '轮换时先保留仍有密文引用的旧版本')
        && str_contains($guide, '不带版本化 keyring 的密钥不能直接原地轮换'),
    'workers remain opt-in and directory sync has two gates' => str_contains($guide, '`SAND_IAM_WEBHOOK_WORKER_ENABLED` | `0`')
        && str_contains($guide, '`SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED` | `0`')
        && str_contains($guide, '`SAND_IAM_IDENTITY_LIFECYCLE_ENABLED=1` 同时满足'),
    'acceptance cleanup stays disabled in releases' => str_contains($guide, '`SAND_IAM_ACCEPTANCE_FIXTURE_CLEANUP_ENABLED` | `0`')
        && str_contains($guide, '发布和生产必须为 `0`'),
    'plugin debug defaults off' => str_contains($app, "'debug' => (int) env('SAND_IAM_DEBUG', 0) === 1")
        && str_contains($guide, '`SAND_IAM_DEBUG` | `0`')
        && str_contains($guide, '发布和生产保持 `0`'),
    'configuration does not claim runtime acceptance' => str_contains($guide, '都不等于能力验收通过'),
    'shipped offline preflight covers every runtime key and avoids runtime overclaim' => $missingFromPreflight === []
        && str_contains($guide, 'check-runtime-configuration.php --profile=release')
        && str_contains($guide, '不连接数据库')
        && str_contains($preflight, 'does not connect to PostgreSQL, start services, or prove runtime acceptance'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
if ($missing !== []) fwrite(STDERR, 'Undocumented configuration keys: ' . implode(', ', $missing) . PHP_EOL);
if ($missingFromPreflight !== []) fwrite(STDERR, 'Configuration keys absent from preflight: ' . implode(', ', $missingFromPreflight) . PHP_EOL);
echo 'Configuration reference: keys=' . count($keys) . '; passed=' . (count($checks) - count($failed)) . '/' . count($checks) . '; failed=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
