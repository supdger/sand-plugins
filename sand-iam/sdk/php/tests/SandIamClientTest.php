<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/SandIamException.php';
require dirname(__DIR__) . '/src/SandIamWorkloadErrorCode.php';
require dirname(__DIR__) . '/src/AuthorizationDenied.php';
require dirname(__DIR__) . '/src/SandIamClient.php';
require dirname(__DIR__) . '/src/ManagementDto.php';
require dirname(__DIR__) . '/src/SandIamManagementClient.php';

use Sand\Iam\Sdk\AuthorizationDenied;
use Sand\Iam\Sdk\SandIamClient;
use Sand\Iam\Sdk\SandIamException;
use Sand\Iam\Sdk\SandIamWorkloadErrorCode;
use Sand\Iam\Sdk\SandIamCredentialIssueInput;
use Sand\Iam\Sdk\SandIamCredentialRevokeInput;
use Sand\Iam\Sdk\SandIamManagementClient;
use Sand\Iam\Sdk\SandIamOnboardingOperation;
use Sand\Iam\Sdk\SandIamProviderPresetDraftInput;

function sdkAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

sdkAssert(SandIamWorkloadErrorCode::NETWORK_FORBIDDEN === 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN' && SandIamWorkloadErrorCode::IDEMPOTENCY_CONFLICT === 'SAND_IAM_IDEMPOTENCY_CONFLICT', 'workload error-code constants are incomplete');

/** @return array<string,mixed> */
function decision(bool $allowed): array
{
    return [
        'allowed' => $allowed,
        'code' => $allowed ? 'allowed' : 'SAND_IAM_POLICY_DENIED',
        'policy_ids' => $allowed ? [12] : [],
        'scope' => ['equals' => ['organization_id' => 42]],
        'application_id' => 3,
        'identity_id' => 101,
        'api_code' => 'matter.detail',
        'api_version' => 'v1',
        'resource_code' => 'matter',
        'action' => 'matter.read',
        'operation' => 'read',
        'risk_level' => 'medium',
    ];
}

$request = null;
$allowClient = new SandIamClient(
    'https://iam.example.test',
    'sand',
    'lawyer',
    3,
    static function (array $input) use (&$request): array {
        $request = $input;
        return ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => decision(true)], JSON_THROW_ON_ERROR)];
    },
);
$result = $allowClient->authorize('siam_at_test', 'matter.detail', ['organization_id' => 42], 'v1', 'request-1');
sdkAssert($result['allowed'] === true && $result['scope']['equals']['organization_id'] === 42, 'allow decision was not returned');
sdkAssert(is_array($request) && ($request['headers']['Authorization'] ?? '') === 'Bearer siam_at_test', 'access token header missing');
sdkAssert(($request['headers']['X-Request-Id'] ?? '') === 'request-1', 'request ID was not forwarded');
$body = json_decode((string) ($request['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
sdkAssert(($body['organization_code'] ?? '') === 'sand' && ($body['application_code'] ?? '') === 'lawyer' && ($body['api_code'] ?? '') === 'matter.detail', 'request body mapping is incorrect');

$matter = (object) ['organization_id' => 42, 'owner_identity_id' => 101];
$allowClient->authorizeEntity(
    'siam_at_test',
    'matter.detail',
    $matter,
    static fn (object $entity): array => ['organization_id' => $entity->organization_id, 'owner_identity_id' => $entity->owner_identity_id],
    [],
    'v1',
    'request-entity',
);
try {
    $allowClient->authorizeCollection(
        'siam_at_test',
        'matter.detail',
        [$matter, (object) ['organization_id' => 43, 'owner_identity_id' => 101]],
        static fn (object $entity): array => ['organization_id' => $entity->organization_id, 'owner_identity_id' => $entity->owner_identity_id],
        [],
        'v1',
        'request-batch',
    );
    throw new RuntimeException('partially unauthorized SDK batch did not throw');
} catch (AuthorizationDenied $exception) {
    sdkAssert($exception->errorCode === 'SAND_IAM_RESOURCE_SCOPE_DENIED', 'SDK entity scope denial did not use the stable code');
}

$denyClient = new SandIamClient(
    'https://iam.example.test',
    'sand',
    'lawyer',
    3,
    static fn (): array => ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => decision(false)], JSON_THROW_ON_ERROR)],
);
try {
    $denyClient->authorize('siam_at_test', 'matter.detail');
    throw new RuntimeException('deny decision did not throw');
} catch (AuthorizationDenied $exception) {
    sdkAssert($exception->errorCode === 'SAND_IAM_POLICY_DENIED' && $exception->httpStatus === 403, 'deny error mapping is incorrect');
}

