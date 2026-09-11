<?php

declare(strict_types=1);

/**
 * Build the mirrored, canonical SandPackage failed-upgrade descriptor.
 *
 * The normalized manifest command excludes both descriptor mirrors before this
 * tool writes either one, which intentionally prevents a self-hash cycle.
 */

$root = dirname(__DIR__);
require_once __DIR__ . '/failed-upgrade-recovery-profile-v2.php';
$checker = $root . '/tools/check-package-integrity.php';
$command = [PHP_BINARY, $checker, '--print-normalized-recovery-payload-manifest'];
$pipes = [];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('cannot start normalized recovery payload manifest generator');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    throw new RuntimeException('cannot calculate normalized recovery payload manifest: ' . trim((string) $stderr));
}
try {
    $manifest = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    throw new RuntimeException('normalized recovery payload manifest is not JSON', 0, $exception);
}
if (!is_array($manifest)
    || ($manifest['schema'] ?? null) !== 'sandpackage.normalized-package-manifest/v1'
    || ($manifest['algorithm'] ?? null) !== 'sandpackage-normalized-package-manifest/v1'
    || !is_string($manifest['digest'] ?? null)
    || preg_match('/^[0-9a-f]{64}$/', $manifest['digest']) !== 1) {
    throw new RuntimeException('normalized recovery payload manifest is invalid');
}
$updateSha256 = hash_file('sha256', $root . '/update.sql');
if (!is_string($updateSha256)) {
    throw new RuntimeException('cannot hash update.sql');
}
$descriptor = [
    'app' => 'sand-iam',
    'candidate_payload' => [
        'algorithm' => 'sandpackage-normalized-package-manifest/v1',
        'digest' => $manifest['digest'],
    ],
    'from_version' => '0.6.0',
    'profile' => sandIamFailedUpgradeRecoveryProfileV2($root),
    'schema' => 'sandpackage.failed-upgrade-recovery/v2',
    'to_version' => '0.7.0',
    'update_lifecycle' => [
        'path' => 'update.sql',
        'sha256' => $updateSha256,
    ],
];
$canonicalize = null;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (is_float($value)) {
        throw new RuntimeException('canonical recovery descriptor forbids floating-point values');
    }
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
$json = json_encode($canonicalize($descriptor), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (!is_string($json)) {
    throw new RuntimeException('cannot encode canonical recovery descriptor');
}
foreach ([$root . '/recovery/failed-upgrade.v2.json', $root . '/plugin/sand-iam/recovery/failed-upgrade.v2.json'] as $path) {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('cannot create recovery descriptor directory: ' . $directory);
    }
    if (file_put_contents($path, $json) !== strlen($json)) {
        throw new RuntimeException('cannot write recovery descriptor: ' . $path);
    }
}

printf("failed-upgrade descriptor bytes=%d sha256=%s payload_sha256=%s update_sha256=%s\n", strlen($json), hash('sha256', $json), $manifest['digest'], $updateSha256);
