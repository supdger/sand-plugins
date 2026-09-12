<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\AuthRateLimit;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\AuthVerification;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\SecurityOperation;
use plugin\SandIam\app\service\HumanAuthService;
use plugin\SandIam\app\service\OrganizationHumanSessionRevoker;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

final class SandIamT01Sender
{
    public static string $code = '';

    public static function send(string $destination, string $code, array $context): void
    {
        self::$code = $code;
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "IAM-T01 service integration failed: {$message}\n");
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

function expectApiException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $message)) {
            return;
        }
        fail("expected {$message}, received {$exception->getMessage()}");
    }
    fail("expected {$message}, but no exception was thrown");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    fail("SandAdmin host dependencies are unavailable at {$hostRoot}");
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$service = new HumanAuthService();
$organization = Organization::create(['code' => 't01-runtime-org', 'name' => 'T01 runtime organization', 'status' => 1]);
$applicationA = Application::create(['organization_id' => (int) $organization->id, 'code' => 'app-a', 'name' => 'T01 app A', 'status' => 1]);
$applicationB = Application::create(['organization_id' => (int) $organization->id, 'code' => 'app-b', 'name' => 'T01 app B', 'status' => 1]);

AuthPolicy::create([
    'application_id' => (int) $applicationA->id,
    'registration_enabled' => 1,
    'require_email_verification' => 1,
    'status' => 1,
]);
AuthPolicy::create([
    'application_id' => (int) $applicationB->id,
    'registration_enabled' => 1,
    'status' => 1,
]);

$applicationRefA = ['organization_code' => 't01-runtime-org', 'application_code' => 'app-a'];
$applicationRefB = ['organization_code' => 't01-runtime-org', 'application_code' => 'app-b'];
$initialPassword = 'T01!InitialPassword';
$newPassword = 'T01!ChangedPassword';
$email = 'runtime@example.test';

$registered = $service->register($applicationRefA + [
    'username' => 'runtime-user',
    'display_name' => 'Runtime user',
    'email' => $email,
    'password' => $initialPassword,
], '127.0.0.1', 't01-register');
assertTrue(($registered['verification_required'] ?? false) === true, 'verification policy did not block session issuance');
assertTrue(!isset($registered['access_token'], $registered['refresh_token']), 'registration returned tokens before required verification');

$verificationRequest = $applicationRefA + [
    'identifier' => $email,
    'purpose' => 'email_verify',
    'channel' => 'email',
    '_ip' => '127.0.0.1',
];
$service->requestVerification($verificationRequest, 't01-verification-request');
assertTrue((bool) preg_match('/^[0-9]{8}$/', SandIamT01Sender::$code), 'verification sender did not receive an eight-digit code');
$correctVerificationCode = SandIamT01Sender::$code;

expectApiException(
    static fn () => $service->confirmVerification($verificationRequest + ['code' => '00000000'], 't01-verification-wrong'),
    'SAND_IAM_AUTH_VERIFICATION_INVALID',
);
$verification = AuthVerification::where('application_id', (int) $applicationA->id)->where('purpose', 'email_verify')->find();
assertTrue($verification !== null && (int) $verification->attempt_count === 1, 'failed verification attempt was not persisted');

$service->confirmVerification($verificationRequest + ['code' => $correctVerificationCode], 't01-verification-confirm');
$identityAuth = IdentityAuth::where('application_id', (int) $applicationA->id)->where('username', 'runtime-user')->find();
assertTrue($identityAuth !== null && $identityAuth->email_verified_time !== null, 'email verification was not persisted');
$service->requestVerification($applicationRefA + [
    'identifier' => 'missing@example.test',
    'purpose' => 'password_reset',
    'channel' => 'email',
    '_password_reset_endpoint' => true,
    '_ip' => '127.0.0.9',
], 't01-reset-missing-account');
$service->requestVerification($applicationRefA + [
    'identifier' => $email,
    'purpose' => 'password_reset',
    'channel' => 'phone',
    '_password_reset_endpoint' => true,
    '_ip' => '127.0.0.9',
], 't01-reset-missing-destination');
$service->requestVerification($applicationRefA + [
    'identifier' => 'missing@example.test',
    'purpose' => 'email_verify',
    'channel' => 'email',
    '_ip' => '127.0.0.9',
], 't01-verify-missing-account');
$service->requestVerification($applicationRefA + [
    'identifier' => $email,
    'purpose' => 'phone_verify',
    'channel' => 'phone',
    '_ip' => '127.0.0.9',
], 't01-verify-missing-destination');

