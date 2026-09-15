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

$verificationRequests = [];
$verificationStatus = 200;
$verificationClient = new SandIamClient('https://iam.example.test', 'sand', 'app', 3,
    static function (array $request) use (&$verificationRequests, &$verificationStatus): array {
        $verificationRequests[] = $request;
        return ['status' => $verificationStatus, 'body' => json_encode($verificationStatus === 200
            ? ['code' => 200, 'data' => '如账号存在，验证码已发送']
            : ['msg' => 'SAND_IAM_AUTH_VERIFICATION_INVALID'], JSON_THROW_ON_ERROR)];
    });
foreach (['email', 'phone'] as $channel) {
    sdkAssert($verificationClient->requestVerification('user', $channel, 'verify-request-01') === null, 'verification request disclosed account state');
    sdkAssert($verificationClient->confirmVerification('user', $channel, '123456', 'verify-confirm-01') === null, 'verification created session');
}
sdkAssert(count($verificationRequests) === 4, 'verification made extra requests');
foreach ($verificationRequests as $index => $request) {
    $body = json_decode($request['body'], true, 32, JSON_THROW_ON_ERROR);
    $confirm = $index % 2 === 1;
    $channel = $index < 2 ? 'email' : 'phone';
    sdkAssert(str_ends_with($request['url'], $confirm ? '/verification/confirm' : '/verification/request') && $request['method'] === 'POST', 'verification route mismatch');
    sdkAssert($body['channel'] === $channel && $body['purpose'] === $channel . '_verify', 'verification purpose mismatches channel');
    sdkAssert($body['organization_code'] === 'sand' && $body['application_code'] === 'app', 'verification scope lost');
    sdkAssert(!isset($request['headers']['Authorization']) && $request['headers']['X-Request-Id'] === ($confirm ? 'verify-confirm-01' : 'verify-request-01'), 'verification auth or request ID incorrect');
    if ($confirm) sdkAssert($body['code'] === '123456', 'verification code lost');
}
$verificationStatus = 400;
try {
    $verificationClient->confirmVerification('user', 'email', 'wrong');
    throw new RuntimeException('verification rejection ignored');
} catch (SandIamException $error) {
    sdkAssert($error->errorCode === 'SAND_IAM_AUTH_VERIFICATION_INVALID', 'verification error code lost');
}
$beforeInvalidVerification = count($verificationRequests);
foreach ([
    static fn () => $verificationClient->requestVerification('', 'email'),
    static fn () => $verificationClient->requestVerification('user', 'password_reset'),
    static fn () => $verificationClient->confirmVerification('user', 'phone', ''),
] as $request) {
    try { $request(); throw new RuntimeException('invalid verification accepted'); }
    catch (SandIamException $error) { sdkAssert($error->errorCode === 'SAND_IAM_SDK_INVALID_ARGUMENT', 'verification argument error mismatch'); }
}
sdkAssert(count($verificationRequests) === $beforeInvalidVerification, 'invalid verification reached transport');

$recoveryRequests = [];
$recoveryStatus = 200;
$recoveryClient = new SandIamClient('https://iam.example.test', 'sand', 'app', 3,
    static function (array $request) use (&$recoveryRequests, &$recoveryStatus): array {
        $recoveryRequests[] = $request;
        return ['status' => $recoveryStatus, 'body' => json_encode($recoveryStatus === 200
            ? ['code' => 200, 'data' => '如账号存在，重置验证码已发送']
            : ['msg' => 'SAND_IAM_AUTH_VERIFICATION_INVALID'], JSON_THROW_ON_ERROR)];
    });
