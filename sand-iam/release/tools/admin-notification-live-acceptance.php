#!/usr/bin/env php
<?php

declare(strict_types=1);

const L01_CONFIRM = 'I_CONFIRM_L01_EXISTING_DATABASE_FIXTURES_AND_CLEANUP';

/** @return non-empty-string */
function l01Required(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value === '') {
        throw new RuntimeException("missing {$name}");
    }
    return $value;
}

/** @param list<string> $headers @return array{status:int,json:array<string,mixed>} */
function l01Request(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('cannot initialize HTTP request');
    }
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($handle);
    if (!is_string($raw)) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("HTTP {$status} returned non-JSON");
    }
    return ['status' => $status, 'json' => $json];
}

/** @return array<string,mixed> */
function l01Success(string $label, array $response): array
{
    if ($response['status'] !== 200 || ($response['json']['code'] ?? null) !== 200) {
        throw new RuntimeException(
            "{$label} failed: http={$response['status']}, code="
            . (string) ($response['json']['code'] ?? 'missing'),
        );
    }
    return $response['json'];
}

function l01Denied(string $label, array $response): void
{
    $businessCode = (int) ($response['json']['code'] ?? 0);
    if (!in_array($response['status'], [200, 400, 401, 403], true)
        || !in_array($businessCode, [400, 401, 403], true)) {
        throw new RuntimeException(
            "{$label} was not denied: http={$response['status']}, code={$businessCode}",
        );
    }
}

function l01Admin(
    string $baseUrl,
    string $authorization,
    string $method,
    string $path,
    string $requestId,
    ?array $body = null,
): array {
    return l01Request(
        $method,
        $baseUrl . $path,
        $body,
        [$authorization, 'X-Request-Id: ' . $requestId],
    );
}

function l01Id(string $label, array $response): int
{
    $json = l01Success($label, $response);
    $id = $json['data']['id'] ?? null;
    if ((!is_int($id) && !ctype_digit((string) $id)) || (int) $id <= 0) {
        throw new RuntimeException("{$label} returned an invalid id");
    }
    return (int) $id;
}

