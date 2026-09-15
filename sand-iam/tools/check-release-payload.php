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

$xmlDsigSchema = 'plugin/sand-iam/vendor/onelogin/php-saml/src/Saml2/schemas/xmldsig-core-schema.xsd';
$xmlDsigSource = isset($payloadSet[$xmlDsigSchema]) ? file_get_contents($root . '/' . $xmlDsigSchema) : false;
$notices = is_file($root . '/THIRD_PARTY_NOTICES.md') ? file_get_contents($root . '/THIRD_PARTY_NOTICES.md') : false;
$sbom = is_file($root . '/SBOM.cdx.json') ? json_decode((string) file_get_contents($root . '/SBOM.cdx.json'), true) : null;
$w3cComponent = null;
foreach (is_array($sbom) && is_array($sbom['components'] ?? null) ? $sbom['components'] : [] as $component) {
    if (is_array($component) && ($component['bom-ref'] ?? null) === 'urn:sandiam:vendored:w3c-xmldsig-core-schema@2002-02-08') {
        $w3cComponent = $component;
        break;
    }
}
$w3cLicense = is_array($w3cComponent) && is_array($w3cComponent['licenses'][0]['license'] ?? null)
    ? $w3cComponent['licenses'][0]['license']
    : [];
$w3cEvidence = array_column(
    is_array($w3cComponent) && is_array($w3cComponent['properties'] ?? null) ? $w3cComponent['properties'] : [],
    'value'
);
$w3cNoticeText = is_string($notices)
    && preg_match('#<!-- W3C-LICENSE-BEGIN -->\R(.*?)\R<!-- W3C-LICENSE-END -->#s', $notices, $w3cNoticeMatch) === 1
    ? $w3cNoticeMatch[1]
    : null;
$record(
    'distributed W3C XML Signature schema has source header, notice, and SBOM component',
    is_string($xmlDsigSource)
        && str_contains($xmlDsigSource, 'Copyright 2001 The Internet Society and W3C')
        && str_contains($xmlDsigSource, 'W3C Software License')
        && str_contains($xmlDsigSource, 'http://www.w3.org/Consortium/Legal/copyright-software-19980720')
        && is_string($notices)
        && str_contains($notices, '`' . $xmlDsigSchema . '`')
        && str_contains($notices, 'Copyright 2001 The Internet Society and W3C')
        && str_contains($notices, 'W3C Software Notice and License')
        && str_contains($notices, 'https://www.w3.org/Consortium/Legal/copyright-software-19980720')
        && is_string($w3cNoticeText)
        && hash_equals('36ea737a8b78df521fa218e3a12fe2cb16e4fedcdbca005fbb703842a48d77bb', hash('sha256', $w3cNoticeText))
        && is_array($w3cComponent)
        && ($w3cComponent['type'] ?? null) === 'data'
        && ($w3cComponent['scope'] ?? null) === 'required'
        && ($w3cLicense['name'] ?? null) === 'W3C Software Notice and License'
        && ($w3cLicense['url'] ?? null) === 'https://www.w3.org/Consortium/Legal/copyright-software-19980720'
        && in_array($xmlDsigSchema, $w3cEvidence, true)
        && in_array('THIRD_PARTY_NOTICES.md', $w3cEvidence, true),
    'the distributed xmldsig-core-schema.xsd must retain its W3C header and be indexed by both THIRD_PARTY_NOTICES.md and SBOM.cdx.json.'
);

$sdkLegalFiles = [
    'sdk/php/LICENSE', 'sdk/php/NOTICE',
    'sdk/typescript/LICENSE', 'sdk/typescript/NOTICE',
    'sdk/dart/LICENSE', 'sdk/dart/NOTICE',
];
$projectNotice = is_file($root . '/NOTICE') ? file_get_contents($root . '/NOTICE') : false;
$sdkLegalFilesMatchProject = is_string($projectLicense) && is_string($projectNotice);
foreach ([
    'sdk/php/LICENSE' => $projectLicense,
    'sdk/typescript/LICENSE' => $projectLicense,
    'sdk/dart/LICENSE' => $projectLicense,
    'sdk/php/NOTICE' => $projectNotice,
    'sdk/typescript/NOTICE' => $projectNotice,
    'sdk/dart/NOTICE' => $projectNotice,
] as $path => $expected) {
    $actual = is_file($root . '/' . $path) ? file_get_contents($root . '/' . $path) : false;
    $sdkLegalFilesMatchProject = $sdkLegalFilesMatchProject
        && is_string($actual)
        && hash_equals(hash('sha256', $expected), hash('sha256', $actual));
}
$phpSdk = json_decode((string) file_get_contents($root . '/sdk/php/composer.json'), true);
$typeScriptSdk = json_decode((string) file_get_contents($root . '/sdk/typescript/package.json'), true);
$dartSdk = (string) file_get_contents($root . '/sdk/dart/pubspec.yaml');
$record(
    'independently distributed SDKs carry Apache-2.0 metadata, license, and notice',
    array_values(array_filter($sdkLegalFiles, static fn (string $path): bool => !isset($payloadSet[$path]))) === []
        && $sdkLegalFilesMatchProject
        && is_array($phpSdk)
        && ($phpSdk['license'] ?? null) === 'Apache-2.0'
        && (($phpSdk['support']['source'] ?? null) === 'https://github.com/supdger/sand-plugins')
        && is_array($typeScriptSdk)
        && ($typeScriptSdk['license'] ?? null) === 'Apache-2.0'
        && (($typeScriptSdk['repository']['url'] ?? null) === 'https://github.com/supdger/sand-plugins.git')
        && in_array('LICENSE', $typeScriptSdk['files'] ?? [], true)
        && in_array('NOTICE', $typeScriptSdk['files'] ?? [], true)
        && str_contains($dartSdk, 'repository: https://github.com/supdger/sand-plugins')
        && str_contains($dartSdk, 'issue_tracker: https://github.com/supdger/sand-plugins/issues'),
    'each SDK must be independently redistributable with its own Apache-2.0 metadata, LICENSE, NOTICE, and current project support URL.'
);