foreach (['email', 'phone'] as $channel) {
    sdkAssert($recoveryClient->forgotPassword('user', $channel, 'recovery-request-01') === null, 'forgot password leaked account result');
    sdkAssert($recoveryClient->resetPassword('user', $channel, '123456', 'new-password', 'recovery-request-02') === null, 'reset password returned a login');
}
sdkAssert(count($recoveryRequests) === 4, 'password recovery triggered an extra request');
foreach ($recoveryRequests as $index => $request) {
    $body = json_decode($request['body'], true, 32, JSON_THROW_ON_ERROR);
    $reset = $index % 2 === 1;
    sdkAssert($request['method'] === 'POST' && str_ends_with($request['url'], $reset ? '/password/reset' : '/password/forgot'), 'password recovery endpoint mismatch');
    sdkAssert(!isset($request['headers']['Authorization']), 'password recovery used a session');
    sdkAssert($request['headers']['X-Request-Id'] === ($reset ? 'recovery-request-02' : 'recovery-request-01'), 'password recovery request ID lost');
    sdkAssert($body['organization_code'] === 'sand' && $body['application_code'] === 'app' && $body['identifier'] === 'user', 'password recovery app binding lost');
    sdkAssert($body['channel'] === ($index < 2 ? 'email' : 'phone'), 'password recovery channel mismatch');
    sdkAssert(!isset($body['new_password']), 'password reset used wrong field');
    if ($reset) sdkAssert($body['code'] === '123456' && $body['password'] === 'new-password', 'password reset fields lost');
}
$recoveryStatus = 400;
try {
    $recoveryClient->resetPassword('user', 'email', 'wrong', 'new-password');
    throw new RuntimeException('password recovery failure accepted');
} catch (SandIamException $error) {
    sdkAssert($error->errorCode === 'SAND_IAM_AUTH_VERIFICATION_INVALID', 'recovery error code lost');
}
$beforeInvalidRecovery = count($recoveryRequests);
foreach ([
    static fn () => $recoveryClient->forgotPassword('', 'email'),
    static fn () => $recoveryClient->forgotPassword('user', 'sms'),
    static fn () => $recoveryClient->resetPassword('user', 'phone', '', 'password'),
    static fn () => $recoveryClient->resetPassword('user', 'phone', 'code', ''),
] as $request) {
    try { $request(); throw new RuntimeException('invalid recovery input accepted'); }
    catch (SandIamException $error) { sdkAssert($error->errorCode === 'SAND_IAM_SDK_INVALID_ARGUMENT', 'recovery input error mismatch'); }
}
sdkAssert(count($recoveryRequests) === $beforeInvalidRecovery, 'invalid recovery input reached transport');

$mfaRequests = [];
$mfaResponse = ['access_token' => 'session-token', 'session_id' => 9];
$mfaStatus = 200;
$mfaClient = new SandIamClient('https://iam.example.test', 'sand', 'app', 3,
    static function (array $request) use (&$mfaRequests, &$mfaResponse, &$mfaStatus): array {
        $mfaRequests[] = $request;
        return ['status' => $mfaStatus, 'body' => json_encode($mfaStatus === 200
            ? ['code' => 200, 'data' => $mfaResponse]
            : ['msg' => 'SAND_IAM_MFA_CHALLENGE_INVALID: expired'], JSON_THROW_ON_ERROR)];
    });
foreach ([
    ['method' => 'totp', 'code' => '123456'],
    ['method' => 'recovery_code', 'code' => 'recovery-proof'],
    ['method' => 'passkey', 'rawId' => 'credential-id', 'response' => [
        'clientDataJSON' => 'client-data', 'authenticatorData' => 'auth-data', 'signature' => 'signature',
    ]],
] as $input) {
    $result = $mfaClient->verifyMfaChallenge($input + ['challenge_token' => 'challenge',
        'organization_code' => 'other', 'application_code' => 'other'], 'mfa-request-001');
    $request = $mfaRequests[array_key_last($mfaRequests)];
    $body = json_decode($request['body'], true, 32, JSON_THROW_ON_ERROR);
    sdkAssert($result === $mfaResponse, 'MFA session result changed');
    sdkAssert($request['method'] === 'POST' && str_ends_with($request['url'], '/auth/mfa/challenge/verify'), 'MFA endpoint mismatch');
    sdkAssert(!isset($request['headers']['Authorization']) && $request['headers']['X-Request-Id'] === 'mfa-request-001', 'MFA auth or request ID mismatch');
    sdkAssert($body['organization_code'] === 'sand' && $body['application_code'] === 'app', 'MFA application binding overwritten');
    foreach ($input as $key => $value) sdkAssert($body[$key] === $value, 'MFA response field lost: ' . $key);
}
$mfaResponse = ['step_up' => true, 'expires_in' => 300];
sdkAssert($mfaClient->verifyMfaChallenge(['challenge_token' => 'step-up', 'method' => 'totp', 'code' => '123456']) === $mfaResponse, 'MFA step-up result lost');
$mfaStatus = 401;
try {
    $mfaClient->verifyMfaChallenge(['challenge_token' => 'expired', 'method' => 'totp', 'code' => '123456']);
    throw new RuntimeException('MFA denial accepted');
} catch (SandIamException $error) {
    sdkAssert($error->errorCode === 'SAND_IAM_MFA_CHALLENGE_INVALID', 'MFA error code lost');
}
$beforeInvalidMfa = count($mfaRequests);
foreach ([['challenge_token' => '', 'method' => 'totp'], ['challenge_token' => 'challenge', 'method' => 'unknown']] as $input) {
    try {
        $mfaClient->verifyMfaChallenge($input);
        throw new RuntimeException('invalid MFA input accepted');
    } catch (SandIamException $error) {
        sdkAssert($error->errorCode === 'SAND_IAM_SDK_INVALID_ARGUMENT', 'MFA argument error mismatch');
    }
}
sdkAssert(count($mfaRequests) === $beforeInvalidMfa, 'invalid MFA input reached transport');

