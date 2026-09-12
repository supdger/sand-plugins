<?php

declare(strict_types=1);

require_once __DIR__ . '/release-bundle-attestation.php';

$options = getopt('', ['artifact-manifest:', 'archive:', 'private-key:', 'output:', 'source:', 'reference:', 'approved-by:', 'approved-at:']);
foreach (['artifact-manifest', 'archive', 'private-key', 'output', 'source', 'reference', 'approved-by', 'approved-at'] as $required) {
    if (!is_string($options[$required] ?? null) || trim($options[$required]) === '') throw new InvalidArgumentException('--' . $required . ' is required');
}
if (!function_exists('sodium_crypto_sign_detached')) throw new RuntimeException('sodium Ed25519 signing is unavailable');
$root = dirname(__DIR__);
$manifestPath = sandIamAssertExternalPath($options['artifact-manifest'], $root, 'artifact manifest');
$archivePath = sandIamAssertExternalPath($options['archive'], $root, 'archive');
$keyPath = sandIamAssertExternalPath($options['private-key'], $root, 'private key');
$keyMode = fileperms($keyPath);
if (!is_int($keyMode) || ($keyMode & 0077) !== 0) throw new RuntimeException('private key permissions must not grant group or other access');
$outputParent = sandIamAssertDirectoryPath(dirname($options['output']), 'output directory');
$rootPath = realpath($root);
if (!is_string($outputParent) || !is_string($rootPath) || str_starts_with($outputParent . '/', $rootPath . '/')) throw new RuntimeException('output must use an existing directory outside the SandIAM package root');
$output = $outputParent . '/' . basename($options['output']);
if (file_exists($output) || is_link($output)) throw new RuntimeException('refusing to overwrite attestation output');
$artifact = sandIamReadArtifactManifest($manifestPath);
$unsigned = sandIamUnsignedBundleAttestation($artifact, $manifestPath, $archivePath, [
    'source' => $options['source'], 'reference' => $options['reference'],
    'approved_by' => $options['approved-by'], 'approved_at' => $options['approved-at'],
]);
$encodedSecretKey = (string) file_get_contents($keyPath);
$secretKey = sandIamStrictBase64($encodedSecretKey, SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'private key');
try {
    $signature = sodium_crypto_sign_detached(sandIamCanonicalJson($unsigned), $secretKey);
} finally {
    sodium_memzero($secretKey);
    sodium_memzero($encodedSecretKey);
}
$signed = $unsigned;
$signed['provenance']['signature'] = ['algorithm' => 'ed25519', 'value' => base64_encode($signature)];
$encoded = json_encode(sandIamCanonicalize($signed), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output, $encoded, LOCK_EX) === false || chmod($output, 0644) === false) throw new RuntimeException('cannot write signed attestation');
echo 'SandIAM release bundle attestation written: ' . $output . ' sha256=' . hash('sha256', $encoded) . PHP_EOL;