function l01Scalar(PDO $database, string $sql, array $params = []): mixed
{
    $statement = $database->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

/** @return array<string,mixed>|null */
function l01Row(PDO $database, string $sql, array $params = []): ?array
{
    $statement = $database->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * @param list<string> $requestIds
 * @return list<array<string,mixed>>
 */
function l01AuditEvidence(PDO $database, array $requestIds): array
{
    if ($requestIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $statement = $database->prepare(
        "SELECT actor_ref, action, resource_type, resource_id, outcome, request_id
         FROM sand_iam_audit_log
         WHERE request_id IN ({$placeholders})
         ORDER BY id",
    );
    $statement->execute($requestIds);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function l01AssertAudit(
    PDO $database,
    string $requestId,
    string $actorRef,
    string $action,
    string $resourceType,
    int $resourceId,
    string $outcome,
): void {
    $count = (int) l01Scalar(
        $database,
        'SELECT count(*) FROM sand_iam_audit_log
         WHERE request_id = :request_id
           AND actor_ref = :actor_ref
           AND action = :action
           AND resource_type = :resource_type
           AND resource_id = :resource_id
           AND outcome = :outcome',
        [
            'request_id' => $requestId,
            'actor_ref' => $actorRef,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'outcome' => $outcome,
        ],
    );
    if ($count !== 1) {
        throw new RuntimeException("expected one exact audit row for {$requestId}, found {$count}");
    }
}

function l01DiscoverId(PDO $database, string $table, string $code, ?int $organizationId = null): int
{
    $allowed = ['sand_iam_organization', 'sand_iam_application'];
    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException('unsupported L01 discovery table');
    }
    $sql = "SELECT id FROM {$table} WHERE code = :code";
    $params = ['code' => $code];
    if ($table === 'sand_iam_application') {
        if ($organizationId === null || $organizationId <= 0) {
            throw new RuntimeException('application discovery requires its organization');
        }
        $sql .= ' AND organization_id = :organization_id';
        $params['organization_id'] = $organizationId;
    }
    $statement = $database->prepare($sql . ' ORDER BY id');
    $statement->execute($params);
    $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    if (count($ids) > 1) {
        throw new RuntimeException("L01 discovery found multiple rows in {$table}");
    }
    return $ids[0] ?? 0;
}

/**
 * Remove only child rows proven to belong to this run. Organization and
 * application rows remain disabled because audit rows retain their scope.
 *
 * @return array<string,int>
 */
function l01PhysicalCleanup(
    PDO $database,
    string $prefix,
    int $organizationId,
    int $applicationId,
    int $organizationAdminId,
    int $applicationAdminId,
): array {
    $provider = l01Row(
        $database,
        'SELECT id, organization_id, code, status FROM sand_iam_message_provider
         WHERE organization_id = :organization_id AND code = :code',
        ['organization_id' => $organizationId, 'code' => $prefix . 'notify'],
    );
    $organizationGrant = l01Row(
        $database,
        'SELECT id, organization_id, admin_user_id, status FROM sand_iam_admin_organization_grant
         WHERE organization_id = :organization_id AND admin_user_id = :admin_user_id',
        ['organization_id' => $organizationId, 'admin_user_id' => $organizationAdminId],
    );
    $applicationGrant = l01Row(
        $database,
        'SELECT id, application_id, admin_user_id, status FROM sand_iam_admin_application_grant
         WHERE application_id = :application_id AND admin_user_id = :admin_user_id',
        ['application_id' => $applicationId, 'admin_user_id' => $applicationAdminId],
    );
    $providerId = (int) ($provider['id'] ?? 0);
    $organizationGrantId = (int) ($organizationGrant['id'] ?? 0);
    $applicationGrantId = (int) ($applicationGrant['id'] ?? 0);
    if ($providerId > 0 && ((int) $provider['organization_id'] !== $organizationId
        || (string) $provider['code'] !== $prefix . 'notify')) {
        throw new RuntimeException('message provider cleanup boundary mismatch');
    }
    if ($organizationGrantId > 0 && ((int) $organizationGrant['organization_id'] !== $organizationId
        || (int) $organizationGrant['admin_user_id'] !== $organizationAdminId)) {
        throw new RuntimeException('organization grant cleanup boundary mismatch');
    }
    if ($applicationGrantId > 0 && ((int) $applicationGrant['application_id'] !== $applicationId
        || (int) $applicationGrant['admin_user_id'] !== $applicationAdminId)) {
        throw new RuntimeException('application grant cleanup boundary mismatch');
    }

    $deleted = ['message_provider_application' => 0, 'message_provider' => 0, 'admin_application_grant' => 0, 'admin_organization_grant' => 0];
    $database->beginTransaction();
    try {
        if ($providerId > 0) {
            $statement = $database->prepare(
                'DELETE FROM sand_iam_message_provider_application
                 WHERE message_provider_id = :provider_id AND application_id = :application_id',
            );
            $statement->execute(['provider_id' => $providerId, 'application_id' => $applicationId]);
            $deleted['message_provider_application'] = $statement->rowCount();
            $statement = $database->prepare(
                'DELETE FROM sand_iam_message_provider
                 WHERE id = :id AND organization_id = :organization_id AND code = :code',
            );
            $statement->execute([
                'id' => $providerId,
                'organization_id' => $organizationId,
                'code' => $prefix . 'notify',
            ]);
            $deleted['message_provider'] = $statement->rowCount();
        }
        if ($applicationGrantId > 0) {
            $statement = $database->prepare(
                'DELETE FROM sand_iam_admin_application_grant
                 WHERE id = :id AND application_id = :application_id AND admin_user_id = :admin_user_id',
            );
            $statement->execute([
                'id' => $applicationGrantId,
                'application_id' => $applicationId,
                'admin_user_id' => $applicationAdminId,
            ]);
            $deleted['admin_application_grant'] = $statement->rowCount();
        }
        if ($organizationGrantId > 0) {
            $statement = $database->prepare(
                'DELETE FROM sand_iam_admin_organization_grant
                 WHERE id = :id AND organization_id = :organization_id AND admin_user_id = :admin_user_id',
            );
            $statement->execute([
                'id' => $organizationGrantId,
                'organization_id' => $organizationId,
                'admin_user_id' => $organizationAdminId,
            ]);
            $deleted['admin_organization_grant'] = $statement->rowCount();
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
    return $deleted;
}

function l01FallbackDisableAnchors(
    PDO $database,
    string $prefix,
    int $organizationId,
    int $applicationId,
): void {
    $unexpectedChildren = (int) l01Scalar(
        $database,
        'SELECT
            (SELECT count(*) FROM sand_iam_application
             WHERE organization_id = :other_application_organization_id AND id <> :expected_application_id)
          + (SELECT count(*) FROM sand_iam_environment WHERE application_id = :environment_application_id)
          + (SELECT count(*) FROM sand_iam_identity WHERE application_id = :identity_application_id)
          + (SELECT count(*) FROM sand_iam_workload_client
             WHERE environment_id IN (SELECT id FROM sand_iam_environment WHERE application_id = :client_application_id))',
        [
            'other_application_organization_id' => $organizationId,
            'expected_application_id' => $applicationId,
            'environment_application_id' => $applicationId,
            'identity_application_id' => $applicationId,
            'client_application_id' => $applicationId,
        ],
    );
    if ($unexpectedChildren !== 0) {
        throw new RuntimeException(
            "L01 refuses direct anchor disable with {$unexpectedChildren} unexpected child records",
        );
    }
    $database->beginTransaction();
    try {
        $application = $database->prepare(
            'UPDATE sand_iam_application
             SET status = 2, update_time = CURRENT_TIMESTAMP
             WHERE id = :id AND organization_id = :organization_id AND code = :code',
        );
        $application->execute([
            'id' => $applicationId,
            'organization_id' => $organizationId,
            'code' => $prefix . 'app',
        ]);
        if ($application->rowCount() !== 1) {
            throw new RuntimeException('L01 application fallback did not match exactly one anchor');
        }
        $organization = $database->prepare(
            'UPDATE sand_iam_organization
             SET status = 2, update_time = CURRENT_TIMESTAMP
             WHERE id = :id AND code = :code',
        );
        $organization->execute(['id' => $organizationId, 'code' => $prefix . 'org']);
        if ($organization->rowCount() !== 1) {
            throw new RuntimeException('L01 organization fallback did not match exactly one anchor');
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }
}

$baseUrl = rtrim(l01Required('SAND_IAM_L01_BASE_URL'), '/');
$platformAuthorization = l01Required('SAND_IAM_L01_PLATFORM_AUTHORIZATION');
$organizationAuthorization = l01Required('SAND_IAM_L01_ORGANIZATION_AUTHORIZATION');
$applicationAuthorization = l01Required('SAND_IAM_L01_APPLICATION_AUTHORIZATION');
$outOfScopeAuthorization = l01Required('SAND_IAM_L01_OUT_OF_SCOPE_AUTHORIZATION');
$organizationAdminId = (int) l01Required('SAND_IAM_L01_ORGANIZATION_ADMIN_ID');
$applicationAdminId = (int) l01Required('SAND_IAM_L01_APPLICATION_ADMIN_ID');
$outOfScopeAdminId = (int) l01Required('SAND_IAM_L01_OUT_OF_SCOPE_ADMIN_ID');
$databaseDsn = l01Required('SAND_IAM_L01_DATABASE_DSN');
$databaseUser = l01Required('SAND_IAM_L01_DATABASE_USER');
$databasePassword = (string) getenv('SAND_IAM_L01_DATABASE_PASSWORD');
$prefix = l01Required('SAND_IAM_L01_PREFIX');
if (l01Required('SAND_IAM_L01_CONFIRM') !== L01_CONFIRM) {
    throw new RuntimeException('L01 confirmation mismatch');
}
if (preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_$/', $prefix) !== 1) {
    throw new RuntimeException('unsafe L01 prefix');
}
if (!str_starts_with($databaseDsn, 'pgsql:')) {
    throw new RuntimeException('L01 requires an existing PostgreSQL DSN');
}
foreach ([$platformAuthorization, $organizationAuthorization, $applicationAuthorization, $outOfScopeAuthorization] as $authorization) {
    if (!str_starts_with($authorization, 'Authorization: Bearer ')) {
        throw new RuntimeException('invalid administrator authorization header');
    }
}
if ($organizationAdminId <= 0 || $applicationAdminId <= 0 || $outOfScopeAdminId <= 0
    || count(array_unique([$organizationAdminId, $applicationAdminId, $outOfScopeAdminId])) !== 3) {
    throw new RuntimeException('L01 requires three distinct non-zero administrator ids');
}

$database = new PDO($databaseDsn, $databaseUser, $databasePassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ((string) l01Scalar($database, 'SELECT current_database()') !== 'sandadmin') {
    throw new RuntimeException('L01 refuses any database other than the authorized sandadmin database');
}

$organizationId = 0;
$applicationId = 0;
$organizationGrantId = 0;
$applicationGrantId = 0;
$messageProviderId = 0;
$messageMountId = 0;
$primaryFailure = null;
$checks = [];
$requestIds = [];
$auditedRequestIds = [];

try {
    $requestIds[] = $requestId = $prefix . 'l01-org-create';
    $organizationId = l01Id('organization create', l01Admin(
        $baseUrl,
        $platformAuthorization,
        'POST',
        '/app/sand-iam/admin/organization/save',
        $requestId,
        ['code' => $prefix . 'org', 'name' => '管理员配置闭环客户主体'],
    ));
    $requestIds[] = $requestId = $prefix . 'l01-app-create';
    $applicationId = l01Id('application create', l01Admin(
        $baseUrl,
        $platformAuthorization,
        'POST',
        '/app/sand-iam/admin/application/save',
        $requestId,
        ['organization_id' => $organizationId, 'code' => $prefix . 'app', 'name' => '管理员配置闭环应用'],
    ));

    $requestIds[] = $auditedRequestIds[] = $requestId = $prefix . 'l01-org-grant';
    $organizationGrantId = l01Id('organization grant create', l01Admin(
        $baseUrl,
        $platformAuthorization,
        'POST',
        '/app/sand-iam/admin/admin-organization-grant/save',
        $requestId,
        ['organization_id' => $organizationId, 'admin_user_id' => $organizationAdminId],
    ));
    $requestIds[] = $auditedRequestIds[] = $requestId = $prefix . 'l01-app-grant';
    $applicationGrantId = l01Id('application grant create', l01Admin(
        $baseUrl,
        $platformAuthorization,
        'POST',
        '/app/sand-iam/admin/admin-application-grant/save',
        $requestId,
        ['application_id' => $applicationId, 'admin_user_id' => $applicationAdminId],
    ));

    $requestIds[] = $auditedRequestIds[] = $requestId = $prefix . 'l01-message-create';
    $messageProviderId = l01Id('message provider create', l01Admin(
        $baseUrl,
        $organizationAuthorization,
        'POST',
        '/app/sand-iam/admin/message-provider/save',
        $requestId,
        [
            'organization_id' => $organizationId,
            'code' => $prefix . 'notify',
            'name' => '管理员闭环通知服务',
            'provider_type' => 'notification',
            'driver_code' => 'acceptance',
        ],
    ));
    $requestIds[] = $auditedRequestIds[] = $requestId = $prefix . 'l01-message-configure';
    $configured = l01Success('message provider configure', l01Admin(
        $baseUrl,
        $organizationAuthorization,
        'POST',
        '/app/sand-iam/admin/message-provider/configure',
        $requestId,
        [
            'id' => $messageProviderId,
            'config' => [
                'endpoint' => 'https://acceptance.invalid/notify',
                'token' => bin2hex(random_bytes(24)),
            ],
        ],
    ));
    if ((int) ($configured['data']['id'] ?? 0) !== $messageProviderId
        || (int) ($configured['data']['config_version'] ?? 0) !== 1) {
        throw new RuntimeException('message provider configure did not persist version one');
    }

    $requestIds[] = $auditedRequestIds[] = $requestId = $prefix . 'l01-message-mount';
    $messageMountId = l01Id('message provider mount', l01Admin(
        $baseUrl,
        $applicationAuthorization,
        'POST',
        '/app/sand-iam/admin/message-provider/mount',
        $requestId,
        [
            'message_provider_id' => $messageProviderId,
            'application_id' => $applicationId,
            'purposes' => ['account_notice', 'security_alert'],
            'template_codes' => ['account_notice' => 'acceptance.account', 'security_alert' => 'acceptance.security'],
            'priority' => 10,
        ],
    ));
    $mounts = l01Success('application message mounts', l01Admin(
        $baseUrl,
        $applicationAuthorization,
        'GET',
        '/app/sand-iam/admin/message-provider/mounts?application_id=' . $applicationId,
        $prefix . 'l01-message-mount-read',
    ));
    $matchingMount = array_values(array_filter(
        is_array($mounts['data'] ?? null) ? $mounts['data'] : [],
        static fn (mixed $row): bool => is_array($row)
            && (int) ($row['id'] ?? 0) === $messageMountId
            && (int) ($row['message_provider_id'] ?? 0) === $messageProviderId
            && (int) ($row['status'] ?? 0) === 1,
    ));
    if (count($matchingMount) !== 1) {
        throw new RuntimeException('application administrator cannot observe the configured notification mount');
    }
    $checks['notification_configured_and_mounted'] = true;

    $outOfScopeRequestId = $prefix . 'l01-out-of-scope-deny';
    $denied = l01Admin(
        $baseUrl,
        $outOfScopeAuthorization,
        'GET',
        '/app/sand-iam/admin/message-provider/mounts?application_id=' . $applicationId,
        $outOfScopeRequestId,
    );
    l01Denied('out-of-scope message mount read', $denied);
    l01AssertAudit(
        $database,
        $outOfScopeRequestId,
        (string) $outOfScopeAdminId,
        'application.access',
        'application',
        $applicationId,
        'denied',
    );
    $checks['out_of_scope_denied'] = true;

    $auditRows = l01AuditEvidence($database, $auditedRequestIds);
    $auditByRequestId = [];
    foreach ($auditRows as $row) {
        $auditByRequestId[(string) ($row['request_id'] ?? '')] = $row;
    }
    foreach ($auditedRequestIds as $auditedRequestId) {
        if (($auditByRequestId[$auditedRequestId]['outcome'] ?? null) !== 'succeeded') {
            throw new RuntimeException("missing successful audit for {$auditedRequestId}");
        }
    }
    if ((string) ($auditByRequestId[$prefix . 'l01-message-create']['actor_ref'] ?? '') !== (string) $organizationAdminId
        || (string) ($auditByRequestId[$prefix . 'l01-message-mount']['actor_ref'] ?? '') !== (string) $applicationAdminId) {
        throw new RuntimeException('delegated administrator audit attribution mismatch');
    }
    $checks['delegated_audit_attribution'] = true;

    $requestIds[] = $requestId = $prefix . 'l01-message-unmount';
    l01Success('message provider unmount', l01Admin(
        $baseUrl,
        $applicationAuthorization,
        'POST',
        '/app/sand-iam/admin/message-provider/unmount',
        $requestId,
        ['id' => $messageMountId],
    ));
    $requestIds[] = $requestId = $prefix . 'l01-message-disable';
    l01Success('message provider disable', l01Admin(
        $baseUrl,
        $organizationAuthorization,
        'POST',
        '/app/sand-iam/admin/message-provider/disable',
        $requestId,
        ['id' => $messageProviderId],
    ));
    $requestIds[] = $requestId = $prefix . 'l01-app-grant-disable';
    l01Success('application grant disable', l01Admin(
        $baseUrl,
        $platformAuthorization,
        'POST',
        '/app/sand-iam/admin/admin-application-grant/disable',
        $requestId,
        ['id' => $applicationGrantId],
    ));
    $requestIds[] = $requestId = $prefix . 'l01-org-grant-disable';
    l01Success('organization grant disable', l01Admin(
        $baseUrl,
        $platformAuthorization,
        'POST',
        '/app/sand-iam/admin/admin-organization-grant/disable',
        $requestId,
        ['id' => $organizationGrantId],
    ));

    $revokedApplicationRequestId = $prefix . 'l01-app-revoked-deny';
    l01Denied('revoked application administrator', l01Admin(
        $baseUrl,
        $applicationAuthorization,
        'GET',
        '/app/sand-iam/admin/application/read?id=' . $applicationId,
        $revokedApplicationRequestId,
    ));
    l01AssertAudit(
        $database,
        $revokedApplicationRequestId,
        (string) $applicationAdminId,
        'application.access',
        'application',
        $applicationId,
        'denied',
    );
    $revokedOrganizationRequestId = $prefix . 'l01-org-revoked-deny';
    l01Denied('revoked organization administrator', l01Admin(
        $baseUrl,
        $organizationAuthorization,
        'GET',
        '/app/sand-iam/admin/message-provider/read?id=' . $messageProviderId,
        $revokedOrganizationRequestId,
    ));
    l01AssertAudit(
        $database,
        $revokedOrganizationRequestId,
        (string) $organizationAdminId,
        'organization.access',
        'organization',
        $organizationId,
        'denied',
    );
    $checks['delegation_revoked'] = true;
} catch (Throwable $exception) {
    $primaryFailure = $exception;
} finally {
    $cleanupFailures = [];
    try {
        if ($organizationId <= 0) {
            $organizationId = l01DiscoverId(
                $database,
                'sand_iam_organization',
                $prefix . 'org',
            );
        }
        if ($applicationId <= 0 && $organizationId > 0) {
            $applicationId = l01DiscoverId(
                $database,
                'sand_iam_application',
                $prefix . 'app',
                $organizationId,
            );
        }
    } catch (Throwable $exception) {
        $cleanupFailures[] = 'discovery: ' . $exception->getMessage();
    }
    if ($applicationId > 0) {
        try {
            l01Success('application disable cleanup', l01Admin(
                $baseUrl,
                $platformAuthorization,
                'POST',
                '/app/sand-iam/admin/application/disable',
                $prefix . 'l01-app-disable',
                ['id' => $applicationId],
            ));
        } catch (Throwable $exception) {
            $cleanupFailures[] = 'application API disable: ' . $exception->getMessage();
        }
    }
    if ($organizationId > 0) {
        try {
            l01Success('organization disable cleanup', l01Admin(
                $baseUrl,
                $platformAuthorization,
                'POST',
                '/app/sand-iam/admin/organization/disable',
                $prefix . 'l01-org-disable',
                ['id' => $organizationId],
            ));
        } catch (Throwable $exception) {
            $cleanupFailures[] = 'organization API disable: ' . $exception->getMessage();
        }
    }
    if ($organizationId > 0 && $applicationId > 0) {
        try {
            $deleted = l01PhysicalCleanup(
                $database,
                $prefix,
                $organizationId,
                $applicationId,
                $organizationAdminId,
                $applicationAdminId,
            );
            l01FallbackDisableAnchors($database, $prefix, $organizationId, $applicationId);
            $activeResidual = (int) l01Scalar(
                $database,
                'SELECT
                    (SELECT count(*) FROM sand_iam_organization WHERE id = :organization_active_id AND status = 1)
                  + (SELECT count(*) FROM sand_iam_application WHERE id = :application_active_id AND status = 1)
                  + (SELECT count(*) FROM sand_iam_message_provider WHERE organization_id = :provider_organization_id)
                  + (SELECT count(*) FROM sand_iam_message_provider_application WHERE application_id = :mount_application_id)
                  + (SELECT count(*) FROM sand_iam_admin_organization_grant
                     WHERE organization_id = :grant_organization_id AND admin_user_id = :organization_admin_id)
                  + (SELECT count(*) FROM sand_iam_admin_application_grant
                     WHERE application_id = :grant_application_id AND admin_user_id = :application_admin_id)',
                [
                    'organization_active_id' => $organizationId,
                    'application_active_id' => $applicationId,
                    'provider_organization_id' => $organizationId,
                    'mount_application_id' => $applicationId,
                    'grant_organization_id' => $organizationId,
                    'grant_application_id' => $applicationId,
                    'organization_admin_id' => $organizationAdminId,
                    'application_admin_id' => $applicationAdminId,
                ],
            );
            if ($activeResidual !== 0) {
                throw new RuntimeException("L01 cleanup left {$activeResidual} active or child records");
            }
            $checks['cleanup'] = true;
            $checks['deleted_rows'] = $deleted;
        } catch (Throwable $exception) {
            $cleanupFailures[] = 'bounded cleanup: ' . $exception->getMessage();
        }
    }
    if ($cleanupFailures !== []) {
        $cleanupMessage = implode('; ', $cleanupFailures);
        $primaryFailure = $primaryFailure === null
            ? new RuntimeException($cleanupMessage)
            : new RuntimeException(
                $primaryFailure->getMessage() . '; cleanup issues: ' . $cleanupMessage,
                0,
                $primaryFailure,
            );
    }
}

if ($primaryFailure !== null) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error' => $primaryFailure->getMessage(),
        'checks' => $checks,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'prefix' => $prefix,
    'organization_id' => $organizationId,
    'application_id' => $applicationId,
    'notification_configured_and_mounted' => $checks['notification_configured_and_mounted'] ?? false,
    'out_of_scope_denied' => $checks['out_of_scope_denied'] ?? false,
    'delegated_audit_attribution' => $checks['delegated_audit_attribution'] ?? false,
    'delegation_revoked' => $checks['delegation_revoked'] ?? false,
    'cleanup' => $checks['cleanup'] ?? false,
    'deleted_rows' => $checks['deleted_rows'] ?? [],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
