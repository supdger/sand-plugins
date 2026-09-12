<?php

declare(strict_types=1);

/**
 * Read-only release hygiene gate. It intentionally fails until the project
 * license has been selected and added.
 */

$root = dirname(__DIR__);
require_once $root . '/tools/package-payload-policy.php';

/** @return string|null */
$normalizeRelative = static function (string $baseDirectory, string $target): ?string {
    $parts = [];
    foreach (explode('/', trim($baseDirectory . '/' . $target, '/')) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            if ($parts === []) return null;
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
};

$payload = sandIamPayloadFiles($root, true);
$payloadSet = array_fill_keys($payload, true);
$checks = [];
$details = [];

$record = static function (string $name, bool $passed, string $detail = '') use (&$checks, &$details): void {
    $checks[$name] = $passed;
    if (!$passed && $detail !== '') $details[$name] = $detail;
};

$projectLicense = is_file($root . '/LICENSE') ? file_get_contents($root . '/LICENSE') : false;
$hasCompleteApacheLicense = is_string($projectLicense)
    && hash_equals('c71d239df91726fc519c6eb72d318ec65820627232b2f796219e87dcf35d0ab4', hash('sha256', $projectLicense));
$record('project license exists', $hasCompleteApacheLicense, 'LICENSE must contain the complete unmodified Apache License 2.0 text.');
$securityPolicy = is_file($root . '/SECURITY.md') ? file_get_contents($root . '/SECURITY.md') : false;
$hasPrivateSecurityChannel = is_string($securityPolicy)
    && preg_match('#(?:https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/security/advisories/new|mailto:[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})#i', $securityPolicy) === 1;
$record('concrete private security reporting channel exists', $hasPrivateSecurityChannel, 'SECURITY.md needs an exact private advisory URL or mailto address approved by the owner.');
$contributionPolicy = is_file($root . '/CONTRIBUTING.md') ? file_get_contents($root . '/CONTRIBUTING.md') : false;
$hasDco = is_string($contributionPolicy)
    && str_contains($contributionPolicy, 'Developer Certificate of Origin')
    && str_contains($contributionPolicy, 'Signed-off-by:');
$hasCla = is_string($contributionPolicy)
    && str_contains($contributionPolicy, 'Contributor License Agreement')
    && preg_match('#https://[^\s)>]+#', $contributionPolicy) === 1;
$record('exactly one contribution governance mechanism is selected', $hasDco xor $hasCla, 'CONTRIBUTING.md must select either a concrete DCO sign-off flow or a concrete CLA URL, not both.');
$record('release changelog exists', isset($payloadSet['CHANGELOG.md']));
$record('third-party notice exists', isset($payloadSet['THIRD_PARTY_NOTICES.md']));
$record('CycloneDX SBOM exists', isset($payloadSet['SBOM.cdx.json']));

$runtimeLicenses = [
    'plugin/sand-iam/vendor/composer/LICENSE',
    'plugin/sand-iam/vendor/onelogin/php-saml/LICENSE',
    'plugin/sand-iam/vendor/robrichards/xmlseclibs/LICENSE',
];
$missingRuntimeLicenses = array_values(array_filter($runtimeLicenses, static fn (string $path): bool => !isset($payloadSet[$path])));
$record('distributed runtime license texts exist', $missingRuntimeLicenses === [], implode(', ', $missingRuntimeLicenses));

$testPayload = array_values(array_filter($payload, static fn (string $path): bool => preg_match('#(?:^|/)tests?(?:/|$)|(?:^|/)[^/]+\.(?:test|spec)\.[^/]+$#i', $path) === 1));
$record('test and spec files are excluded', $testPayload === [], implode(', ', array_slice($testPayload, 0, 10)));

$publicMarkdown = array_values(array_filter($payload, static fn (string $path): bool => str_ends_with(strtolower($path), '.md')
    && !str_starts_with($path, 'plugin/sand-iam/vendor/')));
$brokenLinks = [];
$internalMarkers = [];
$industryMarkers = [];
foreach ($publicMarkdown as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        $brokenLinks[] = $path . ': unreadable';
        continue;
    }
    if (preg_match('#(?:docs/development|\.codex/|\.cursor/|/Users/[A-Za-z0-9._-]+/)#', $source) === 1) {
        $internalMarkers[] = $path;
    }
    if ((str_starts_with($path, 'docs/user-guide/') || str_starts_with($path, 'examples/'))
        && preg_match('/(?:案件|律所|律师|律序|\bmatter\b|\blawyer\b)/iu', $source) === 1) {
        $industryMarkers[] = $path;
    }
    preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $source, $matches);
    foreach ($matches[1] ?? [] as $rawTarget) {
        $target = trim((string) $rawTarget, " \t\n\r\0\x0B<>");
        if ($target === '' || str_starts_with($target, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1) continue;
        $target = rawurldecode(explode('#', $target, 2)[0]);
        $relative = $normalizeRelative(dirname($path) === '.' ? '' : dirname($path), $target);
        if ($relative === null || !isset($payloadSet[$relative])) $brokenLinks[] = $path . ' -> ' . $target;
    }
}
$record('public Markdown links stay inside the payload', $brokenLinks === [], implode(', ', array_slice($brokenLinks, 0, 10)));
$record('public Markdown has no internal task or local-path markers', $internalMarkers === [], implode(', ', $internalMarkers));
$record('public guides and examples are industry-neutral', $industryMarkers === [], implode(', ', $industryMarkers));

$textExtensions = ['css', 'dart', 'html', 'ini', 'js', 'json', 'lock', 'md', 'mjs', 'pgsql', 'php', 'sh', 'sql', 'ts', 'tsx', 'txt', 'vue', 'yaml', 'yml'];
$localPathFiles = [];
$internalPayloadFiles = [];
$credentialFiles = [];
foreach ($payload as $path) {
    if (str_starts_with($path, 'plugin/sand-iam/vendor/')) continue;
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, $textExtensions, true) && !in_array(basename($path), ['composer.lock', 'composer.json'], true)) continue;
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) continue;
    if (preg_match('#/Users/[A-Za-z0-9._-]+/#', $source) === 1) $localPathFiles[] = $path;
    if (preg_match('#(?:\.codex/|\.cursor/|docs/development/|candidate/dirty-not-release|autopilot)#i', $source) === 1) $internalPayloadFiles[] = $path;
    if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\bsiam_(?:at|wc|rt)_[A-Za-z0-9_-]{8,}\b/i', $source) === 1) $credentialFiles[] = $path;
}
$record('payload has no developer-machine absolute paths', $localPathFiles === [], implode(', ', $localPathFiles));
$record('payload has no internal task-system references', $internalPayloadFiles === [], implode(', ', $internalPayloadFiles));
$record('payload has no high-confidence private keys or SandIAM credentials', $credentialFiles === [], implode(', ', $credentialFiles));

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name;
    if (!$passed && isset($details[$name])) echo ': ' . $details[$name];
    echo PHP_EOL;
}
echo 'Release payload hygiene: passed=' . (count($checks) - count($failed)) . '/' . count($checks) . '; failed=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
