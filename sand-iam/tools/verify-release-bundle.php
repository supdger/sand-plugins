<?php

declare(strict_types=1);

require_once __DIR__ . '/release-bundle-attestation.php';

$options = getopt('', ['artifact-manifest:', 'archive:', 'attestation:', 'public-key:']);
foreach (['artifact-manifest', 'archive', 'attestation', 'public-key'] as $required) {
    if (!is_string($options[$required] ?? null) || trim($options[$required]) === '') throw new InvalidArgumentException('--' . $required . ' is required');
}
if (!function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('sodium Ed25519 verification is unavailable');
$root = dirname(__DIR__);
$manifestPath = sandIamAssertExternalPath($options['artifact-manifest'], $root, 'artifact manifest');
$archivePath = sandIamAssertExternalPath($options['archive'], $root, 'archive');
$attestationPath = sandIamAssertExternalPath($options['attestation'], $root, 'attestation');
$publicKeyPath = sandIamAssertExternalPath($options['public-key'], $root, 'public key');
$artifact = sandIamReadArtifactManifest($manifestPath);
$attestation = json_decode((string) file_get_contents($attestationPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($attestation) || ($attestation['schema'] ?? null) !== 'sand-iam.release-bundle-attestation/v1' || ($attestation['kind'] ?? null) !== 'external-reviewed-release-bundle') throw new RuntimeException('invalid release bundle attestation schema');
$signature = $attestation['provenance']['signature'] ?? null;
if (!is_array($signature) || ($signature['algorithm'] ?? null) !== 'ed25519' || !is_string($signature['value'] ?? null)) throw new RuntimeException('release bundle attestation has no Ed25519 signature');
$unsigned = $attestation;
unset($unsigned['provenance']['signature']);
$publicKey = sandIamStrictBase64((string) file_get_contents($publicKeyPath), SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'public key');
$signatureBytes = sandIamStrictBase64($signature['value'], SODIUM_CRYPTO_SIGN_BYTES, 'signature');
if (!sodium_crypto_sign_verify_detached($signatureBytes, sandIamCanonicalJson($unsigned), $publicKey)) throw new RuntimeException('release bundle signature verification failed');
$expected = sandIamUnsignedBundleAttestation($artifact, $manifestPath, $archivePath, $attestation['provenance']);
if (sandIamCanonicalJson($unsigned) !== sandIamCanonicalJson($expected)) throw new RuntimeException('signed release bundle fields do not match current artifact files');
$zip = sandIamInspectReleaseZip($archivePath);
if ($zip['entry_count'] !== ($artifact['package']['entry_count'] ?? null) || $zip['files'] !== ($artifact['files'] ?? null)) throw new RuntimeException('ZIP entry hashes differ from artifact manifest');
foreach (['LICENSE', 'SBOM.cdx.json', 'THIRD_PARTY_NOTICES.md', 'SECURITY.md', 'CONTRIBUTING.md'] as $required) {
    if (!isset($zip['files'][$required])) throw new RuntimeException('release ZIP is missing ' . $required);
}
foreach (array_keys($zip['files']) as $path) {
    if (preg_match('#(?:^|/)(?:test|tests)(?:/|$)|(?:^|/)[^/]+\.(?:test|spec)\.[^/]+$#i', $path) === 1) throw new RuntimeException('release ZIP contains test material: ' . $path);
}
echo 'SandIAM release bundle verified: archive_sha256=' . $attestation['archive']['sha256']
    . ' public_key_sha256=' . hash('sha256', $publicKey) . ' entries=' . $zip['entry_count'] . PHP_EOL;
