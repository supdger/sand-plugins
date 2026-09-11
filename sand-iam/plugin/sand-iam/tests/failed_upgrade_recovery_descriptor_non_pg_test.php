<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$authorityRoot = dirname(__DIR__, 3);
require_once __DIR__ . '/isolated_sandiam_fixture.php';
$authoritySnapshot = sandIamAuthorityPayloadSnapshot($authorityRoot);

try {
    $exitCode = sandIamWithIsolatedFixture($authorityRoot, static function (string $root): int {
        $tool = $root . '/tools/check-package-integrity.php';
        $rootDescriptor = $root . '/recovery/failed-upgrade.v2.json';
        $packageDescriptor = $root . '/plugin/sand-iam/recovery/failed-upgrade.v2.json';

/** @return array{0:int,1:string} */
$run = static function (array $arguments) use ($tool): array {
    $command = array_merge([PHP_BINARY, $tool], $arguments);
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return [127, 'cannot start integrity checker'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim((string) $stdout . "\n" . (string) $stderr)];
};

/** @param callable(): bool $callback */
$withDescriptors = static function (string $rootBytes, string $packageBytes, callable $callback) use ($rootDescriptor, $packageDescriptor): bool {
    $rootOriginal = file_get_contents($rootDescriptor);
    $packageOriginal = file_get_contents($packageDescriptor);
    if (!is_string($rootOriginal) || !is_string($packageOriginal)
        || file_put_contents($rootDescriptor, $rootBytes) !== strlen($rootBytes)
        || file_put_contents($packageDescriptor, $packageBytes) !== strlen($packageBytes)) {
        return false;
    }
    try {
        return $callback();
    } finally {
        file_put_contents($rootDescriptor, $rootOriginal);
        file_put_contents($packageDescriptor, $packageOriginal);
    }
};

$rootBytes = file_get_contents($rootDescriptor);
$packageBytes = file_get_contents($packageDescriptor);
$descriptor = is_string($rootBytes) ? json_decode($rootBytes, true) : null;
$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $item) {
        $value[$key] = $canonicalize($item);
    }
    return $value;
};
$profile = is_array($descriptor) ? ($descriptor['profile'] ?? null) : null;
$assertionTypes = is_array($profile) && is_array($profile['assertions'] ?? null)
    ? array_values(array_unique(array_column($profile['assertions'], 'type')))
    : [];
sort($assertionTypes, SORT_STRING);
$checks = [
    'recovery descriptor exists, is root/plugin-identical and passes integrity' => is_file($tool)
        && is_string($rootBytes) && $rootBytes === $packageBytes
        && is_array($descriptor)
        && ($run([])[0] === 0),
    'descriptor has recursive canonical bytes and strict inline v2 fields' => is_array($descriptor)
        && $rootBytes === json_encode($canonicalize($descriptor), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        && array_keys($descriptor) === ['app', 'candidate_payload', 'from_version', 'profile', 'schema', 'to_version', 'update_lifecycle']
        && ($descriptor['schema'] ?? null) === 'sandpackage.failed-upgrade-recovery/v2'
        && is_array($profile)
        && array_keys($profile) === ['app', 'assertions', 'from_version', 'id', 'schema', 'state', 'to_version']
        && ($profile['schema'] ?? null) === 'sandpackage.failed-upgrade-recovery-profile/v2'
        && ($profile['state'] ?? null) === 'prefix_033_034'
        && count($profile['assertions'] ?? []) === 101
        && $assertionTypes === ['column_exact', 'constraint_exact', 'index_exact', 'ledger_absent', 'menu_rows_exact', 'relations_exact']
        && !array_key_exists('retry_states', $descriptor),
    'unknown JSON key fails closed' => is_string($rootBytes) && $withDescriptors(
        substr($rootBytes, 0, -1) . ',"unknown":true}',
        substr($rootBytes, 0, -1) . ',"unknown":true}',
        static function () use ($run): bool {
            [$status, $output] = $run([]);
            return $status !== 0 && str_contains($output, 'failed-upgrade recovery descriptor');
        },
    ),
    'non-canonical descriptor whitespace and duplicate keys fail closed' => is_string($rootBytes) && $withDescriptors(
        " {\"app\":\"sand-iam\",\"app\":\"sand-iam\"}",
        " {\"app\":\"sand-iam\",\"app\":\"sand-iam\"}",
        static function () use ($run): bool {
            [$status, $output] = $run([]);
            return $status !== 0 && str_contains($output, 'failed-upgrade recovery descriptor');
        },
    ),
    'descriptor exclusion removes the cycle while payload and update bindings remain fail-closed' => is_array($descriptor) && is_string($rootBytes) && (static function () use ($run, $withDescriptors, $descriptor, $rootBytes): bool {
        [$beforeStatus, $before] = $run(['--print-normalized-recovery-payload-manifest']);
        $changed = $descriptor;
        $changed['candidate_payload']['digest'] = str_repeat('0', 64);
        $changed['update_lifecycle']['sha256'] = str_repeat('f', 64);
        $changedBytes = json_encode($changed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($beforeStatus !== 0 || !is_string($changedBytes)) {
            return false;
        }
        return $withDescriptors($changedBytes, $changedBytes, static function () use ($run, $before): bool {
            [$manifestStatus, $after] = $run(['--print-normalized-recovery-payload-manifest']);
            [$integrityStatus, $integrity] = $run([]);
            return $manifestStatus === 0
                && $before === $after
                && $integrityStatus !== 0
                && str_contains($integrity, 'failed-upgrade recovery descriptor');
        });
    })(),
    'inline profile tampering fails closed' => is_array($descriptor) && (static function () use ($descriptor, $canonicalize, $withDescriptors, $run): bool {
        $changed = $descriptor;
        $changed['profile']['assertions'][0]['relations'][0] = 'sand_iam_forged_relation';
        $bytes = json_encode($canonicalize($changed), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!is_string($bytes)) {
            return false;
        }
        return $withDescriptors($bytes, $bytes, static function () use ($run): bool {
            [$status, $output] = $run([]);
            return $status !== 0 && str_contains($output, 'failed-upgrade recovery descriptor');
        });
    })(),
];

$passed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $passed += $ok ? 1 : 0;
}
$total = count($checks);
echo "Failed-upgrade recovery descriptor contract: passed={$passed}/{$total}; failed=" . ($total - $passed) . PHP_EOL;
        return $passed === $total ? 0 : 1;
    });
} finally {
    if (sandIamAuthorityPayloadSnapshot($authorityRoot) !== $authoritySnapshot) {
        throw new RuntimeException('authority payload or recovery descriptor changed during isolated descriptor test');
    }
}

exit($exitCode);