foreach ([
    'http://localhost.example.com', 'http://127.0.0.1.example.com',
    'http://localhost@remote.example.com', 'http://127.0.0.12',
    'https://user:password@iam.example.com', 'https://iam.example.com?target=x',
    'https://iam.example.com#fragment', 'https://', "https://iam.example.com\n",
    'http://localhost\\@remote.example.com',
] as $invalidUrl) {
    foreach ([
        static fn () => new SandIamClient($invalidUrl, 'sand', 'app'),
        static fn () => new SandIamManagementClient($invalidUrl, static fn () => 'token'),
    ] as $construct) {
        $rejected = false;
        try { $construct(); } catch (\InvalidArgumentException | SandIamException) { $rejected = true; }
        sdkAssert($rejected, 'unsafe SDK base URL accepted: ' . $invalidUrl);
    }
}
foreach (['https://iam.example.com/prefix', 'http://localhost:8080', 'http://127.0.0.1:8080', 'http://[::1]:8080'] as $validUrl) {
    new SandIamClient($validUrl, 'sand', 'app');
    new SandIamManagementClient($validUrl, static fn () => 'token');
}

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
$login = $authClient->login('lawyer@example.test', 'secret-password', 'php-sdk-test', 'sdk-login', 'captcha-login-proof');
sdkAssert(($login['access_token'] ?? '') === 'siam_at_login', 'login response was not returned');
sdkAssert(($authRequests[0]['method'] ?? '') === 'POST' && !isset($authRequests[0]['headers']['Authorization']), 'login request method or anonymous header is incorrect');
$loginBody = json_decode((string) $authRequests[0]['body'], true, 32, JSON_THROW_ON_ERROR);
sdkAssert($loginBody['captcha_token'] === 'captcha-login-proof', 'login captcha token was omitted');
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

$mfaRequests = [];
$mfaStatus = 200;
$mfaReplay = false;
$mfaClient = new SandIamClient('https://iam.example.test', 'example', 'business', 3,
    static function (array $input) use (&$mfaRequests, &$mfaStatus, &$mfaReplay): array {
        $mfaRequests[] = $input;
        $path = parse_url($input['url'], PHP_URL_PATH);
        $data = match ($path) {
            '/api/sand-iam/v1/auth/mfa/factors' => [['id' => 9, 'type' => 'passkey', 'name' => '电脑', 'status' => 1]],
            '/api/sand-iam/v1/auth/mfa/totp/start' => ['factor_id' => 8, 'secret' => 'fixture-secret', 'otpauth_uri' => 'otpauth://totp/fixture'],
            '/api/sand-iam/v1/auth/mfa/totp/confirm' => ['enabled' => true, 'recovery_codes' => ['fixture-code']],
            '/api/sand-iam/v1/auth/mfa/recovery/regenerate' => ['recovery_codes' => ['replacement-code']],
            default => '完成',
        };
        if ($mfaReplay && is_array($data)) {
            unset($data['secret'], $data['otpauth_uri'], $data['recovery_codes']);
            $data['secret_available'] = false;
        }
        return ['status' => $mfaStatus, 'body' => json_encode(
            $mfaStatus === 200 ? ['code' => 200, 'data' => $data] : ['msg' => 'SAND_IAM_AUTH_CURRENT_PASSWORD_INVALID'],
            JSON_THROW_ON_ERROR,
        )];
    });
