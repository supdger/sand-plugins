<?php

declare(strict_types=1);

/** Generate a deterministic CycloneDX 1.6 inventory from committed lock files. */

$root = dirname(__DIR__);
$output = $root . '/SBOM.cdx.json';
$checkOnly = in_array('--check', array_slice($argv, 1), true);
$licensePolicy = require __DIR__ . '/dependency-license-policy.php';
if (!is_array($licensePolicy) || !is_array($licensePolicy['npm'] ?? null) || !is_array($licensePolicy['pub'] ?? null)) {
    throw new RuntimeException('dependency license policy is invalid');
}
$licenseChoice = static function (array $license, string $coordinate): array {
    $id = $license['id'] ?? null;
    $expression = $license['expression'] ?? null;
    if (is_string($id) && $id !== '' && $expression === null) return ['license' => ['id' => $id]];
    if (is_string($expression) && $expression !== '' && $id === null) return ['expression' => $expression];
    throw new RuntimeException('license policy must declare exactly one id or expression for ' . $coordinate);
};

/** @var array<string,array<string,mixed>> $components */
$components = [];
$add = static function (array $component) use (&$components): void {
    $ref = (string) ($component['bom-ref'] ?? '');
    if ($ref === '') throw new RuntimeException('SBOM component has no bom-ref');
    if (!isset($components[$ref])) {
        $components[$ref] = $component;
        return;
    }
    $existingScope = $components[$ref]['scope'] ?? 'optional';
    if ($existingScope === 'optional' && ($component['scope'] ?? 'optional') === 'required') {
        $components[$ref]['scope'] = 'required';
    }
    $properties = array_merge($components[$ref]['properties'] ?? [], $component['properties'] ?? []);
    $seen = [];
    $properties = array_values(array_filter($properties, static function (array $property) use (&$seen): bool {
        $key = ($property['name'] ?? '') . "\0" . ($property['value'] ?? '');
        if (isset($seen[$key])) return false;
        $seen[$key] = true;
        return true;
    }));
    usort($properties, static fn (array $a, array $b): int => [$a['name'], $a['value']] <=> [$b['name'], $b['value']]);
    $components[$ref]['properties'] = $properties;
};

$composerPath = $root . '/plugin/sand-iam/composer.lock';
$composer = json_decode((string) file_get_contents($composerPath), true, 512, JSON_THROW_ON_ERROR);
foreach (['packages' => 'required', 'packages-dev' => 'optional'] as $section => $scope) {
    foreach (($composer[$section] ?? []) as $package) {
        if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) continue;
        $purl = 'pkg:composer/' . $package['name'] . '@' . rawurlencode($package['version']);
        $component = [
            'type' => 'library', 'bom-ref' => $purl, 'name' => $package['name'],
            'version' => $package['version'], 'scope' => $scope, 'purl' => $purl,
            'properties' => [['name' => 'sandiam:lockfile', 'value' => 'plugin/sand-iam/composer.lock']],
        ];
        $licenses = array_values(array_filter($package['license'] ?? [], 'is_string'));
        if ($licenses !== []) $component['licenses'] = array_map(static fn (string $id): array => ['license' => ['id' => $id]], $licenses);
        $vendorLicense = 'plugin/sand-iam/vendor/' . $package['name'] . '/LICENSE';
        if (is_file($root . '/' . $vendorLicense)) {
            $component['properties'][] = ['name' => 'sandiam:license-evidence', 'value' => $vendorLicense];
        } elseif (is_string($package['source']['url'] ?? null) && $package['source']['url'] !== '') {
            $component['properties'][] = ['name' => 'sandiam:license-evidence', 'value' => $package['source']['url']];
        } else {
            throw new RuntimeException('composer component has no license evidence for ' . $package['name'] . '@' . $package['version']);
        }
        $reference = $package['source']['reference'] ?? null;
        if (is_string($reference) && $reference !== '') $component['properties'][] = ['name' => 'sandiam:vcs-reference', 'value' => $reference];
        $add($component);
    }
}

// php-saml redistributes this schema as a distinct W3C work. Composer's
// package-level MIT declaration does not replace the schema's own notice.
$xmlDsigSchema = 'plugin/sand-iam/vendor/onelogin/php-saml/src/Saml2/schemas/xmldsig-core-schema.xsd';
if (!is_file($root . '/' . $xmlDsigSchema)) {
    throw new RuntimeException('distributed W3C XML Signature schema is missing');
}
$add([
    'type' => 'data',
    'bom-ref' => 'urn:sandiam:vendored:w3c-xmldsig-core-schema@2002-02-08',
    'name' => 'W3C XML Signature Core Schema',
    'version' => '2002-02-08',
    'scope' => 'required',
    'licenses' => [[
        'license' => [
            'name' => 'W3C Software Notice and License',
            'url' => 'https://www.w3.org/Consortium/Legal/copyright-software-19980720',
        ],
    ]],
    'properties' => [
        ['name' => 'sandiam:distributed-path', 'value' => $xmlDsigSchema],
        ['name' => 'sandiam:license-evidence', 'value' => $xmlDsigSchema],
        ['name' => 'sandiam:notice', 'value' => 'THIRD_PARTY_NOTICES.md'],
        ['name' => 'sandiam:source-url', 'value' => 'http://www.w3.org/2000/09/xmldsig#'],
    ],
]);

