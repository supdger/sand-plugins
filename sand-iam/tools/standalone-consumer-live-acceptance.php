#!/usr/bin/env php
<?php

declare(strict_types=1);

const STANDALONE_CONFIRM = 'I_CONFIRM_L04_EXISTING_DATABASE_FIXTURES_AND_CLEANUP';
const FIXTURE_CONFIRM = 'I_CONFIRM_DELETE_ONLY_THIS_ACCEPTANCE_FIXTURE';

/** @return non-empty-string */
function requiredEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value === '') throw new RuntimeException("missing {$name}");
    return $value;
}

/** @param list<string> $headers @return array{status:int,json:array<string,mixed>} */
function requestJson(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $handle = curl_init($url);
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
function expectBusiness(string $label, array $response, int $http = 200, int $code = 200): array
{
    if ($response['status'] !== $http || ($response['json']['code'] ?? null) !== $code) {
        $reason = $response['json']['msg'] ?? $response['json']['message'] ?? $response['json']['error'] ?? null;
        throw new RuntimeException(
            $label . ' failed: http=' . $response['status']
            . ', code=' . (string) ($response['json']['code'] ?? 'missing')
            . (is_string($reason) && $reason !== '' ? ', reason=' . $reason : '')
        );
    }
    return $response['json'];
}

function responseId(string $label, array $response): int
{
    $json = expectBusiness($label, $response);
    $id = $json['data']['id'] ?? null;
    if (!is_int($id) && !ctype_digit((string) $id)) throw new RuntimeException("{$label} did not return an id");
    $id = (int) $id;
    if ($id <= 0) throw new RuntimeException("{$label} returned an invalid id");
    return $id;
}

/** @return array<string,mixed> */
function adminPost(string $baseUrl, string $authorization, string $path, string $requestId, array $body): array
{
    return requestJson('POST', $baseUrl . $path, $body, [$authorization, 'X-Request-Id: ' . $requestId]);
}

function waitPort(string $host, int $port, bool $open): void
{
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.1);
        $isOpen = is_resource($socket);
        if ($isOpen) fclose($socket);
        if ($isOpen === $open) return;
        usleep(100_000);
    }
    throw new RuntimeException("port {$host}:{$port} did not become " . ($open ? 'open' : 'closed'));
}

/** @return resource */
function startStandalone(string $directory, array $environment)
{
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:8088', '-t', 'public', 'public/router.php'],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/private/tmp/sandiam-l04-standalone.log', 'a'],
            2 => ['file', '/private/tmp/sandiam-l04-standalone.log', 'a'],
        ],
        $pipes,
        $directory,
        $environment,
    );
    if (!is_resource($process)) throw new RuntimeException('standalone consumer could not start');
    try {
        waitPort('127.0.0.1', 8088, true);
    } catch (Throwable $exception) {
        proc_terminate($process);
        proc_close($process);
        throw $exception;
    }
    return $process;
}

function stopStandalone(mixed $process): void
{
    if (!is_resource($process)) return;
    proc_terminate($process);
    proc_close($process);
    waitPort('127.0.0.1', 8088, false);
}

/** @param array<string,list<int>> $ids @param array<string,list<string>> $requests */
function cleanupL04(string $baseUrl, string $authorization, string $prefix, int $organizationId, int $applicationId, int $identityId, array $ids, array $requests): void
{
    if ($ids === []) return;
    $requestId = $prefix . 'l04-cleanup';
    expectBusiness('L04 fixture cleanup', adminPost(
        $baseUrl,
        $authorization,
        '/app/sand-iam/admin/acceptance-fixture/cleanup',
        $requestId,
        [
            'chain_id' => 'non-ai-business-consumer',
            'request_id' => $requestId,
            'prefix' => $prefix,
            'confirmation' => FIXTURE_CONFIRM,
            'organization_id' => $organizationId,
            'application_id' => $applicationId,
            'identity_id' => $identityId,
            'object_ids' => $ids,
            'object_request_ids' => $requests,
        ],
    ));
}