$loginPayload = $applicationRefA + ['identifier' => $email, 'password' => 'wrong-password'];
$failedRateBefore = (int) AuthRateLimit::where('application_id', (int) $applicationA->id)->where('action', 'login')->sum('attempt_count');
expectApiException(static fn () => $service->login($loginPayload, '127.0.0.2', 't01-login-failed-0'), 'SAND_IAM_AUTHENTICATION_FAILED');
expectApiException(static fn () => $service->login($loginPayload, '127.0.0.2', 't01-login-failed-0'), 'SAND_IAM_AUTHENTICATION_FAILED');
$failedAuth = IdentityAuth::where('id', (int) $identityAuth->id)->find();
assertTrue($failedAuth !== null && (int) $failedAuth->failed_login_count === 1, 'same failed login request incremented the account failure count twice');
assertTrue((int) AuthRateLimit::where('application_id', (int) $applicationA->id)->where('action', 'login')->sum('attempt_count') === $failedRateBefore + 1, 'same failed login request consumed the login rate limit twice');
assertTrue(AuditLog::where('request_id', 't01-login-failed-0')->where('action', 'identity.login')->count() === 1, 'same failed login request duplicated its failure audit');
for ($attempt = 1; $attempt < 5; $attempt++) {
    expectApiException(static fn () => $service->login($loginPayload, '127.0.0.2', 't01-login-failed-' . $attempt), 'SAND_IAM_AUTHENTICATION_FAILED');
}
expectApiException(
    static fn () => $service->login($applicationRefA + ['identifier' => $email, 'password' => $initialPassword], '127.0.0.2', 't01-login-locked'),
    'SAND_IAM_AUTH_ACCOUNT_LOCKED',
);
IdentityAuth::where('id', (int) $identityAuth->id)->update(['failed_login_count' => 0, 'locked_until' => null]);

$successfulLoginPayload = $applicationRefA + ['identifier' => $email, 'password' => $initialPassword];
$loginRateBefore = (int) AuthRateLimit::where('application_id', (int) $applicationA->id)->where('action', 'login')->sum('attempt_count');
$tokens = $service->login($successfulLoginPayload, '127.0.0.3', 't01-login-success');
$tokensRetry = $service->login($successfulLoginPayload, '127.0.0.3', 't01-login-success');
assertTrue($tokensRetry === $tokens, 'same-request password login did not recover the original token response');
assertTrue((int) AuthRateLimit::where('application_id', (int) $applicationA->id)->where('action', 'login')->sum('attempt_count') === $loginRateBefore + 1, 'completed password login retry consumed the login rate limit twice');
assertTrue(AuditLog::where('request_id', 't01-login-success')->where('action', 'identity.login')->count() === 1, 'same-request password login duplicated the success audit');
$loginOperation = SecurityOperation::where('operation', 'identity.login')->where('request_id', 't01-login-success')->find();
$loginOperationJson = json_encode($loginOperation?->result, JSON_UNESCAPED_SLASHES);
assertTrue(
    $loginOperation !== null
        && is_string($loginOperationJson)
        && str_contains($loginOperationJson, 'encrypted_replay')
        && !str_contains($loginOperationJson, $initialPassword)
        && !str_contains($loginOperationJson, (string) $tokens['access_token'])
        && !str_contains($loginOperationJson, (string) $tokens['refresh_token']),
    'password login retry state persisted plaintext credential material or omitted recovery ciphertext',
);
expectApiException(static fn () => $service->login($successfulLoginPayload + ['user_agent' => 'changed'], '127.0.0.3', 't01-login-success'), 'SAND_IAM_IDEMPOTENCY_CONFLICT');
expectApiException(static fn () => $service->login($successfulLoginPayload, '127.0.0.33', 't01-login-success'), 'SAND_IAM_IDEMPOTENCY_CONFLICT');
$session = AuthSession::find((int) $tokens['session_id']);
assertTrue($session !== null, 'login did not create a session');
assertTrue(!hash_equals((string) $session->access_token_hash, (string) $tokens['access_token']), 'access token was stored in plaintext');
assertTrue(!hash_equals((string) $session->refresh_token_hash, (string) $tokens['refresh_token']), 'refresh token was stored in plaintext');
assertTrue(count($service->sessions((string) $tokens['access_token'])) === 1, 'active session was not listed');