$testPayload = array_values(array_filter($payload, static fn (string $path): bool => preg_match('#(?:^|/)tests?(?:/|$)|(?:^|/)[^/]+\.(?:test|spec)\.[^/]+$#i', $path) === 1));
$record('test and spec files are excluded', $testPayload === [], implode(', ', array_slice($testPayload, 0, 10)));

$publicMarkdown = array_values(array_filter($payload, static fn (string $path): bool => str_ends_with(strtolower($path), '.md')
    && !str_starts_with($path, 'plugin/sand-iam/vendor/')));
$brokenLinks = [];
$internalMarkers = [];
foreach ($publicMarkdown as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source)) {
        $brokenLinks[] = $path . ': unreadable';
        continue;
    }
    if (preg_match('#(?:docs/development|\.codex/|\.cursor/|(?:^|[\s"\'`=:(\[,])/(?:Users|home)/[A-Za-z0-9._-]+(?:/|$)|(?:^|[\s"\'`=:(\[,])/private(?:/|$)|file://|(?<![A-Za-z0-9_])(?:\$HOME|\$\{HOME\}|~)(?:[\\\\/]|$)|(?<![A-Za-z0-9])[A-Za-z]:[\\\\/])#', $source) === 1) {
        $internalMarkers[] = $path;
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

$localPathFiles = [];
$internalPayloadFiles = [];
$credentialFiles = [];
$industryMarkers = [];
foreach ($payload as $path) {
    if (str_starts_with($path, 'plugin/sand-iam/vendor/')) continue;
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source) || str_contains($source, "\0") || preg_match('//u', $source) !== 1) continue;
    if (preg_match('#(?:file://|(?:^|[\s"\'`=:(\[,])/(?:Users|home)/[A-Za-z0-9._-]+(?:/|$)|(?:^|[\s"\'`=:(\[,])/private(?:/|$)|(?<![A-Za-z0-9_])(?:\$HOME|\$\{HOME\}|~)(?:[\\\\/]|$)|(?<![A-Za-z0-9])[A-Za-z]:[\\\\/])#', $source) === 1) $localPathFiles[] = $path;
    if (preg_match('#(?:\.codex/|\.cursor/|docs/development/|candidate/dirty-not-release|autopilot)#i', $source) === 1) $internalPayloadFiles[] = $path;
    if (preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|\bsiam_(?:at|wc|rt)_[A-Za-z0-9_-]{8,}\b/i', $source) === 1) $credentialFiles[] = $path;
    if (preg_match('/(?:案件|律所|律师|律序|\bmatter\b|\blawyer\b)/iu', $source) === 1) $industryMarkers[] = $path;
}
$record('payload has no developer-machine absolute paths', $localPathFiles === [], implode(', ', $localPathFiles));
$record('payload has no internal task-system references', $internalPayloadFiles === [], implode(', ', $internalPayloadFiles));
$record('payload has no high-confidence private keys or SandIAM credentials', $credentialFiles === [], implode(', ', $credentialFiles));
$record('release payload is industry-neutral', $industryMarkers === [], implode(', ', $industryMarkers));

$failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));
foreach ($checks as $name => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $name;
    if (!$passed && isset($details[$name])) echo ': ' . $details[$name];
    echo PHP_EOL;
}
echo 'Release payload hygiene: passed=' . (count($checks) - count($failed)) . '/' . count($checks) . '; failed=' . count($failed) . PHP_EOL;
exit($failed === [] ? 0 : 1);
