<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationExperience;
use plugin\SandIam\app\model\ApplicationNetworkPolicy;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\AuthRateLimit;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\AuthVerification;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\security\NetworkPolicy;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * Application-user authentication. This service deliberately does not read or
 * write SandAdmin administrator accounts.
 */
final class HumanAuthService
{
    private const DEFAULT_ACCESS_TTL_SECONDS = 900;
    private const DEFAULT_REFRESH_TTL_SECONDS = 2_592_000;
    private const DEFAULT_VERIFICATION_TTL_SECONDS = 600;
    private const DEFAULT_LOCK_SECONDS = 900;
    private const DEFAULT_MAX_LOGIN_FAILURES = 5;
    private const DEFAULT_MAX_RATE_ATTEMPTS = 10;

    public function __construct(private readonly AuditWriter $auditWriter = new AuditWriter())
    {
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function register(array $payload, string $ip, string $requestId): array
    {
        $this->requirePepper();
        $application = $this->application($payload);
        $this->assertNetworkAllowed($application, $ip);
        $policy = $this->policy((int) $application->id);
        if (!(bool) $policy['registration_enabled']) throw new ApiException('SAND_IAM_AUTH_REGISTRATION_DISABLED', 403);
        $this->assertExperienceAllows($application, 'register');
        $this->assertRegistrationFields($application, $payload);
        $username = $this->username((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $this->assertPasswordPolicy($password, $policy);
        $email = $this->email((string) ($payload['email'] ?? ''));
        $phone = $this->phone((string) ($payload['phone'] ?? ''));
        if ($email === null && $phone === null) {
            throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: email or phone is required', 400);
        }
        $this->consumeRateLimit((int) $application->id, 'register', $ip, $policy);
        if ((bool) $policy['require_captcha']) $this->verifyCaptcha($application, (string) ($payload['captcha_token'] ?? ''), 'register', $ip, $requestId);

        Db::startTrans();
        try {
            $identity = Identity::create([
                'application_id' => (int) $application->id,
                'code' => $username,
                'display_name' => $this->displayName((string) ($payload['display_name'] ?? $username)),
                'status' => 1,
            ]);
            $auth = IdentityAuth::create([
                'application_id' => (int) $application->id,
                'identity_id' => (int) $identity->id,
                'username' => $username,
                'email' => $email,
                'phone' => $phone,
                'password_hash' => $this->passwordHash($password),
                'pepper_version' => $this->pepperVersion(),
                'password_changed_time' => $this->now(),
                'status' => 1,
            ]);
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.created', ['code', 'display_name', 'lifecycle_state', 'status'], $requestId);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if (str_contains($exception->getMessage(), 'unique')) {
                throw new ApiException('SAND_IAM_AUTH_ACCOUNT_CONFLICT', 409);
            }
            throw $exception;
        }

        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.register', 'identity', (int) $identity->id, 'succeeded', $requestId, [], (string) $identity->id);
        if (!$this->verificationSatisfied($auth, $policy)) {
            return ['identity' => ['id' => (int) $identity->id, 'code' => (string) $identity->code, 'display_name' => (string) $identity->display_name], 'verification_required' => true];
        }
        if ((new MfaService())->hasEnabledFactor((int) $application->id, (int) $identity->id)) {
            return (new MfaService())->beginPasswordLogin($application, $identity, $requestId);
        }
        return $this->tokenResponse($this->createSession($application, $identity, $auth, $ip, (string) ($payload['user_agent'] ?? ''), $policy), $identity, $policy);
    }

    /** @param array<string,mixed> $payload */
    public function activateInvitation(Application $application, string $targetType, string $target, array $payload, string $requestId, bool $manageTransaction = true): Identity
    {
        $this->requirePepper();
        if ((int) config('plugin.sand-iam.app.identity_lifecycle_enabled', 0) !== 1) throw new ApiException('SAND_IAM_IDENTITY_LIFECYCLE_UNAVAILABLE', 503);
        $policy = $this->policy((int) $application->id);
        $username = $this->username((string) ($payload['username'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $this->assertPasswordPolicy($password, $policy);
        $email = $targetType === 'email' ? $this->email($target) : null;
        $phone = $targetType === 'phone' ? $this->phone($target) : null;
        if (($targetType === 'email' && $email === null) || ($targetType === 'phone' && $phone === null)) throw new ApiException('SAND_IAM_INVITATION_TARGET_INVALID', 400);
        if ($manageTransaction) Db::startTrans();
        try {
            $identity = Identity::create(['application_id' => (int) $application->id, 'code' => $username, 'display_name' => $this->displayName((string) ($payload['display_name'] ?? $username)), 'lifecycle_state' => 'active', 'status' => 1]);
            IdentityAuth::create(['application_id' => (int) $application->id, 'identity_id' => (int) $identity->id, 'username' => $username, 'email' => $email, 'phone' => $phone, 'password_hash' => $this->passwordHash($password), 'pepper_version' => $this->pepperVersion(), 'password_changed_time' => $this->now(), 'email_verified_time' => $email === null ? null : $this->now(), 'phone_verified_time' => $phone === null ? null : $this->now(), 'status' => 1]);
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.created', ['code', 'display_name', 'lifecycle_state', 'status'], $requestId);
            if ($manageTransaction) Db::commit();
        } catch (\Throwable $exception) {
            if ($manageTransaction) Db::rollback();
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_AUTH_ACCOUNT_CONFLICT', 409);
            throw $exception;
        }
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.invitation_activate', 'identity', (int) $identity->id, 'succeeded', $requestId, [], (string) $identity->id);
        return $identity;
    }

    /** @param array<string,mixed> $payload */
    public function upgradeGuestInvitation(Application $application, Identity $identity, string $targetType, string $target, array $payload, string $requestId): Identity
    {
        $this->requirePepper();
        if ((int) $identity->application_id !== (int) $application->id || (string) ($identity->lifecycle_state ?? '') !== 'guest' || (int) $identity->status !== 1) throw new ApiException('SAND_IAM_GUEST_NOT_UPGRADEABLE', 409);
        $policy = $this->policy((int) $application->id); $username = $this->username((string) ($payload['username'] ?? '')); $password = (string) ($payload['password'] ?? ''); $this->assertPasswordPolicy($password, $policy);
        $email = $targetType === 'email' ? $this->email($target) : null; $phone = $targetType === 'phone' ? $this->phone($target) : null;
        if (($targetType === 'email' && $email === null) || ($targetType === 'phone' && $phone === null)) throw new ApiException('SAND_IAM_INVITATION_TARGET_INVALID', 400);
        try {
            $identity->save(['code' => $username, 'display_name' => $this->displayName((string) ($payload['display_name'] ?? $username)), 'lifecycle_state' => 'active', 'status' => 1]);
            IdentityAuth::create(['application_id' => (int) $application->id, 'identity_id' => (int) $identity->id, 'username' => $username, 'email' => $email, 'phone' => $phone, 'password_hash' => $this->passwordHash($password), 'pepper_version' => $this->pepperVersion(), 'password_changed_time' => $this->now(), 'email_verified_time' => $email === null ? null : $this->now(), 'phone_verified_time' => $phone === null ? null : $this->now(), 'status' => 1]);
            (new IdentityEventPublisher())->publish($application, $identity, 'identity.updated', ['code', 'display_name', 'lifecycle_state', 'status'], $requestId);
        } catch (\Throwable $exception) { if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_AUTH_ACCOUNT_CONFLICT', 409); throw $exception; }
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.guest_upgrade', 'identity', (int) $identity->id, 'succeeded', $requestId, [], (string) $identity->id);
        return $identity;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function login(array $payload, string $ip, string $requestId): array
    {
        $this->requirePepper();
        $application = $this->application($payload);
        $this->assertNetworkAllowed($application, $ip);
        $policy = $this->policy((int) $application->id);
        $this->assertExperienceAllows($application, 'login');
        $identifier = $this->identifier((string) ($payload['identifier'] ?? ''));
        $this->consumeRateLimit((int) $application->id, 'login', $identifier . '|' . $ip, $policy);
        if ((bool) $policy['require_captcha']) $this->verifyCaptcha($application, (string) ($payload['captcha_token'] ?? ''), 'login', $ip, $requestId);
        $auth = $this->findAuth((int) $application->id, $identifier);
        $identity = $auth ? Identity::where('id', (int) $auth->identity_id)->where('application_id', (int) $application->id)->where('status', 1)->find() : null;
        if ($auth === null || $identity === null || (int) $auth->status !== 1 || !$this->pepperVersionMatches((string) ($auth->pepper_version ?? ''))) {
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.login', 'identity', null, 'failed', $requestId, ['reason' => 'authentication_failed']);
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        if ($this->locked($auth->locked_until)) {
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.login', 'identity', (int) $identity->id, 'denied', $requestId, ['reason' => 'locked']);
            throw new ApiException('SAND_IAM_AUTH_ACCOUNT_LOCKED', 423);
        }
        if (!$this->verifyPassword((string) ($payload['password'] ?? ''), (string) $auth->password_hash)) {
            Db::startTrans();
            try {
                $lockedAuth = IdentityAuth::where('id', (int) $auth->id)->lock(true)->find();
                if ($lockedAuth === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
                $failures = (int) $lockedAuth->failed_login_count + 1;
                $lockedAuth->save([
                    'failed_login_count' => $failures,
                    'locked_until' => $failures >= (int) $policy['max_login_failures'] ? date('Y-m-d H:i:s', time() + (int) $policy['lock_seconds']) : null,
                ]);
                Db::commit();
            } catch (\Throwable $exception) {
                Db::rollback();
                throw $exception;
            }
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.login', 'identity', (int) $identity->id, 'failed', $requestId, ['reason' => 'authentication_failed']);
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        $auth->save(['failed_login_count' => 0, 'locked_until' => null, 'last_login_time' => $this->now()]);
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.login', 'identity', (int) $identity->id, 'succeeded', $requestId, [], (string) $identity->id);
        if (!$this->verificationSatisfied($auth, $policy)) throw new ApiException('SAND_IAM_AUTH_VERIFICATION_REQUIRED', 403);
        if ((new MfaService())->hasEnabledFactor((int) $application->id, (int) $identity->id)) {
            return (new MfaService())->beginPasswordLogin($application, $identity, $requestId);
        }
        return $this->tokenResponse($this->createSession($application, $identity, $auth, $ip, (string) ($payload['user_agent'] ?? ''), $policy), $identity, $policy);
    }

    /**
     * Verify a local password for a non-HTTP protocol without creating a
     * SandIAM bearer session. Protocol adapters must not use login() and then
     * discard issued tokens.
     */
    public function verifyPasswordForProtocol(Application $application, string $identifierValue, string $password, string $ip, string $requestId, string $protocol): Identity
    {
        $this->requirePepper();
        if (!in_array($protocol, ['radius'], true)) throw new ApiException('SAND_IAM_AUTH_PROTOCOL_UNSUPPORTED', 400);
        $application = Application::where('id', (int) $application->id)->where('status', 1)->find();
        if ($application === null || Organization::where('id', (int) $application->organization_id)->where('status', 1)->find() === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        $this->assertNetworkAllowed($application, $ip);
        $policy = $this->policy((int) $application->id);
        $identifier = $this->identifier($identifierValue);
        $this->consumeRateLimit((int) $application->id, $protocol . '_login', $identifier . '|' . $ip, $policy);
        $auth = $this->findAuth((int) $application->id, $identifier);
        $identity = $auth ? Identity::where('id', (int) $auth->identity_id)->where('application_id', (int) $application->id)->where('status', 1)->find() : null;
        if ($auth === null || $identity === null || (int) $auth->status !== 1 || !$this->pepperVersionMatches((string) ($auth->pepper_version ?? '')) || $this->locked($auth->locked_until)) {
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.' . $protocol . '_login', 'identity', $identity ? (int) $identity->id : null, 'failed', $requestId, ['reason' => 'authentication_failed']);
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        if (!$this->verifyPassword($password, (string) $auth->password_hash)) {
            Db::startTrans();
            try {
                $lockedAuth = IdentityAuth::where('id', (int) $auth->id)->lock(true)->find();
                if ($lockedAuth === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
                $failures = (int) $lockedAuth->failed_login_count + 1;
                $lockedAuth->save(['failed_login_count' => $failures, 'locked_until' => $failures >= (int) $policy['max_login_failures'] ? date('Y-m-d H:i:s', time() + (int) $policy['lock_seconds']) : null]);
                Db::commit();
            } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.' . $protocol . '_login', 'identity', (int) $identity->id, 'failed', $requestId, ['reason' => 'authentication_failed']);
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        if (!$this->verificationSatisfied($auth, $policy)) throw new ApiException('SAND_IAM_AUTH_VERIFICATION_REQUIRED', 403);
        if ((new MfaService())->hasEnabledFactor((int) $application->id, (int) $identity->id)) throw new ApiException('SAND_IAM_RADIUS_MFA_REQUIRED', 403);
        $auth->save(['failed_login_count' => 0, 'locked_until' => null, 'last_login_time' => $this->now()]);
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.' . $protocol . '_login', 'identity', (int) $identity->id, 'succeeded', $requestId, [], (string) $identity->id);
        return $identity;
    }

    /** @return array<string, mixed> */
    public function refresh(string $refreshToken, string $ip, string $requestId): array
    {
        $this->requirePepper();
        if ($refreshToken === '') {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        $hash = $this->tokenHash($refreshToken);
        Db::startTrans();
        try {
            $refresh = AuthRefreshToken::where('token_hash', $hash)->lock(true)->find();
            if ($refresh === null) {
                throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            }
            $session = AuthSession::where('id', (int) $refresh->session_id)->lock(true)->find();
            if ($session === null || (int) $refresh->status !== 1 || $refresh->used_time !== null) {
                if ($session !== null) {
                    $session->save(['status' => 2, 'revoked_time' => $this->now()]);
                    AuthRefreshToken::where('session_id', (int) $session->id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
                    Db::commit();
                    $this->auditForSession($session, 'identity.refresh_replay', 'denied', $requestId);
                    throw new ApiException('SAND_IAM_AUTH_REFRESH_REPLAY_DETECTED', 401);
                }
                throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            }
            if ((int) $session->status !== 1 || $session->revoked_time !== null || $this->expired($session->refresh_expire_time)) {
                throw new ApiException('SAND_IAM_AUTH_TOKEN_EXPIRED', 401);
            }
            $identity = Identity::where('id', (int) $session->identity_id)->where('application_id', (int) $session->application_id)->where('status', 1)->find();
            $application = Application::where('id', (int) $session->application_id)->where('status', 1)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
            if ($identity === null || $application === null || $organization === null) {
                throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            }
            $this->assertNetworkAllowed($application, $ip);
            if ((string) ($session->auth_method ?? 'local_password') === 'federation') {
                $binding = IdentityBinding::where('id', (int) $session->identity_binding_id)->lock(true)->find();
                if ($binding === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
                try {
                    $this->assertFederatedBinding($binding, (int) $application->id, (int) $identity->id);
                } catch (ApiException) {
                    $session->save(['status' => 2, 'revoked_time' => $this->now()]);
                    AuthRefreshToken::where('session_id', (int) $session->id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
                    Db::commit();
                    throw new ApiException('SAND_IAM_AUTH_FEDERATED_SOURCE_REVOKED', 401);
                }
            } else {
                $auth = IdentityAuth::where('identity_id', (int) $identity->id)->where('application_id', (int) $application->id)->where('status', 1)->find();
                if ($auth === null || !$this->pepperVersionMatches((string) ($auth->pepper_version ?? ''))) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            }
            $policy = $this->policy((int) $application->id);
            $tokens = $this->newTokens($policy);
            $refresh->save(['used_time' => $this->now(), 'status' => 2]);
            AuthRefreshToken::create(['session_id' => (int) $session->id, 'token_hash' => $this->tokenHash($tokens['refresh_token']), 'pepper_version' => $this->pepperVersion(), 'expire_time' => $tokens['refresh_expire_time'], 'status' => 1]);
            $session->save([
                'access_token_hash' => $this->tokenHash($tokens['access_token']),
                'previous_refresh_token_hash' => $session->refresh_token_hash,
                'refresh_token_hash' => $this->tokenHash($tokens['refresh_token']),
                'access_expire_time' => $tokens['access_expire_time'],
                'refresh_expire_time' => $tokens['refresh_expire_time'],
                'last_used_time' => $this->now(),
                'ip_hash' => $this->secretHash($ip),
            ]);
            Db::commit();
        } catch (ApiException $exception) {
            if (in_array($exception->getMessage(), ['SAND_IAM_AUTH_REFRESH_REPLAY_DETECTED', 'SAND_IAM_AUTH_FEDERATED_SOURCE_REVOKED'], true)) throw $exception;
            Db::rollback();
            throw $exception;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.refresh', 'auth_session', (int) $session->id, 'succeeded', $requestId, [], (string) $identity->id);
        return $this->tokenResponse($tokens + ['session_id' => (int) $session->id], $identity, $policy);
    }

    public function logout(string $accessToken, string $requestId): void
    {
        $session = $this->accessSession($accessToken);
        $session->save(['status' => 2, 'revoked_time' => $this->now()]);
        AuthRefreshToken::where('session_id', (int) $session->id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
        $this->auditForSession($session, 'identity.logout', 'succeeded', $requestId);
    }

    /** @return list<array<string, mixed>> */
    public function sessions(string $accessToken): array
    {
        $current = $this->accessSession($accessToken);
        return array_map(static fn (AuthSession $session): array => [
            'id' => (int) $session->id,
            'create_time' => $session->create_time,
            'last_used_time' => $session->last_used_time,
            'access_expire_time' => $session->access_expire_time,
            'refresh_expire_time' => $session->refresh_expire_time,
            'current' => (int) $session->id === (int) $current->id,
        ], AuthSession::where('identity_id', (int) $current->identity_id)->where('application_id', (int) $current->application_id)->where('status', 1)->where('refresh_expire_time', '>', $this->now())->order('last_used_time', 'desc')->select()->all());
    }

    public function revokeSession(string $accessToken, int $sessionId, string $requestId): void
    {
        $current = $this->accessSession($accessToken);
        $session = AuthSession::where('id', $sessionId)->where('identity_id', (int) $current->identity_id)->where('application_id', (int) $current->application_id)->find();
        if ($session === null) {
            throw new ApiException('SAND_IAM_AUTH_SESSION_NOT_FOUND', 404);
        }
        $session->save(['status' => 2, 'revoked_time' => $this->now()]);
        AuthRefreshToken::where('session_id', (int) $session->id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
        $this->auditForSession($session, 'identity.session_revoke', 'succeeded', $requestId);
    }

    /** @return array{0:Application,1:Identity} */
    public function authenticatedPrincipal(string $accessToken): array
    {
        $session = $this->accessSession($accessToken);
        $application = Application::where('id', (int) $session->application_id)->where('status', 1)->find();
        $identity = Identity::where('id', (int) $session->identity_id)->where('application_id', (int) $session->application_id)->where('status', 1)->find();
        if ($application === null || $identity === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        return [$application, $identity];
    }

    /**
     * Returns the live SandIAM user session for protocol hand-off.  OAuth/OIDC
     * may consume this only after checking that its client belongs to the same
     * application; it is not a cross-application SSO session.
     */
    public function authenticatedSession(string $accessToken): AuthSession
    {
        return $this->accessSession($accessToken);
    }

    /** @param array<string,mixed> $payload */
    public function resolveApplication(array $payload): Application
    {
        $this->requirePepper();
        return $this->application($payload);
    }

    public function assertCurrentPassword(string $accessToken, string $password, string $ip = '', string $requestId = ''): void
    {
        [$application, $identity] = $this->authenticatedPrincipal($accessToken);
        $policy = $this->policy((int) $application->id);
        $this->consumeRateLimit((int) $application->id, 'current_password', (string) $identity->id . '|' . $ip, $policy);
        $auth = IdentityAuth::where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)->where('status', 1)->find();
        if ($auth === null || !$this->verifyPassword($password, (string) $auth->password_hash)) {
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.current_password_verify', 'identity', (int) $identity->id, 'failed', $requestId, ['reason' => 'authentication_failed'], (string) $identity->id);
            throw new ApiException('SAND_IAM_AUTH_CURRENT_PASSWORD_INVALID', 401);
        }
    }

    /** @return array{step_up:true,expires_in:int} */
    public function stepUpPassword(string $accessToken, string $password, string $ip, string $requestId): array
    {
        $this->assertCurrentPassword($accessToken, $password, $ip, $requestId);
        $session = $this->authenticatedSession($accessToken);
        Db::startTrans();
        try {
            $session = AuthSession::where('id', (int) $session->id)->where('status', 1)->lock(true)->find();
            if ($session === null || $session->revoked_time !== null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            $session->save(['step_up_time' => $this->now(), 'step_up_method' => 'password']);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        $application = Application::find((int) $session->application_id);
        $this->audit($application ? (int) $application->organization_id : null, (int) $session->application_id, 'identity.step_up', 'auth_session', (int) $session->id, 'succeeded', $requestId, ['method' => 'password'], (string) $session->identity_id);
        return ['step_up' => true, 'expires_in' => 300];
    }

    public function assertMfaAttemptAllowed(int $applicationId, string $subject): void
    {
        $this->requirePepper();
        $this->consumeRateLimit($applicationId, 'mfa_verify', $subject, $this->policy($applicationId));
    }

    /** Public passkey challenge allocation follows the same live application, network, and rate-limit boundary as login. */
    public function assertPasskeyOptionsAllowed(Application $application, string $ip): Application
    {
        $this->requirePepper();
        $live = $this->livePasskeyApplication($application, $ip);
        $this->consumeRateLimit((int) $live->id, 'passkey_options', $ip, $this->policy((int) $live->id));
        return $live;
    }

    /** Passkey completion rechecks the same mutable application boundary without consuming the options budget. */
    public function assertPasskeyFinishAllowed(Application $application, string $ip): Application
    {
        $this->requirePepper();
        $this->consumePasskeyFinishRate($application, $ip);
        Db::startTrans();
        try {
            $live = $this->lockPasskeyFinishBoundaryInTransaction($application, $ip);
            Db::commit();
            return $live;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /** Consume the completion-specific limit before entering a caller-owned passkey transaction. */
    public function consumePasskeyFinishRate(Application $application, string $ip): void
    {
        $this->requirePepper();
        $this->consumeRateLimit((int) $application->id, 'passkey_finish', $ip, $this->policy((int) $application->id));
    }

    /**
     * Locks passkey mutable state in the only permitted order. The caller must
     * hold its transaction until it has consumed its challenge and credential.
     */
    public function lockPasskeyFinishBoundaryInTransaction(Application $application, string $ip): Application
    {
        $this->requirePepper();
        $live = $this->lockActiveApplicationInTransaction($application);
        $experience = ApplicationExperience::where('application_id', (int) $live->id)->lock(true)->find();
        $networkPolicy = ApplicationNetworkPolicy::where('application_id', (int) $live->id)->lock(true)->find();
        AuthPolicy::where('application_id', (int) $live->id)->where('status', 1)->lock(true)->find();
        $this->assertPasskeyExperienceAllows($experience);
        $this->assertNetworkAllowedForRecord($live, $networkPolicy, $ip);
        return $live;
    }

    public function isLoginMethodEnabled(Application $application, string $method): bool
    {
        try {
            $this->assertExperienceAllows($application, $method);
            return true;
        } catch (ApiException) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function issueSessionAfterMfa(Application $application, Identity $identity, string $ip, string $userAgent, string $requestId): array
    {
        Db::startTrans();
        try {
            $live = $this->lockActiveApplicationInTransaction($application);
            $result = $this->issueSessionAfterMfaInTransaction($live, $identity, $ip, $userAgent, $requestId);
            Db::commit();
            return $result;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /** Issues a local-password MFA session while the caller owns the boundary transaction. */
    public function issueSessionAfterMfaInTransaction(Application $application, Identity $identity, string $ip, string $userAgent, string $requestId): array
    {
        $this->requirePepper();
        $auth = IdentityAuth::where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)->where('status', 1)->lock(true)->find();
        $liveIdentity = Identity::where('id', (int) $identity->id)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
        if ($auth === null || $liveIdentity === null || !$this->pepperVersionMatches((string) $auth->pepper_version)) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        $policy = $this->policy((int) $application->id);
        $this->assertNetworkAllowed($application, $ip);
        $tokens = $this->createSessionInTransaction($application, $liveIdentity, $ip, $userAgent, $policy);
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.mfa_login', 'identity', (int) $liveIdentity->id, 'succeeded', $requestId, [], (string) $liveIdentity->id);
        return $this->tokenResponse($tokens, $liveIdentity, $policy);
    }

    /** @return array<string,mixed> */
    public function issueFederatedSession(Application $application, Identity $identity, IdentityBinding $binding, string $ip, string $userAgent, string $requestId): array
    {
        Db::startTrans();
        try {
            $result = $this->issueFederatedSessionInTransaction($application, $identity, $binding, $ip, $userAgent, $requestId);
            Db::commit();
            return $result;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /**
     * Issues a federation session inside a caller-owned transaction after that
     * caller has locked and consumed its own one-time credential.
     *
     * @return array<string,mixed>
     */
    public function issueFederatedSessionInTransaction(Application $application, Identity $identity, IdentityBinding $binding, string $ip, string $userAgent, string $requestId): array
    {
        $this->requirePepper();
        $liveApplication = Application::where('id', (int) $application->id)->where('status', 1)->lock(true)->find();
        $liveIdentity = Identity::where('id', (int) $identity->id)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
        $organization = $liveApplication === null ? null : Organization::where('id', (int) $liveApplication->organization_id)->where('status', 1)->lock(true)->find();
        if ($liveApplication === null || $liveIdentity === null || $organization === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        $this->assertNetworkAllowed($liveApplication, $ip);
        $this->assertFederatedBinding($binding, (int) $application->id, (int) $identity->id);
        $policy = $this->policy((int) $application->id);
        $tokens = $this->newTokens($policy);
        $session = AuthSession::create(['application_id' => (int) $application->id, 'identity_id' => (int) $identity->id, 'identity_binding_id' => (int) $binding->id, 'auth_method' => 'federation', 'access_token_hash' => $this->tokenHash($tokens['access_token']), 'refresh_token_hash' => $this->tokenHash($tokens['refresh_token']), 'pepper_version' => $this->pepperVersion(), 'access_expire_time' => $tokens['access_expire_time'], 'refresh_expire_time' => $tokens['refresh_expire_time'], 'last_used_time' => $this->now(), 'ip_hash' => $this->secretHash($ip), 'user_agent' => mb_substr($userAgent, 0, 255), 'status' => 1]);
        AuthRefreshToken::create(['session_id' => (int) $session->id, 'token_hash' => $this->tokenHash($tokens['refresh_token']), 'pepper_version' => $this->pepperVersion(), 'expire_time' => $tokens['refresh_expire_time'], 'status' => 1]);
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.federation_login', 'identity', (int) $identity->id, 'succeeded', $requestId, ['identity_binding_id' => (int) $binding->id], (string) $identity->id);
        return $this->tokenResponse($tokens + ['session_id' => (int) $session->id], $identity, $policy);
    }

    /** @return array<string,mixed> */
    public function completeFederatedLogin(Application $application, Identity $identity, IdentityBinding $binding, string $ip, string $userAgent, string $requestId): array
    {
        $this->assertFederatedBinding($binding, (int) $application->id, (int) $identity->id);
        if ((new MfaService())->hasEnabledFactor((int) $application->id, (int) $identity->id)) {
            return (new MfaService())->beginFederatedLogin($application, $identity, $binding, $requestId);
        }
        return $this->issueFederatedSession($application, $identity, $binding, $ip, $userAgent, $requestId);
    }

    /** @param array<string, mixed> $payload */
    public function requestVerification(array $payload, string $requestId): void
    {
        $this->requirePepper();
        $application = $this->application($payload);
        $this->assertNetworkAllowed($application, (string) ($payload['_ip'] ?? ''));
        $purpose = $this->purpose((string) ($payload['purpose'] ?? ''));
        if ($purpose === 'password_reset' && !($payload['_password_reset_endpoint'] ?? false)) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: use password/forgot for password_reset codes', 400);
        $channel = $this->channel((string) ($payload['channel'] ?? ''));
        $this->assertPurposeChannel($purpose, $channel);
        $policy = $this->policy((int) $application->id);
        $this->consumeRateLimit((int) $application->id, 'verification_request', $this->identifier((string) ($payload['identifier'] ?? '')) . '|' . (string) ($payload['_ip'] ?? ''), $policy);
        $auth = $this->findAuth((int) $application->id, $this->identifier((string) ($payload['identifier'] ?? '')));
        if ($auth === null) {
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.verification_request', 'identity', null, 'succeeded', $requestId, ['purpose' => $purpose, 'channel' => $channel, 'delivery' => 'not_applicable', 'reason' => 'identity_not_found']);
            return;
        }
        $destination = $channel === 'email' ? (string) $auth->email : (string) $auth->phone;
        if ($destination === '') {
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.verification_request', 'identity', (int) $auth->identity_id, 'succeeded', $requestId, ['purpose' => $purpose, 'channel' => $channel, 'delivery' => 'not_applicable', 'reason' => 'destination_unavailable']);
            return;
        }
        try {
            $this->sendVerification($application, $auth, $purpose, $channel, $destination, $requestId, $policy);
        } catch (ApiException $exception) {
            if (!in_array($exception->getMessage(), ['SAND_IAM_AUTH_VERIFICATION_CHANNEL_UNAVAILABLE', 'SAND_IAM_AUTH_VERIFICATION_DELIVERY_FAILED'], true)) {
                throw $exception;
            }
            // The delivery helper has already recorded an internal, specific
            // reason. Keep the public endpoint neutral for every identity.
        }
    }

    /** @param array<string, mixed> $payload */
    public function confirmVerification(array $payload, string $requestId): void
    {
        $this->requirePepper();
        $application = $this->application($payload);
        $this->assertNetworkAllowed($application, (string) ($payload['_ip'] ?? ''));
        $auth = $this->findAuth((int) $application->id, $this->identifier((string) ($payload['identifier'] ?? '')));
        if ($auth === null) {
            throw new ApiException('SAND_IAM_AUTH_VERIFICATION_INVALID', 400);
        }
        $channel = $this->channel((string) ($payload['channel'] ?? ''));
        $purpose = $this->purpose((string) ($payload['purpose'] ?? ''));
        if ($purpose === 'password_reset') throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: password_reset codes are consumed only by password/reset', 400);
        $this->assertPurposeChannel($purpose, $channel);
        $this->consumeRateLimit((int) $application->id, 'verification_confirm', (string) $auth->identity_id . '|' . (string) ($payload['_ip'] ?? ''), $this->policy((int) $application->id));
        Db::startTrans();
        try {
            $this->consumeVerification($application, $auth, $purpose, $channel, (string) ($payload['code'] ?? ''), $requestId);
            if ($channel === 'email') {
                $auth->save(['email_verified_time' => $this->now()]);
            } else {
                $auth->save(['phone_verified_time' => $this->now()]);
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($exception instanceof ApiException && $exception->getMessage() === 'SAND_IAM_AUTH_VERIFICATION_INVALID') {
                $this->persistVerificationFailure($application, $auth, $purpose, $channel, $requestId);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    public function resetPassword(array $payload, string $requestId): void
    {
        $this->requirePepper();
        $application = $this->application($payload);
        $this->assertNetworkAllowed($application, (string) ($payload['_ip'] ?? ''));
        $policy = $this->policy((int) $application->id);
        $auth = $this->findAuth((int) $application->id, $this->identifier((string) ($payload['identifier'] ?? '')));
        if ($auth === null) {
            throw new ApiException('SAND_IAM_AUTH_VERIFICATION_INVALID', 400);
        }
        $password = (string) ($payload['password'] ?? '');
        $this->assertPasswordPolicy($password, $policy);
        $channel = $this->channel((string) ($payload['channel'] ?? ''));
        $this->consumeRateLimit((int) $application->id, 'password_reset', (string) $auth->identity_id . '|' . (string) ($payload['_ip'] ?? ''), $policy);
        Db::startTrans();
        try {
            $this->consumeVerification($application, $auth, 'password_reset', $channel, (string) ($payload['code'] ?? ''), $requestId);
            $auth->save(['password_hash' => $this->passwordHash($password), 'password_changed_time' => $this->now(), 'failed_login_count' => 0, 'locked_until' => null]);
            AuthSession::where('identity_id', (int) $auth->identity_id)->where('application_id', (int) $application->id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
            AuthRefreshToken::whereIn('session_id', AuthSession::where('identity_id', (int) $auth->identity_id)->where('application_id', (int) $application->id)->column('id'))->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($exception instanceof ApiException && $exception->getMessage() === 'SAND_IAM_AUTH_VERIFICATION_INVALID') {
                $this->persistVerificationFailure($application, $auth, 'password_reset', $channel, $requestId);
            }
            throw $exception;
        }
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.password_reset', 'identity', (int) $auth->identity_id, 'succeeded', $requestId);
    }

    public function changePassword(
        string $accessToken,
        string $currentPassword,
        string $newPassword,
        string $ip,
        string $requestId,
    ): void {
        $this->requirePepper();
        $session = $this->accessSession($accessToken);
        $policy = $this->policy((int) $session->application_id);
        $this->assertPasswordPolicy($newPassword, $policy);
        $this->consumeRateLimit(
            (int) $session->application_id,
            'password_change',
            (string) $session->identity_id . '|' . $ip,
            $policy,
        );
        Db::startTrans();
        try {
            $application = Application::where('id', (int) $session->application_id)->where('status', 1)->lock(true)->find();
            $identity = $application === null ? null : Identity::where('id', (int) $session->identity_id)
                ->where('application_id', (int) $application->id)
                ->where('status', 1)
                ->lock(true)
                ->find();
            $auth = $identity === null ? null : IdentityAuth::where('identity_id', (int) $identity->id)
                ->where('application_id', (int) $application->id)
                ->where('status', 1)
                ->lock(true)
                ->find();
            if ($application === null || $identity === null || $auth === null || !$this->verifyPassword($currentPassword, (string) $auth->password_hash)) {
                throw new ApiException('SAND_IAM_AUTH_CURRENT_PASSWORD_INVALID', 401);
            }
            if ($this->verifyPassword($newPassword, (string) $auth->password_hash)) {
                throw new ApiException('SAND_IAM_AUTH_PASSWORD_REUSE_FORBIDDEN', 400);
            }
            $now = $this->now();
            $auth->save([
                'password_hash' => $this->passwordHash($newPassword),
                'password_changed_time' => $now,
                'failed_login_count' => 0,
                'locked_until' => null,
            ]);
            $sessionIds = AuthSession::where('identity_id', (int) $identity->id)
                ->where('application_id', (int) $application->id)
                ->where('status', 1)
                ->lock(true)
                ->column('id');
            if ($sessionIds !== []) {
                AuthSession::whereIn('id', $sessionIds)->update(['status' => 2, 'revoked_time' => $now]);
                AuthRefreshToken::whereIn('session_id', $sessionIds)->where('status', 1)->update(['status' => 2, 'revoked_time' => $now]);
            }
            $this->audit(
                (int) $application->organization_id,
                (int) $application->id,
                'identity.password_change',
                'identity',
                (int) $identity->id,
                'succeeded',
                $requestId,
                ['sessions_revoked' => count($sessionIds)],
                (string) $identity->id,
            );
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($exception instanceof ApiException && str_contains($exception->getMessage(), 'CURRENT_PASSWORD_INVALID')) {
                try {
                    $application = Application::find((int) $session->application_id);
                    $this->audit(
                        $application ? (int) $application->organization_id : null,
                        (int) $session->application_id,
                        'identity.password_change',
                        'identity',
                        (int) $session->identity_id,
                        'denied',
                        $requestId,
                        ['reason' => 'current_password_invalid'],
                        (string) $session->identity_id,
                    );
                } catch (\Throwable) {
                    // Preserve the authentication failure if audit storage is unavailable.
                }
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload */
    private function application(array $payload): Application
    {
        $organizationCode = trim((string) ($payload['organization_code'] ?? ''));
        $applicationCode = trim((string) ($payload['application_code'] ?? ''));
        $application = Application::alias('application')
            ->join('sand_iam_organization organization', 'organization.id = application.organization_id')
            ->field('application.*')
            ->where('organization.code', $organizationCode)->where('organization.status', 1)
            ->where('application.code', $applicationCode)->where('application.status', 1)->find();
        if ($application === null) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        return $application;
    }

    private function findAuth(int $applicationId, string $identifier): ?IdentityAuth
    {
        return IdentityAuth::where('application_id', $applicationId)->where(function ($query) use ($identifier): void {
            $query->where('username', $identifier)->whereOr('email', $identifier)->whereOr('phone', $identifier);
        })->find();
    }

    /** @return array<string, mixed> */
    private function createSession(Application $application, Identity $identity, IdentityAuth $auth, string $ip, string $userAgent, array $policy): array
    {
        $this->assertNetworkAllowed($application, $ip);
        Db::startTrans();
        try {
            $liveApplication = $this->lockActiveApplicationInTransaction($application);
            $tokens = $this->createSessionInTransaction($liveApplication, $identity, $ip, $userAgent, $policy);
            Db::commit();
            return $tokens;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    /** @return array{access_token:string,refresh_token:string,access_expire_time:string,refresh_expire_time:string,session_id:int} */
    private function createSessionInTransaction(Application $application, Identity $identity, string $ip, string $userAgent, array $policy): array
    {
        $tokens = $this->newTokens($policy);
        $session = AuthSession::create([
            'application_id' => (int) $application->id,
            'identity_id' => (int) $identity->id,
            'access_token_hash' => $this->tokenHash($tokens['access_token']),
            'refresh_token_hash' => $this->tokenHash($tokens['refresh_token']),
            'pepper_version' => $this->pepperVersion(),
            'access_expire_time' => $tokens['access_expire_time'],
            'refresh_expire_time' => $tokens['refresh_expire_time'],
            'last_used_time' => $this->now(),
            'ip_hash' => $this->secretHash($ip),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'status' => 1,
        ]);
        AuthRefreshToken::create(['session_id' => (int) $session->id, 'token_hash' => $this->tokenHash($tokens['refresh_token']), 'pepper_version' => $this->pepperVersion(), 'expire_time' => $tokens['refresh_expire_time'], 'status' => 1]);
        return $tokens + ['session_id' => (int) $session->id];
    }

    /** @return array{access_token:string,refresh_token:string,access_expire_time:string,refresh_expire_time:string} */
    private function newTokens(array $policy): array
    {
        return [
            'access_token' => 'siam_at_' . bin2hex(random_bytes(32)),
            'refresh_token' => 'siam_rt_' . bin2hex(random_bytes(32)),
            'access_expire_time' => date('Y-m-d H:i:s', time() + (int) $policy['access_token_ttl_seconds']),
            'refresh_expire_time' => date('Y-m-d H:i:s', time() + (int) $policy['refresh_token_ttl_seconds']),
        ];
    }

    /** @param array<string, mixed> $tokens @return array<string, mixed> */
    private function tokenResponse(array $tokens, Identity $identity, array $policy): array
    {
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) $policy['access_token_ttl_seconds'],
            'session_id' => $tokens['session_id'],
            'identity' => ['id' => (int) $identity->id, 'code' => (string) $identity->code, 'display_name' => (string) $identity->display_name],
        ];
    }

    private function accessSession(string $accessToken): AuthSession
    {
        $this->requirePepper();
        $session = AuthSession::where('access_token_hash', $this->tokenHash($accessToken))->where('status', 1)->find();
        if ($session === null || $session->revoked_time !== null || $this->expired($session->access_expire_time)) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        $application = Application::where('id', (int) $session->application_id)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        $identity = Identity::where('id', (int) $session->identity_id)->where('application_id', (int) $session->application_id)->where('status', 1)->find();
        if ($application === null || $organization === null || $identity === null || !$this->pepperVersionMatches((string) ($session->pepper_version ?? ''))) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        if ((string) ($session->auth_method ?? 'local_password') === 'federation') {
            $binding = IdentityBinding::where('id', (int) $session->identity_binding_id)->find();
            if ($binding === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
            try {
                $this->assertFederatedBinding($binding, (int) $application->id, (int) $identity->id);
            } catch (ApiException) {
                $session->save(['status' => 2, 'revoked_time' => $this->now()]);
                AuthRefreshToken::where('session_id', (int) $session->id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
                throw new ApiException('SAND_IAM_AUTH_FEDERATED_SOURCE_REVOKED', 401);
            }
        } else {
            $auth = IdentityAuth::where('identity_id', (int) $session->identity_id)->where('application_id', (int) $session->application_id)->where('status', 1)->find();
            if ($auth === null || !$this->pepperVersionMatches((string) ($auth->pepper_version ?? ''))) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        return $session;
    }

    private function assertFederatedBinding(IdentityBinding $binding, int $applicationId, int $identityId): void
    {
        if ((int) $binding->status !== 1 || (string) ($binding->source_state ?? 'active') !== 'active' || (int) $binding->application_id !== $applicationId || (int) $binding->identity_id !== $identityId) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        $provider = IdentityProvider::where('id', (int) $binding->identity_provider_id)->where('status', 1)->find();
        $mount = $provider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('status', 1)->find();
        $organization = $provider === null ? null : Organization::where('id', (int) $provider->organization_id)->where('status', 1)->find();
        if ($provider === null || $mount === null || $organization === null || (int) $organization->id !== (int) $mount->organization_id) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
    }

    private function sendVerification(Application $application, IdentityAuth $auth, string $purpose, string $channel, string $destination, string $requestId, array $policy): void
    {
        $code = (string) random_int(10_000_000, 99_999_999);
        $destinationHash = $this->secretHash($destination);
        AuthVerification::where('application_id', (int) $application->id)->where('identity_id', (int) $auth->identity_id)->where('purpose', $purpose)->where('channel', $channel)->where('status', 1)->update(['status' => 2]);
        $verification = AuthVerification::create([
            'application_id' => (int) $application->id,
            'identity_id' => (int) $auth->identity_id,
            'purpose' => $purpose,
            'channel' => $channel,
            'destination_hash' => $destinationHash,
            'code_hash' => $this->verificationHash((int) $application->id, $purpose, $channel, $destinationHash, $code),
            'expire_time' => date('Y-m-d H:i:s', time() + (int) $policy['verification_ttl_seconds']),
            'pepper_version' => $this->pepperVersion(),
            'status' => 1,
        ]);
        try {
            $sent = (new MessageProviderService())->sendCode((int) $application->id, $channel, $destination, $code, ['purpose' => $purpose, 'expire_time' => $verification->expire_time, 'request_id' => $requestId]);
        } catch (ApiException $exception) {
            if ($exception->getMessage() === 'SAND_IAM_MESSAGE_PROVIDER_UNAVAILABLE') {
                $sent = false;
            } else {
                $this->recordVerificationDeliveryFailure($application, $verification, $purpose, $channel, $requestId, 'delivery_failed');
                throw new ApiException('SAND_IAM_AUTH_VERIFICATION_DELIVERY_FAILED', 503);
            }
        } catch (\Throwable $exception) {
            $this->recordVerificationDeliveryFailure($application, $verification, $purpose, $channel, $requestId, 'delivery_failed');
            throw new ApiException('SAND_IAM_AUTH_VERIFICATION_DELIVERY_FAILED', 503);
        }
        if (!$sent) {
            $sender = (string) config('plugin.sand-iam.app.auth_' . $channel . '_sender', '');
            if ($sender === '' || !class_exists($sender) || !is_callable([$sender, 'send'])) {
                $this->recordVerificationDeliveryFailure($application, $verification, $purpose, $channel, $requestId, 'channel_unavailable');
                throw new ApiException('SAND_IAM_AUTH_VERIFICATION_CHANNEL_UNAVAILABLE', 503);
            }
            try {
                $sender::send($destination, $code, ['purpose' => $purpose, 'expire_time' => $verification->expire_time]);
            } catch (\Throwable $exception) {
                $this->recordVerificationDeliveryFailure($application, $verification, $purpose, $channel, $requestId, 'delivery_failed');
                throw new ApiException('SAND_IAM_AUTH_VERIFICATION_DELIVERY_FAILED', 503);
            }
        }
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.verification_request', 'auth_verification', (int) $verification->id, 'succeeded', $requestId, ['purpose' => $purpose, 'channel' => $channel]);
    }

    private function recordVerificationDeliveryFailure(Application $application, AuthVerification $verification, string $purpose, string $channel, string $requestId, string $reason): void
    {
        $verification->save(['status' => 2]);
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.verification_request', 'auth_verification', (int) $verification->id, 'failed', $requestId, ['purpose' => $purpose, 'channel' => $channel, 'reason' => $reason]);
    }

    private function consumeVerification(Application $application, IdentityAuth $auth, string $purpose, string $channel, string $code, string $requestId): void
    {
        $destination = $channel === 'email' ? (string) $auth->email : (string) $auth->phone;
        $destinationHash = $this->secretHash($destination);
        $verification = AuthVerification::where('application_id', (int) $application->id)->where('identity_id', (int) $auth->identity_id)->where('purpose', $purpose)->where('channel', $channel)->where('destination_hash', $destinationHash)->where('status', 1)->order('id', 'desc')->lock(true)->find();
        if ($verification === null || !$this->pepperVersionMatches((string) ($verification->pepper_version ?? '')) || $verification->consumed_time !== null || $this->expired($verification->expire_time) || !hash_equals((string) $verification->code_hash, $this->verificationHash((int) $application->id, $purpose, $channel, $destinationHash, $code))) {
            throw new ApiException('SAND_IAM_AUTH_VERIFICATION_INVALID', 400);
        }
        $verification->save(['consumed_time' => $this->now(), 'status' => 2]);
        $this->audit((int) $application->organization_id, (int) $application->id, 'identity.verification_confirm', 'auth_verification', (int) $verification->id, 'succeeded', $requestId, ['purpose' => $purpose, 'channel' => $channel]);
    }

    private function persistVerificationFailure(Application $application, IdentityAuth $auth, string $purpose, string $channel, string $requestId): void
    {
        $destination = $channel === 'email' ? (string) $auth->email : (string) $auth->phone;
        Db::startTrans();
        try {
            $verification = AuthVerification::where('application_id', (int) $application->id)->where('identity_id', (int) $auth->identity_id)->where('purpose', $purpose)->where('channel', $channel)->where('destination_hash', $this->secretHash($destination))->where('status', 1)->order('id', 'desc')->lock(true)->find();
            if ($verification !== null) {
                $attempts = (int) $verification->attempt_count + 1;
                $verification->save(['attempt_count' => $attempts, 'status' => $attempts >= 5 ? 2 : 1]);
            }
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.verification_confirm', 'auth_verification', $verification ? (int) $verification->id : null, 'failed', $requestId, ['purpose' => $purpose, 'channel' => $channel]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    private function consumeRateLimit(int $applicationId, string $action, string $subject, array $policy, int $retry = 0): void
    {
        $hash = $this->secretHash($subject);
        Db::startTrans();
        try {
            $row = AuthRateLimit::where('application_id', $applicationId)->where('action', $action)->where('subject_hash', $hash)->lock(true)->find();
            if ($row === null || strtotime((string) $row->window_start) <= time() - 60) {
                if ($row === null) {
                    AuthRateLimit::create(['application_id' => $applicationId, 'action' => $action, 'subject_hash' => $hash, 'window_start' => $this->now(), 'attempt_count' => 1]);
                } else {
                    $row->save(['window_start' => $this->now(), 'attempt_count' => 1]);
                }
            } else {
                if ((int) $row->attempt_count >= (int) $policy['rate_limit_per_minute']) throw new ApiException('SAND_IAM_AUTH_RATE_LIMITED', 429);
                $row->save(['attempt_count' => (int) $row->attempt_count + 1]);
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($retry === 0 && (str_contains($exception->getMessage(), '23505') || str_contains($exception->getMessage(), 'unique'))) {
                $this->consumeRateLimit($applicationId, $action, $subject, $policy, 1);
                return;
            }
            throw $exception;
        }
    }

    private function auditForSession(AuthSession $session, string $action, string $outcome, string $requestId): void
    {
        $application = Application::find((int) $session->application_id);
        $this->audit($application ? (int) $application->organization_id : null, (int) $session->application_id, $action, 'auth_session', (int) $session->id, $outcome, $requestId, ['identity_id' => (int) $session->identity_id], (string) $session->identity_id);
    }

    /** @param array<string, mixed> $context */
    private function audit(?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId, array $context = [], string $actorRef = 'redacted'): void
    {
        $this->auditWriter->write('application_user', $actorRef, $organizationId, $applicationId, $action, $resourceType, $resourceId, $outcome, $this->auditRequestId($requestId), $context);
    }

    private function auditRequestId(string $requestId): string { return $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)); }
    private function requirePepper(): void { if ((string) config('plugin.sand-iam.app.auth_pepper', '') === '') throw new ApiException('SAND_IAM_AUTH_CONFIGURATION_UNAVAILABLE', 503); }
    private function pepper(): string { return (string) config('plugin.sand-iam.app.auth_pepper', ''); }
    private function pepperVersion(): string { return (string) config('plugin.sand-iam.app.auth_pepper_version', 'v1'); }
    private function pepperVersionMatches(string $version): bool { return $version !== '' && hash_equals($this->pepperVersion(), $version); }
    private function secretHash(string $value): string { return hash_hmac('sha256', $value, $this->pepper()); }
    private function tokenHash(string $token): string { return $this->secretHash('token:' . $token); }
    private function verificationHash(int $applicationId, string $purpose, string $channel, string $destinationHash, string $code): string { return $this->secretHash(implode('|', ['verification', $applicationId, $purpose, $channel, $destinationHash, $code])); }
    private function passwordHash(string $password): string { $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT; return password_hash($this->secretHash('password:' . $password), $algorithm); }
    private function verifyPassword(string $password, string $hash): bool { return password_verify($this->secretHash('password:' . $password), $hash); }
    private function now(): string { return date('Y-m-d H:i:s'); }
    private function expired(mixed $value): bool { return $value !== null && $value !== '' && strtotime((string) $value) <= time(); }
    private function locked(mixed $value): bool { return $value !== null && $value !== '' && strtotime((string) $value) > time(); }
    private function username(string $value): string { $value = strtolower(trim($value)); if (!preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $value)) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: invalid username', 400); return $value; }
    private function identifier(string $value): string { $value = trim($value); if ($value === '') throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: identifier is required', 400); return filter_var($value, FILTER_VALIDATE_EMAIL) ? strtolower($value) : (str_starts_with($value, '+') ? '+' . preg_replace('/[^0-9]/', '', substr($value, 1)) : strtolower($value)); }
    private function email(string $value): ?string { $value = trim($value); if ($value === '') return null; $value = strtolower($value); if (!filter_var($value, FILTER_VALIDATE_EMAIL)) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: invalid email', 400); return $value; }
    private function phone(string $value): ?string { $value = trim($value); if ($value === '') return null; $value = '+' . preg_replace('/[^0-9]/', '', ltrim($value, '+')); if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $value)) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: invalid phone', 400); return $value; }
    private function displayName(string $value): string { $value = trim($value); if ($value === '' || mb_strlen($value) > 128) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: invalid display_name', 400); return $value; }
    private function channel(string $value): string { if (!in_array($value, ['email', 'phone'], true)) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: invalid verification channel', 400); return $value; }
    private function purpose(string $value): string { if (!in_array($value, ['email_verify', 'phone_verify', 'password_reset'], true)) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: invalid verification purpose', 400); return $value; }
    /** @return array<string, int> */
    private function policy(int $applicationId): array
    {
        $defaults = ['registration_enabled' => 0, 'password_min_length' => 12, 'password_max_length' => 128, 'require_uppercase' => 1, 'require_lowercase' => 1, 'require_digit' => 1, 'require_symbol' => 1, 'require_email_verification' => 0, 'require_phone_verification' => 0, 'require_captcha' => 0, 'access_token_ttl_seconds' => self::DEFAULT_ACCESS_TTL_SECONDS, 'refresh_token_ttl_seconds' => self::DEFAULT_REFRESH_TTL_SECONDS, 'verification_ttl_seconds' => self::DEFAULT_VERIFICATION_TTL_SECONDS, 'max_login_failures' => self::DEFAULT_MAX_LOGIN_FAILURES, 'lock_seconds' => self::DEFAULT_LOCK_SECONDS, 'rate_limit_per_minute' => self::DEFAULT_MAX_RATE_ATTEMPTS];
        $record = AuthPolicy::where('application_id', $applicationId)->where('status', 1)->find();
        if ($record === null) return $defaults;
        foreach (array_keys($defaults) as $field) {
            $defaults[$field] = (str_starts_with($field, 'require_') || $field === 'registration_enabled') ? ((int) $record->$field === 1 ? 1 : 0) : (int) $record->$field;
        }
        return $defaults;
    }
    private function assertExperienceAllows(Application $application, string $action): void
    {
        if ((int) config('plugin.sand-iam.app.application_experience_enabled', 0) !== 1) return;
        $experience = ApplicationExperience::where('application_id', (int) $application->id)->where('status', 1)->find();
        if ($experience === null) return;
        $methods = $this->configuredStrings($experience->login_methods ?? null);
        $method = $action === 'login' || $action === 'register' ? 'password' : $action;
        if (!in_array($method, $methods, true)) throw new ApiException('SAND_IAM_AUTH_METHOD_DISABLED', 403);
        if ($action === 'register' && (string) $experience->registration_mode !== 'open') throw new ApiException('SAND_IAM_AUTH_REGISTRATION_DISABLED', 403);
    }

    private function livePasskeyApplication(Application $application, string $ip): Application
    {
        $live = Application::where('id', (int) $application->id)->where('status', 1)->find();
        $organization = $live === null ? null : Organization::where('id', (int) $live->organization_id)->where('status', 1)->find();
        if ($live === null || $organization === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        $this->assertNetworkAllowed($live, $ip);
        $this->assertExperienceAllows($live, 'passkey');
        return $live;
    }

    private function lockActiveApplicationInTransaction(Application $application): Application
    {
        $organization = Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
        $live = $organization === null ? null : Application::where('id', (int) $application->id)->where('organization_id', (int) $organization->id)->where('status', 1)->lock(true)->find();
        if ($organization === null || $live === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        return $live;
    }

    private function assertPasskeyExperienceAllows(?ApplicationExperience $experience): void
    {
        if ((int) config('plugin.sand-iam.app.application_experience_enabled', 0) !== 1 || $experience === null || (int) $experience->status !== 1) return;
        if (!in_array('passkey', $this->configuredStrings($experience->login_methods ?? null), true)) {
            throw new ApiException('SAND_IAM_AUTH_METHOD_DISABLED', 403);
        }
    }

    /** Registration fields are a live contract, not merely a portal rendering hint. */
    private function assertRegistrationFields(Application $application, array $payload): void
    {
        if ((int) config('plugin.sand-iam.app.application_experience_enabled', 0) !== 1) return;
        $experience = ApplicationExperience::where('application_id', (int) $application->id)->where('status', 1)->find();
        if ($experience === null) return;
        $fields = $this->configuredStrings($experience->registration_fields ?? null);
        if ($fields === [] || !in_array('username', $fields, true) || (!in_array('email', $fields, true) && !in_array('phone', $fields, true))) {
            throw new ApiException('SAND_IAM_AUTH_REGISTRATION_CONFIGURATION_INVALID', 503);
        }
        foreach ($fields as $field) {
            if (!in_array($field, ['username', 'display_name', 'email', 'phone'], true)) throw new ApiException('SAND_IAM_AUTH_REGISTRATION_CONFIGURATION_INVALID', 503);
            if (trim((string) ($payload[$field] ?? '')) === '') throw new ApiException('SAND_IAM_AUTH_REGISTRATION_FIELD_REQUIRED', 400);
        }
        foreach (['display_name', 'email', 'phone'] as $field) {
            if (!in_array($field, $fields, true) && trim((string) ($payload[$field] ?? '')) !== '') throw new ApiException('SAND_IAM_AUTH_REGISTRATION_FIELD_UNSUPPORTED', 400);
        }
    }

    /** @return list<string> */
    private function configuredStrings(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value)) return [];
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) return [];
            $item = trim($item);
            if ($item === '') return [];
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }
    private function assertNetworkAllowed(Application $application, string $ip): void
    {
        $record = ApplicationNetworkPolicy::where('application_id', (int) $application->id)->where('status', 1)->find();
        $this->assertNetworkAllowedForRecord($application, $record, $ip);
    }

    private function assertNetworkAllowedForRecord(Application $application, ?ApplicationNetworkPolicy $record, string $ip): void
    {
        if ((int) config('plugin.sand-iam.app.application_network_policy_enabled', 0) !== 1 || $record === null || (int) $record->status !== 1) return;
        if (!NetworkPolicy::allows(['allow_cidrs' => $record->allow_cidrs, 'deny_cidrs' => $record->deny_cidrs], $ip)) {
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.network_access', 'application', (int) $application->id, 'denied', '', ['reason' => 'network_policy']);
            throw new ApiException('SAND_IAM_NETWORK_ACCESS_DENIED: 当前网络地址不允许访问该应用', 403);
        }
    }
    private function verifyCaptcha(Application $application, string $token, string $action, string $ip, string $requestId): void
    {
        try {
            (new MessageProviderService())->verifyCaptcha((int) $application->id, $token, $action, ['ip_hash' => $this->secretHash($ip), 'request_id' => $requestId]);
            $this->audit((int) $application->organization_id, (int) $application->id, 'identity.captcha_verify', 'application', (int) $application->id, 'succeeded', $requestId, ['action' => $action]);
        } catch (\Throwable $exception) {
            try {
                $this->audit((int) $application->organization_id, (int) $application->id, 'identity.captcha_verify', 'application', (int) $application->id, 'failed', $requestId, ['action' => $action]);
            } catch (\Throwable) {
                // Preserve the captcha decision when audit storage is unavailable.
            }
            throw $exception;
        }
    }
    private function verificationSatisfied(IdentityAuth $auth, array $policy): bool { return (!(bool) $policy['require_email_verification'] || $auth->email_verified_time !== null) && (!(bool) $policy['require_phone_verification'] || $auth->phone_verified_time !== null); }
    private function assertPurposeChannel(string $purpose, string $channel): void { if (($purpose === 'email_verify' && $channel !== 'email') || ($purpose === 'phone_verify' && $channel !== 'phone')) throw new ApiException('SAND_IAM_AUTH_INVALID_REQUEST: verification purpose and channel do not match', 400); }
    private function assertPasswordPolicy(string $password, array $policy): void { if (strlen($password) < (int) $policy['password_min_length'] || strlen($password) > (int) $policy['password_max_length'] || ((bool) $policy['require_lowercase'] && !preg_match('/[a-z]/', $password)) || ((bool) $policy['require_uppercase'] && !preg_match('/[A-Z]/', $password)) || ((bool) $policy['require_digit'] && !preg_match('/[0-9]/', $password)) || ((bool) $policy['require_symbol'] && !preg_match('/[^a-zA-Z0-9]/', $password))) throw new ApiException('SAND_IAM_AUTH_PASSWORD_POLICY_VIOLATION', 400); }
}
