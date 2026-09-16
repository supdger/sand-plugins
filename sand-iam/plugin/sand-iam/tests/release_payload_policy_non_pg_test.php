<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$root = dirname(__DIR__, 3);
require_once $root . '/tools/package-payload-policy.php';
require_once __DIR__ . '/isolated_sandiam_fixture.php';

/** @return array{0:int,1:string} */
$runHygiene = static function (string $fixtureRoot): array {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixtureRoot . '/tools/check-release-payload.php') . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};

[$currentHygieneStatus, $currentHygieneOutput] = $runHygiene($root);
$governanceGate = $currentHygieneStatus === 0
    && str_contains($currentHygieneOutput, 'Release payload hygiene: passed=16/16; failed=0')
    && sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runHygiene): bool {
        if (file_put_contents($fixtureRoot . '/LICENSE', "incomplete license fixture\n") === false) return false;
        [$status, $output] = $runHygiene($fixtureRoot);
        return $status !== 0
            && str_contains($output, '[FAIL] project license exists')
            && str_contains($output, '[FAIL] independently distributed SDKs carry Apache-2.0 metadata, license, and notice')
            && str_contains($output, 'Release payload hygiene: passed=14/16; failed=2');
    });
$localPathGate = sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runHygiene): bool {
    $readme = $fixtureRoot . '/sdk/typescript/README.md';
    $original = file_get_contents($readme);
    if (!is_string($original) || file_put_contents($readme, $original . "\nLocal staging: `/private/tmp/sand-iam`\nUser staging: `/Users/alice/sand-iam`\n") === false) return false;
    [$status, $output] = $runHygiene($fixtureRoot);
    return $status !== 0
        && str_contains($output, '[FAIL] public Markdown has no internal task or local-path markers')
        && str_contains($output, '[FAIL] payload has no developer-machine absolute paths');
});
$httpsPathGate = sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runHygiene): bool {
    $readme = $fixtureRoot . '/sdk/typescript/README.md';
    $original = file_get_contents($readme);
    if (!is_string($original) || file_put_contents($readme, $original . "\nReference: https://example.com/home/alice/\n") === false) return false;
    [$status, $output] = $runHygiene($fixtureRoot);
    return $status === 0
        && str_contains($output, 'Release payload hygiene: passed=16/16; failed=0');
});
$w3cNoticeGate = sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runHygiene): bool {
    $noticePath = $fixtureRoot . '/THIRD_PARTY_NOTICES.md';
    $notice = file_get_contents($noticePath);
    $withoutDisclaimer = is_string($notice)
        ? preg_replace('#THIS SOFTWARE AND DOCUMENTATION IS PROVIDED "AS IS,".*?OTHER RIGHTS\.\R\R#s', '', $notice, 1)
        : null;
    if (!is_string($withoutDisclaimer) || $withoutDisclaimer === $notice || file_put_contents($noticePath, $withoutDisclaimer) === false) return false;
    [$status, $output] = $runHygiene($fixtureRoot);
    return $status !== 0
        && str_contains($output, '[FAIL] distributed W3C XML Signature schema has source header, notice, and SBOM component');
});
$sdkLegalFilesGate = sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($runHygiene): bool {
    $licensePath = $fixtureRoot . '/sdk/dart/LICENSE';
    $license = file_get_contents($licensePath);
    if (!is_string($license) || file_put_contents($licensePath, "corrupted SDK license fixture\n") === false) return false;
    [$status, $output] = $runHygiene($fixtureRoot);
    return $status !== 0
        && str_contains($output, '[FAIL] independently distributed SDKs carry Apache-2.0 metadata, license, and notice');
});

$payload = sandIamPayloadFiles($root, true);
$checks = [
    'release changelog is public payload' => in_array('CHANGELOG.md', $payload, true),
    'third-party notice is public payload' => in_array('THIRD_PARTY_NOTICES.md', $payload, true),
    'CycloneDX SBOM is public payload' => in_array('SBOM.cdx.json', $payload, true),
    'Apache-2.0 license and notice are public payload' => in_array('LICENSE', $payload, true)
        && in_array('NOTICE', $payload, true),
    'contribution and security policies are public payload' => in_array('CONTRIBUTING.md', $payload, true)
        && in_array('SECURITY.md', $payload, true),
    'Dart singular test directory is excluded' => !in_array('sdk/dart/test/client_test.dart', $payload, true),
    'PHP plural tests directory is excluded' => !in_array('plugin/sand-iam/tests/release_payload_policy_non_pg_test.php', $payload, true),
    'standalone consumer local Composer vendor tree is excluded without excluding shipped dependencies' => sandIamPayloadExcluded('examples/webman-business-app/standalone/vendor/autoload.php')
        && sandIamPayloadExcluded('examples/webman-business-app/standalone/vendor/acme/package/src/Consumer.php')
        && sandIamPayloadExcluded('examples/machine-service-client/provider/vendor/autoload.php')
        && sandIamPayloadExcluded('examples/machine-service-client/provider/vendor/acme/package/src/Provider.php')
        && !sandIamPayloadExcluded('plugin/sand-iam/vendor/autoload.php')
        && !sandIamPayloadExcluded('sdk/typescript/dist/index.js'),
    'public root README is included' => in_array('README.md', $payload, true),
    'public backup guide provides a non-creating fail-closed PostgreSQL drill' => (static function () use ($root): bool {
        $guide = file_get_contents($root . '/docs/user-guide/backup-and-restore.md');
        return is_string($guide)
            && str_contains($guide, 'pg_dump --format=custom')
            && str_contains($guide, 'pg_restore --list')
            && str_contains($guide, 'pg_restore --exit-on-error --single-transaction')
            && str_contains($guide, '本流程不创建数据库')
            && str_contains($guide, '不得使用 `--create`、`--clean`')
            && str_contains($guide, '```sh restore-guard-contract')
            && str_contains($guide, '(pg_control_system()).system_identifier')
            && str_contains($guide, 'system_identifier:database_oid:database_name_hex')
            && str_contains($guide, 'RESTORE_TARGET_IDENTITY')
            && str_contains($guide, 'development|test|staging|isolated')
            && str_contains($guide, '[ "$restore_user_relations" = "0" ] || exit 1')
            && !str_contains($guide, "\ncreatedb ");
    })(),
    'development task board is excluded' => !in_array('docs/development/sand-iam-task-board.md', $payload, true),
    'release hygiene checker scans paths, internal markers, and credentials' => (static function () use ($root): bool {
        $source = file_get_contents($root . '/tools/check-release-payload.php');
        return is_string($source)
            && str_contains($source, 'payload has no developer-machine absolute paths')
            && str_contains($source, 'payload has no internal task-system references')
            && str_contains($source, 'payload has no high-confidence private keys or SandIAM credentials');
    })(),
    'license, private security channel, and one DCO contribution mechanism pass and fail closed' => $governanceGate,
    'release hygiene rejects backtick-wrapped /private and /Users developer-machine paths in an isolated payload' => $localPathGate,
    'release hygiene allows HTTPS URLs whose path resembles a home directory' => $httpsPathGate,
    'release hygiene rejects a W3C notice with its full disclaimer removed' => $w3cNoticeGate,
    'release hygiene rejects SDK legal text that differs from the root notice' => $sdkLegalFilesGate,
];

$failed = [];
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$passed) $failed[] = $name;
}
echo 'Release payload policy: passed=' . (count($checks) - count($failed)) . '/' . count($checks) . '; failed=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
