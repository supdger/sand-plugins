<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\AuthChallenge;
use plugin\SandIam\app\model\MfaFactor;
use plugin\SandIam\app\model\MfaRecoveryCode;
use plugin\SandIam\app\model\WebauthnCredential;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * Multi-factor authentication and WebAuthn. All state is application scoped.
 * Browser binary values are base64url strings at this API boundary.
 */
final class MfaService
{
    private const CHALLENGE_TTL = 300;
    private const MAX_CHALLENGE_ATTEMPTS = 5;

    public function __construct(private readonly AuditWriter $auditWriter = new AuditWriter()) {}

    public function hasEnabledFactor(int $applicationId, int $identityId): bool
    {
        return MfaFactor::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->where('type', 'totp')->find() !== null
            || WebauthnCredential::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->find() !== null;
    }

    /** A passkey is a login method only while the current RP policy is valid. */
    public function hasUsablePasskey(Application $application, int $identityId): bool
    {
        if (!(new HumanAuthService())->isLoginMethodEnabled($application, 'passkey')) {
            return false;
        }
        try {
            $this->webAuthnPolicy($application);
        } catch (ApiException) {
            return false;
        }
        return WebauthnCredential::where('application_id', (int) $application->id)->where('identity_id', $identityId)->where('status', 1)->find() !== null;
    }

    /** @return array<string,mixed> */
    public function beginPasswordLogin(Application $application, Identity $identity, string $requestId): array
    {
        $methods = $this->methods((int) $application->id, (int) $identity->id);
        $challenge = $this->createChallenge($application, $identity, 'mfa_login', ['methods' => $methods]);
        $result = ['mfa_required' => true, 'challenge_token' => $challenge['token'], 'expires_in' => self::CHALLENGE_TTL, 'methods' => $methods];
        if (in_array('passkey', $methods, true)) $result['public_key'] = $this->webAuthnRequestOptions($application, $identity, $challenge['raw']);
        $this->audit($application, $identity, 'identity.mfa_login_start', 'mfa_challenge', $challenge['id'], 'succeeded', $requestId);
        return $result;
    }

    /** @return array<string,mixed> */
    public function beginFederatedLogin(Application $application, Identity $identity, IdentityBinding $binding, string $requestId): array
    {
        $methods = $this->methods((int) $application->id, (int) $identity->id);
        $challenge = $this->createChallenge($application, $identity, 'mfa_login', [
            'methods' => $methods,
            'auth_method' => 'federation',
            'identity_binding_id' => (int) $binding->id,
        ]);
        $result = ['mfa_required' => true, 'challenge_token' => $challenge['token'], 'expires_in' => self::CHALLENGE_TTL, 'methods' => $methods];
        if (in_array('passkey', $methods, true)) $result['public_key'] = $this->webAuthnRequestOptions($application, $identity, $challenge['raw']);
        $this->audit($application, $identity, 'identity.federation_mfa_login_start', 'mfa_challenge', $challenge['id'], 'succeeded', $requestId, ['identity_binding_id' => (int) $binding->id]);
        return $result;
    }

    /** @return array<string,mixed> */
    public function beginStepUp(string $accessToken, string $requestId): array
    {
        [$application, $identity] = $this->current($accessToken);
        $session = (new HumanAuthService())->authenticatedSession($accessToken);
        $methods = $this->methods((int) $application->id, (int) $identity->id);
        if ($methods === []) throw new ApiException('SAND_IAM_MFA_METHOD_UNAVAILABLE', 400);
        $challenge = $this->createChallenge($application, $identity, 'mfa_step_up', ['methods' => $methods, 'auth_session_id' => (int) $session->id]);
        $result = ['mfa_required' => true, 'challenge_token' => $challenge['token'], 'expires_in' => self::CHALLENGE_TTL, 'methods' => $methods];
        if (in_array('passkey', $methods, true)) $result['public_key'] = $this->webAuthnRequestOptions($application, $identity, $challenge['raw']);
        $this->audit($application, $identity, 'identity.step_up_start', 'mfa_challenge', $challenge['id'], 'succeeded', $requestId, ['method' => 'mfa']);
        return $result;
    }

