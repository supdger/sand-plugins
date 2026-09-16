#!/usr/bin/env php
<?php

declare(strict_types=1);

use Sand\Iam\Sdk\SandIamClient;
use Sand\Iam\Sdk\SandIamException;

const MACHINE_CONFIRM = 'I_CONFIRM_L03_EXISTING_DATABASE_FIXTURES_AND_CLEANUP';

/** @return non-empty-string */
function machineRequiredEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value === '') throw new RuntimeException("missing {$name}");
    return $value;
}

/** @param list<string> $headers @return array{status:int,json:array<string,mixed>} */
function machineRequest(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('cannot initialize HTTP request');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($handle);
    if (!is_string($raw)) throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException("HTTP {$status} returned non-JSON");
    return ['status' => $status, 'json' => $json];
}

/** @return array<string,mixed> */
function machineBusiness(string $label, array $response): array
{
    if ($response['status'] !== 200 || ($response['json']['code'] ?? null) !== 200) {
        throw new RuntimeException($label . ' failed: http=' . $response['status'] . ', code=' . (string) ($response['json']['code'] ?? 'missing'));
    }
    return $response['json'];
}

function machineAdminPost(string $baseUrl, string $authorization, string $path, string $requestId, array $body): array
{
    return machineRequest('POST', $baseUrl . $path, $body, [$authorization, 'X-Request-Id: ' . $requestId]);
}

function machineResponseId(string $label, array $response): int
{
    $json = machineBusiness($label, $response);
    $id = $json['data']['id'] ?? null;
    if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id <= 0) {
        throw new RuntimeException("{$label} returned an invalid id");
    }
    return (int) $id;
}

function machineScalar(PDO $database, string $sql, array $params = []): mixed
{
    $statement = $database->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

/** @return array<string,mixed>|null */
function machineRow(PDO $database, string $sql, array $params = []): ?array
{
    $statement = $database->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function machineWaitPort(int $port, bool $open): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);
        $isOpen = is_resource($socket);
        if ($isOpen) fclose($socket);
        if ($isOpen === $open) return;
        usleep(100_000);
    }
    throw new RuntimeException("port 127.0.0.1:{$port} did not become " . ($open ? 'open' : 'closed'));
}

/** @return resource */
function machineStartProvider(string $directory, array $environment)
{
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:8089', '-t', 'public', 'public/index.php'],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/private/tmp/sandiam-l03-provider.log', 'a'],
            2 => ['file', '/private/tmp/sandiam-l03-provider.log', 'a'],
        ],
        $pipes,
        $directory,
        $environment,
    );
    if (!is_resource($process)) throw new RuntimeException('Provider B could not start');
    try {
        machineWaitPort(8089, true);
    } catch (Throwable $exception) {
        proc_terminate($process);
        proc_close($process);
        throw $exception;
    }
    return $process;
}

function machineStopProvider(mixed $process): void
{
    if (!is_resource($process)) return;
    proc_terminate($process);
    proc_close($process);
    machineWaitPort(8089, false);
}

/** @return array{status:int,json:array<string,mixed>} */
function machineProvider(string $documentId, string $context, string $idempotencyKey, string $requestId): array
{
    return machineRequest(
        'POST',
        'http://127.0.0.1:8089/provider/v1/documents/' . rawurlencode($documentId) . '/process',
        [],
        [
            'X-Request-Id: ' . $requestId,
            'X-Sand-Iam-Context: ' . $context,
            'Idempotency-Key: ' . $idempotencyKey,
        ],
    );
}

function machineAssertDeniedIssue(
    SandIamClient $client,
    string $credential,
    string $service,
    string $audience,
    string $action,
    string $requestId,
): void {
    try {
        $client->issueContext($credential, $service, $audience, [$action], null, $requestId);
    } catch (SandIamException $exception) {
        if ($exception->httpStatus === 403) return;
        throw new RuntimeException("{$requestId} used unexpected status {$exception->httpStatus}");
    }
    throw new RuntimeException("{$requestId} was unexpectedly allowed");
}

function machineDeleteId(PDO $database, string $table, int $id): void
{
    if ($id <= 0) return;
    $statement = $database->prepare("DELETE FROM {$table} WHERE id = :id");
    $statement->execute(['id' => $id]);
}