sdkAssert($mfaClient->mfaFactors('user-token', 'mfa-list-1')[0]['type'] === 'passkey', 'MFA list lost factor type');
sdkAssert($mfaClient->startTotp('user-token', '手机', 'password', 'mfa-start-1')['secret'] === 'fixture-secret', 'TOTP enrollment secret missing');
sdkAssert($mfaClient->confirmTotp('user-token', 8, '123456', 'mfa-confirm-1')['enabled'] === true, 'TOTP confirmation missing');
$mfaClient->renameMfaFactor('user-token', 9, '新电脑', 'passkey', 'mfa-rename-1');
$mfaClient->revokeMfaFactor('user-token', 9, 'password', 'passkey', 'mfa-revoke-1');
sdkAssert($mfaClient->regenerateRecoveryCodes('user-token', 'password', 'mfa-recovery-1')['recovery_codes'] === ['replacement-code'], 'replacement codes missing');
$expectedMfaBodies = [null, ['name' => '手机', 'current_password' => 'password'], ['factor_id' => 8, 'code' => '123456'],
    ['factor_id' => 9, 'name' => '新电脑', 'type' => 'passkey'], ['factor_id' => 9, 'password' => 'password', 'type' => 'passkey'], ['password' => 'password']];
$expectedMfaPaths = ['factors', 'totp/start', 'totp/confirm', 'factors/rename', 'factors/revoke', 'recovery/regenerate'];
foreach ($mfaRequests as $index => $request) {
    sdkAssert(parse_url($request['url'], PHP_URL_PATH) === '/api/sand-iam/v1/auth/mfa/' . $expectedMfaPaths[$index], 'MFA endpoint incorrect');
    sdkAssert(($request['headers']['Authorization'] ?? '') === 'Bearer user-token', 'MFA call must authenticate current user');
    sdkAssert(str_starts_with($request['headers']['X-Request-Id'] ?? '', 'mfa-'), 'MFA request id missing');
    sdkAssert(!str_contains($request['url'] . ($request['body'] ?? ''), 'user-token'), 'MFA token leaked outside header');
    sdkAssert(($request['method'] ?? '') === ($index === 0 ? 'GET' : 'POST'), 'MFA HTTP method incorrect');
    sdkAssert(($request['body'] === null ? null : json_decode($request['body'], true)) === $expectedMfaBodies[$index], 'MFA request fields incorrect');
}
foreach ([
    fn () => $mfaClient->mfaFactors(''),
    fn () => $mfaClient->startTotp('user-token', '', ''),
    fn () => $mfaClient->confirmTotp('user-token', 0, '123456'),
    fn () => $mfaClient->confirmTotp('user-token', 8, ''),
    fn () => $mfaClient->renameMfaFactor('user-token', 9, 'name', 'unknown'),
    fn () => $mfaClient->revokeMfaFactor('user-token', 9, ''),
    fn () => $mfaClient->regenerateRecoveryCodes('user-token', ''),
] as $invalidMfaCall) {
    try { $invalidMfaCall(); throw new RuntimeException('invalid MFA input accepted'); }
    catch (SandIamException $exception) {
        sdkAssert(in_array($exception->errorCode, ['SAND_IAM_SDK_INVALID_ARGUMENT', 'SAND_IAM_AUTHENTICATION_FAILED'], true), 'MFA input error lost');
    }
}
sdkAssert(count($mfaRequests) === 6, 'invalid MFA input reached transport');
$mfaStatus = 401;
try {
    $mfaClient->regenerateRecoveryCodes('user-token', 'wrong-password', 'mfa-error-1');
    throw new RuntimeException('MFA failure accepted');
} catch (SandIamException $exception) {
    sdkAssert($exception->errorCode === 'SAND_IAM_AUTH_CURRENT_PASSWORD_INVALID', 'MFA error code lost');
}
sdkAssert(count($mfaRequests) === 7, 'MFA operation automatically retried');
$mfaStatus = 200;
$mfaReplay = true;
foreach ([
    $mfaClient->startTotp('user-token', '手机', 'password', 'mfa-start-1'),
    $mfaClient->confirmTotp('user-token', 8, '123456', 'mfa-confirm-1'),
    $mfaClient->regenerateRecoveryCodes('user-token', 'password', 'mfa-recovery-1'),
] as $replayedMfaResult) {
    sdkAssert(($replayedMfaResult['secret_available'] ?? null) === false
        && !isset($replayedMfaResult['secret']) && !isset($replayedMfaResult['otpauth_uri'])
        && !isset($replayedMfaResult['recovery_codes']), 'MFA replay invented a secret');
}