$rotated = $service->refresh((string) $tokens['refresh_token'], '127.0.0.4', 't01-refresh');
$rotatedRetry = $service->refresh((string) $tokens['refresh_token'], '127.0.0.4', 't01-refresh');
assertTrue(
    hash_equals((string) $rotated['access_token'], (string) $rotatedRetry['access_token'])
        && hash_equals((string) $rotated['refresh_token'], (string) $rotatedRetry['refresh_token']),
    'same-request refresh retry did not recover the original rotated token response',
);
assertTrue(count($service->sessions((string) $rotatedRetry['access_token'])) === 1, 'safe refresh retry revoked the active session');
$refreshOperation = SecurityOperation::where('actor_type', 'refresh_token')->where('operation', 'identity.refresh')->where('request_id', 't01-refresh')->find();
$refreshOperationJson = json_encode($refreshOperation?->result, JSON_UNESCAPED_SLASHES);
assertTrue(
    $refreshOperation !== null
        && is_string($refreshOperationJson)
        && str_contains($refreshOperationJson, 'encrypted_replay')
        && !str_contains($refreshOperationJson, (string) $tokens['refresh_token'])
        && !str_contains($refreshOperationJson, (string) $rotated['access_token'])
        && !str_contains($refreshOperationJson, (string) $rotated['refresh_token']),
    'refresh retry state persisted plaintext token material or omitted the authenticated ciphertext',
);
assertTrue(AuditLog::where('request_id', 't01-refresh')->where('action', 'identity.refresh')->count() === 1, 'same-request refresh retry duplicated the success audit');
expectApiException(
    static fn () => $service->refresh((string) $tokens['refresh_token'], '127.0.0.4', 't01-refresh-replay'),
    'SAND_IAM_AUTH_REFRESH_REPLAY_DETECTED',
);
expectApiException(static fn () => $service->sessions((string) $rotated['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');

$logoutTokens = $service->login($applicationRefA + ['identifier' => $email, 'password' => $initialPassword], '127.0.0.5', 't01-login-logout');
$service->logout((string) $logoutTokens['access_token'], 't01-logout');
expectApiException(static fn () => $service->sessions((string) $logoutTokens['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');

$resetSession = $service->login($applicationRefA + ['identifier' => $email, 'password' => $initialPassword], '127.0.0.6', 't01-login-reset');
$passwordResetRequest = $applicationRefA + [
    'identifier' => $email,
    'purpose' => 'password_reset',
    'channel' => 'email',
    '_password_reset_endpoint' => true,
    '_ip' => '127.0.0.6',
];
$service->requestVerification($passwordResetRequest, 't01-reset-request');
$service->resetPassword($applicationRefA + [
    'identifier' => $email,
    'channel' => 'email',
    'code' => SandIamT01Sender::$code,
    'password' => $newPassword,
    '_ip' => '127.0.0.6',
], 't01-reset');
expectApiException(static fn () => $service->sessions((string) $resetSession['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');
$postReset = $service->login($applicationRefA + ['identifier' => $email, 'password' => $newPassword], '127.0.0.7', 't01-login-new-password');

$crossApplication = $service->register($applicationRefB + [
    'username' => 'runtime-user-b',
    'display_name' => 'Runtime user B',
    'email' => $email,
    'password' => $initialPassword,
], '127.0.0.8', 't01-register-cross-application');
assertTrue(isset($crossApplication['access_token']), 'same email could not register independently in another application');

$revocation = (new OrganizationHumanSessionRevoker())->disable((int) $organization->id, ['status' => 2], 1, 't01-organization-disable');
assertTrue(($revocation['changed'] ?? false) === true && (int) ($revocation['session_count'] ?? 0) > 0, 'organization disable did not revoke active human sessions');
$revokedSession = AuthSession::find((int) $postReset['session_id']);
assertTrue($revokedSession !== null && (int) $revokedSession->status === 2 && $revokedSession->revoked_time !== null, 'organization disable did not revoke the current application session');
assertTrue(AuthRefreshToken::where('session_id', (int) $postReset['session_id'])->where('status', 1)->count() === 0, 'organization disable left an active refresh token for the revoked session');
expectApiException(static fn () => $service->sessions((string) $postReset['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');
expectApiException(static fn () => $service->refresh((string) $postReset['refresh_token'], '127.0.0.7', 't01-refresh-disabled-organization'), 'SAND_IAM_AUTH_REFRESH_REPLAY_DETECTED');
$organization->save(['status' => 1]);
expectApiException(static fn () => $service->sessions((string) $postReset['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');
expectApiException(static fn () => $service->refresh((string) $postReset['refresh_token'], '127.0.0.7', 't01-refresh-restored-organization'), 'SAND_IAM_AUTH_REFRESH_REPLAY_DETECTED');
expectApiException(static fn () => $service->sessions((string) $crossApplication['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');

$identity = Identity::find((int) $postReset['identity']['id']);
assertTrue($identity !== null, 'post-reset identity is missing');
$identity->save(['status' => 2]);
expectApiException(static fn () => $service->sessions((string) $postReset['access_token']), 'SAND_IAM_AUTHENTICATION_FAILED');

$auditJson = json_encode(AuditLog::where('organization_id', (int) $organization->id)->select()->toArray(), JSON_UNESCAPED_SLASHES);
assertTrue(is_string($auditJson), 'audit rows could not be encoded');
foreach ([$initialPassword, $newPassword, (string) $tokens['access_token'], (string) $tokens['refresh_token'], $correctVerificationCode, $email] as $sensitiveValue) {
    assertTrue(!str_contains($auditJson, $sensitiveValue), 'sensitive authentication material leaked into audit rows');
}

echo "human auth service integration passed\n";