$errorClient = new SandIamClient(
    'https://iam.example.test',
    'sand',
    'lawyer',
    3,
    static fn (): array => ['status' => 401, 'body' => json_encode(['msg' => 'SAND_IAM_AUTHENTICATION_FAILED: 登录状态已失效'], JSON_THROW_ON_ERROR)],
);
try {
    $errorClient->decide('expired', 'matter.detail');
    throw new RuntimeException('HTTP error did not throw');
} catch (SandIamException $exception) {
    sdkAssert($exception->errorCode === 'SAND_IAM_AUTHENTICATION_FAILED' && $exception->httpStatus === 401, 'HTTP error mapping is incorrect');
}

$invalidClient = new SandIamClient(
    'https://iam.example.test',
    'sand',
    'lawyer',
    3,
    static fn (): array => ['status' => 200, 'body' => '{"data":{"allowed":true}}'],
);
try {
    $invalidClient->decide('siam_at_test', 'matter.detail');
    throw new RuntimeException('invalid response did not throw');
} catch (SandIamException $exception) {
    sdkAssert($exception->errorCode === 'SAND_IAM_SDK_INVALID_RESPONSE', 'invalid response mapping is incorrect');
}

$authRequests = [];
$authClient = new SandIamClient(
    'https://iam.example.test',
    'sand',
    'lawyer',
    3,
    static function (array $input) use (&$authRequests): array {
        $authRequests[] = $input;
        $path = (string) parse_url((string) $input['url'], PHP_URL_PATH);
        $data = match ($path) {
            '/api/sand-iam/v1/auth/login' => ['access_token' => 'siam_at_login', 'refresh_token' => 'siam_rt_login', 'identity' => ['id' => 9, 'display_name' => '测试用户']],
            '/api/sand-iam/v1/me/profile' => ['identity_id' => 9, 'display_name' => '测试用户', 'organization' => ['code' => 'sand', 'name' => 'Sand'], 'application' => ['code' => 'lawyer', 'name' => '律序']],
            '/api/sand-iam/v1/auth/sessions' => [['id' => 20, 'current' => true]],
            default => 'ok',
        };
        return ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => $data], JSON_THROW_ON_ERROR)];
    },
);
$login = $authClient->login('lawyer@example.test', 'secret-password', 'php-sdk-test', 'sdk-login');
sdkAssert(($login['access_token'] ?? '') === 'siam_at_login', 'login response was not returned');
sdkAssert(($authRequests[0]['method'] ?? '') === 'POST' && !isset($authRequests[0]['headers']['Authorization']), 'login request method or anonymous header is incorrect');
$loginBody = json_decode((string) $authRequests[0]['body'], true, 32, JSON_THROW_ON_ERROR);
sdkAssert(($loginBody['organization_code'] ?? '') === 'sand' && ($loginBody['application_code'] ?? '') === 'lawyer', 'login did not bind the configured application');
$profile = $authClient->profile('siam_at_login', 'sdk-profile');
sdkAssert(($profile['display_name'] ?? '') === '测试用户' && ($authRequests[1]['method'] ?? '') === 'GET' && $authRequests[1]['body'] === null, 'profile request or response is incorrect');
$sessions = $authClient->sessions('siam_at_login', 'sdk-sessions');
sdkAssert(count($sessions) === 1 && ($sessions[0]['current'] ?? false) === true, 'session list was not returned');
$authClient->changePassword('siam_at_login', 'old-password', 'new-password', 'sdk-password');
$passwordRequest = $authRequests[3];
$passwordBody = json_decode((string) $passwordRequest['body'], true, 32, JSON_THROW_ON_ERROR);
sdkAssert(($passwordRequest['headers']['Authorization'] ?? '') === 'Bearer siam_at_login' && ($passwordBody['current_password'] ?? '') === 'old-password', 'password change did not use the authenticated application session');