$passkeyRequests = [];
$passkeyStatus = 200;
$passkeyClient = new SandIamClient('https://iam.example.test', 'example', 'business', 3,
    static function (array $input) use (&$passkeyRequests, &$passkeyStatus): array {
        $passkeyRequests[] = $input;
        $path = parse_url($input['url'], PHP_URL_PATH);
        $data = str_ends_with($path, '/options')
            ? ['challenge_token' => 'fixture-challenge', 'public_key' => ['challenge' => 'base64url-challenge', 'timeout' => 300000]]
            : (str_contains($path, '/registration/') ? '通行密钥已添加' : ['access_token' => 'new-session', 'expires_in' => 300]);
        return ['status' => $passkeyStatus, 'body' => json_encode($passkeyStatus === 200
            ? ['code' => 200, 'data' => $data] : ['msg' => 'SAND_IAM_PASSKEY_SIGNATURE_INVALID'], JSON_THROW_ON_ERROR)];
    });
$attestation = ['clientDataJSON' => 'client-data', 'attestationObject' => 'attestation'];
$assertion = ['clientDataJSON' => 'client-data', 'authenticatorData' => 'auth-data', 'signature' => 'signature', 'userHandle' => 'user-handle'];
sdkAssert($passkeyClient->passkeyRegistrationOptions('session', '电脑', 'password', 'passkey-register-options')['public_key']['challenge'] === 'base64url-challenge', 'registration options lost');
$passkeyClient->passkeyRegistrationFinish('session', 'fixture-challenge', 'raw-id', $attestation, 'passkey-register-finish');
sdkAssert($passkeyClient->passkeyAuthenticationOptions('passkey-login-options')['challenge_token'] === 'fixture-challenge', 'authentication challenge lost');
sdkAssert($passkeyClient->passkeyAuthenticationFinish('fixture-challenge', 'raw-id', $assertion, 'platform-agent', 'passkey-login-finish')['access_token'] === 'new-session', 'Passkey login result lost');
$passkeyPaths = ['registration/options', 'registration/finish', 'authentication/options', 'authentication/finish'];
$passkeyBodies = [
    ['name' => '电脑', 'current_password' => 'password'],
    ['challenge_token' => 'fixture-challenge', 'rawId' => 'raw-id', 'response' => $attestation],
    ['organization_code' => 'example', 'application_code' => 'business'],
    ['organization_code' => 'example', 'application_code' => 'business', 'challenge_token' => 'fixture-challenge', 'rawId' => 'raw-id', 'response' => $assertion, 'user_agent' => 'platform-agent'],
];
foreach ($passkeyRequests as $index => $request) {
    sdkAssert(parse_url($request['url'], PHP_URL_PATH) === '/api/sand-iam/v1/auth/passkeys/' . $passkeyPaths[$index], 'Passkey route incorrect');
    sdkAssert($request['method'] === 'POST', 'Passkey method incorrect');
    sdkAssert(json_decode($request['body'], true) === $passkeyBodies[$index], 'Passkey payload changed platform response or application');
    sdkAssert(($request['headers']['Authorization'] ?? '') === ($index < 2 ? 'Bearer session' : ''), 'Passkey authentication boundary incorrect');
    sdkAssert(str_starts_with($request['headers']['X-Request-Id'] ?? '', 'passkey-'), 'Passkey request id missing');
}
foreach ([
    fn () => $passkeyClient->passkeyRegistrationOptions('', '', 'password'),
    fn () => $passkeyClient->passkeyRegistrationFinish('', 'challenge', 'raw-id', $attestation),
    fn () => $passkeyClient->passkeyRegistrationOptions('session', '', ''),
    fn () => $passkeyClient->passkeyRegistrationFinish('session', '', 'raw-id', $attestation),
    fn () => $passkeyClient->passkeyRegistrationFinish('session', 'challenge', 'raw-id', $assertion),
    fn () => $passkeyClient->passkeyAuthenticationFinish('challenge', 'raw-id', $attestation),
    fn () => $passkeyClient->passkeyAuthenticationFinish('challenge', '', $assertion),
    fn () => $passkeyClient->passkeyAuthenticationFinish('challenge', 'raw-id', array_diff_key($assertion, ['userHandle' => true])),
] as $invalidPasskeyCall) {
    try { $invalidPasskeyCall(); throw new RuntimeException('invalid Passkey input accepted'); }
    catch (SandIamException $exception) {
        sdkAssert(in_array($exception->errorCode, ['SAND_IAM_SDK_INVALID_ARGUMENT', 'SAND_IAM_AUTHENTICATION_FAILED'], true), 'Passkey input error lost');
    }
}
sdkAssert(count($passkeyRequests) === 4, 'invalid Passkey input sent');
$passkeyStatus = 401;
try {
    $passkeyClient->passkeyAuthenticationFinish('challenge', 'raw-id', $assertion);
    throw new RuntimeException('invalid Passkey signature accepted');
} catch (SandIamException $exception) {
    sdkAssert($exception->errorCode === 'SAND_IAM_PASSKEY_SIGNATURE_INVALID', 'Passkey rejection lost');
}
sdkAssert(count($passkeyRequests) === 5, 'Passkey failure automatically retried');

