<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

require_once __DIR__ . '/isolated_sandiam_fixture.php';

$root = dirname(__DIR__, 3);

/** @return array{int,string} */
$run = static function (string $fixtureRoot, array $arguments): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixtureRoot . '/tools/generate-sbom.php');
    foreach ($arguments as $argument) $command .= ' ' . escapeshellarg($argument);
    $output = [];
    exec($command . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};

[$currentStatus, $currentOutput] = $run($root, ['--check']);
if ($currentStatus !== 0 || !str_contains($currentOutput, 'components=')) {
    fwrite(STDERR, "authority SBOM is stale: {$currentOutput}\n");
    exit(1);
}
$currentDocument = json_decode((string) file_get_contents($root . '/SBOM.cdx.json'), true);
$currentComponents = is_array($currentDocument['components'] ?? null) ? $currentDocument['components'] : [];
$nodePreambleExpression = null;
foreach ($currentComponents as $component) {
    if (!is_array($component) || !is_array($component['licenses'] ?? null) || $component['licenses'] === []) {
        fwrite(STDERR, 'SBOM component has no audited license: ' . (string) ($component['bom-ref'] ?? 'unknown') . "\n");
        exit(1);
    }
    $properties = is_array($component['properties'] ?? null) ? $component['properties'] : [];
    $evidence = array_values(array_filter($properties, static fn (mixed $property): bool => is_array($property)
        && ($property['name'] ?? null) === 'sandiam:license-evidence'
        && is_string($property['value'] ?? null) && $property['value'] !== ''));
    if ($evidence === []) {
        fwrite(STDERR, 'SBOM component has no license evidence: ' . (string) ($component['bom-ref'] ?? 'unknown') . "\n");
        exit(1);
    }
    if (($component['purl'] ?? null) === 'pkg:pub/node_preamble@2.0.2') {
        $nodePreambleExpression = $component['licenses'][0]['expression'] ?? null;
    }
}
if ($nodePreambleExpression !== 'BSD-3-Clause AND MIT') {
    fwrite(STDERR, "SBOM does not preserve the node_preamble compound license\n");
    exit(1);
}

$passed = sandIamWithIsolatedFixture($root, static function (string $fixtureRoot) use ($run): bool {
    $lock = $fixtureRoot . '/sdk/typescript/pnpm-lock.yaml';
    $source = file_get_contents($lock);
    if (!is_string($source)) return false;
    $changed = str_replace('typescript@5.9.3', 'typescript@5.9.4', $source);
    if ($changed === $source || file_put_contents($lock, $changed) === false) return false;
    [$staleStatus, $staleOutput] = $run($fixtureRoot, ['--check']);
    [$generateStatus, $generateOutput] = $run($fixtureRoot, []);
    return $staleStatus !== 0 && $generateStatus !== 0
        && str_contains($staleOutput, 'license policy has no npm entry for typescript@5.9.4')
        && str_contains($generateOutput, 'license policy has no npm entry for typescript@5.9.4');
});

if (!$passed) {
    fwrite(STDERR, "SBOM generator did not fail closed for an unaudited lockfile coordinate\n");
    exit(1);
}

echo "SandIAM deterministic SBOM generation checks passed\n";