/** @param array<string,array{id?:string,expression?:string,evidence:string}> $licenseMap */
$readPnpm = static function (string $relative, array $licenseMap) use ($root, $add, $licenseChoice): void {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) throw new RuntimeException('cannot read ' . $relative);
    $inPackages = false;
    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        if ($line === 'packages:') { $inPackages = true; continue; }
        if ($inPackages && $line === 'snapshots:') break;
        if (!$inPackages || preg_match('/^  ([^ ].*):$/', $line, $match) !== 1) continue;
        $coordinate = trim($match[1], "'\"");
        $separator = strrpos($coordinate, '@');
        if ($separator === false || $separator === 0) continue;
        $name = substr($coordinate, 0, $separator);
        $version = substr($coordinate, $separator + 1);
        if ($name === '' || $version === '') continue;
        $purl = 'pkg:npm/' . str_replace('%2F', '/', rawurlencode($name)) . '@' . rawurlencode($version);
        $component = [
            'type' => 'library', 'bom-ref' => $purl, 'name' => $name, 'version' => $version,
            'scope' => 'optional', 'purl' => $purl,
            'properties' => [['name' => 'sandiam:lockfile', 'value' => $relative]],
        ];
        $coordinateKey = $name . '@' . $version;
        $license = $licenseMap[$coordinateKey] ?? null;
        if (!is_array($license) || !is_string($license['evidence'] ?? null)) {
            throw new RuntimeException('license policy has no npm entry for ' . $coordinateKey);
        }
        $component['licenses'] = [$licenseChoice($license, $coordinateKey)];
        $component['properties'][] = ['name' => 'sandiam:license-evidence', 'value' => $license['evidence']];
        $add($component);
    }
};
$readPnpm('portal/pnpm-lock.yaml', $licensePolicy['npm']);
$readPnpm('sdk/typescript/pnpm-lock.yaml', $licensePolicy['npm']);

$dartPath = $root . '/sdk/dart/pubspec.lock';
$dartSource = file_get_contents($dartPath);
if (!is_string($dartSource)) throw new RuntimeException('cannot read sdk/dart/pubspec.lock');
$current = null;
$dartPackages = [];
foreach (preg_split('/\R/', $dartSource) ?: [] as $line) {
    if (preg_match('/^  ([A-Za-z0-9_]+):$/', $line, $match) === 1) {
        $current = $match[1];
        $dartPackages[$current] = [];
        continue;
    }
    if ($current === null) continue;
    if (preg_match('/^    dependency: "?([^"\r\n]+)"?$/', $line, $match) === 1) $dartPackages[$current]['dependency'] = $match[1];
    if (preg_match('/^    version: "([^"]+)"$/', $line, $match) === 1) $dartPackages[$current]['version'] = $match[1];
    if (preg_match('/^      sha256: "?([a-f0-9]{64})"?$/', $line, $match) === 1) $dartPackages[$current]['sha256'] = $match[1];
}
foreach ($dartPackages as $name => $package) {
    if (!is_string($package['version'] ?? null)) continue;
    $purl = 'pkg:pub/' . rawurlencode($name) . '@' . rawurlencode($package['version']);
    $dependencyKind = (string) ($package['dependency'] ?? 'transitive');
    $component = [
        'type' => 'library', 'bom-ref' => $purl, 'name' => $name, 'version' => $package['version'],
        'scope' => $dependencyKind === 'direct main' ? 'required' : 'optional', 'purl' => $purl,
        'properties' => [
            ['name' => 'sandiam:dependency-kind', 'value' => $dependencyKind],
            ['name' => 'sandiam:lockfile', 'value' => 'sdk/dart/pubspec.lock'],
        ],
    ];
    if (is_string($package['sha256'] ?? null)) $component['hashes'] = [['alg' => 'SHA-256', 'content' => $package['sha256']]];
    $coordinateKey = $name . '@' . $package['version'];
    $license = $licensePolicy['pub'][$coordinateKey] ?? null;
    if (!is_array($license) || !is_string($license['evidence'] ?? null)) {
        throw new RuntimeException('license policy has no pub entry for ' . $coordinateKey);
    }
    $component['licenses'] = [$licenseChoice($license, $coordinateKey)];
    $component['properties'][] = ['name' => 'sandiam:license-evidence', 'value' => $license['evidence']];
    $add($component);
}

ksort($components, SORT_STRING);
$version = (string) (parse_ini_file($root . '/info.ini')['version'] ?? 'unknown');
$rootRef = 'pkg:generic/sandiam@' . rawurlencode($version);
$document = [
    '$schema' => 'https://cyclonedx.org/schema/bom-1.6.schema.json',
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.6',
    'version' => 1,
    'metadata' => [
        'component' => [
            'type' => 'application', 'bom-ref' => $rootRef, 'name' => 'SandIAM', 'version' => $version,
            'licenses' => [['license' => ['id' => 'Apache-2.0']]],
            'properties' => [
                ['name' => 'sandiam:license-evidence', 'value' => 'LICENSE'],
                ['name' => 'sandiam:notice', 'value' => 'NOTICE'],
            ],
        ],
        'properties' => [['name' => 'sandiam:generation', 'value' => 'deterministic-lockfile-license-inventory/v2']],
    ],
    'components' => array_values($components),
    'dependencies' => [['ref' => $rootRef, 'dependsOn' => array_keys($components)]],
];
$encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if ($checkOnly) {
    $actual = is_file($output) ? file_get_contents($output) : false;
    if (!is_string($actual) || !hash_equals(hash('sha256', $encoded), hash('sha256', $actual))) {
        fwrite(STDERR, "SBOM.cdx.json is stale; run php tools/generate-sbom.php\n");
        exit(1);
    }
    echo 'SandIAM CycloneDX SBOM current components=' . count($components) . ' sha256=' . hash('sha256', $encoded) . PHP_EOL;
    exit(0);
}
if (file_put_contents($output, $encoded) === false) throw new RuntimeException('cannot write SBOM.cdx.json');
echo 'SandIAM CycloneDX SBOM components=' . count($components) . ' sha256=' . hash('sha256', $encoded) . PHP_EOL;