$securityRequests = [];
$captchaResult = ['required' => false];
$securityStatus = 200;
$securityClient = new SandIamClient('https://iam.example.test/prefix', 'example', 'business', 3,
    static function (array $input) use (&$securityRequests, &$captchaResult, &$securityStatus): array {
        $securityRequests[] = $input;
        $path = parse_url($input['url'], PHP_URL_PATH);
        $data = match (true) {
            str_ends_with($path, '/captcha/config') => $captchaResult,
            str_ends_with($path, '/step-up/password') => ['step_up' => true, 'expires_in' => 300],
            str_ends_with($path, '/step-up/mfa/start') => ['mfa_required' => true, 'challenge_token' => 'fixture-challenge', 'methods' => ['totp'], 'expires_in' => 300],
            default => '身份源绑定已解除',
        };
        return ['status' => $securityStatus, 'body' => json_encode($securityStatus === 200
            ? ['code' => 200, 'data' => $data] : ['msg' => 'SAND_IAM_FEDERATION_STEP_UP_REQUIRED'], JSON_THROW_ON_ERROR)];
    });
foreach ([
    ['required' => false],
    ['required' => true, 'available' => false],
    ['required' => true, 'available' => true, 'widget' => ['kind' => 'turnstile', 'site_key' => 'public-key', 'action' => 'login', 'application_binding' => 'app-bound']],
] as $config) {
    $captchaResult = $config;
    sdkAssert($securityClient->captchaConfiguration('login', 'security-captcha') === $config, 'Captcha branch lost');
}
$securityClient->captchaConfiguration('register', 'security-register');
foreach ($securityRequests as $index => $request) {
    sdkAssert($request['method'] === 'GET' && $request['body'] === null, 'Captcha must use GET query without body');
    sdkAssert(!isset($request['headers']['Authorization']), 'Captcha configuration must be anonymous');
    sdkAssert(parse_url($request['url'], PHP_URL_PATH) === '/prefix/api/sand-iam/v1/auth/captcha/config', 'Captcha path prefix lost');
    parse_str(parse_url($request['url'], PHP_URL_QUERY), $query);
    sdkAssert($query === ['organization_code' => 'example', 'application_code' => 'business', 'action' => $index === 3 ? 'register' : 'login'], 'Captcha application/action query incorrect');
}
sdkAssert($securityClient->stepUpPassword('session', 'password', 'security-password')['step_up'] === true, 'password step-up result lost');
sdkAssert($securityClient->startMfaStepUp('session', 'security-mfa')['challenge_token'] === 'fixture-challenge', 'MFA step-up challenge lost');
$securityClient->unlinkFederation('session', 7, 'security-unlink');
foreach (array_slice($securityRequests, 4) as $index => $request) {
    sdkAssert($request['method'] === 'POST' && ($request['headers']['Authorization'] ?? '') === 'Bearer session', 'security operation must use session');
    sdkAssert(parse_url($request['url'], PHP_URL_QUERY) === null && !str_contains($request['body'], 'session'), 'session leaked outside Authorization');
    sdkAssert(json_decode($request['body'], true) === [['password' => 'password'], [], ['binding_id' => 7]][$index], 'security operation body mismatch');
    sdkAssert(($request['headers']['X-Request-Id'] ?? '') === ['security-password', 'security-mfa', 'security-unlink'][$index], 'security request ID lost');
}
foreach ([
    fn () => $securityClient->captchaConfiguration('reset'),
    fn () => $securityClient->stepUpPassword('', 'password'),
    fn () => $securityClient->stepUpPassword('session', ''),
    fn () => $securityClient->startMfaStepUp(''),
    fn () => $securityClient->unlinkFederation('', 7),
    fn () => $securityClient->unlinkFederation('session', 0),
] as $invalidSecurityCall) {
    try { $invalidSecurityCall(); throw new RuntimeException('invalid security operation accepted'); }
    catch (SandIamException $exception) {
        sdkAssert(in_array($exception->errorCode, ['SAND_IAM_SDK_INVALID_ARGUMENT', 'SAND_IAM_AUTHENTICATION_FAILED'], true), 'security input error lost');
    }
}
sdkAssert(count($securityRequests) === 7, 'invalid security input sent');
$securityStatus = 401;
try { $securityClient->unlinkFederation('session', 7); throw new RuntimeException('step-up rejection accepted'); }
catch (SandIamException $exception) { sdkAssert($exception->errorCode === 'SAND_IAM_FEDERATION_STEP_UP_REQUIRED', 'step-up requirement lost'); }
sdkAssert(count($securityRequests) === 8, 'unlink automatically retried or performed step-up');