function scalar(PDO $database, string $sql, array $params = []): mixed
{
    $statement = $database->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

/** @return array<string,mixed>|null */
function row(PDO $database, string $sql, array $params = []): ?array
{
    $statement = $database->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($value) ? $value : null;
}

$baseUrl = rtrim(requiredEnv('SAND_IAM_L04_BASE_URL'), '/');
$authorization = requiredEnv('SAND_IAM_L04_ADMIN_AUTHORIZATION');
$dsn = requiredEnv('SAND_IAM_L04_DATABASE_DSN');
$databaseUser = requiredEnv('SAND_IAM_L04_DATABASE_USER');
$databasePassword = (string) getenv('SAND_IAM_L04_DATABASE_PASSWORD');
$password = requiredEnv('SAND_IAM_L04_USER_PASSWORD');
$prefix = requiredEnv('SAND_IAM_L04_PREFIX');
$adminId = (int) requiredEnv('SAND_IAM_L04_ADMIN_ID');
$confirmation = requiredEnv('SAND_IAM_L04_CONFIRM');
if ($confirmation !== STANDALONE_CONFIRM) throw new RuntimeException('L04 confirmation mismatch');
if (preg_match('/^sand_iam_acceptance_[a-f0-9]{16}_$/', $prefix) !== 1) throw new RuntimeException('unsafe L04 prefix');
if (!str_starts_with($dsn, 'pgsql:')) throw new RuntimeException('L04 requires an existing PostgreSQL DSN');
if (!str_starts_with($authorization, 'Authorization: Bearer ')) throw new RuntimeException('invalid administrator authorization header');
if ($adminId <= 0) throw new RuntimeException('invalid administrator id');

$root = realpath(dirname(__DIR__));
$standalone = realpath(dirname(__DIR__) . '/examples/webman-business-app/standalone');
$schema = $standalone === false ? false : $standalone . '/schema.pgsql';
if ($root === false || $standalone === false || !is_file($schema)) throw new RuntimeException('standalone source is unavailable');

$database = new PDO($dsn, $databaseUser, $databasePassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if ((string) scalar($database, 'SELECT current_database()') !== 'sandadmin') {
    throw new RuntimeException('L04 refuses any database other than the authorized sandadmin database');
}
foreach (['standalone_work_item', 'standalone_business_audit'] as $table) {
    if (scalar($database, "SELECT to_regclass('public.{$table}')") !== null) {
        throw new RuntimeException("L04 refuses a pre-existing {$table} table");
    }
}

$organizationId = 0;
$applicationId = 0;
$authPolicyId = 0;
$identityId = 0;
$sessionId = 0;
$userToken = '';
$crossApplicationId = 0;
$crossAuthPolicyId = 0;
$crossIdentityId = 0;
$crossSessionId = 0;
$crossUserToken = '';
$l04Ids = [];
$l04Requests = [];
$businessTablesCreated = false;
$standaloneProcess = null;
$report = ['checks' => []];
$auditEvidence = [];
$primaryFailure = null;

$organizationCode = $prefix . 'org';
$applicationCode = $prefix . 'app';
$username = $prefix . 'user';
$crossPrefix = 'sand_iam_acceptance_' . bin2hex(random_bytes(8)) . '_';
$crossApplicationCode = $crossPrefix . 'app';
$crossUsername = $crossPrefix . 'user';
$readAction = $prefix . 'work_item.read';
$closeAction = $prefix . 'work_item.close';
$resourceCode = $prefix . 'work_item';
$readApiCode = $prefix . 'work_item.read';
$closeApiCode = $prefix . 'work_item.close';

try {
    $database->beginTransaction();
    $database->exec((string) file_get_contents($schema));
    $database->commit();
    $businessTablesCreated = true;

    $organizationRequest = $prefix . 'l04-org-create';
    $organizationId = responseId('organization create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/organization/save', $organizationRequest, [
        'code' => $organizationCode, 'name' => '独立业务应用验收客户主体',
    ]));
    $applicationRequest = $prefix . 'l04-app-create';
    $applicationId = responseId('application create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/application/save', $applicationRequest, [
        'organization_id' => $organizationId, 'code' => $applicationCode, 'name' => '独立非 AI 业务应用',
    ]));
    $authPolicyRequest = $prefix . 'l04-auth-policy';
    $authPolicyId = responseId('auth policy create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/auth-policy/save', $authPolicyRequest, [
        'application_id' => $applicationId,
        'registration_enabled' => 1,
        'password_min_length' => 12,
        'password_max_length' => 128,
        'require_uppercase' => 1,
        'require_lowercase' => 1,
        'require_digit' => 1,
        'require_symbol' => 1,
        'require_email_verification' => 2,
        'require_phone_verification' => 2,
        'require_captcha' => 2,
        'status' => 1,
    ]));
    $registrationRequest = $prefix . 'l04-register';
    $registration = expectBusiness('application user registration', requestJson('POST', $baseUrl . '/api/sand-iam/v1/auth/register', [
        'organization_code' => $organizationCode,
        'application_code' => $applicationCode,
        'username' => $username,
        'display_name' => '独立业务应用验收用户',
        'email' => $username . '@example.test',
        'password' => $password,
    ], ['X-Request-Id: ' . $registrationRequest]));
    $identityId = (int) ($registration['data']['identity']['id'] ?? 0);
    $sessionId = (int) ($registration['data']['session_id'] ?? 0);
    $userToken = (string) ($registration['data']['access_token'] ?? '');
    if ($identityId <= 0 || $sessionId <= 0 || $userToken === '') throw new RuntimeException('registration did not return identity/session/token');

    $crossApplicationRequest = $crossPrefix . 'l04-app-create';
    $crossApplicationId = responseId('cross-application create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/application/save', $crossApplicationRequest, [
        'organization_id' => $organizationId, 'code' => $crossApplicationCode, 'name' => '独立业务应用跨应用拒绝对照',
    ]));
    $crossAuthPolicyRequest = $crossPrefix . 'l04-auth-policy';
    $crossAuthPolicyId = responseId('cross-application auth policy create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/auth-policy/save', $crossAuthPolicyRequest, [
        'application_id' => $crossApplicationId,
        'registration_enabled' => 1,
        'password_min_length' => 12,
        'password_max_length' => 128,
        'require_uppercase' => 1,
        'require_lowercase' => 1,
        'require_digit' => 1,
        'require_symbol' => 1,
        'require_email_verification' => 2,
        'require_phone_verification' => 2,
        'require_captcha' => 2,
        'status' => 1,
    ]));
    $crossRegistrationRequest = $crossPrefix . 'l04-register';
    $crossRegistration = expectBusiness('cross-application user registration', requestJson('POST', $baseUrl . '/api/sand-iam/v1/auth/register', [
        'organization_code' => $organizationCode,
        'application_code' => $crossApplicationCode,
        'username' => $crossUsername,
        'display_name' => '跨应用拒绝对照用户',
        'email' => $crossUsername . '@example.test',
        'password' => $password,
    ], ['X-Request-Id: ' . $crossRegistrationRequest]));
    $crossIdentityId = (int) ($crossRegistration['data']['identity']['id'] ?? 0);
    $crossSessionId = (int) ($crossRegistration['data']['session_id'] ?? 0);
    $crossUserToken = (string) ($crossRegistration['data']['access_token'] ?? '');
    if ($crossIdentityId <= 0 || $crossSessionId <= 0 || $crossUserToken === '') {
        throw new RuntimeException('cross-application registration did not return identity/session/token');
    }

    foreach ([
        ['code' => $readAction, 'name' => '读取工作项'],
        ['code' => $closeAction, 'name' => '关闭工作项'],
    ] as $offset => $definition) {
        $requestId = $prefix . 'l04-action-' . ($offset + 1);
        $id = responseId('business action create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/application-business-action/save', $requestId, [
            'application_id' => $applicationId,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'state' => 'draft',
            'status' => 1,
        ]));
        $l04Ids['application_business_action'][] = $id;
        $l04Requests['application_business_action'][] = $requestId;
        expectBusiness('business action publish', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/application-business-action/publish', $prefix . 'l04-action-publish-' . ($offset + 1), ['id' => $id]));
    }

    $resourceRequest = $prefix . 'l04-resource';
    $resourceId = responseId('business resource create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/resource/save', $resourceRequest, [
        'application_id' => $applicationId,
        'code' => $resourceCode,
        'name' => '工作项',
        'owner_field' => 'owner_identity_id',
        'organization_field' => 'organization_id',
        'status' => 1,
    ]));
    $l04Ids['resource'] = [$resourceId];
    $l04Requests['resource'] = [$resourceRequest];

    $apiDefinitions = [
        ['code' => $readApiCode, 'name' => '读取工作项', 'action' => $readAction, 'operation' => 'read', 'method' => 'GET', 'route' => '/items/{id}'],
        ['code' => $closeApiCode, 'name' => '关闭工作项', 'action' => $closeAction, 'operation' => 'update', 'method' => 'POST', 'route' => '/items/{id}/close'],
    ];
    foreach ($apiDefinitions as $offset => $definition) {
        $apiRequest = $prefix . 'l04-api-' . ($offset + 1);
        $apiId = responseId('API resource create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/api-resource/save', $apiRequest, [
            'application_id' => $applicationId,
            'resource_id' => $resourceId,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'action' => $definition['action'],
            'operation' => $definition['operation'],
            'api_version' => 'v1',
            'audience' => $prefix . 'consumer',
            'risk_level' => $definition['operation'] === 'read' ? 'low' : 'medium',
            'status' => 1,
        ]));
        $l04Ids['api_resource'][] = $apiId;
        $l04Requests['api_resource'][] = $apiRequest;
        $routeRequest = $prefix . 'l04-route-' . ($offset + 1);
        $routeId = responseId('API route binding create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/api-route-binding/save', $routeRequest, [
            'application_id' => $applicationId,
            'api_resource_id' => $apiId,
            'http_method' => $definition['method'],
            'route_template' => $definition['route'],
            'source' => 'manual',
            'status' => 1,
        ]));
        $l04Ids['api_route_binding'][] = $routeId;
        $l04Requests['api_route_binding'][] = $routeRequest;
    }

    foreach ([
        ['action' => $readAction, 'name' => '读取工作项策略'],
        ['action' => $closeAction, 'name' => '关闭工作项策略'],
    ] as $offset => $definition) {
        $policyRequest = $prefix . 'l04-policy-' . ($offset + 1);
        $policyId = responseId('policy create', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/policy/save', $policyRequest, [
            'application_id' => $applicationId,
            'resource_id' => $resourceId,
            'identity_id' => $identityId,
            'action' => $definition['action'],
            'effect' => 'allow',
            'condition' => [],
            'scope' => ['equals' => ['organization_id' => $organizationId, 'owner_identity_id' => $identityId]],
            'priority' => 100,
            'state' => 'draft',
            'status' => 1,
        ]));
        $l04Ids['policy'][] = $policyId;
        $l04Requests['policy'][] = $policyRequest;
        expectBusiness('policy publish', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/policy/publish', $prefix . 'l04-policy-publish-' . ($offset + 1), ['id' => $policyId]));
    }

    $insert = $database->prepare('INSERT INTO standalone_work_item (organization_id, owner_identity_id) VALUES (:organization_id, :owner_identity_id) RETURNING id');
    $insert->execute(['organization_id' => $organizationId, 'owner_identity_id' => $identityId]);
    $allowedItemId = (int) $insert->fetchColumn();
    $insert->execute(['organization_id' => $organizationId, 'owner_identity_id' => $identityId + 1000000]);
    $deniedItemId = (int) $insert->fetchColumn();

    $environment = array_merge(getenv(), [
        'BUSINESS_DATABASE_DSN' => $dsn,
        'BUSINESS_DATABASE_USER' => $databaseUser,
        'BUSINESS_DATABASE_PASSWORD' => $databasePassword,
        'SAND_IAM_BASE_URL' => $baseUrl,
        'SAND_IAM_ORGANIZATION_CODE' => $organizationCode,
        'SAND_IAM_APPLICATION_CODE' => $applicationCode,
        'SAND_IAM_READ_API_CODE' => $readApiCode,
        'SAND_IAM_CLOSE_API_CODE' => $closeApiCode,
    ]);
    $standaloneProcess = startStandalone($standalone, $environment);
    $report['checks']['health'] = requestJson('GET', 'http://127.0.0.1:8088/health');
    if ($report['checks']['health']['status'] !== 200) throw new RuntimeException('standalone health failed');

    $userAuthorization = 'Authorization: Bearer ' . $userToken;
    $readRequest = $prefix . 'l04-read-allow';
    $read = requestJson('GET', "http://127.0.0.1:8088/items/{$allowedItemId}", null, [$userAuthorization, 'X-Request-Id: ' . $readRequest]);
    if ($read['status'] !== 200) throw new RuntimeException('allowed business read failed');
    $report['checks']['read_allow'] = $read['status'];

    $readDenyRequest = $prefix . 'l04-read-deny';
    $readDeny = requestJson('GET', "http://127.0.0.1:8088/items/{$deniedItemId}", null, [$userAuthorization, 'X-Request-Id: ' . $readDenyRequest]);
    if ($readDeny['status'] !== 403) throw new RuntimeException('out-of-scope business read was not denied');
    $report['checks']['read_scope_deny'] = $readDeny['status'];

    $closeRequest = $prefix . 'l04-close-allow';
    $close = requestJson('POST', "http://127.0.0.1:8088/items/{$allowedItemId}/close", null, [$userAuthorization, 'X-Request-Id: ' . $closeRequest]);
    if ($close['status'] !== 200 || ($close['json']['state'] ?? null) !== 'closed' || ($close['json']['version'] ?? null) !== 2) {
        throw new RuntimeException('allowed business close did not commit exactly once: ' . json_encode([
            'response' => $close,
            'persisted_state' => scalar($database, 'SELECT state FROM standalone_work_item WHERE id = :id', ['id' => $allowedItemId]),
            'persisted_version' => scalar($database, 'SELECT version FROM standalone_work_item WHERE id = :id', ['id' => $allowedItemId]),
            'business_audit_count' => scalar($database, 'SELECT count(*) FROM standalone_business_audit WHERE request_id = :request_id', ['request_id' => $closeRequest]),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    $report['checks']['close_allow'] = $close['status'];

    $closeDenyRequest = $prefix . 'l04-close-deny';
    $closeDeny = requestJson('POST', "http://127.0.0.1:8088/items/{$deniedItemId}/close", null, [$userAuthorization, 'X-Request-Id: ' . $closeDenyRequest]);
    if ($closeDeny['status'] !== 403 || scalar($database, 'SELECT state FROM standalone_work_item WHERE id = :id', ['id' => $deniedItemId]) !== 'open') {
        throw new RuntimeException('out-of-scope business close produced a side effect');
    }
    $report['checks']['close_scope_deny'] = $closeDeny['status'];

    $crossApplicationRequestId = $prefix . 'l04-cross-application-deny';
    $crossApplicationDeny = requestJson('GET', "http://127.0.0.1:8088/items/{$allowedItemId}", null, [
        'Authorization: Bearer ' . $crossUserToken,
        'X-Request-Id: ' . $crossApplicationRequestId,
    ]);
    if ($crossApplicationDeny['status'] !== 403
        || (int) scalar($database, 'SELECT count(*) FROM standalone_business_audit WHERE request_id = :request_id AND outcome = :outcome', [
            'request_id' => $crossApplicationRequestId, 'outcome' => 'denied',
        ]) !== 1
        || (int) scalar($database, 'SELECT count(*) FROM sand_iam_audit_log WHERE request_id = :request_id AND outcome = :outcome', [
            'request_id' => $crossApplicationRequestId, 'outcome' => 'denied',
        ]) < 1) {
        throw new RuntimeException('cross-application token was not rejected and audited before the business effect');
    }
    $report['checks']['cross_application_deny'] = $crossApplicationDeny['status'];

    $revokeRequest = $prefix . 'l04-session-revoke';
    expectBusiness('session revoke', requestJson('POST', $baseUrl . '/api/sand-iam/v1/auth/sessions/revoke', ['id' => $sessionId], [$userAuthorization, 'X-Request-Id: ' . $revokeRequest]));
    $revokedRequest = $prefix . 'l04-revoked-deny';
    $revoked = requestJson('GET', "http://127.0.0.1:8088/items/{$allowedItemId}", null, [$userAuthorization, 'X-Request-Id: ' . $revokedRequest]);
    if ($revoked['status'] !== 401) throw new RuntimeException('revoked application session still reached the business object');
    $report['checks']['revoked_session_deny'] = $revoked['status'];

    foreach ([$readRequest, $readDenyRequest, $closeRequest, $closeDenyRequest] as $requestId) {
        if ((int) scalar($database, 'SELECT count(*) FROM sand_iam_audit_log WHERE request_id = :request_id AND action LIKE :pattern', ['request_id' => $requestId, 'pattern' => 'authorize.%']) !== 1
            || (int) scalar($database, 'SELECT count(*) FROM sand_iam_audit_log WHERE request_id = :request_id AND action LIKE :pattern', ['request_id' => $requestId, 'pattern' => 'scope.%']) !== 1
            || (int) scalar($database, 'SELECT count(*) FROM standalone_business_audit WHERE request_id = :request_id', ['request_id' => $requestId]) !== 1) {
            throw new RuntimeException("dual audit evidence is incomplete for {$requestId}");
        }
        $businessAudit = row($database, 'SELECT request_id,action,outcome,reason_code,actor_ref AS actor_reference,work_item_id AS item_id FROM standalone_business_audit WHERE request_id = :request_id', ['request_id' => $requestId]);
        $iamAudits = [];
        $statement = $database->prepare('SELECT request_id,action,outcome,resource_type FROM sand_iam_audit_log WHERE request_id = :request_id ORDER BY id');
        $statement->execute(['request_id' => $requestId]);
        while (($audit = $statement->fetch(PDO::FETCH_ASSOC)) !== false) $iamAudits[] = $audit;
        if ($businessAudit === null || count($iamAudits) !== 2) throw new RuntimeException("dual audit evidence could not be exported for {$requestId}");
        $auditEvidence[] = ['business' => $businessAudit, 'iam' => $iamAudits];
    }
    if ((int) scalar($database, 'SELECT count(*) FROM standalone_business_audit WHERE request_id = :request_id AND outcome = :outcome', ['request_id' => $revokedRequest, 'outcome' => 'denied']) !== 1
        || (int) scalar($database, 'SELECT count(*) FROM sand_iam_audit_log WHERE request_id = :request_id AND action = :action AND outcome = :outcome', [
            'request_id' => $revokedRequest, 'action' => 'authorize.resolve', 'outcome' => 'denied',
        ]) !== 1) {
        throw new RuntimeException('revoked-session dual denial audit is missing');
    }
    $revokedBusinessAudit = row($database, 'SELECT request_id,action,outcome,reason_code,actor_ref AS actor_reference,work_item_id AS item_id FROM standalone_business_audit WHERE request_id = :request_id', ['request_id' => $revokedRequest]);
    $revokedIamAudit = row($database, 'SELECT request_id,action,outcome,resource_type FROM sand_iam_audit_log WHERE request_id = :request_id AND action = :action', [
        'request_id' => $revokedRequest, 'action' => 'authorize.resolve',
    ]);
    if ($revokedBusinessAudit === null || $revokedIamAudit === null) throw new RuntimeException('revoked-session dual audit evidence could not be exported');
    $auditEvidence[] = ['business' => $revokedBusinessAudit, 'iam' => [$revokedIamAudit]];
    $report['checks']['dual_audit_requests'] = 6;
    $crossBusinessAudit = row($database, 'SELECT request_id,action,outcome,reason_code,actor_ref AS actor_reference,work_item_id AS item_id FROM standalone_business_audit WHERE request_id = :request_id', ['request_id' => $crossApplicationRequestId]);
    $crossIamAudit = row($database, 'SELECT request_id,action,outcome,resource_type FROM sand_iam_audit_log WHERE request_id = :request_id AND outcome = :outcome ORDER BY id LIMIT 1', [
        'request_id' => $crossApplicationRequestId, 'outcome' => 'denied',
    ]);
    if ($crossBusinessAudit === null || $crossIamAudit === null) throw new RuntimeException('cross-application dual audit evidence could not be exported');
    $auditEvidence[] = ['business' => $crossBusinessAudit, 'iam' => [$crossIamAudit]];
} catch (Throwable $exception) {
    $primaryFailure = $exception;
} finally {
    try {
        stopStandalone($standaloneProcess);
    } catch (Throwable $exception) {
        $primaryFailure ??= $exception;
    }
    if ($sessionId > 0 && $userToken !== '') {
        try {
            $cleanupRevoke = requestJson(
                'POST',
                $baseUrl . '/api/sand-iam/v1/auth/sessions/revoke',
                ['id' => $sessionId],
                ['Authorization: Bearer ' . $userToken, 'X-Request-Id: ' . $prefix . 'l04-cleanup-session-revoke'],
            );
            if (!in_array($cleanupRevoke['status'], [200, 401], true)) {
                throw new RuntimeException('normal session revocation failed during L04 cleanup');
            }
        } catch (Throwable $exception) {
            $primaryFailure ??= $exception;
        }
    }
    if ($crossSessionId > 0 && $crossUserToken !== '') {
        try {
            $cleanupRevoke = requestJson(
                'POST',
                $baseUrl . '/api/sand-iam/v1/auth/sessions/revoke',
                ['id' => $crossSessionId],
                ['Authorization: Bearer ' . $crossUserToken, 'X-Request-Id: ' . $crossPrefix . 'l04-cleanup-session-revoke'],
            );
            if (!in_array($cleanupRevoke['status'], [200, 401], true)) {
                throw new RuntimeException('cross-application session revocation failed during L04 cleanup');
            }
        } catch (Throwable $exception) {
            $primaryFailure ??= $exception;
        }
    }
    if ($organizationId > 0 && $applicationId > 0 && $identityId > 0) {
        try {
            cleanupL04($baseUrl, $authorization, $prefix, $organizationId, $applicationId, $identityId, $l04Ids, $l04Requests);
        } catch (Throwable $exception) {
            $primaryFailure ??= $exception;
        }
    }
    if ($organizationId > 0 && $applicationId > 0 && $authPolicyId > 0) {
        try {
            $requestId = $prefix . 'l04-auth-cleanup';
            $ids = ['auth_policy' => [$authPolicyId]];
            $requests = ['auth_policy' => [$prefix . 'l04-auth-policy']];
            $sessionActions = [];
            if ($identityId > 0) {
                $ids['identity'] = [$identityId];
                $requests['identity'] = [$prefix . 'l04-register'];
            }
            if ($sessionId > 0) {
                $ids['auth_session'] = [$sessionId];
                $requests['auth_session'] = [$prefix . 'l04-register'];
                $sessionActions = ['identity.register'];
            }
            expectBusiness('auth fixture cleanup', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/acceptance-fixture/cleanup', $requestId, [
                'chain_id' => 'human-auth-session-mfa',
                'request_id' => $requestId,
                'prefix' => $prefix,
                'confirmation' => FIXTURE_CONFIRM,
                'organization_id' => $organizationId,
                'application_id' => $applicationId,
                'object_ids' => $ids,
                'object_request_ids' => $requests,
                'human_auth_registration_request_id' => $prefix . 'l04-register',
                'human_auth_action_request_ids' => [],
                'human_auth_session_actions' => $sessionActions,
                'partial_recovery' => true,
            ]));
        } catch (Throwable $exception) {
            $primaryFailure ??= $exception;
        }
    }
    if ($organizationId > 0 && $crossApplicationId > 0 && $crossAuthPolicyId > 0) {
        try {
            $requestId = $crossPrefix . 'l04-auth-cleanup';
            $ids = ['auth_policy' => [$crossAuthPolicyId]];
            $requests = ['auth_policy' => [$crossPrefix . 'l04-auth-policy']];
            $sessionActions = [];
            if ($crossIdentityId > 0) {
                $ids['identity'] = [$crossIdentityId];
                $requests['identity'] = [$crossPrefix . 'l04-register'];
            }
            if ($crossSessionId > 0) {
                $ids['auth_session'] = [$crossSessionId];
                $requests['auth_session'] = [$crossPrefix . 'l04-register'];
                $sessionActions = ['identity.register'];
            }
            expectBusiness('cross-application auth fixture cleanup', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/acceptance-fixture/cleanup', $requestId, [
                'chain_id' => 'human-auth-session-mfa',
                'request_id' => $requestId,
                'prefix' => $crossPrefix,
                'confirmation' => FIXTURE_CONFIRM,
                'organization_id' => $organizationId,
                'application_id' => $crossApplicationId,
                'object_ids' => $ids,
                'object_request_ids' => $requests,
                'human_auth_registration_request_id' => $crossPrefix . 'l04-register',
                'human_auth_action_request_ids' => [],
                'human_auth_session_actions' => $sessionActions,
                'partial_recovery' => true,
            ]));
        } catch (Throwable $exception) {
            $primaryFailure ??= $exception;
        }
    }
    if ($organizationId > 0) {
        try {
            foreach ([
                [$crossApplicationId, $crossPrefix . 'l04-app-disable', 'cross-application'],
                [$applicationId, $prefix . 'l04-app-disable', 'application'],
            ] as [$id, $requestId, $label]) {
                if ($id <= 0) continue;
                expectBusiness($label . ' disable', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/application/disable', $requestId, ['id' => $id]));
            }
            expectBusiness('organization disable', adminPost($baseUrl, $authorization, '/app/sand-iam/admin/organization/disable', $prefix . 'l04-org-disable', ['id' => $organizationId]));
            if ((int) scalar($database, 'SELECT count(*) FROM sand_iam_organization WHERE id = :id AND status = 2', ['id' => $organizationId]) !== 1
                || (int) scalar($database, 'SELECT count(*) FROM sand_iam_application WHERE id IN (:first, :second) AND status = 2', ['first' => $applicationId, 'second' => $crossApplicationId]) !== 2) {
                throw new RuntimeException('L04 root audit anchors were not retained disabled');
            }
            $report['checks']['retained_disabled_audit_anchors'] = 3;
        } catch (Throwable $exception) {
            $primaryFailure ??= $exception;
        }
    }
    if ($businessTablesCreated) {
        try {
            $database->beginTransaction();
            $database->exec('DROP TABLE standalone_business_audit');
            $database->exec('DROP TABLE standalone_work_item');
            $database->commit();
            if (scalar($database, "SELECT to_regclass('public.standalone_work_item')") !== null
                || scalar($database, "SELECT to_regclass('public.standalone_business_audit')") !== null) {
                throw new RuntimeException('standalone business tables remain after cleanup');
            }
            $report['checks']['business_tables_removed'] = true;
        } catch (Throwable $exception) {
            if ($database->inTransaction()) $database->rollBack();
            $primaryFailure ??= $exception;
        }
    }
}

if ($primaryFailure instanceof Throwable) {
    fwrite(STDERR, 'L04 live acceptance failed: ' . $primaryFailure->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode([
    'status' => 'passed',
    'prefix' => $prefix,
    'organization_id' => $organizationId,
    'application_id' => $applicationId,
    'identity_id' => $identityId,
    'checks' => $report['checks'],
    'audit_evidence' => $auditEvidence,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