$baseUrl = rtrim(machineRequiredEnv('SAND_IAM_L03_BASE_URL'), '/');
$authorization = machineRequiredEnv('SAND_IAM_L03_ADMIN_AUTHORIZATION');
$dsn = machineRequiredEnv('SAND_IAM_L03_DATABASE_DSN');
$databaseUser = machineRequiredEnv('SAND_IAM_L03_DATABASE_USER');
$databasePassword = (string) getenv('SAND_IAM_L03_DATABASE_PASSWORD');
$prefix = machineRequiredEnv('SAND_IAM_L03_PREFIX');
$confirmation = machineRequiredEnv('SAND_IAM_L03_CONFIRM');
if ($confirmation !== MACHINE_CONFIRM) throw new RuntimeException('L03 confirmation mismatch');
if (preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_$/', $prefix) !== 1) throw new RuntimeException('unsafe L03 prefix');
if (!str_starts_with($dsn, 'pgsql:')) throw new RuntimeException('L03 requires an existing PostgreSQL DSN');
if (!str_starts_with($authorization, 'Authorization: Bearer ')) throw new RuntimeException('invalid administrator authorization header');

$providerRoot = realpath(dirname(__DIR__) . '/examples/machine-service-client/provider');
$schema = $providerRoot === false ? false : $providerRoot . '/schema.pgsql';
$autoload = $providerRoot === false ? false : $providerRoot . '/vendor/autoload.php';
if ($providerRoot === false || !is_file($schema) || !is_file($autoload)) {
    throw new RuntimeException('Provider B source or installed dependencies are unavailable');
}
require_once $autoload;

$database = new PDO($dsn, $databaseUser, $databasePassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ((string) machineScalar($database, 'SELECT current_database()') !== 'sandadmin') {
    throw new RuntimeException('L03 refuses any database other than the authorized sandadmin database');
}
foreach (['provider_b_document', 'provider_b_document_process', 'provider_b_audit_log'] as $table) {
    if (machineScalar($database, "SELECT to_regclass('public.{$table}')") !== null) {
        throw new RuntimeException("L03 refuses a pre-existing {$table} table");
    }
}

$organizationId = $applicationId = $environmentId = $clientId = 0;
$serviceId = $actionId = $grantId = $credentialId = 0;
$credential = '';
$providerProcess = null;
$tablesCreated = false;
$primaryFailure = null;
$checks = [];
$organizationCode = $prefix . 'org';
$applicationCode = $prefix . 'app';
$serviceCode = $prefix . 'provider';
$audience = $prefix . 'provider';
$actionCode = $prefix . 'document.process';
$documentId = $prefix . 'document';
$requestIds = [];
$auditEvidence = [];

try {
    $database->beginTransaction();
    $database->exec((string) file_get_contents($schema));
    $database->commit();
    $tablesCreated = true;

    $requestIds[] = $requestId = $prefix . 'l03-org-create';
    $organizationId = machineResponseId('organization create', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/organization/save', $requestId, [
        'code' => $organizationCode, 'name' => '机器服务验收客户主体',
    ]));
    $requestIds[] = $requestId = $prefix . 'l03-app-create';
    $applicationId = machineResponseId('application create', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/application/save', $requestId, [
        'organization_id' => $organizationId, 'code' => $applicationCode, 'name' => '机器服务验收应用',
    ]));
    $requestIds[] = $requestId = $prefix . 'l03-environment-create';
    $environmentId = machineResponseId('environment create', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/environment/save', $requestId, [
        'application_id' => $applicationId, 'code' => 'acceptance', 'name' => '机器服务验收环境',
    ]));
    $requestIds[] = $requestId = $prefix . 'l03-client-create';
    $clientId = machineResponseId('workload client create', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/client/save', $requestId, [
        'environment_id' => $environmentId, 'code' => $prefix . 'caller', 'name' => '机器调用方', 'audience' => $audience,
    ]));
    $requestIds[] = $requestId = $prefix . 'l03-service-create';
    $serviceId = machineResponseId('service create', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/service/save', $requestId, [
        'code' => $serviceCode, 'name' => 'Provider B 文档服务',
    ]));
    $requestIds[] = $requestId = $prefix . 'l03-action-create';
    $actionId = machineResponseId('service action create', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/action/save', $requestId, [
        'service_id' => $serviceId, 'code' => $actionCode, 'name' => '处理业务文档',
    ]));
    $requestIds[] = $requestId = $prefix . 'l03-grant-create';
    $grantId = machineResponseId('service grant create', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/grant/save', $requestId, [
        'workload_client_id' => $clientId,
        'service_action_id' => $actionId,
        'audience' => $audience,
        'quota_policy' => (object) [],
        'network_policy' => ['allow_cidrs' => ['127.0.0.0/8'], 'deny_cidrs' => []],
    ]));
    $requestIds[] = $requestId = $prefix . 'l03-credential-issue';
    $issued = machineBusiness('credential issue', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/credential/issue', $requestId, [
        'workload_client_id' => $clientId, 'name' => '机器服务真实链验收凭证',
    ]));
    $credentialId = (int) ($issued['data']['id'] ?? 0);
    $credential = (string) ($issued['data']['credential'] ?? '');
    if ($credentialId <= 0 || $credential === '') throw new RuntimeException('credential issue did not return one-time plaintext');

    $insert = $database->prepare('INSERT INTO provider_b_document (id,organization_id,body) VALUES (:id,:organization_id,:body)');
    $documentBody = "Provider B real business bytes\x00" . $prefix;
    $insert->bindValue(':id', $documentId);
    $insert->bindValue(':organization_id', $organizationId, PDO::PARAM_INT);
    $insert->bindValue(':body', $documentBody, PDO::PARAM_LOB);
    $insert->execute();

    $providerProcess = machineStartProvider($providerRoot, array_merge(getenv(), [
        'SAND_IAM_BASE_URL' => $baseUrl,
        'SAND_IAM_ORGANIZATION_CODE' => $organizationCode,
        'SAND_IAM_APPLICATION_CODE' => $applicationCode,
        'PROVIDER_B_DATABASE_DSN' => $dsn,
        'PROVIDER_B_DATABASE_USER' => $databaseUser,
        'PROVIDER_B_DATABASE_PASSWORD' => $databasePassword,
        'PROVIDER_B_SERVICE_CODE' => $serviceCode,
        'PROVIDER_B_AUDIENCE' => $audience,
        'PROVIDER_B_ACTION' => $actionCode,
    ]));
    if (machineRequest('GET', 'http://127.0.0.1:8089/health')['status'] !== 200) {
        throw new RuntimeException('Provider B health failed');
    }
    $checks['health'] = 200;

    $sdk = new SandIamClient($baseUrl, $organizationCode, $applicationCode);
    $issueRequest = $prefix . 'l03-context-issue';
    $context = $sdk->issueContext($credential, $serviceCode, $audience, [$actionCode], null, $issueRequest);
    $allowRequest = $prefix . 'l03-provider-allow';
    $key = $prefix . 'idempotency-allow';
    $allowed = machineProvider($documentId, $context['context'], $key, $allowRequest);
    if ($allowed['status'] !== 200 || ($allowed['json']['data']['replayed'] ?? null) !== false) {
        throw new RuntimeException('Provider B allowed call did not commit a first result');
    }
    $replayRequest = $prefix . 'l03-provider-replay';
    $replayed = machineProvider($documentId, $context['context'], $key, $replayRequest);
    if ($replayed['status'] !== 200 || ($replayed['json']['data']['replayed'] ?? null) !== true
        || ($replayed['json']['data']['process_id'] ?? null) !== ($allowed['json']['data']['process_id'] ?? null)) {
        throw new RuntimeException('Provider B idempotency replay was not stable');
    }
    if ((int) machineScalar($database, 'SELECT count(*) FROM provider_b_document_process WHERE document_id = :document', ['document' => $documentId]) !== 1
        || (string) machineScalar($database, 'SELECT content_sha256 FROM provider_b_document_process WHERE document_id = :document', ['document' => $documentId]) !== hash('sha256', $documentBody)) {
        throw new RuntimeException('Provider B business side effect is missing or duplicated');
    }
    $checks['business_effect_once'] = true;
    $checks['idempotent_replay'] = true;

    $tamperedRequest = $prefix . 'l03-provider-tampered';
    [$encodedContext, $contextSignature] = array_pad(explode('.', $context['context'], 2), 2, '');
    if ($encodedContext === '' || $contextSignature === '') throw new RuntimeException('issued context format is invalid');
    $tamperedContext = $encodedContext . '.' . ($contextSignature[0] === 'a' ? 'b' : 'a') . substr($contextSignature, 1);
    $tampered = machineProvider($documentId, $tamperedContext, $prefix . 'idempotency-tampered', $tamperedRequest);
    if ($tampered['status'] !== 401) throw new RuntimeException('tampered context was not rejected');

    machineAssertDeniedIssue($sdk, $credential, $serviceCode, $audience . '-wrong', $actionCode, $prefix . 'l03-wrong-audience');
    machineAssertDeniedIssue($sdk, $credential, $serviceCode, $audience, $actionCode . '.wrong', $prefix . 'l03-wrong-action');
    $checks['wrong_audience_action_denied'] = true;

    $requestIds[] = $revokeRequest = $prefix . 'l03-credential-revoke';
    machineBusiness('credential revoke', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/credential/revoke', $revokeRequest, ['id' => $credentialId]));
    $revokedRequest = $prefix . 'l03-provider-revoked';
    $revoked = machineProvider($documentId, $context['context'], $prefix . 'idempotency-revoked', $revokedRequest);
    if ($revoked['status'] !== 401
        || (int) machineScalar($database, 'SELECT count(*) FROM provider_b_document_process WHERE document_id = :document', ['document' => $documentId]) !== 1) {
        throw new RuntimeException('revoked context reached the business side effect');
    }
    $checks['revoked_context_denied'] = true;

    foreach ([$allowRequest => 'succeeded', $replayRequest => 'succeeded', $tamperedRequest => 'failed', $revokedRequest => 'failed'] as $requestId => $outcome) {
        if ((int) machineScalar($database, 'SELECT count(*) FROM provider_b_audit_log WHERE request_id = :request_id AND outcome = :outcome', ['request_id' => $requestId, 'outcome' => $outcome]) !== 1) {
            throw new RuntimeException("Provider B audit is incomplete for {$requestId}");
        }
        if ((int) machineScalar($database, 'SELECT count(*) FROM sand_iam_audit_log WHERE request_id LIKE :request_id AND action = :action', ['request_id' => $requestId . '%', 'action' => 'context.verify']) !== 1) {
            throw new RuntimeException("SandIAM verification audit is incomplete for {$requestId}");
        }
        $providerAudit = machineRow($database, 'SELECT request_id,context_id,action,outcome,error_code,http_status,replayed FROM provider_b_audit_log WHERE request_id = :request_id', ['request_id' => $requestId]);
        $iamAudit = machineRow($database, 'SELECT request_id,action,outcome,resource_type FROM sand_iam_audit_log WHERE request_id LIKE :request_id AND action = :action', ['request_id' => $requestId . '%', 'action' => 'context.verify']);
        if ($providerAudit === null || $iamAudit === null) throw new RuntimeException("dual audit rows disappeared for {$requestId}");
        $auditEvidence[] = ['business' => $providerAudit, 'iam' => $iamAudit];
    }
    if ((int) machineScalar($database, 'SELECT count(*) FROM sand_iam_audit_log WHERE request_id = :request_id AND action = :action AND outcome = :outcome', [
        'request_id' => $issueRequest, 'action' => 'context.issue', 'outcome' => 'succeeded',
    ]) !== 1) {
        throw new RuntimeException('SandIAM context issue audit is incomplete');
    }
    $checks['dual_audit_requests'] = 4;

    $requestIds[] = $requestId = $prefix . 'l03-grant-revoke';
    machineBusiness('service grant revoke', machineAdminPost($baseUrl, $authorization, '/app/sand-iam/admin/grant/revoke', $requestId, ['id' => $grantId]));
    foreach ([
        ['/app/sand-iam/admin/client/disable', $clientId, 'client'],
        ['/app/sand-iam/admin/environment/disable', $environmentId, 'environment'],
        ['/app/sand-iam/admin/action/disable', $actionId, 'action'],
        ['/app/sand-iam/admin/service/disable', $serviceId, 'service'],
        ['/app/sand-iam/admin/application/disable', $applicationId, 'application'],
        ['/app/sand-iam/admin/organization/disable', $organizationId, 'organization'],
    ] as [$path, $id, $label]) {
        $requestIds[] = $requestId = $prefix . 'l03-' . $label . '-disable';
        machineBusiness($label . ' disable', machineAdminPost($baseUrl, $authorization, $path, $requestId, ['id' => $id]));
    }
    $checks['normal_revocation_before_cleanup'] = true;
} catch (Throwable $exception) {
    $primaryFailure = $exception;
} finally {
    try {
        machineStopProvider($providerProcess);
    } catch (Throwable $exception) {
        $primaryFailure ??= $exception;
    }
    try {
        $database->beginTransaction();
        if ($tablesCreated) {
            $database->exec('DELETE FROM provider_b_audit_log');
            $database->exec('DELETE FROM provider_b_document_process');
            $database->exec('DELETE FROM provider_b_document');
            $database->exec('DROP TABLE provider_b_audit_log');
            $database->exec('DROP TABLE provider_b_document_process');
            $database->exec('DROP TABLE provider_b_document');
        }
        if ($grantId > 0) {
            $statement = $database->prepare('DELETE FROM sand_iam_service_quota_bucket WHERE grant_id = :id');
            $statement->execute(['id' => $grantId]);
            $statement = $database->prepare('DELETE FROM sand_iam_service_invocation_operation WHERE grant_id = :id');
            $statement->execute(['id' => $grantId]);
        }
        machineDeleteId($database, 'sand_iam_credential', $credentialId);
        machineDeleteId($database, 'sand_iam_service_grant', $grantId);
        machineDeleteId($database, 'sand_iam_workload_client', $clientId);
        machineDeleteId($database, 'sand_iam_environment', $environmentId);
        $statement = $database->prepare('DELETE FROM sand_iam_security_operation WHERE request_id LIKE :prefix');
        $statement->execute(['prefix' => $prefix . '%']);
        machineDeleteId($database, 'sand_iam_service_action', $actionId);
        machineDeleteId($database, 'sand_iam_service', $serviceId);
        $database->commit();

        foreach (['provider_b_document', 'provider_b_document_process', 'provider_b_audit_log'] as $table) {
            if (machineScalar($database, "SELECT to_regclass('public.{$table}')") !== null) {
                throw new RuntimeException("cleanup left {$table}");
            }
        }
        foreach ([
            'sand_iam_environment' => $environmentId,
            'sand_iam_workload_client' => $clientId,
            'sand_iam_credential' => $credentialId,
            'sand_iam_service' => $serviceId,
            'sand_iam_service_action' => $actionId,
            'sand_iam_service_grant' => $grantId,
        ] as $table => $id) {
            if ($id > 0 && (int) machineScalar($database, "SELECT count(*) FROM {$table} WHERE id = :id", ['id' => $id]) !== 0) {
                throw new RuntimeException("cleanup left {$table} id {$id}");
            }
        }
        if (($organizationId > 0 && (int) machineScalar($database, 'SELECT count(*) FROM sand_iam_organization WHERE id = :id AND status = 2', ['id' => $organizationId]) !== 1)
            || ($applicationId > 0 && (int) machineScalar($database, 'SELECT count(*) FROM sand_iam_application WHERE id = :id AND status = 2', ['id' => $applicationId]) !== 1)) {
            throw new RuntimeException('cleanup did not retain disabled organization/application audit anchors');
        }
        if ((int) machineScalar($database, 'SELECT count(*) FROM sand_iam_audit_log WHERE request_id LIKE :prefix', ['prefix' => $prefix . '%']) < count($auditEvidence)
            || (int) machineScalar($database, 'SELECT count(*) FROM sand_iam_security_operation WHERE request_id LIKE :prefix', ['prefix' => $prefix . '%']) !== 0) {
            throw new RuntimeException('cleanup lost SandIAM audit evidence or retained idempotency rows');
        }
        $checks['zero_residual'] = true;
        $checks['retained_disabled_audit_anchors'] = 2;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        $primaryFailure ??= $exception;
    }
}

if ($primaryFailure instanceof Throwable) {
    fwrite(STDERR, 'L03 live acceptance failed: ' . $primaryFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'prefix' => $prefix,
    'checks' => $checks,
    'audit_evidence' => $auditEvidence,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