    /** @return array<string,mixed> */
    public function totpStart(string $accessToken, string $name, string $currentPassword, string $requestId, string $ip = ''): array
    {
        $requestId = RequestId::normalize($requestId);
        [$application, $identity] = $this->current($accessToken);
        $name = $this->factorName($name, '身份验证器');
        $operations = new IdempotencyService();
        $actorRef = $this->idempotencyActor($application, $identity);
        $fingerprint = IdempotencyService::fingerprint([
            'name' => $name,
            'password_proof' => $this->hash('current-password:' . $currentPassword),
        ]);
        $replay = $operations->replayIfCompleted('application_user', $actorRef, 'identity.totp_start', $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];
        (new HumanAuthService())->assertCurrentPassword($accessToken, $currentPassword, $ip, $requestId);
        $this->requireEncryptionKey();
        $result = $operations->execute(
            'application_user',
            $actorRef,
            'identity.totp_start',
            $requestId,
            $fingerprint,
            'mfa_factor',
            function () use ($application, $identity, $name, $requestId): array {
                $lockedIdentity = Identity::where('id', (int) $identity->id)
                    ->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
                if ($lockedIdentity === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
                if (MfaFactor::where('application_id', (int) $application->id)
                    ->where('identity_id', (int) $identity->id)
                    ->where('type', 'totp')->where('status', 1)->find()) {
                    throw new ApiException('SAND_IAM_MFA_TOTP_ALREADY_ENABLED', 409);
                }
                MfaFactor::where('application_id', (int) $application->id)
                    ->where('identity_id', (int) $identity->id)
                    ->where('type', 'totp')->where('status', 2)
                    ->whereNull('revoked_time')->update(['revoked_time' => $this->now()]);
                $secret = random_bytes(20);
                $factor = MfaFactor::create([
                    'application_id' => (int) $application->id, 'identity_id' => (int) $identity->id,
                    'type' => 'totp', 'name' => $name, 'encrypted_secret' => $this->encrypt($secret),
                    'encryption_version' => $this->encryptionVersion(), 'status' => 2,
                ]);
                $base32 = $this->base32Encode($secret);
                $label = rawurlencode((string) $application->name . ':' . (string) $identity->code);
                $issuer = rawurlencode((string) $application->name);
                $this->audit($application, $identity, 'identity.totp_start', 'mfa_factor', (int) $factor->id, 'succeeded', $requestId);
                return [
                    'resource_id' => (int) $factor->id,
                    'result' => [
                        'factor_id' => (int) $factor->id,
                        'secret' => $base32,
                        'otpauth_uri' => "otpauth://totp/{$label}?secret={$base32}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30",
                    ],
                ];
            },
        );
        return $result['result'];
    }

    /** @return array<string,mixed> */
    public function totpConfirm(string $accessToken, int $factorId, string $code, string $requestId): array
    {
        $requestId = RequestId::normalize($requestId);
        [$application, $identity] = $this->current($accessToken);
        $operations = new IdempotencyService();
        $actorRef = $this->idempotencyActor($application, $identity);
        $fingerprint = IdempotencyService::fingerprint([
            'factor_id' => $factorId,
            'code_proof' => $this->hash('totp-confirm:' . $code),
        ]);
        $replay = $operations->replayIfCompleted('application_user', $actorRef, 'identity.totp_confirm', $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];
        $factor = $this->factor($application, $identity, $factorId, 'totp', true);
        $secret = $this->decrypt((string) $factor->encrypted_secret);
        if ((int) $factor->status === 1) {
            $replayedCounter = $this->verifyTotp($secret, $code, null);
            if ($replayedCounter !== null && $factor->last_used_counter !== null && $replayedCounter <= (int) $factor->last_used_counter) {
                $this->audit($application, $identity, 'identity.totp_confirm', 'mfa_factor', $factorId, 'denied', $requestId, ['reason' => 'totp_replayed']);
                throw new ApiException('SAND_IAM_MFA_TOTP_REPLAYED', 400);
            }
            throw new ApiException('SAND_IAM_MFA_TOTP_ALREADY_ENABLED', 409);
        }
        $counter = $this->verifyTotp($secret, $code, null);
        if ($counter === null) {
            $this->audit($application, $identity, 'identity.totp_confirm', 'mfa_factor', $factorId, 'failed', $requestId);
            throw new ApiException('SAND_IAM_MFA_TOTP_INVALID', 400);
        }
        $result = $operations->execute(
            'application_user',
            $actorRef,
            'identity.totp_confirm',
            $requestId,
            $fingerprint,
            'mfa_factor',
            function () use ($application, $identity, $factorId, $counter, $requestId): array {
                $locked = MfaFactor::where('id', $factorId)->where('application_id', (int) $application->id)
                    ->where('identity_id', (int) $identity->id)->where('status', 2)->whereNull('revoked_time')->lock(true)->find();
                if ($locked === null) throw new ApiException('SAND_IAM_MFA_TOTP_ALREADY_ENABLED', 409);
                $locked->save(['status' => 1, 'last_used_counter' => $counter, 'last_used_time' => $this->now()]);
                $codes = $this->replaceRecoveryCodes($application, $identity, $locked);
                $this->audit($application, $identity, 'identity.totp_confirm', 'mfa_factor', $factorId, 'succeeded', $requestId);
                return ['resource_id' => $factorId, 'result' => ['enabled' => true, 'recovery_codes' => $codes]];
            },
        );
        return $result['result'];
    }

    /** @return list<array<string,mixed>> */
    public function factors(string $accessToken): array
    {
        [$application, $identity] = $this->current($accessToken);
        $totp = array_map(static fn (MfaFactor $f): array => ['id' => (int) $f->id, 'type' => 'totp', 'name' => (string) $f->name, 'status' => (int) $f->status, 'create_time' => $f->create_time, 'last_used_time' => $f->last_used_time], MfaFactor::where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)->where('status', 1)->order('id', 'desc')->select()->all());
        $passkeys = array_map(static fn (WebauthnCredential $f): array => ['id' => (int) $f->id, 'type' => 'passkey', 'name' => (string) $f->name, 'status' => (int) $f->status, 'create_time' => $f->create_time, 'last_used_time' => $f->last_used_time], WebauthnCredential::where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)->where('status', 1)->order('id', 'desc')->select()->all());
        return array_merge($totp, $passkeys);
    }

    public function rename(string $accessToken, int $factorId, string $name, string $requestId, string $type = 'totp'): void
    {
        [$application, $identity] = $this->current($accessToken);
        Db::startTrans();
        try {
            $factor = $this->factorRecord($application, $identity, $factorId, $type);
            $factor->save(['name' => $this->factorName($name, (string) $factor->name)]);
            $this->audit($application, $identity, 'identity.mfa_rename', 'mfa_factor', $factorId, 'succeeded', $requestId);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
    }

    public function revoke(string $accessToken, int $factorId, string $password, string $requestId, string $type = 'totp', string $ip = ''): void
    {
        [$application, $identity] = $this->current($accessToken);
        (new HumanAuthService())->assertCurrentPassword($accessToken, $password, $ip, $requestId);
        Db::startTrans();
        try {
            $factor = $this->factorRecord($application, $identity, $factorId, $type);
            $factor->save(['status' => 2, 'revoked_time' => $this->now()]);
            if ($type === 'totp') MfaRecoveryCode::where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)->where('factor_id', $factorId)->where('status', 1)->update(['status' => 2]);
            $this->audit($application, $identity, 'identity.mfa_revoke', 'mfa_factor', $factorId, 'succeeded', $requestId);
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
    }

    /** @return array{recovery_codes:list<string>} */
    public function regenerateRecoveryCodes(string $accessToken, string $password, string $requestId, string $ip = ''): array
    {
        $requestId = RequestId::normalize($requestId);
        [$application, $identity] = $this->current($accessToken);
        $operations = new IdempotencyService();
        $actorRef = $this->idempotencyActor($application, $identity);
        $fingerprint = IdempotencyService::fingerprint([
            'factor_type' => 'totp',
            'password_proof' => $this->hash('current-password:' . $password),
        ]);
        $replay = $operations->replayIfCompleted('application_user', $actorRef, 'identity.recovery_regenerate', $requestId, $fingerprint);
        if ($replay !== null) return $replay['result'];
        (new HumanAuthService())->assertCurrentPassword($accessToken, $password, $ip, $requestId);
        $result = $operations->execute(
            'application_user',
            $actorRef,
            'identity.recovery_regenerate',
            $requestId,
            $fingerprint,
            'mfa_factor',
            function () use ($application, $identity, $requestId): array {
                $factor = MfaFactor::where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)
                    ->where('type', 'totp')->where('status', 1)->order('id', 'asc')->lock(true)->find();
                if ($factor === null) throw new ApiException('SAND_IAM_MFA_TOTP_REQUIRED_FOR_RECOVERY_CODES', 400);
                $codes = $this->replaceRecoveryCodes($application, $identity, $factor);
                $this->audit($application, $identity, 'identity.recovery_regenerate', 'mfa_factor', (int) $factor->id, 'succeeded', $requestId);
                return ['resource_id' => (int) $factor->id, 'result' => ['recovery_codes' => $codes]];
            },
        );
        return $result['result'];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function verifyLoginChallenge(array $payload, string $ip, string $requestId): array
    {
        $requestId = RequestId::normalize($requestId);
        $application = $this->application($payload);
        $token = (string) ($payload['challenge_token'] ?? '');
        $challengeProof = $this->hash('mfa-token:' . $token);
        $operations = new IdempotencyService();
        $fingerprint = IdempotencyService::fingerprint([
            'application_id' => (int) $application->id,
            'challenge_response' => $payload,
        ]);
        $replay = $operations->replayIfCompleted(
            'mfa_challenge',
            $challengeProof,
            'identity.mfa_challenge_verify',
            $requestId,
            $fingerprint,
        );
        if ($replay !== null) {
            return $this->recoverChallengeVerificationResult($replay['result'], $token, $requestId, $ip);
        }
        $rateIdentity = $token === '' ? null : AuthChallenge::where('application_id', (int) $application->id)
            ->whereIn('purpose', ['mfa_login', 'mfa_step_up'])->where('token_hash', $challengeProof)->value('identity_id');
        $humanAuth = new HumanAuthService();
        $humanAuth->assertMfaAttemptAllowed((int) $application->id, (string) ($rateIdentity ?: 'unknown') . '|' . $ip);
        $challenge = null;
        $identity = null;
        try {
            $execution = $operations->execute(
                'mfa_challenge',
                $challengeProof,
                'identity.mfa_challenge_verify',
                $requestId,
                $fingerprint,
                'auth_session',
                function () use (&$application, &$challenge, &$identity, $humanAuth, $token, $challengeProof, $payload, $ip, $requestId): array {
                    $purpose = (string) AuthChallenge::where('application_id', (int) $application->id)->where('token_hash', $challengeProof)->value('purpose');
                    if (!in_array($purpose, ['mfa_login', 'mfa_step_up'], true)) throw new ApiException('SAND_IAM_MFA_CHALLENGE_INVALID', 401);
                    $challenge = $this->lockedChallenge($application, $token, $purpose);
                    $identity = Identity::where('id', (int) $challenge->identity_id)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
                    if ($identity === null) throw new ApiException('SAND_IAM_MFA_CHALLENGE_INVALID', 401);
                    $method = (string) ($payload['method'] ?? '');
                    if ($method === 'totp') $this->verifyChallengeTotp($application, $identity, (string) ($payload['code'] ?? ''));
                    elseif ($method === 'recovery_code') $this->consumeRecoveryCode($application, $identity, (string) ($payload['code'] ?? ''));
                    elseif ($method === 'passkey') $this->verifyAssertion($application, $identity, $this->decrypt((string) $challenge->encrypted_challenge), $payload);
                    else throw new ApiException('SAND_IAM_MFA_METHOD_UNAVAILABLE', 400);
                    $context = json_decode((string) $challenge->context, true);
                    if (!is_array($context)) $context = [];
                    $challenge->save(['status' => 2, 'consumed_time' => $this->now()]);
                    if ($purpose === 'mfa_step_up') {
                        $session = AuthSession::where('id', (int) ($context['auth_session_id'] ?? 0))->where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)->where('status', 1)->lock(true)->find();
                        if ($session === null || $session->revoked_time !== null) throw new ApiException('SAND_IAM_MFA_CHALLENGE_INVALID', 401);
                        $session->save(['step_up_time' => $this->now(), 'step_up_method' => 'mfa']);
                        $result = ['step_up' => true, 'expires_in' => self::CHALLENGE_TTL, 'response_kind' => 'step_up', 'step_up_session_id' => (int) $session->id];
                        $resourceId = (int) $session->id;
                    } elseif (($context['auth_method'] ?? null) === 'federation') {
                        $bindingId = (int) ($context['identity_binding_id'] ?? 0);
                        $binding = IdentityBinding::where('id', $bindingId)->where('application_id', (int) $application->id)->where('identity_id', (int) $identity->id)->lock(true)->find();
                        if ($binding === null) throw new ApiException('SAND_IAM_MFA_CHALLENGE_INVALID', 401);
                        $tokens = $humanAuth->issueFederatedSessionInTransaction($application, $identity, $binding, $ip, (string) ($payload['user_agent'] ?? ''), $requestId);
                        $resourceId = (int) $tokens['session_id'];
                        $result = $tokens + ['response_kind' => 'session', 'encrypted_replay' => $humanAuth->sealSessionTokenResponse($tokens, $token, $requestId, 'mfa-challenge-verify')];
                    } else {
                        $tokens = $humanAuth->issueSessionAfterMfaInTransaction($application, $identity, $ip, (string) ($payload['user_agent'] ?? ''), $requestId);
                        $resourceId = (int) $tokens['session_id'];
                        $result = $tokens + ['response_kind' => 'session', 'encrypted_replay' => $humanAuth->sealSessionTokenResponse($tokens, $token, $requestId, 'mfa-challenge-verify')];
                    }
                    $this->audit($application, $identity, $purpose === 'mfa_step_up' ? 'identity.step_up' : 'identity.mfa_login_verify', 'mfa_challenge', (int) $challenge->id, 'succeeded', $requestId, ['method' => $method]);
                    return ['resource_id' => $resourceId, 'result' => $result];
                },
            );
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && str_starts_with($e->getMessage(), 'SAND_IAM_IDEMPOTENCY_')) throw $e;
            if ($challenge !== null && $e instanceof ApiException) {
                $this->failChallenge($challenge, $application, $identity, $requestId, $e);
            } elseif ($challenge === null) {
                $this->audit($application, null, 'identity.mfa_challenge_verify', 'auth_challenge', null, 'failed', $requestId, ['reason' => 'challenge_invalid']);
            }
            throw $e;
        }
        if ($execution['replayed']) return $this->recoverChallengeVerificationResult($execution['result'], $token, $requestId, $ip);
        $result = $execution['result'];
        unset($result['encrypted_replay'], $result['response_kind'], $result['step_up_session_id']);
        return $result;
    }

    /** @return array<string,mixed> */
    public function passkeyRegistrationOptions(string $accessToken, string $name, string $currentPassword, string $requestId, string $ip = ''): array
    {
        [$application, $identity] = $this->current($accessToken);
        $humanAuth = new HumanAuthService();
        $humanAuth->assertCurrentPassword($accessToken, $currentPassword, $ip, $requestId);
        $application = $humanAuth->assertPasskeyOptionsAllowed($application, $ip);
        $this->webAuthnPolicy($application);
        $challenge = $this->createChallenge($application, $identity, 'webauthn_register', []);
        $policy = $this->webAuthnPolicy($application);
        $userHandle = $this->b64(random_bytes(32));
        $challenge['model']->save(['encrypted_user_handle' => $this->encrypt($userHandle), 'context' => json_encode(['name' => $this->factorName($name, '通行密钥')], JSON_UNESCAPED_UNICODE)]);
        $this->audit($application, $identity, 'identity.passkey_register_start', 'mfa_challenge', $challenge['id'], 'succeeded', $requestId);
        return ['challenge_token' => $challenge['token'], 'public_key' => ['challenge' => $this->b64($challenge['raw']), 'rp' => ['id' => $policy['rp_id'], 'name' => (string) $application->name], 'user' => ['id' => $userHandle, 'name' => (string) $identity->code, 'displayName' => (string) $identity->display_name], 'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]], 'authenticatorSelection' => ['residentKey' => 'required', 'userVerification' => $policy['user_verification']], 'attestation' => 'none', 'timeout' => self::CHALLENGE_TTL * 1000]];
    }

    /** @param array<string,mixed> $payload */
    public function passkeyRegistrationFinish(string $accessToken, array $payload, string $requestId, string $ip = ''): void
    {
        $requestId = RequestId::normalize($requestId);
        [$application, $identity] = $this->current($accessToken);
        $operations = new IdempotencyService();
        $actorRef = $this->idempotencyActor($application, $identity);
        $fingerprint = IdempotencyService::fingerprint([
            'challenge_token_proof' => $this->hash('passkey-registration-finish:' . (string) ($payload['challenge_token'] ?? '')),
            'credential_response' => $payload,
        ]);
        $replay = $operations->replayIfCompleted(
            'application_user',
            $actorRef,
            'identity.passkey_register_finish',
            $requestId,
            $fingerprint,
        );
        if ($replay !== null) return;
        $humanAuth = new HumanAuthService();
        $humanAuth->consumePasskeyFinishRate($application, $ip);
        $challenge = null;
        try {
            $operations->execute(
                'application_user',
                $actorRef,
                'identity.passkey_register_finish',
                $requestId,
                $fingerprint,
                'webauthn_credential',
                function () use (&$application, &$identity, &$challenge, $humanAuth, $ip, $payload, $requestId): array {
                    // Keep this order with authentication finish: organization, application,
                    // experience/network policy, auth policy, challenge, then credential.
                    $application = $humanAuth->lockPasskeyFinishBoundaryInTransaction($application, $ip);
                    $this->webAuthnPolicy($application);
                    $challenge = $this->lockedChallenge($application, (string) ($payload['challenge_token'] ?? ''), 'webauthn_register', (int) $identity->id);
                    $identity = Identity::where('id', (int) $identity->id)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
                    if ($identity === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
                    $credential = $this->validateRegistration($application, $identity, $this->decrypt((string) $challenge->encrypted_challenge), $payload);
                    $context = json_decode((string) $challenge->context, true) ?: [];
                    $factor = WebauthnCredential::create(['application_id' => (int) $application->id, 'identity_id' => (int) $identity->id, 'name' => (string) ($context['name'] ?? '通行密钥'), 'credential_id' => $credential['id'], 'public_key' => $credential['public_key'], 'sign_count' => $credential['sign_count'], 'user_handle' => $this->decrypt((string) $challenge->encrypted_user_handle), 'status' => 1]);
                    $challenge->save(['status' => 2, 'consumed_time' => $this->now()]);
                    $this->audit($application, $identity, 'identity.passkey_register_finish', 'mfa_challenge', (int) $challenge->id, 'succeeded', $requestId);
                    return ['resource_id' => (int) $factor->id, 'result' => ['registered' => true]];
                },
            );
        } catch (\Throwable $e) {
            if ($challenge !== null) $this->failChallenge($challenge, $application, $identity, $requestId, $e);
            throw $e;
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function passkeyAuthenticationOptions(array $payload, string $requestId, string $ip = ''): array
    {
        $requestId = RequestId::normalize($requestId);
        $application = $this->application($payload);
        $humanAuth = new HumanAuthService();
        $application = $humanAuth->assertPasskeyOptionsBoundary($application, $ip);
        $this->webAuthnPolicy($application);
        $operations = new IdempotencyService();
        $actorRef = 'anonymous:' . (int) $application->id;
        $fingerprint = IdempotencyService::fingerprint([
            'application_id' => (int) $application->id,
            'source_network_proof' => $this->hash('passkey-options-ip:' . $ip),
        ]);
        $replay = $operations->replayIfCompleted(
            'application_user',
            $actorRef,
            'identity.passkey_auth_options',
            $requestId,
            $fingerprint,
        );
        if ($replay !== null) {
            return $this->recoverChallengeResponse($application, $replay['result'], $requestId, 'passkey-authentication-options', $ip);
        }

        $issuedResponse = null;
        $execution = $operations->execute(
            'application_user',
            $actorRef,
            'identity.passkey_auth_options',
            $requestId,
            $fingerprint,
            'mfa_challenge',
            function () use (&$application, &$issuedResponse, $humanAuth, $ip, $requestId): array {
                $application = $humanAuth->lockPasskeyFinishBoundaryInTransaction($application, $ip);
                $this->webAuthnPolicy($application);
                $humanAuth->consumePasskeyOptionsRateInTransaction($application, $ip);
                $challenge = $this->createChallenge($application, null, 'webauthn_auth', []);
                $issuedResponse = [
                    'challenge_token' => $challenge['token'],
                    'public_key' => $this->webAuthnRequestOptions($application, null, $challenge['raw']),
                ];
                $this->audit($application, null, 'identity.passkey_auth_start', 'mfa_challenge', $challenge['id'], 'succeeded', $requestId);
                return [
                    'resource_id' => $challenge['id'],
                    'result' => [
                        'challenge_id' => $challenge['id'],
                        'encrypted_replay' => $this->sealChallengeResponse(
                            $application,
                            $issuedResponse,
                            $requestId,
                            'passkey-authentication-options',
                        ),
                    ],
                ];
            },
        );
        if ($execution['replayed']) {
            return $this->recoverChallengeResponse($application, $execution['result'], $requestId, 'passkey-authentication-options', $ip);
        }
        if (!is_array($issuedResponse)) throw new ApiException('SAND_IAM_MFA_CHALLENGE_RETRY_UNAVAILABLE', 503);
        return $issuedResponse;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function passkeyAuthenticationFinish(array $payload, string $ip, string $requestId): array
    {
        $requestId = RequestId::normalize($requestId);
        $application = $this->application($payload);
        $humanAuth = new HumanAuthService();
        $challengeToken = (string) ($payload['challenge_token'] ?? '');
        $challengeProof = $this->hash('mfa-token:' . $challengeToken);
        $operations = new IdempotencyService();
        $fingerprint = IdempotencyService::fingerprint([
            'application_id' => (int) $application->id,
            'credential_response' => $payload,
        ]);
        $replay = $operations->replayIfCompleted(
            'mfa_challenge',
            $challengeProof,
            'identity.passkey_auth_finish',
            $requestId,
            $fingerprint,
        );
        if ($replay !== null) {
            return $humanAuth->recoverSessionTokenResponse(
                $replay['result'],
                $challengeToken,
                $requestId,
                'passkey-authentication-finish',
                $ip,
            );
        }
        $humanAuth->consumePasskeyFinishRate($application, $ip);
        $credentialId = $this->credentialId($payload);
        $rateIdentity = WebauthnCredential::where('application_id', (int) $application->id)->where('credential_id', $credentialId)->value('identity_id');
        $humanAuth->assertMfaAttemptAllowed((int) $application->id, (string) ($rateIdentity ?: 'unknown') . '|' . $ip);
        $challenge = null;
        $identity = null;
        try {
            $execution = $operations->execute(
                'mfa_challenge',
                $challengeProof,
                'identity.passkey_auth_finish',
                $requestId,
                $fingerprint,
                'auth_session',
                function () use (&$application, &$challenge, &$identity, $humanAuth, $ip, $payload, $credentialId, $requestId, $challengeToken): array {
                    // Do not release these locks before challenge consumption, credential
                    // verification, and session creation. Configuration changes therefore
                    // win before a passkey can write credentials or issue a session.
                    $application = $humanAuth->lockPasskeyFinishBoundaryInTransaction($application, $ip);
                    $this->webAuthnPolicy($application);
                    $challenge = $this->lockedChallenge($application, $challengeToken, 'webauthn_auth');
                    $factor = WebauthnCredential::where('application_id', (int) $application->id)->where('credential_id', $credentialId)->where('status', 1)->lock(true)->find();
                    if ($factor === null) throw new ApiException('SAND_IAM_PASSKEY_CREDENTIAL_NOT_FOUND', 401);
                    $identity = Identity::where('id', (int) $factor->identity_id)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
                    if ($identity === null) throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
                    $this->verifyAssertion($application, $identity, $this->decrypt((string) $challenge->encrypted_challenge), $payload, $factor);
                    $challenge->save([
                        'identity_id' => (int) $identity->id,
                        'status' => 2,
                        'consumed_time' => $this->now(),
                    ]);
                    $tokens = $humanAuth->issueSessionAfterMfaInTransaction($application, $identity, $ip, (string) ($payload['user_agent'] ?? ''), $requestId);
                    $this->audit($application, $identity, 'identity.passkey_auth_finish', 'mfa_challenge', (int) $challenge->id, 'succeeded', $requestId);
                    return [
                        'resource_id' => (int) $tokens['session_id'],
                        'result' => $tokens + [
                            'encrypted_replay' => $humanAuth->sealSessionTokenResponse(
                                $tokens,
                                $challengeToken,
                                $requestId,
                                'passkey-authentication-finish',
                            ),
                        ],
                    ];
                },
            );
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && str_starts_with($e->getMessage(), 'SAND_IAM_IDEMPOTENCY_')) throw $e;
            if ($challenge !== null && $e instanceof ApiException) {
                $this->failChallenge($challenge, $application, $identity, $requestId, $e);
            } elseif ($challenge === null) {
                $this->audit($application, null, 'identity.passkey_auth_finish', 'auth_challenge', null, 'failed', $requestId, ['reason' => 'challenge_invalid']);
            }
            throw $e;
        }
        if ($execution['replayed']) {
            return $humanAuth->recoverSessionTokenResponse(
                $execution['result'],
                $challengeToken,
                $requestId,
                'passkey-authentication-finish',
                $ip,
            );
        }
        $result = $execution['result'];
        unset($result['encrypted_replay']);
        return $result;
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    private function recoverChallengeVerificationResult(array $stored, string $challengeToken, string $requestId, string $ip): array
    {
        if (($stored['response_kind'] ?? null) === 'session') {
            return (new HumanAuthService())->recoverSessionTokenResponse(
                $stored,
                $challengeToken,
                $requestId,
                'mfa-challenge-verify',
                $ip,
            );
        }
        if (($stored['response_kind'] ?? null) !== 'step_up') {
            throw new ApiException('SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE', 401);
        }
        $sessionId = (int) ($stored['step_up_session_id'] ?? 0);
        $session = $sessionId > 0
            ? AuthSession::where('id', $sessionId)->where('status', 1)->whereNotNull('step_up_time')->find()
            : null;
        if ($session === null || $session->revoked_time !== null) {
            throw new ApiException('SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE', 401);
        }
        return ['step_up' => true, 'expires_in' => (int) ($stored['expires_in'] ?? self::CHALLENGE_TTL)];
    }

    /** @param array<string,mixed> $response */
    private function sealChallengeResponse(Application $application, array $response, string $requestId, string $context): string
    {
        return $this->encrypt(json_encode([
            'application_id' => (int) $application->id,
            'request_id' => $requestId,
            'context' => $context,
            'expires_at' => time() + self::CHALLENGE_TTL,
            'response' => $response,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $stored @return array<string,mixed> */
    private function recoverChallengeResponse(Application $application, array $stored, string $requestId, string $context, string $ip): array
    {
        $transactionStarted = false;
        try {
            $envelope = $stored['encrypted_replay'] ?? null;
            $challengeId = (int) ($stored['challenge_id'] ?? 0);
            if (!is_string($envelope) || $envelope === '' || $challengeId < 1) throw new \RuntimeException('missing challenge replay');
            $payload = json_decode($this->decrypt($envelope), true, 16, JSON_THROW_ON_ERROR);
            $response = is_array($payload) ? ($payload['response'] ?? null) : null;
            $challengeToken = is_array($response) ? ($response['challenge_token'] ?? null) : null;
            $publicKey = is_array($response) ? ($response['public_key'] ?? null) : null;
            if (
                !is_array($payload)
                || (int) ($payload['application_id'] ?? 0) !== (int) $application->id
                || !hash_equals($requestId, (string) ($payload['request_id'] ?? ''))
                || !hash_equals($context, (string) ($payload['context'] ?? ''))
                || !is_int($payload['expires_at'] ?? null)
                || $payload['expires_at'] < time()
                || !is_array($response)
                || !is_string($challengeToken)
                || !is_array($publicKey)
                || !is_string($publicKey['challenge'] ?? null)
            ) {
                throw new \RuntimeException('invalid challenge replay');
            }
            Db::startTrans();
            $transactionStarted = true;
            $application = (new HumanAuthService())->lockPasskeyFinishBoundaryInTransaction($application, $ip);
            $this->webAuthnPolicy($application);
            $challenge = AuthChallenge::where('id', $challengeId)
                ->where('application_id', (int) $application->id)
                ->where('purpose', 'webauthn_auth')
                ->where('status', 1)
                ->whereNull('consumed_time')
                ->lock(true)
                ->find();
            if (
                $challenge === null
                || strtotime((string) $challenge->expire_time) <= time()
                || !hash_equals((string) $challenge->token_hash, $this->hash('mfa-token:' . $challengeToken))
                || !hash_equals((string) $publicKey['challenge'], $this->b64($this->decrypt((string) $challenge->encrypted_challenge)))
            ) {
                throw new \RuntimeException('inactive challenge replay');
            }
            Db::commit();
            $transactionStarted = false;
            return $response;
        } catch (\Throwable $exception) {
            if ($transactionStarted) Db::rollback();
            if ($exception instanceof ApiException && (
                str_starts_with($exception->getMessage(), 'SAND_IAM_MFA_CONFIGURATION_UNAVAILABLE')
                || str_starts_with($exception->getMessage(), 'SAND_IAM_AUTH_CONFIGURATION_UNAVAILABLE')
            )) {
                throw $exception;
            }
            throw new ApiException('SAND_IAM_MFA_CHALLENGE_RETRY_UNAVAILABLE', 401);
        }
    }

    /** @return array{0:Application,1:Identity} */
    private function current(string $accessToken): array { return (new HumanAuthService())->authenticatedPrincipal($accessToken); }
    private function idempotencyActor(Application $application, Identity $identity): string { return (int) $application->id . ':' . (int) $identity->id; }
    /** @param array<string,mixed> $payload */
    private function application(array $payload): Application { return (new HumanAuthService())->resolveApplication($payload); }
    private function factor(Application $app, Identity $identity, int $id, ?string $type = null, bool $allowPending = false): MfaFactor { $q = MfaFactor::where('id', $id)->where('application_id', (int) $app->id)->where('identity_id', (int) $identity->id)->whereNull('revoked_time'); if ($type) $q->where('type', $type); if (!$allowPending) $q->where('status', 1); $factor = $q->find(); if ($factor === null) throw new ApiException('SAND_IAM_MFA_FACTOR_NOT_FOUND', 404); return $factor; }
    private function factorRecord(Application $app, Identity $identity, int $id, string $type): MfaFactor|WebauthnCredential { if (!in_array($type, ['totp', 'passkey'], true)) throw new ApiException('SAND_IAM_MFA_METHOD_UNAVAILABLE', 400); if ($type === 'passkey') { $credential = WebauthnCredential::where('id', $id)->where('application_id', (int) $app->id)->where('identity_id', (int) $identity->id)->where('status', 1)->find(); if ($credential === null) throw new ApiException('SAND_IAM_MFA_FACTOR_NOT_FOUND', 404); return $credential; } return $this->factor($app, $identity, $id); }
    /** @return list<string> */
    private function methods(int $applicationId, int $identityId): array { $out = []; if (MfaFactor::where('application_id', $applicationId)->where('identity_id', $identityId)->where('type', 'totp')->where('status', 1)->find()) { $out[] = 'totp'; if (MfaRecoveryCode::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->find()) $out[] = 'recovery_code'; } if (WebauthnCredential::where('application_id', $applicationId)->where('identity_id', $identityId)->where('status', 1)->find()) $out[] = 'passkey'; return $out; }
    /** @return array{id:int,token:string,raw:string,model:AuthChallenge} */
    private function createChallenge(Application $app, ?Identity $identity, string $purpose, array $context): array { $this->requireEncryptionKey(); $token = 'siam_mc_' . bin2hex(random_bytes(32)); $raw = random_bytes(32); $model = AuthChallenge::create(['application_id' => (int) $app->id, 'identity_id' => $identity ? (int) $identity->id : null, 'purpose' => $purpose, 'token_hash' => $this->hash('mfa-token:' . $token), 'encrypted_challenge' => $this->encrypt($raw), 'encryption_version' => $this->encryptionVersion(), 'expire_time' => date('Y-m-d H:i:s', time() + self::CHALLENGE_TTL), 'context' => json_encode($context, JSON_UNESCAPED_UNICODE), 'status' => 1]); return ['id' => (int) $model->id, 'token' => $token, 'raw' => $raw, 'model' => $model]; }
    private function lockedChallenge(Application $app, string $token, string $purpose, ?int $identityId = null): AuthChallenge { if ($token === '') throw new ApiException('SAND_IAM_MFA_CHALLENGE_INVALID', 401); $q = AuthChallenge::where('application_id', (int) $app->id)->where('purpose', $purpose)->where('token_hash', $this->hash('mfa-token:' . $token))->where('status', 1); if ($identityId !== null) $q->where('identity_id', $identityId); $challenge = $q->lock(true)->find(); if ($challenge === null || $challenge->consumed_time !== null || strtotime((string) $challenge->expire_time) <= time()) throw new ApiException('SAND_IAM_MFA_CHALLENGE_INVALID', 401); return $challenge; }
    private function failChallenge(AuthChallenge $challenge, Application $app, ?Identity $identity, string $requestId, \Throwable $e): void { Db::startTrans(); try { $locked = AuthChallenge::where('id', (int) $challenge->id)->where('status', 1)->whereNull('consumed_time')->lock(true)->find(); if ($locked !== null) { $attempts = (int) $locked->attempt_count + 1; $locked->save(['attempt_count' => $attempts, 'status' => $attempts >= self::MAX_CHALLENGE_ATTEMPTS ? 2 : 1]); } Db::commit(); } catch (\Throwable) { Db::rollback(); } $this->audit($app, $identity, 'identity.mfa_challenge_verify', 'auth_challenge', (int) $challenge->id, 'failed', $requestId, ['reason' => $e instanceof ApiException ? substr($e->getMessage(), 0, 64) : 'verification_failed']); }
    private function verifyChallengeTotp(Application $app, Identity $identity, string $code): void { $factor = MfaFactor::where('application_id', (int) $app->id)->where('identity_id', (int) $identity->id)->where('type', 'totp')->where('status', 1)->lock(true)->find(); if ($factor === null) throw new ApiException('SAND_IAM_MFA_METHOD_UNAVAILABLE', 400); $secret = $this->decrypt((string) $factor->encrypted_secret); $counter = $this->verifyTotp($secret, $code, $factor->last_used_counter === null ? null : (int) $factor->last_used_counter); if ($counter === null) { $replayed = $this->verifyTotp($secret, $code, null); if ($replayed !== null && $factor->last_used_counter !== null && $replayed <= (int) $factor->last_used_counter) throw new ApiException('SAND_IAM_MFA_TOTP_REPLAYED', 401); throw new ApiException('SAND_IAM_MFA_TOTP_INVALID', 401); } $factor->save(['last_used_counter' => $counter, 'last_used_time' => $this->now()]); }
    private function consumeRecoveryCode(Application $app, Identity $identity, string $code): void { $normalized = strtoupper(str_replace(['-', ' '], '', trim($code))); if (!preg_match('/^[A-Z2-9]{10}$/', $normalized)) throw new ApiException('SAND_IAM_MFA_RECOVERY_CODE_INVALID', 401); $row = MfaRecoveryCode::where('application_id', (int) $app->id)->where('identity_id', (int) $identity->id)->where('code_hash', $this->hash('recovery:' . $app->id . ':' . $identity->id . ':' . $normalized))->where('status', 1)->lock(true)->find(); if ($row === null || $row->used_time !== null) throw new ApiException('SAND_IAM_MFA_RECOVERY_CODE_INVALID', 401); $row->save(['status' => 2, 'used_time' => $this->now()]); }
    /** @return list<string> */
    private function replaceRecoveryCodes(Application $app, Identity $identity, MfaFactor $factor): array { MfaRecoveryCode::where('application_id', (int) $app->id)->where('identity_id', (int) $identity->id)->where('status', 1)->update(['status' => 2]); $codes=[]; for($i=0;$i<10;$i++){ $code=$this->recoveryCode(); $codes[]=$code; $normalized=str_replace('-', '', $code); MfaRecoveryCode::create(['application_id'=>(int)$app->id,'identity_id'=>(int)$identity->id,'factor_id'=>(int)$factor->id,'code_hash'=>$this->hash('recovery:' . $app->id . ':' . $identity->id . ':' . $normalized),'generation'=>time(),'status'=>1]); } return $codes; }
    private function recoveryCode(): string { $chars='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $value=''; for($i=0;$i<10;$i++) $value.=$chars[random_int(0,strlen($chars)-1)]; return substr($value,0,5).'-'.substr($value,5); }
    private function verifyTotp(string $secret, string $code, ?int $lastCounter): ?int { if (!preg_match('/^[0-9]{6}$/', $code)) return null; $base=intdiv(time(),30); for($n=-1;$n<=1;$n++){ $counter=$base+$n; if($counter<0 || ($lastCounter !== null && $counter <= $lastCounter)) continue; if(hash_equals($this->hotp($secret,$counter),$code)) return $counter; } return null; }
    private function hotp(string $secret,int $counter): string { $mac=hash_hmac('sha1',pack('N2',0,$counter),$secret,true); $offset=ord($mac[19])&15; $value=((ord($mac[$offset])&127)<<24)|(ord($mac[$offset+1])<<16)|(ord($mac[$offset+2])<<8)|ord($mac[$offset+3]); return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT); }
    /** @return array{rp_id:string,origins:list<string>,user_verification:string} */
    private function webAuthnPolicy(Application $app): array { $policy=AuthPolicy::where('application_id',(int)$app->id)->where('status',1)->find(); $rp=strtolower(trim((string)($policy?->webauthn_rp_id ?? ''))); $origins=$policy?->webauthn_allowed_origins ?? []; if(is_string($origins)) $origins=json_decode($origins,true)?:[]; $uv=(string)($policy?->webauthn_user_verification ?? 'required'); if($rp==='' || !is_array($origins) || $origins===[] || !in_array($uv,['required','preferred','discouraged'],true)) throw new ApiException('SAND_IAM_PASSKEY_CONFIGURATION_UNAVAILABLE',503); foreach($origins as $origin){ if(!is_string($origin)||!preg_match('#^https://[a-z0-9.-]+(?::[0-9]{1,5})?$#',$origin)) throw new ApiException('SAND_IAM_PASSKEY_CONFIGURATION_UNAVAILABLE',503); $host=(string)parse_url($origin,PHP_URL_HOST); $port=parse_url($origin,PHP_URL_PORT); if(($host!==$rp&&!str_ends_with($host,'.'.$rp))||($port!==null&&($port<1||$port>65535))) throw new ApiException('SAND_IAM_PASSKEY_CONFIGURATION_UNAVAILABLE',503); } return ['rp_id'=>$rp,'origins'=>array_values($origins),'user_verification'=>$uv]; }
    /** @return array<string,mixed> */
    private function webAuthnRequestOptions(Application $app, ?Identity $identity, string $raw): array { $policy=$this->webAuthnPolicy($app); $result=['challenge'=>$this->b64($raw),'rpId'=>$policy['rp_id'],'timeout'=>self::CHALLENGE_TTL*1000,'userVerification'=>$policy['user_verification']]; if($identity!==null){$allow=[];foreach(WebauthnCredential::where('application_id',(int)$app->id)->where('identity_id',(int)$identity->id)->where('status',1)->select() as $factor)$allow[]=['type'=>'public-key','id'=>(string)$factor->credential_id];$result['allowCredentials']=$allow;}return $result; }
    /** @param array<string,mixed> $payload @return array{id:string,public_key:string,sign_count:int} */
    private function validateRegistration(Application $app, Identity $identity, string $expected, array $payload): array { $response=$payload['response']??null; if(!is_array($response)) throw new ApiException('SAND_IAM_PASSKEY_REGISTRATION_INVALID',400); $this->clientData((string)($response['clientDataJSON']??''),'webauthn.create',$expected,$app); $encodedAttestation=(string)($response['attestationObject']??''); if(strlen($encodedAttestation)>131072)throw new ApiException('SAND_IAM_PASSKEY_PAYLOAD_TOO_LARGE',413);$attestation=$this->decode($this->b64d($encodedAttestation)); if(!is_array($attestation)||($attestation['fmt']??null)!=='none'||!is_string($attestation['authData']??null)||!is_array($attestation['attStmt']??null)||count($attestation['attStmt'])!==0) throw new ApiException('SAND_IAM_PASSKEY_ATTESTATION_INVALID',400); $parsed=$this->authenticatorData($attestation['authData'],$app,true); $id=$this->credentialId($payload); if(!hash_equals($id,$this->b64((string)$parsed['credential_id']))||WebauthnCredential::where('application_id',(int)$app->id)->where('credential_id',$id)->lock(true)->find()) throw new ApiException('SAND_IAM_PASSKEY_CREDENTIAL_CONFLICT',409); $cose=$parsed['credential_public_key']; if(!is_array($cose)||(int)($cose[3]??0)!==-7||(int)($cose[1]??0)!==2||(int)($cose[-1]??0)!==1||!is_string($cose[-2]??null)||!is_string($cose[-3]??null)) throw new ApiException('SAND_IAM_PASSKEY_ALGORITHM_UNSUPPORTED',400); return ['id'=>$id,'public_key'=>$this->es256Pem($cose[-2],$cose[-3]),'sign_count'=>$parsed['sign_count']]; }
    /** @param array<string,mixed> $payload */
    private function verifyAssertion(Application $app, Identity $identity, string $expected, array $payload, ?WebauthnCredential $explicit = null): void { $response=$payload['response']??null;if(!is_array($response))throw new ApiException('SAND_IAM_PASSKEY_ASSERTION_INVALID',401);$this->clientData((string)($response['clientDataJSON']??''),'webauthn.get',$expected,$app);$encodedAuth=(string)($response['authenticatorData']??'');$encodedSignature=(string)($response['signature']??'');if(strlen($encodedAuth)>16384||strlen($encodedSignature)>4096)throw new ApiException('SAND_IAM_PASSKEY_PAYLOAD_TOO_LARGE',413);$authData=$this->b64d($encodedAuth);$parsed=$this->authenticatorData($authData,$app,false);$factor=$explicit??WebauthnCredential::where('application_id',(int)$app->id)->where('identity_id',(int)$identity->id)->where('credential_id',$this->credentialId($payload))->where('status',1)->lock(true)->find();if($factor===null)throw new ApiException('SAND_IAM_PASSKEY_CREDENTIAL_NOT_FOUND',401);$encodedHandle=(string)($response['userHandle']??'');if($explicit!==null&&$encodedHandle==='')throw new ApiException('SAND_IAM_PASSKEY_USER_HANDLE_INVALID',401);if($encodedHandle!==''&&!hash_equals((string)$factor->user_handle,$this->b64($this->b64d($encodedHandle))))throw new ApiException('SAND_IAM_PASSKEY_USER_HANDLE_INVALID',401);$signature=$this->b64d($encodedSignature);$clientRaw=$this->b64d((string)$response['clientDataJSON']);if(openssl_verify($authData.hash('sha256',$clientRaw,true),$signature,(string)$factor->public_key,OPENSSL_ALGO_SHA256)!==1)throw new ApiException('SAND_IAM_PASSKEY_SIGNATURE_INVALID',401);$old=(int)$factor->sign_count;$next=(int)$parsed['sign_count'];if($old!==0&&$next!==0&&$next<=$old)throw new ApiException('SAND_IAM_PASSKEY_SIGN_COUNT_REPLAYED',401);$factor->save(['sign_count'=>$next,'last_used_time'=>$this->now()]); }
    /** @return array<string,mixed> */
    private function clientData(string $encoded,string $type,string $expected,Application $app): array { if(strlen($encoded)>16384)throw new ApiException('SAND_IAM_PASSKEY_PAYLOAD_TOO_LARGE',413);$raw=$this->b64d($encoded);$value=json_decode($raw,true,16);$policy=$this->webAuthnPolicy($app);if(!is_array($value)||($value['type']??'')!==$type||!is_string($value['challenge']??null)||!hash_equals($this->b64($expected),(string)$value['challenge'])||!in_array($value['origin']??'', $policy['origins'],true)||($value['crossOrigin']??false)!==false)throw new ApiException('SAND_IAM_PASSKEY_CLIENT_DATA_INVALID',401);return $value; }
    /** @return array<string,mixed> */
    private function authenticatorData(string $data,Application $app,bool $registration): array { if(strlen($data)<37)throw new ApiException('SAND_IAM_PASSKEY_AUTHENTICATOR_DATA_INVALID',401);$policy=$this->webAuthnPolicy($app);if(!hash_equals(hash('sha256',$policy['rp_id'],true),substr($data,0,32)))throw new ApiException('SAND_IAM_PASSKEY_RP_ID_MISMATCH',401);$flags=ord($data[32]);if(($flags&1)===0)throw new ApiException('SAND_IAM_PASSKEY_USER_PRESENCE_REQUIRED',401);if(($flags&16)!==0&&($flags&8)===0)throw new ApiException('SAND_IAM_PASSKEY_BACKUP_STATE_INVALID',401);if($policy['user_verification']==='required'&&($flags&4)===0)throw new ApiException('SAND_IAM_PASSKEY_USER_VERIFICATION_REQUIRED',401);$count=unpack('N',substr($data,33,4))[1];$result=['sign_count'=>$count];if($registration){if(($flags&64)===0||strlen($data)<55)throw new ApiException('SAND_IAM_PASSKEY_ATTESTATION_INVALID',400);$len=unpack('n',substr($data,53,2))[1];$offset=55+$len;if(strlen($data)<$offset+1)throw new ApiException('SAND_IAM_PASSKEY_ATTESTATION_INVALID',400);$result['credential_id']=substr($data,55,$len);[$key]=$this->decodeAt($data,$offset);$result['credential_public_key']=$key;}return $result; }
    private function credentialId(array $payload): string { $id=(string)($payload['rawId']??$payload['id']??'');$raw=$this->b64d($id);if($raw===''||strlen($raw)>1023)throw new ApiException('SAND_IAM_PASSKEY_CREDENTIAL_INVALID',400);return $this->b64($raw); }
    private function es256Pem(string $x,string $y): string { if(strlen($x)!==32||strlen($y)!==32)throw new ApiException('SAND_IAM_PASSKEY_ALGORITHM_UNSUPPORTED',400);$der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004').$x.$y;return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n"; }
    /** @return array{0:mixed,1:int} */
    private function decodeAt(string $data,int $offset,int $depth=0): array { if($depth>8||!isset($data[$offset]))throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400);$byte=ord($data[$offset++]);$major=$byte>>5;$ai=$byte&31;$length=$ai<24?$ai:($ai===24?(isset($data[$offset])?ord($data[$offset++]):throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400)):($ai===25?(isset($data[$offset+1])?unpack('n',substr($data,$offset,2))[1]:throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400)):($ai===26?(isset($data[$offset+3])?unpack('N',substr($data,$offset,4))[1]:throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400)):throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400))));$offset+=($ai===25?2:($ai===26?4:0));if($major===0)return[$length,$offset];if($major===1)return[-1-$length,$offset];if($major===2||$major===3){if($length>65536||strlen($data)<$offset+$length)throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400);$v=substr($data,$offset,$length);return[$v,$offset+$length];}if($major===4){if($length>128)throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400);$a=[];for($i=0;$i<$length;$i++){[$v,$offset]=$this->decodeAt($data,$offset,$depth+1);$a[]=$v;}return[$a,$offset];}if($major===5){if($length>128)throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400);$a=[];for($i=0;$i<$length;$i++){[$k,$offset]=$this->decodeAt($data,$offset,$depth+1);[$v,$offset]=$this->decodeAt($data,$offset,$depth+1);$a[$k]=$v;}return[$a,$offset];}throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400); }
    private function decode(string $data): mixed { [$value,$offset]=$this->decodeAt($data,0);if($offset!==strlen($data))throw new ApiException('SAND_IAM_PASSKEY_CBOR_INVALID',400);return $value; }
    private function requireEncryptionKey(): void { $this->key(); }
    private function key(?string $version=null): string { $version=$version??$this->encryptionVersion();$encoded='';if(hash_equals($this->encryptionVersion(),$version))$encoded=(string)config('plugin.sand-iam.app.mfa_encryption_key','');if($encoded===''){ $ring=config('plugin.sand-iam.app.mfa_encryption_keys','');if(is_string($ring))$ring=json_decode($ring,true);if(is_array($ring)&&isset($ring[$version])&&is_string($ring[$version]))$encoded=$ring[$version];}$key=base64_decode($encoded,true);if($version===''||$key===false||strlen($key)!==32||!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'))throw new ApiException('SAND_IAM_MFA_CONFIGURATION_UNAVAILABLE',503);return $key; }
    private function encryptionVersion(): string { return (string)config('plugin.sand-iam.app.mfa_encryption_key_version','v1'); }
    private function encrypt(string $value): string {$nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);return $this->encryptionVersion().'.'.$this->b64($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($value,'sand-iam:mfa:'.$this->encryptionVersion(),$nonce,$this->key()));}
    private function decrypt(string $value): string {$parts=explode('.',$value,2);if(count($parts)!==2||$parts[0]==='')throw new ApiException('SAND_IAM_MFA_CONFIGURATION_UNAVAILABLE',503);$wire=$this->b64d($parts[1]);$size=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;if(strlen($wire)<$size+16)throw new ApiException('SAND_IAM_MFA_SECRET_INVALID',400);$plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($wire,$size),'sand-iam:mfa:'.$parts[0],substr($wire,0,$size),$this->key($parts[0]));if($plain===false)throw new ApiException('SAND_IAM_MFA_SECRET_INVALID',400);return $plain;}
    private function hash(string $value): string { $pepper=(string)config('plugin.sand-iam.app.auth_pepper','');if($pepper==='')throw new ApiException('SAND_IAM_AUTH_CONFIGURATION_UNAVAILABLE',503);return hash_hmac('sha256',$value,$pepper); }
    private function base32Encode(string $data): string {$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($data)as$c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5)as$piece){if(strlen($piece)<5)$piece=str_pad($piece,5,'0');$out.=$alphabet[bindec($piece)];}return $out;}
    private function b64(string $value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    private function b64d(string $value): string{$value=strtr($value,'-_','+/');$out=base64_decode($value.str_repeat('=',(4-strlen($value)%4)%4),true);if($out===false)throw new ApiException('SAND_IAM_PASSKEY_BINARY_INVALID',400);return $out;}
    private function factorName(string $name,string $default): string{$name=trim($name);if($name==='')$name=$default;if(mb_strlen($name)>128||preg_match('/[\x00-\x1F\x7F]/u',$name))throw new ApiException('SAND_IAM_MFA_INVALID_REQUEST: 设备名称不能为空、不能包含控制字符且不能超过 128 个字符',400);return $name;}
    private function now(): string{return date('Y-m-d H:i:s');}
    private function audit(Application $app,?Identity $identity,string $action,string $resource,?int $id,string $outcome,string $requestId,array $context=[]): void{$this->auditWriter->write('application_user',$identity?(string)$identity->id:'redacted',(int)$app->organization_id,(int)$app->id,$action,$resource,$id,$outcome,$requestId!==''?substr($requestId,0,96):bin2hex(random_bytes(16)),$context);}
}
