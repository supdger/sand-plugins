<?php

declare(strict_types=1);

// behavior-test-gate: unit

require dirname(__DIR__, 3) . '/tools/fixtures/c02-keycloak-directory/C02KeycloakDirectory.php';

function c02DirectoryFail(string $message): never
{
    fwrite(STDERR, "c02 keycloak directory non-PG test failed: {$message}\n");
    exit(1);
}

$stateDirectory = sys_get_temp_dir() . '/sand_iam_c02_directory_' . bin2hex(random_bytes(8));
$controlToken = str_repeat('control-token-', 4);
$accessToken = str_repeat('directory-token-', 3);
$scope = 'sand_iam_acceptance_0123456789abcdef_';
$realm = 'sandiam-acceptance';
$directory = new C02KeycloakDirectory($stateDirectory, $controlToken);
$control = ['x-c02-control-token' => $controlToken];
$records = [[
    'id' => $scope . 'directory-user',
    'username' => 'directory.user',
    'email' => 'directory.user@example.test',
    'firstName' => 'Directory',
    'lastName' => 'User',
    'enabled' => true,
]];

try {
    $configured = $directory->handle('POST', '/directory/control/configure', [], $control, json_encode([
        'scope' => $scope,
        'realm' => $realm,
        'access_token' => $accessToken,
        'records' => $records,
    ], JSON_THROW_ON_ERROR));
    $configId = $configured['body']['directory_config_id'] ?? null;
    if ($configured['status'] !== 200 || !is_string($configId) || strlen($configId) !== 64) {
        c02DirectoryFail('configuration was not accepted');
    }

    $path = '/admin/realms/' . rawurlencode($realm) . '/users';
    $query = ['first' => '0', 'max' => '500', 'briefRepresentation' => 'true'];
    if ($directory->handle('GET', $path, $query, ['authorization' => 'Bearer rejected'], '')['status'] !== 401) {
        c02DirectoryFail('invalid bearer token was accepted');
    }
    $first = $directory->handle('GET', $path, $query, ['authorization' => 'Bearer ' . $accessToken], '');
    if ($first['status'] !== 200
        || count($first['body']) !== 1
        || ($first['body'][0]['id'] ?? null) !== $scope . 'directory-user'
        || ($first['body'][0]['enabled'] ?? null) !== true) {
        c02DirectoryFail('initial directory page is invalid');
    }

    $records[0]['firstName'] = 'Updated';
    $records[0]['enabled'] = false;
    $mutated = $directory->handle('POST', '/directory/control/mutate', [], $control, json_encode([
        'scope' => $scope,
        'directory_config_id' => $configId,
        'records' => $records,
    ], JSON_THROW_ON_ERROR));
    if ($mutated['status'] !== 200 || ($mutated['body']['generation'] ?? 0) !== 2) {
        c02DirectoryFail('controlled source mutation failed');
    }
    $second = $directory->handle('GET', $path, $query, ['authorization' => 'Bearer ' . $accessToken], '');
    if ($second['status'] !== 200
        || ($second['body'][0]['firstName'] ?? null) !== 'Updated'
        || ($second['body'][0]['enabled'] ?? null) !== false) {
        c02DirectoryFail('mutated directory page is invalid');
    }

    $proof = $directory->handle('GET', '/directory/control/proof', ['scope' => $scope], $control, '');
    $encodedProof = json_encode($proof, JSON_THROW_ON_ERROR);
    if ($proof['status'] !== 200
        || ($proof['body']['generation'] ?? 0) !== 2
        || ($proof['body']['call_count'] ?? 0) !== 2
        || ($proof['body']['generations_seen'] ?? []) !== [1, 2]
        || str_contains($encodedProof, $accessToken)) {
        c02DirectoryFail('proof does not preserve the required minimal evidence');
    }

    $stateFiles = glob($stateDirectory . '/*.json') ?: [];
    if (count($stateFiles) !== 1 || (fileperms($stateFiles[0]) & 0777) !== 0600) {
        c02DirectoryFail('temporary secret state is not mode 0600');
    }
    $cleanup = $directory->handle('POST', '/directory/control/cleanup', [], $control, json_encode([
        'scope' => $scope,
        'directory_config_id' => $configId,
    ], JSON_THROW_ON_ERROR));
    $status = $directory->handle('GET', '/directory/control/status', ['scope' => $scope], $control, '');
    if ($cleanup['status'] !== 200 || ($cleanup['body']['residual'] ?? -1) !== 0 || ($status['body']['residual'] ?? -1) !== 0) {
        c02DirectoryFail('cleanup did not prove zero residual');
    }
} finally {
    foreach (glob($stateDirectory . '/*') ?: [] as $file) unlink($file);
    if (is_dir($stateDirectory)) rmdir($stateDirectory);
}

echo "c02 keycloak directory non-PG test passed\n";