$invitationData = ['id' => 42, 'display_name' => '访客', 'access_token' => 'must-not-return'];
$invitationStatus = 200; $invitationCalls = 0;
$invitationClient = new SandIamClient('https://iam.example.test/prefix', 'example', 'business', 3,
    static function (array $request) use (&$invitationData, &$invitationStatus, &$invitationCalls): array {
        $invitationCalls++;
        sdkAssert($request['url'] === 'https://iam.example.test/prefix/api/sand-iam/v1/invitations/accept', 'invitation URL leaked token or lost prefix');
        sdkAssert($request['method'] === 'POST' && !isset($request['headers']['Authorization']), 'invitation must be anonymous POST');
        sdkAssert($request['headers']['X-Request-Id'] === 'invite-request' && $request['headers']['Cache-Control'] === 'no-store', 'invitation request headers lost');
        sdkAssert(json_decode($request['body'], true) === ['token' => 'invite-token', 'username' => 'new-user', 'password' => 'password', 'display_name' => '访客'], 'invitation payload mismatch');
        return ['status' => $invitationStatus, 'body' => json_encode(['data' => $invitationData, 'msg' => 'SAND_IAM_INVITATION_EXPIRED'], JSON_THROW_ON_ERROR)];
    });
sdkAssert($invitationClient->acceptInvitation('invite-token', 'new-user', 'password', '访客', 'invite-request') === ['id' => 42, 'display_name' => '访客'], 'invitation result must not be a login');
foreach ([null, [], ['id' => 0, 'display_name' => 'x'], ['id' => '42', 'display_name' => 'x'], ['id' => 42]] as $invitationData) {
    try { $invitationClient->acceptInvitation('invite-token', 'new-user', 'password', '访客', 'invite-request'); throw new RuntimeException('invalid invitation response accepted'); }
    catch (SandIamException $error) { sdkAssert($error->errorCode === 'SAND_IAM_SDK_INVALID_RESPONSE', 'wrong invitation response error'); }
}
$before = $invitationCalls; $invitationStatus = 410;
try { $invitationClient->acceptInvitation('invite-token', 'new-user', 'password', '访客', 'invite-request'); throw new RuntimeException('expired invitation accepted'); }
catch (SandIamException $error) { sdkAssert($error->errorCode === 'SAND_IAM_INVITATION_EXPIRED', 'invitation error lost'); }
sdkAssert($invitationCalls === $before + 1, 'invitation retried');
foreach ([['', 'new-user', 'password'], ['invite-token', '', 'password'], ['invite-token', 'new-user', '']] as $args) {
    try { $invitationClient->acceptInvitation(...$args); throw new RuntimeException('empty invitation input accepted'); }
    catch (SandIamException $error) { sdkAssert($error->errorCode === 'SAND_IAM_SDK_INVALID_ARGUMENT', 'wrong invitation input error'); }
}
sdkAssert($invitationCalls === $before + 1, 'invalid invitation input sent');
echo "SandIAM PHP SDK tests passed\n";