$workloadRequests = [];
$workloadClient = new SandIamClient(
    'https://iam.example.test', 'sand', 'lawyer', 3,
    static function (array $input) use (&$workloadRequests): array {
        $workloadRequests[] = $input;
        $path = (string) parse_url((string) $input['url'], PHP_URL_PATH);
        $data = $path === '/app/sand-iam/runtime/context/issue'
            ? ['context' => 'signed-context', 'context_id' => 'ctx-1', 'expire_time' => '2026-08-22 12:00:00']
            : ['context_id' => 'ctx-1', 'service_code' => 'sand-ai', 'audience' => 'sand-ai', 'actions' => ['inference.chat', 'inference.embed']];
        return ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => $data], JSON_THROW_ON_ERROR)];
    },
);
$issued = $workloadClient->issueContext('siam_wc_secret', 'sand-ai', 'sand-ai', ['inference.chat'], ['case_id' => 1], 'workload-issue-1');
sdkAssert($issued['context'] === 'signed-context', 'workload context was not returned');
$issueRequest = $workloadRequests[0];
$issueBody = json_decode((string) $issueRequest['body'], true, 32, JSON_THROW_ON_ERROR);
sdkAssert(($issueRequest['headers']['Authorization'] ?? '') === 'Bearer siam_wc_secret' && ($issueRequest['headers']['Cache-Control'] ?? '') === 'no-store', 'workload credential or no-store header is missing');
sdkAssert(!str_contains((string) $issueRequest['body'], 'siam_wc_secret') && !isset($issueBody['organization_id'], $issueBody['application_id'], $issueBody['environment_id'], $issueBody['workload_client_id']), 'workload request leaked credential or caller-controlled scope IDs');
$claims = $workloadClient->verifyContext('signed-context', 'sand-ai', 'sand-ai', ['inference.chat', 'inference.embed'], '127.0.0.1', 'workload-verify-1');
sdkAssert(($claims['context_id'] ?? '') === 'ctx-1' && count($workloadRequests) === 3, 'workload verify did not verify every action');
foreach (array_slice($workloadRequests, 1) as $verifyRequest) {
    $verifyBody = json_decode((string) $verifyRequest['body'], true, 32, JSON_THROW_ON_ERROR);
    sdkAssert(!isset($verifyRequest['headers']['Authorization']) && !isset($verifyBody['source_ip']), 'verify must not send context as Bearer or caller-supplied source IP');
}

foreach (['inference.chat', ['inference.chat' => true], ['inference.chat', 1], ['INFERENCE.CHAT']] as $malformedActions) {
    $malformedClient = new SandIamClient(
        'https://iam.example.test', 'sand', 'lawyer', 3,
        static fn (): array => ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => ['context_id' => 'ctx-1', 'service_code' => 'sand-ai', 'audience' => 'sand-ai', 'actions' => $malformedActions]], JSON_THROW_ON_ERROR)],
    );
    try {
        $malformedClient->verifyContext('signed-context', 'sand-ai', 'sand-ai', ['inference.chat'], null, 'workload-invalid-actions');
        throw new RuntimeException('malformed workload actions did not throw');
    } catch (SandIamException $exception) {
        sdkAssert($exception->errorCode === 'SAND_IAM_SDK_INVALID_RESPONSE', 'malformed workload actions did not use the stable invalid-response code');
    }
}

$managementRequests = [];
$management = new SandIamManagementClient(
    'https://iam.example.test',
    static fn (): string => 'sandadmin-session-token',
    3,
    static function (array $input) use (&$managementRequests): array {
        $managementRequests[] = $input;
        $path = (string) parse_url((string) $input['url'], PHP_URL_PATH);
        $data = match ($path) {
            '/app/sand-iam/admin/identity-provider-preset/index' => [['code' => 'github_oauth2', 'name' => 'GitHub OAuth 应用']],
            '/app/sand-iam/admin/credential/issue' => ['id' => 7, 'key_prefix' => 'siam_wc_', 'credential' => 'one-time-credential'],
            '/app/sand-iam/admin/credential/revoke' => ['id' => 7],
            default => ['preview_hash' => 'preview-1', 'route_sync' => ['created' => 1]],
        };
        return ['status' => 200, 'body' => json_encode(['code' => 200, 'data' => $data], JSON_THROW_ON_ERROR)];
    },
);
$preview = $management->routeSyncPreview(['operation_id' => 'route-sync-op-1', 'route_manifest' => ['format' => 'sand-iam.route-sync/v1']], 'management-preview-1');
sdkAssert(($preview['preview_hash'] ?? '') === 'preview-1', 'management preview response was not returned');
$applied = $management->routeSyncApply(new SandIamOnboardingOperation(['operation_id' => 'route-sync-op-1', 'route_manifest' => ['format' => 'sand-iam.route-sync/v1']], 'management-apply-1', 'preview-1'));
sdkAssert(($applied['route_sync']['created'] ?? 0) === 1, 'route sync apply did not use onboarding apply');
$issuedCredential = $management->credentialIssue(new SandIamCredentialIssueInput(7, 'production', null, 'credential-issue-1'));
sdkAssert($issuedCredential->secretAvailable === true && $issuedCredential->replayed === false, 'one-time credential state is incorrect');
$secret = $issuedCredential->revealSecretOnce();
sdkAssert($secret === 'one-time-credential', 'one-time credential was not available at the handoff boundary');
try { (string) $issuedCredential; throw new RuntimeException('credential result unexpectedly stringified'); } catch (Throwable $exception) { sdkAssert(str_contains($exception->getMessage(), '禁止'), 'credential result stringification did not fail closed'); }
$management->credentialRevoke(new SandIamCredentialRevokeInput(7, 'credential-revoke-1'));
$presets = $management->providerPresetList('preset-list-1');
sdkAssert(($presets[0]['code'] ?? '') === 'github_oauth2', 'preset list was not returned');
$draft = $management->providerPresetDraft(new SandIamProviderPresetDraftInput('github_oauth2', 'client-id', 'https://iam.example.test/callback', ['https://app.example.test/complete'], null, 'preset-draft-1'));
sdkAssert(($draft['preview_hash'] ?? '') === 'preview-1', 'preset draft was not returned');
foreach ($managementRequests as $request) {
    sdkAssert(($request['headers']['Authorization'] ?? '') === 'Bearer sandadmin-session-token' && ($request['headers']['Cache-Control'] ?? '') === 'no-store', 'management request lacks administrator Bearer or no-store');
    sdkAssert(!str_contains((string) ($request['body'] ?? ''), 'sandadmin-session-token'), 'management session token leaked into request body');
}
try {
    $management->credentialRevoke(new SandIamCredentialRevokeInput(7, 'short'));
    throw new RuntimeException('destructive management operation accepted implicit request id');
} catch (SandIamException $exception) {
    sdkAssert($exception->errorCode === 'SAND_IAM_SDK_REQUEST_ID_REQUIRED', 'missing management request id did not have a stable code');
}

echo "SandIAM PHP SDK tests passed\n";
