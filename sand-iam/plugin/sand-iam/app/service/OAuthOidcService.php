<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthorizationCode;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\OAuthClient;
use plugin\SandIam\app\model\OAuthAuthorizationRequest;
use plugin\SandIam\app\model\OAuthConsent;
use plugin\SandIam\app\model\OAuthGrant;
use plugin\SandIam\app\model\OAuthToken;
use plugin\SandIam\app\model\OidcSigningKey;
use plugin\SandIam\app\model\OidcLogoutDelivery;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/**
 * OAuth/OIDC provider.  This is deliberately separate from HumanAuthService:
 * the latter owns SandIAM login sessions; this class only delegates an already
 * authenticated application user into a registered OAuth client of that same
 * application.
 */
final class OAuthOidcService
{
    private const ACCESS_TTL = 900;
    private const CLIENT_ACCESS_TTL = 600;
    private const REFRESH_TTL = 2_592_000;
    private const CODE_TTL = 120;
    private const AUTHORIZATION_REQUEST_TTL = 600;
    private const CORE_SCOPES = ['openid', 'profile', 'email', 'offline_access'];

    public function __construct(private readonly AuditWriter $auditWriter = new AuditWriter())
    {
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function beginAuthorization(array $payload, string $requestId): array
    {
        $this->requireConfiguration();
        $client = $this->clientFromRequest($payload, false);
        $redirectUri = (string) ($payload['redirect_uri'] ?? '');
        if (!$this->isExactRedirect($client, $redirectUri, false)) {
            $this->auditClient($client, 'oauth.authorize', 'denied', $requestId, ['reason' => 'redirect_uri']);
            throw new ApiException('SAND_IAM_OAUTH_REDIRECT_URI_INVALID', 400);
        }
        try {
            $this->validateAuthorizePayload($payload, $client);
            $scopes = $this->scopes((string) ($payload['scope'] ?? ''), $client);
            $prompt = $this->prompt((string) ($payload['prompt'] ?? ''));
        } catch (ApiException $exception) {
            return ['redirect_uri' => $this->authorizationErrorRedirect($redirectUri, $exception->getMessage(), (string) ($payload['state'] ?? ''))];
        }
        if ($prompt === 'none') {
            return ['redirect_uri' => $this->authorizationErrorRedirect($redirectUri, 'login_required', (string) ($payload['state'] ?? ''))];
        }
        $request = 'siam_oar_' . bin2hex(random_bytes(32));
        $csrf = 'siam_oac_' . bin2hex(random_bytes(32));
        $stored = ['redirect_uri' => $redirectUri, 'state' => (string) ($payload['state'] ?? ''), 'scope' => implode(' ', $scopes), 'nonce' => (string) ($payload['nonce'] ?? ''), 'code_challenge' => (string) $payload['code_challenge'], 'code_challenge_method' => 'S256', 'prompt' => $prompt, 'max_age' => array_key_exists('max_age', $payload) ? (int) $payload['max_age'] : null];
        Db::startTrans();
        try {
            OAuthAuthorizationRequest::create([
                'application_id' => (int) $client->application_id,
                'client_id' => (int) $client->id,
                'identity_id' => null,
                'auth_session_id' => null,
                'request_hash' => $this->hash('authorization-request:' . $request),
                'csrf_hash' => $this->hash('authorization-csrf:' . $csrf),
                'encrypted_payload' => $this->encryptNonce(json_encode($stored, JSON_THROW_ON_ERROR)),
                'encryption_version' => 'v1',
                'expire_time' => date('Y-m-d H:i:s', time() + self::AUTHORIZATION_REQUEST_TTL),
                'status' => 1,
            ]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        $this->auditClient($client, 'oauth.authorize_begin', 'succeeded', $requestId, ['scope' => $scopes, 'prompt' => $prompt]);
        return ['interaction_uri' => $this->issuer() . '/oauth/interaction?request=' . rawurlencode($request), 'expires_in' => self::AUTHORIZATION_REQUEST_TTL];
    }

    /** @return array<string,mixed> */
    public function interaction(string $requestToken): array
    {
        $request = $this->authorizationRequest($requestToken);
        $client = OAuthClient::where('id', (int) $request->client_id)->where('application_id', (int) $request->application_id)->where('status', 1)->find();
        $application = Application::where('id', (int) $request->application_id)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        $stored = json_decode($this->decryptNonce((string) $request->encrypted_payload), true, 16, JSON_THROW_ON_ERROR);
        if ($client === null || $application === null || $organization === null || !is_array($stored)) throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID', 400);
        return ['client_name' => (string) $client->name, 'client_id' => (string) $client->code, 'organization_code' => (string) $organization->code, 'application_code' => (string) $application->code, 'scope' => $this->scopeValues((string) $stored['scope']), 'expires_in' => max(0, strtotime((string) $request->expire_time) - time()), 'bound' => $request->auth_session_id !== null];
    }

    /** @return array<string,mixed> */
    public function bindInteraction(string $requestToken, string $sessionToken, string $requestId): array
    {
        $session = (new HumanAuthService())->authenticatedSession($sessionToken);
        Db::startTrans();
        try {
            $request = $this->authorizationRequest($requestToken, true);
            $client = OAuthClient::where('id', (int) $request->client_id)->where('application_id', (int) $request->application_id)->where('status', 1)->find();
            $identity = Identity::where('id', (int) $session->identity_id)->where('application_id', (int) $session->application_id)->where('status', 1)->find();
            if ($client === null || $identity === null || (int) $session->application_id !== (int) $request->application_id) throw new ApiException('SAND_IAM_OAUTH_APPLICATION_MISMATCH', 403);
            if ($request->auth_session_id !== null && ((int) $request->auth_session_id !== (int) $session->id || (int) $request->identity_id !== (int) $identity->id)) throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_ALREADY_BOUND', 400);
            $csrf = 'siam_oac_' . bin2hex(random_bytes(32));
            $stored = json_decode($this->decryptNonce((string) $request->encrypted_payload), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($stored) || ($this->hasPrompt($stored, 'login') && strtotime((string) $session->create_time) < strtotime((string) $request->create_time)) || (($stored['max_age'] ?? null) !== null && time() - strtotime((string) $session->create_time) > (int) $stored['max_age'])) throw new ApiException('SAND_IAM_OAUTH_REAUTH_REQUIRED', 401);
            $request->save(['identity_id' => (int) $identity->id, 'auth_session_id' => (int) $session->id, 'csrf_hash' => $this->hash('authorization-csrf:' . $csrf)]);
            $consent = OAuthConsent::where('application_id', (int) $client->application_id)->where('client_id', (int) $client->id)->where('identity_id', (int) $identity->id)->where('status', 1)->whereNull('revoked_time')->find();
            $this->auditClient($client, 'oauth.interaction_bind', 'succeeded', $requestId, []);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        if (!is_array($stored)) throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID', 400);
        $requestedScopes = $this->scopeValues((string) $stored['scope']);
        $savedScopes = $consent === null ? [] : $this->scopeValues((string) $consent->scope);
        return ['csrf_token' => $csrf, 'client_name' => (string) $client->name, 'scope' => $requestedScopes, 'consent_required' => $this->hasPrompt($stored, 'consent') || $consent === null || array_diff($requestedScopes, $savedScopes) !== [], 'expires_in' => max(0, strtotime((string) $request->expire_time) - time())];
    }

    /** @param array<string,mixed> $payload @return array{redirect_uri:string} */
    public function approveAuthorization(array $payload, string $sessionToken, string $requestId): array
    {
        $this->requireConfiguration();
        $requestToken = (string) ($payload['authorization_request'] ?? '');
        $csrf = (string) ($payload['csrf_token'] ?? '');
        if ($requestToken === '' || $csrf === '') throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID', 400);
        $session = (new HumanAuthService())->authenticatedSession($sessionToken);
        Db::startTrans();
        try {
            $request = OAuthAuthorizationRequest::where('request_hash', $this->hash('authorization-request:' . $requestToken))->where('status', 1)->lock(true)->find();
            if ($request === null || $request->consumed_time !== null || $this->expired($request->expire_time) || !hash_equals((string) $request->csrf_hash, $this->hash('authorization-csrf:' . $csrf)) || (int) $request->auth_session_id !== (int) $session->id || (int) $request->application_id !== (int) $session->application_id || (int) $request->identity_id !== (int) $session->identity_id) throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID', 400);
            $client = OAuthClient::where('id', (int) $request->client_id)->where('application_id', (int) $request->application_id)->where('status', 1)->find();
            $identity = Identity::where('id', (int) $request->identity_id)->where('application_id', (int) $request->application_id)->where('status', 1)->find();
            $stored = json_decode($this->decryptNonce((string) $request->encrypted_payload), true, 16, JSON_THROW_ON_ERROR);
            if ($client === null || $identity === null || !is_array($stored)) throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID', 400);
            $request->save(['consumed_time' => $this->now(), 'status' => 2]);
            if (($payload['decision'] ?? '') !== 'approve') {
                $this->auditClient($client, 'oauth.authorize_consent', 'denied', $requestId, []);
                Db::commit();
                return ['redirect_uri' => $this->authorizationErrorRedirect((string) $stored['redirect_uri'], 'access_denied', (string) $stored['state'])];
            }
            $consent = OAuthConsent::where('application_id', (int) $client->application_id)->where('client_id', (int) $client->id)->where('identity_id', (int) $identity->id)->lock(true)->find();
            $fields = ['scope' => (string) $stored['scope'], 'granted_time' => $this->now(), 'revoked_time' => null, 'status' => 1];
            if ($consent === null) OAuthConsent::create(['application_id' => (int) $client->application_id, 'client_id' => (int) $client->id, 'identity_id' => (int) $identity->id] + $fields); else $consent->save($fields);
            $redirect = $this->issueAuthorizationCode($client, $identity, $session, $stored, $this->scopeValues((string) $stored['scope']), $requestId);
            $this->auditClient($client, 'oauth.authorize_consent', 'succeeded', $requestId, []);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        return ['redirect_uri' => $redirect];
    }

    /** @param array<string,mixed> $payload @param list<string> $scopes */
    private function issueAuthorizationCode(OAuthClient $client, Identity $identity, AuthSession $session, array $payload, array $scopes, string $requestId): string
    {
        $rawCode = 'siam_ac_' . bin2hex(random_bytes(32));
        AuthorizationCode::create([
            'application_id' => (int) $client->application_id,
            'client_id' => (int) $client->id,
            'identity_id' => (int) $identity->id,
            'auth_session_id' => (int) $session->id,
            'code_hash' => $this->hash('code:' . $rawCode),
            'redirect_uri' => (string) $payload['redirect_uri'],
            'scope' => implode(' ', $scopes),
            'encrypted_nonce' => in_array('openid', $scopes, true) ? $this->encryptNonce((string) $payload['nonce']) : null,
            'nonce_encryption_version' => in_array('openid', $scopes, true) ? 'v1' : null,
            'nonce_hash' => in_array('openid', $scopes, true) ? $this->hash('nonce:' . (string) $payload['nonce']) : null,
            'code_challenge' => (string) $payload['code_challenge'],
            'code_challenge_method' => 'S256',
            'auth_time' => (string) $session->create_time,
            'expire_time' => date('Y-m-d H:i:s', time() + self::CODE_TTL),
            'status' => 1,
        ]);
        $this->auditClient($client, 'oauth.authorization_code', 'succeeded', $requestId, ['scope' => $scopes]);
        $parameters = ['code' => $rawCode];
        if (($state = (string) ($payload['state'] ?? '')) !== '') $parameters['state'] = $state;
        return $this->appendQuery((string) $payload['redirect_uri'], $parameters);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function token(array $payload, string $requestId): array
    {
        $this->requireConfiguration();
        $grant = (string) ($payload['grant_type'] ?? '');
        return match ($grant) {
            'authorization_code' => $this->exchangeAuthorizationCode($payload, $requestId),
            'refresh_token' => $this->refresh($payload, $requestId),
            'client_credentials' => $this->clientCredentials($payload, $requestId),
            default => throw new ApiException('SAND_IAM_OAUTH_UNSUPPORTED_GRANT_TYPE', 400),
        };
    }

    /** @return array<string,mixed> */
    public function discovery(): array
    {
        $issuer = $this->issuer();
        $metadata = [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/oauth/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'end_session_endpoint' => $issuer . '/oauth/logout',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'subject_types_supported' => ['pairwise'],
            'scopes_supported' => self::CORE_SCOPES,
        ];
        if ((int) config('plugin.sand-iam.app.oauth_dynamic_registration_enabled', 0) === 1) $metadata['registration_endpoint'] = $issuer . '/oauth/register';
        if ((int) config('plugin.sand-iam.app.oidc_frontchannel_logout_enabled', 0) === 1) {
            $metadata['frontchannel_logout_supported'] = true;
            $metadata['frontchannel_logout_session_supported'] = true;
        }
        if ((int) config('plugin.sand-iam.app.oidc_backchannel_logout_enabled', 0) === 1) {
            $metadata['backchannel_logout_supported'] = true;
            $metadata['backchannel_logout_session_supported'] = true;
        }
        return $metadata;
    }

    /** @return array{keys:list<array<string,mixed>>} */
    public function jwks(): array
    {
        $this->requireConfiguration();
        $current = $this->currentJwk();
        $keys = [$current];
        foreach (OidcSigningKey::whereIn('state', ['active', 'verify_only', 'retiring'])->select()->all() as $record) {
            if ($record->retire_time !== null && strtotime((string) $record->retire_time) <= time()) continue;
            $jwk = $record->public_jwk;
            if (is_string($jwk)) $jwk = json_decode($jwk, true);
            if (is_array($jwk) && isset($jwk['kid'], $jwk['n'], $jwk['e'])) {
                if (array_intersect(array_keys($jwk), ['d', 'p', 'q', 'dp', 'dq', 'qi', 'k']) !== []) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503);
                $jwk = array_intersect_key($jwk, array_flip(['kty', 'use', 'alg', 'kid', 'n', 'e']));
                if (hash_equals((string) $jwk['kid'], (string) $current['kid']) && (!hash_equals((string) $jwk['n'], (string) $current['n']) || !hash_equals((string) $jwk['e'], (string) $current['e']))) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503);
                if (!hash_equals((string) $jwk['kid'], (string) $current['kid'])) $keys[] = $jwk;
            }
        }
        $unique = [];
        foreach ($keys as $key) $unique[(string) $key['kid']] = $key;
        return ['keys' => array_values($unique)];
    }

    /** @return array<string,mixed> */
    public function userinfo(string $token, string $requestId): array
    {
        $claims = $this->verifiedAccessToken($token, $this->userinfoAudience());
        $applicationId = (int) $claims['application_id'];
        $client = OAuthClient::where('code', (string) $claims['client_id'])->where('application_id', $applicationId)->where('status', 1)->find();
        $grant = OAuthGrant::where('id', (int) $claims['grant_id'])->where('application_id', $applicationId)->where('status', 1)->whereNull('revoked_time')->find();
        $identity = Identity::where('id', (int) $claims['identity_id'])->where('application_id', $applicationId)->where('status', 1)->find();
        if ($client === null || !$this->clientApplicationActive($client) || $grant === null || $identity === null || (int) $grant->client_id !== (int) $client->id || (int) $grant->identity_id !== (int) $identity->id) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        $scope = $this->scopeValues((string) $claims['scope']);
        if (!in_array('openid', $scope, true)) throw new ApiException('SAND_IAM_OAUTH_INSUFFICIENT_SCOPE', 403);
        $result = ['sub' => (string) $claims['sub']];
        if (in_array('profile', $scope, true)) {
            $result['name'] = (string) $identity->display_name;
            $result['preferred_username'] = (string) $identity->code;
        }
        if (in_array('email', $scope, true)) {
            $auth = IdentityAuth::where('application_id', $applicationId)->where('identity_id', (int) $identity->id)->where('status', 1)->find();
            if ($auth !== null && (string) $auth->email !== '') {
                $result['email'] = (string) $auth->email;
                $result['email_verified'] = $auth->email_verified_time !== null;
            }
        }
        $this->auditClient($client, 'oauth.userinfo', 'succeeded', $requestId, ['scope' => $scope]);
        return $result;
    }

    /** @param array<string,mixed> $payload */
    public function revoke(array $payload, string $requestId): void
    {
        $this->requireConfiguration();
        $client = $this->clientFromRequest($payload, true);
        $token = (string) ($payload['token'] ?? '');
        $record = $token === '' ? null : OAuthToken::where('token_hash', $this->hash('token:' . $token))->find();
        if ($record !== null && (int) $record->client_id === (int) $client->id && (int) $record->application_id === (int) $client->application_id) {
            $this->revokeGrant((int) $record->grant_id);
            $this->auditClient($client, 'oauth.revoke', 'succeeded', $requestId, ['known_token' => true]);
            return;
        }
        // RFC 7009 intentionally returns success for an unknown token.
        $this->auditClient($client, 'oauth.revoke', 'succeeded', $requestId, ['known_token' => false]);
    }

    /** @param array<string,mixed> $payload @return array{redirect_uri:?string,frontchannel_uris:list<string>}|null */
    public function logout(array $payload, string $requestId): ?array
    {
        $this->requireConfiguration();
        $hint = (string) ($payload['id_token_hint'] ?? '');
        if ($hint === '') throw new ApiException('SAND_IAM_OIDC_ID_TOKEN_HINT_REQUIRED', 400);
        $claims = $this->verifyJwt($hint, 'id_token');
        $client = OAuthClient::where('code', (string) ($claims['aud'] ?? ''))->where('status', 1)->find();
        if ($client === null || !$this->clientApplicationActive($client)) throw new ApiException('SAND_IAM_OIDC_LOGOUT_HINT_INVALID', 400);
        $postLogout = (string) ($payload['post_logout_redirect_uri'] ?? '');
        if ($postLogout !== '' && !$this->isExactRedirect($client, $postLogout, true)) {
            $this->auditClient($client, 'oauth.logout', 'denied', $requestId, ['reason' => 'post_logout_redirect_uri']);
            throw new ApiException('SAND_IAM_OIDC_POST_LOGOUT_REDIRECT_URI_INVALID', 400);
        }
        $idRecord = OAuthToken::where('token_hash', $this->hash('token:' . $hint))->where('token_type', 'id')->where('token_id', (string) ($claims['jti'] ?? ''))->where('client_id', (int) $client->id)->where('status', 1)->find();
        $grant = $idRecord === null ? null : OAuthGrant::where('id', (int) $idRecord->grant_id)->where('application_id', (int) $client->application_id)->where('client_id', (int) $client->id)->find();
        $session = $grant === null ? null : AuthSession::where('id', (int) $grant->auth_session_id)->where('application_id', (int) $client->application_id)->where('identity_id', (int) $grant->identity_id)->find();
        $frontchannelUris = []; $backchannelCount = 0;
        if ($session !== null) {
            Db::startTrans();
            try {
                $session = AuthSession::where('id', (int) $session->id)->where('application_id', (int) $client->application_id)->where('identity_id', (int) $grant->identity_id)->lock(true)->find();
                if ($session !== null && (int) $session->status === 1) {
                    $grants = OAuthGrant::where('application_id', (int) $client->application_id)->where('auth_session_id', (int) $session->id)->where('status', 1)->lock(true)->select();
                    foreach ($grants as $activeGrant) {
                        $relyingParty = OAuthClient::where('id', (int) $activeGrant->client_id)->where('application_id', (int) $client->application_id)->where('status', 1)->find();
                        if ($relyingParty === null) continue;
                        if (($uri = $this->frontchannelLogoutUri($relyingParty, (string) $session->id)) !== null) $frontchannelUris[] = $uri;
                        if ($this->enqueueBackchannelLogout($relyingParty, (string) $session->id)) $backchannelCount++;
                    }
                    foreach ($grants as $activeGrant) $this->revokeGrant((int) $activeGrant->id);
                    $session->save(['status' => 2, 'revoked_time' => $this->now()]);
                    AuthRefreshToken::where('session_id', (int) $session->id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
                }
                Db::commit();
            } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        }
        $this->auditClient($client, 'oauth.logout', 'succeeded', $requestId, ['frontchannel_count' => count($frontchannelUris), 'backchannel_count' => $backchannelCount]);
        $frontchannelUris = array_values(array_unique($frontchannelUris));
        if ($postLogout === '' && $frontchannelUris === []) return null;
        $state = (string) ($payload['state'] ?? '');
        if ($state !== '' && (strlen($state) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $state))) throw new ApiException('SAND_IAM_OIDC_LOGOUT_STATE_INVALID', 400);
        $redirectUri = $postLogout === '' ? null : ($state === '' ? $postLogout : $this->appendQuery($postLogout, ['state' => $state]));
        return ['redirect_uri' => $redirectUri, 'frontchannel_uris' => $frontchannelUris];
    }

    /**
     * Reissues a fresh logout token for one terminally failed delivery while
     * retaining the dead row as immutable operational evidence.
     *
     * @return array{source_delivery_id:int,delivery_id:int,event_id:string,state:string,already_reissued:bool}
     */
    public function reissueBackchannelLogout(int $clientId, int $applicationId, int $deliveryId, string $actor, string $requestId): array
    {
        $this->requireConfiguration();
        if ((int) config('plugin.sand-iam.app.oidc_backchannel_logout_enabled', 0) !== 1) {
            throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_LOGOUT_DISABLED', 503);
        }
        $payload = ['client_id' => $clientId, 'application_id' => $applicationId, 'delivery_id' => $deliveryId];
        $result = (new IdempotencyService())->execute(
            'admin',
            $actor,
            'oidc.backchannel_logout_reissue',
            $requestId,
            IdempotencyService::fingerprint($payload),
            'oidc_logout_delivery',
            function () use ($clientId, $applicationId, $deliveryId, $actor, $requestId): array {
                $client = OAuthClient::where('id', $clientId)->where('application_id', $applicationId)->where('status', 1)->lock(true)->find();
                if ($client === null || !$this->clientApplicationActive($client)) {
                    throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_CLIENT_UNAVAILABLE', 400);
                }
                $targetUri = $this->backchannelLogoutUri($client);
                if ($targetUri === null) throw new ApiException('SAND_IAM_OIDC_BACKCHANNEL_URI_UNAVAILABLE', 400);

                $source = OidcLogoutDelivery::where('id', $deliveryId)
                    ->where('application_id', $applicationId)
                    ->where('oauth_client_id', $clientId)
                    ->lock(true)
                    ->find();
                if ($source === null) throw new ApiException('SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_FOUND', 404);
                if ((string) $source->state !== 'dead' || (int) $source->status !== 2) {
                    throw new ApiException('SAND_IAM_OIDC_LOGOUT_DELIVERY_NOT_RECOVERABLE', 409);
                }
                $session = AuthSession::where('id', (int) $source->auth_session_id)
                    ->where('application_id', $applicationId)
                    ->where('status', 2)
                    ->whereNotNull('revoked_time')
                    ->lock(true)
                    ->find();
                if ($session === null) throw new ApiException('SAND_IAM_OIDC_LOGOUT_SESSION_NOT_REVOKED', 409);

                // A deterministic successor jti turns the existing unique
                // event constraint into a durable one-successor-per-dead-row
                // concurrency guard. A successor that later dies can itself
                // be recovered, producing a new link in the chain.
                $eventId = $this->backchannelRecoveryEventId((string) $source->event_id);
                $delivery = OidcLogoutDelivery::where('event_id', $eventId)->find();
                $alreadyReissued = $delivery !== null;
                if ($delivery !== null) {
                    if ((int) $delivery->application_id !== $applicationId
                        || (int) $delivery->oauth_client_id !== $clientId
                        || (int) $delivery->auth_session_id !== (int) $source->auth_session_id) {
                        throw new ApiException('SAND_IAM_OIDC_LOGOUT_RECOVERY_CONFLICT', 409);
                    }
                } else {
                    $delivery = $this->createBackchannelLogoutDelivery($client, (string) $source->auth_session_id, $eventId, $targetUri);
                    $application = Application::find($applicationId);
                    $this->auditWriter->write(
                        'admin',
                        $actor,
                        $application ? (int) $application->organization_id : null,
                        $applicationId,
                        'oidc.backchannel_logout_reissue',
                        'oidc_logout_delivery',
                        (int) $delivery->id,
                        'succeeded',
                        RequestId::normalize($requestId),
                        [
                            'oauth_client_id' => $clientId,
                            'source_delivery_id' => $deliveryId,
                            'source_event_digest' => hash('sha256', (string) $source->event_id),
                            'successor_event_digest' => hash('sha256', $eventId),
                        ],
                    );
                }
                $response = [
                    'source_delivery_id' => $deliveryId,
                    'delivery_id' => (int) $delivery->id,
                    'event_id' => $eventId,
                    'state' => (string) $delivery->state,
                    'already_reissued' => $alreadyReissued,
                ];
                return ['resource_id' => (int) $delivery->id, 'result' => $response];
            },
        );
        $response = $result['result'];
        unset($response['secret_available']);
        /** @var array{source_delivery_id:int,delivery_id:int,event_id:string,state:string,already_reissued:bool} $response */
        return $response;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function exchangeAuthorizationCode(array $payload, string $requestId): array
    {
        $client = $this->clientFromRequest($payload, true);
        $codeValue = (string) ($payload['code'] ?? '');
        $redirectUri = (string) ($payload['redirect_uri'] ?? '');
        $verifier = (string) ($payload['code_verifier'] ?? '');
        if ($codeValue === '' || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !$this->isExactRedirect($client, $redirectUri, false)) throw new ApiException('SAND_IAM_OAUTH_INVALID_GRANT', 400);
        Db::startTrans();
        try {
            $code = AuthorizationCode::where('code_hash', $this->hash('code:' . $codeValue))->where('application_id', (int) $client->application_id)->where('client_id', (int) $client->id)->lock(true)->find();
            if ($code === null || (int) $code->status !== 1 || $code->consumed_time !== null || $this->expired($code->expire_time) || !hash_equals((string) $code->redirect_uri, $redirectUri) || !hash_equals((string) $code->code_challenge, $this->s256($verifier))) throw new ApiException('SAND_IAM_OAUTH_INVALID_GRANT', 400);
            $session = AuthSession::where('id', (int) $code->auth_session_id)->where('application_id', (int) $client->application_id)->where('identity_id', (int) $code->identity_id)->where('status', 1)->lock(true)->find();
            $identity = Identity::where('id', (int) $code->identity_id)->where('application_id', (int) $client->application_id)->where('status', 1)->find();
            if ($session === null || $session->revoked_time !== null || $this->expired($session->access_expire_time) || $identity === null) throw new ApiException('SAND_IAM_OAUTH_INVALID_GRANT', 400);
            $grant = OAuthGrant::create(['application_id' => (int) $client->application_id, 'client_id' => (int) $client->id, 'identity_id' => (int) $identity->id, 'auth_session_id' => (int) $session->id, 'grant_type' => 'authorization_code', 'scope' => (string) $code->scope, 'auth_time' => (string) $code->auth_time, 'status' => 1]);
            $response = $this->issueUserTokens($client, $grant, $identity, $session, $code->encrypted_nonce === null ? null : $this->decryptNonce((string) $code->encrypted_nonce));
            $code->save(['consumed_time' => $this->now(), 'status' => 2]);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        $this->auditClient($client, 'oauth.token_exchange', 'succeeded', $requestId, ['grant_type' => 'authorization_code']);
        return $response;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function refresh(array $payload, string $requestId): array
    {
        $client = $this->clientFromRequest($payload, true);
        $value = (string) ($payload['refresh_token'] ?? '');
        if ($value === '') throw new ApiException('SAND_IAM_OAUTH_INVALID_GRANT', 400);
        Db::startTrans();
        try {
            $token = OAuthToken::where('token_hash', $this->hash('token:' . $value))->where('application_id', (int) $client->application_id)->where('client_id', (int) $client->id)->where('token_type', 'refresh')->lock(true)->find();
            if ($token === null) throw new ApiException('SAND_IAM_OAUTH_INVALID_GRANT', 400);
            $grant = OAuthGrant::where('id', (int) $token->grant_id)->where('application_id', (int) $client->application_id)->lock(true)->find();
            if ($grant === null || (int) $token->status !== 1 || $token->used_time !== null || $token->revoked_time !== null || $this->expired($token->expire_time) || (int) $grant->status !== 1 || $grant->revoked_time !== null) {
                if ($grant !== null) $this->revokeGrant((int) $grant->id);
                Db::commit();
                $this->auditClient($client, 'oauth.refresh_replay', 'denied', $requestId, []);
                throw new ApiException('SAND_IAM_OAUTH_REFRESH_REPLAY_DETECTED', 401);
            }
            $identity = Identity::where('id', (int) $grant->identity_id)->where('application_id', (int) $client->application_id)->where('status', 1)->find();
            $session = AuthSession::where('id', (int) $grant->auth_session_id)->where('application_id', (int) $client->application_id)->where('identity_id', (int) $grant->identity_id)->where('status', 1)->find();
            if ($identity === null || $session === null || $session->revoked_time !== null) throw new ApiException('SAND_IAM_OAUTH_INVALID_GRANT', 400);
            $token->save(['used_time' => $this->now(), 'status' => 2]);
            $response = $this->issueUserTokens($client, $grant, $identity, $session, null);
            Db::commit();
        } catch (ApiException $exception) {
            if ($exception->getMessage() === 'SAND_IAM_OAUTH_REFRESH_REPLAY_DETECTED') throw $exception;
            Db::rollback();
            throw $exception;
        } catch (\Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
        $this->auditClient($client, 'oauth.refresh', 'succeeded', $requestId, []);
        return $response;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function clientCredentials(array $payload, string $requestId): array
    {
        $client = $this->clientFromRequest($payload, true);
        if ((string) $client->client_type !== 'confidential') throw new ApiException('SAND_IAM_OAUTH_UNAUTHORIZED_CLIENT', 400);
        $scopes = $this->scopes((string) ($payload['scope'] ?? ''), $client, false);
        if (in_array('openid', $scopes, true) || in_array('offline_access', $scopes, true)) throw new ApiException('SAND_IAM_OAUTH_INVALID_SCOPE', 400);
        $grant = OAuthGrant::create(['application_id' => (int) $client->application_id, 'client_id' => (int) $client->id, 'grant_type' => 'client_credentials', 'scope' => implode(' ', $scopes), 'status' => 1]);
        $audience = $this->clientCredentialsAudience($client, (string) ($payload['audience'] ?? ''));
        $claims = array_merge($this->baseClaims($client, $grant, null, self::CLIENT_ACCESS_TTL, 'access_token', $audience), ['sub' => 'client:' . $client->code]);
        $access = $this->jwt($claims, 'at+jwt');
        $this->recordToken($client, $grant, null, 'access', $access, (string) $claims['jti'], (string) $claims['scope'], (int) $claims['exp']);
        $this->auditClient($client, 'oauth.client_credentials', 'succeeded', $requestId, ['scope' => $scopes]);
        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::CLIENT_ACCESS_TTL, 'scope' => implode(' ', $scopes)];
    }

    /** @return array<string,mixed> */
    private function issueUserTokens(OAuthClient $client, OAuthGrant $grant, Identity $identity, AuthSession $session, ?string $nonce): array
    {
        $accessClaims = $this->baseClaims($client, $grant, $identity, self::ACCESS_TTL, 'access_token');
        $access = $this->jwt($accessClaims, 'at+jwt');
        $this->recordToken($client, $grant, $identity, 'access', $access, (string) $accessClaims['jti'], (string) $grant->scope, (int) $accessClaims['exp']);
        $result = ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL, 'scope' => (string) $grant->scope];
        if (in_array('offline_access', $this->scopeValues((string) $grant->scope), true)) {
            $refresh = 'siam_ort_' . bin2hex(random_bytes(32));
            $this->recordToken($client, $grant, $identity, 'refresh', $refresh, 'rt_' . bin2hex(random_bytes(16)), (string) $grant->scope, time() + self::REFRESH_TTL);
            $result['refresh_token'] = $refresh;
        }
        if (in_array('openid', $this->scopeValues((string) $grant->scope), true)) {
            $now = time();
            $idClaims = ['iss' => $this->issuer(), 'sub' => $this->subject((int) $client->application_id, (int) $identity->id), 'aud' => (string) $client->code, 'exp' => $now + self::ACCESS_TTL, 'iat' => $now, 'jti' => 'ot_' . bin2hex(random_bytes(16)), 'nonce' => $nonce ?? '', 'auth_time' => strtotime((string) $grant->auth_time), 'sid' => (string) $session->id, 'token_use' => 'id_token'];
            if ($idClaims['nonce'] === '') unset($idClaims['nonce']);
            $idToken = $this->jwt($idClaims, 'JWT');
            $this->recordToken($client, $grant, $identity, 'id', $idToken, (string) $idClaims['jti'], (string) $grant->scope, (int) $idClaims['exp']);
            $result['id_token'] = $idToken;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function baseClaims(OAuthClient $client, OAuthGrant $grant, ?Identity $identity, int $ttl, string $use, ?string $accessAudience = null): array
    {
        $now = time();
        return ['iss' => $this->issuer(), 'sub' => $identity === null ? '' : $this->subject((int) $client->application_id, (int) $identity->id), 'aud' => $use === 'access_token' ? ($accessAudience ?? $this->userinfoAudience()) : (string) $client->code, 'exp' => $now + $ttl, 'iat' => $now, 'jti' => 'ot_' . bin2hex(random_bytes(16)), 'client_id' => (string) $client->code, 'scope' => (string) $grant->scope, 'application_id' => (int) $client->application_id, 'grant_id' => (int) $grant->id, 'token_use' => $use, 'typ' => $use === 'access_token' ? 'at+jwt' : 'JWT', 'identity_id' => $identity === null ? null : (int) $identity->id];
    }

    private function recordToken(OAuthClient $client, OAuthGrant $grant, ?Identity $identity, string $type, string $value, string $tokenId, string $scope, int $expire): void
    {
        OAuthToken::create(['application_id' => (int) $client->application_id, 'client_id' => (int) $client->id, 'grant_id' => (int) $grant->id, 'identity_id' => $identity?->id, 'token_type' => $type, 'token_hash' => $this->hash('token:' . $value), 'token_id' => $tokenId, 'scope' => $scope, 'expire_time' => date('Y-m-d H:i:s', $expire), 'status' => 1]);
    }

    /** @return array<string,mixed> */
    /** @return array<string,mixed> */
    public function verifyAccessTokenForAudience(string $token, string $expectedAudience, array $requiredScopes = []): array
    {
        $claims = $this->verifiedAccessToken($token, $expectedAudience);
        $scopes = $this->scopeValues((string) $claims['scope']);
        if (array_diff($requiredScopes, $scopes) !== []) throw new ApiException('SAND_IAM_OAUTH_INSUFFICIENT_SCOPE', 403);
        return $claims;
    }

    private function verifiedAccessToken(string $token, string $expectedAudience): array
    {
        $claims = $this->verifyJwt($token, 'access_token', $expectedAudience);
        $record = OAuthToken::where('token_hash', $this->hash('token:' . $token))->where('token_type', 'access')->where('token_id', (string) $claims['jti'])->where('application_id', (int) $claims['application_id'])->where('status', 1)->whereNull('revoked_time')->find();
        $client = OAuthClient::where('code', (string) $claims['client_id'])->where('application_id', (int) $claims['application_id'])->where('status', 1)->find();
        $grant = $record === null ? null : OAuthGrant::where('id', (int) $record->grant_id)->where('application_id', (int) $claims['application_id'])->where('client_id', $client?->id ?? 0)->where('status', 1)->whereNull('revoked_time')->find();
        if ($record === null || $client === null || !$this->clientApplicationActive($client) || $grant === null || (int) $record->client_id !== (int) $client->id || (int) $record->grant_id !== (int) $claims['grant_id'] || (int) $record->application_id !== (int) $claims['application_id'] || (int) $grant->client_id !== (int) $client->id || $this->expired($record->expire_time)) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        if ($grant->identity_id === null) {
            if (($claims['sub'] ?? '') !== 'client:' . (string) $client->code || ($claims['identity_id'] ?? null) !== null) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        } else {
            if ((int) ($claims['identity_id'] ?? 0) !== (int) $grant->identity_id || ($claims['sub'] ?? '') !== $this->subject((int) $client->application_id, (int) $grant->identity_id)) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        }
        return $claims;
    }

    /** @return array<string,mixed> */
    private function verifyJwt(string $token, string $expectedUse, ?string $expectedAudience = null): array
    {
        [$headerPart, $payloadPart, $signaturePart] = array_pad(explode('.', $token, 3), 3, '');
        try {
            $header = json_decode($this->b64d($headerPart), true, 32, JSON_THROW_ON_ERROR);
            $claims = json_decode($this->b64d($payloadPart), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        }
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null) || ($header['typ'] ?? '') !== ($expectedUse === 'access_token' ? 'at+jwt' : 'JWT') || ($claims['token_use'] ?? '') !== $expectedUse || ($claims['iss'] ?? '') !== $this->issuer() || !is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null) || !is_string($claims['jti'] ?? null) || $claims['exp'] <= time() || $claims['iat'] > time() + 60 || !preg_match('/^ot_[a-f0-9]{32}$/', (string) $claims['jti'])) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        if ($expectedUse === 'access_token' && (!is_string($claims['scope'] ?? null) || $this->scopeValues((string) $claims['scope']) === [])) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        if ($expectedUse === 'id_token' && (!is_string($claims['sub'] ?? null) || !is_string($claims['aud'] ?? null) || !is_int($claims['auth_time'] ?? null) || !is_string($claims['sid'] ?? null))) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        $jwk = null;
        foreach ($this->jwks()['keys'] as $key) if (hash_equals((string) $key['kid'], (string) $header['kid'])) { $jwk = $key; break; }
        if ($jwk === null || openssl_verify($headerPart . '.' . $payloadPart, $this->b64d($signaturePart), $this->jwkPem($jwk), OPENSSL_ALGO_SHA256) !== 1) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        if (!isset($claims['aud'], $claims['jti'])) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        if ($expectedUse === 'access_token' && (!isset($claims['client_id'], $claims['application_id'], $claims['grant_id']) || $expectedAudience === null || !hash_equals((string) $claims['aud'], $expectedAudience))) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        return $claims;
    }

    /** @param array<string,mixed> $payload */
    private function clientFromRequest(array $payload, bool $authenticate): OAuthClient
    {
        $code = trim((string) ($payload['client_id'] ?? ''));
        $client = $code !== '' ? OAuthClient::where('code', $code)->where('status', 1)->find() : null;
        if ($client === null || !$this->clientApplicationActive($client)) throw new ApiException('SAND_IAM_OAUTH_INVALID_CLIENT', 401);
        if ($authenticate && (string) $client->client_type === 'confidential') {
            $secret = (string) ($payload['client_secret'] ?? '');
            if ($secret === '' || !password_verify($this->hash('client-secret:' . $secret), (string) $client->secret_hash)) throw new ApiException('SAND_IAM_OAUTH_INVALID_CLIENT', 401);
        }
        if ($authenticate && (string) $client->client_type === 'public' && (string) ($payload['grant_type'] ?? '') === 'client_credentials') throw new ApiException('SAND_IAM_OAUTH_UNAUTHORIZED_CLIENT', 400);
        return $client;
    }

    /** @param array<string,mixed> $payload */
    private function validateAuthorizePayload(array $payload, OAuthClient $client): void
    {
        if (($payload['response_type'] ?? '') !== 'code') throw new ApiException('unsupported_response_type', 400);
        $state = (string) ($payload['state'] ?? '');
        if (strlen($state) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $state)) throw new ApiException('invalid_request', 400);
        $method = (string) ($payload['code_challenge_method'] ?? '');
        $challenge = (string) ($payload['code_challenge'] ?? '');
        if ($method !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) throw new ApiException('invalid_request', 400);
        $scopes = $this->scopes((string) ($payload['scope'] ?? ''), $client);
        $nonce = (string) ($payload['nonce'] ?? '');
        if (in_array('openid', $scopes, true) && ($nonce === '' || strlen($nonce) > 255 || preg_match('/[\x00-\x1f\x7f]/', $nonce))) throw new ApiException('invalid_request', 400);
        if (array_key_exists('max_age', $payload) && (!is_numeric($payload['max_age']) || (int) $payload['max_age'] < 0 || (int) $payload['max_age'] > 86_400)) throw new ApiException('invalid_request', 400);
    }

    /** @return list<string> */
    private function scopes(string $value, OAuthClient $client, bool $requireOpenid = true): array
    {
        $scope = $this->scopeValues($value);
        if ($scope === [] || ($requireOpenid && !in_array('openid', $scope, true))) throw new ApiException('SAND_IAM_OAUTH_INVALID_SCOPE', 400);
        $allowed = $client->allowed_scopes;
        if (is_string($allowed)) $allowed = json_decode($allowed, true);
        if (!is_array($allowed) || array_diff($scope, $allowed) !== []) throw new ApiException('SAND_IAM_OAUTH_INVALID_SCOPE', 400);
        return $scope;
    }

    /** @return list<string> */
    private function scopeValues(string $value): array
    {
        $value = trim($value);
        if ($value === '') return [];
        $values = preg_split('/ +/', $value);
        if (!is_array($values)) return [];
        foreach ($values as $scope) if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $scope) !== 1) return [];
        return array_values(array_unique($values));
    }

    private function clientCredentialsAudience(OAuthClient $client, string $requested): string
    {
        $audiences = $client->allowed_audiences;
        if (is_string($audiences)) $audiences = json_decode($audiences, true);
        $audience = $requested !== '' ? $requested : (string) ($client->default_audience ?? '');
        if (!is_array($audiences) || $audience === '' || !in_array($audience, $audiences, true)) throw new ApiException('SAND_IAM_OAUTH_INVALID_AUDIENCE', 400);
        return $audience;
    }

    private function isExactRedirect(OAuthClient $client, string $uri, bool $logout): bool
    {
        if ($uri === '' || strlen($uri) > 2048 || !filter_var($uri, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($uri);
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user'], $parts['pass'], $parts['fragment'])) return false;
        $uris = $logout ? $client->post_logout_redirect_uris : $client->redirect_uris;
        if (is_string($uris)) $uris = json_decode($uris, true);
        return is_array($uris) && in_array($uri, $uris, true);
    }

    private function frontchannelLogoutUri(OAuthClient $client, string $sessionId): ?string
    {
        if ((int) config('plugin.sand-iam.app.oidc_frontchannel_logout_enabled', 0) !== 1) return null;
        $uri = trim((string) ($client->frontchannel_logout_uri ?? ''));
        if ($uri === '' || strlen($uri) > 2048 || !filter_var($uri, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($uri);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;
        $parameters = ['iss' => $this->issuer()];
        if ((bool) ($client->frontchannel_logout_session_required ?? true)) $parameters['sid'] = $sessionId;
        return $this->appendQuery($uri, $parameters);
    }

    private function enqueueBackchannelLogout(OAuthClient $client, string $sessionId): bool
    {
        if ((int) config('plugin.sand-iam.app.oidc_backchannel_logout_enabled', 0) !== 1) return false;
        $uri = $this->backchannelLogoutUri($client);
        if ($uri === null) return false;
        $eventId = 'bcl_' . bin2hex(random_bytes(16));
        $this->createBackchannelLogoutDelivery($client, $sessionId, $eventId, $uri);
        return true;
    }

    private function createBackchannelLogoutDelivery(OAuthClient $client, string $sessionId, string $eventId, string $targetUri): OidcLogoutDelivery
    {
        $claims = $this->backchannelLogoutClaims($client, $sessionId, $eventId, time());
        $logoutToken = $this->jwt($claims, 'logout+jwt');
        return OidcLogoutDelivery::create([
            'application_id' => (int) $client->application_id,
            'oauth_client_id' => (int) $client->id,
            'auth_session_id' => (int) $sessionId,
            'event_id' => $eventId,
            'encrypted_logout_token' => (new OidcLogoutTokenCipher())->encrypt(json_encode([
                'target_uri' => $targetUri,
                'logout_token' => $logoutToken,
            ], JSON_THROW_ON_ERROR)),
            'state' => 'pending',
            'attempt_count' => 0,
            'next_attempt_time' => $this->now(),
            'status' => 1,
        ]);
    }

    private function backchannelLogoutUri(OAuthClient $client): ?string
    {
        $uri = trim((string) ($client->backchannel_logout_uri ?? ''));
        if ($uri === '' || strlen($uri) > 2048 || !filter_var($uri, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($uri);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;
        return $uri;
    }

    private function backchannelRecoveryEventId(string $sourceEventId): string
    {
        return 'bcl_r_' . substr(hash('sha256', "sand-iam:oidc-backchannel-reissue\0" . $sourceEventId), 0, 32);
    }

    /** @return array{iss:string,aud:string,iat:int,exp:int,jti:string,sid:string,events:array<string,\stdClass>} */
    private function backchannelLogoutClaims(OAuthClient $client, string $sessionId, string $eventId, int $issuedAt): array
    {
        return [
            'iss' => $this->issuer(),
            'aud' => (string) $client->code,
            'iat' => $issuedAt,
            'exp' => $issuedAt + OidcBackchannelLogoutService::MAX_DELIVERY_WINDOW + $this->oidcClockSkewSeconds(),
            'jti' => $eventId,
            'sid' => $sessionId,
            'events' => ['http://schemas.openid.net/event/backchannel-logout' => new \stdClass()],
        ];
    }

    private function authorizationErrorRedirect(string $uri, string $error, string $state): string
    {
        $parameters = ['error' => $this->safeProtocolError($error)];
        if ($state !== '') $parameters['state'] = $state;
        return $this->appendQuery($uri, $parameters);
    }

    private function safeProtocolError(string $value): string
    {
        if (str_contains($value, 'INVALID_SCOPE')) return 'invalid_scope';
        if (str_contains($value, 'UNSUPPORTED_RESPONSE_TYPE')) return 'unsupported_response_type';
        return in_array($value, ['unsupported_response_type', 'invalid_request', 'invalid_scope', 'consent_required', 'login_required', 'access_denied'], true) ? $value : 'invalid_request';
    }

    private function prompt(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        $prompts = preg_split('/ +/', $value);
        if (!is_array($prompts) || $prompts === [] || array_diff($prompts, ['none', 'login', 'consent']) !== [] || (in_array('none', $prompts, true) && count($prompts) !== 1)) throw new ApiException('invalid_request', 400);
        return implode(' ', array_values(array_unique($prompts)));
    }

    /** @param array<string,mixed> $stored */
    private function hasPrompt(array $stored, string $prompt): bool
    {
        return in_array($prompt, preg_split('/ +/', trim((string) ($stored['prompt'] ?? ''))) ?: [], true);
    }
    private function authorizationRequest(string $token, bool $lock = false): OAuthAuthorizationRequest
    {
        $query = OAuthAuthorizationRequest::where('request_hash', $this->hash('authorization-request:' . $token))->where('status', 1);
        if ($lock) $query->lock(true);
        $request = $query->find();
        if ($request === null || $request->consumed_time !== null || $this->expired($request->expire_time)) throw new ApiException('SAND_IAM_OAUTH_AUTHORIZATION_REQUEST_INVALID', 400);
        return $request;
    }

    private function clientApplicationActive(OAuthClient $client): bool
    {
        $application = Application::where('id', (int) $client->application_id)->where('status', 1)->find();
        return $application !== null && Organization::where('id', (int) $application->organization_id)->where('status', 1)->find() !== null;
    }

    private function revokeGrant(int $id): void
    {
        $time = $this->now();
        OAuthGrant::where('id', $id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $time]);
        OAuthToken::where('grant_id', $id)->where('status', 1)->update(['status' => 2, 'revoked_time' => $time]);
    }

    private function jwt(array $claims, string $typ): string
    {
        $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => $typ, 'kid' => (string) $this->currentJwk()['kid']], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode($claims, JSON_THROW_ON_ERROR));
        if (!openssl_sign($header . '.' . $payload, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        return $header . '.' . $payload . '.' . $this->b64($signature);
    }

    /** @return array<string,mixed> */
    private function currentJwk(): array
    {
        $record = $this->activeDatabaseSigningKey();
        if ($record !== null) {
            $jwk = $record->public_jwk;
            if (is_string($jwk)) $jwk = json_decode($jwk, true);
            if (!is_array($jwk) || ($jwk['kty'] ?? null) !== 'RSA' || ($jwk['alg'] ?? null) !== 'RS256' || !is_string($jwk['kid'] ?? null) || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
            return array_intersect_key($jwk, array_flip(['kty', 'use', 'alg', 'kid', 'n', 'e']));
        }
        $details = openssl_pkey_get_details($this->privateKey());
        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid(), 'n' => $this->b64((string) $details['rsa']['n']), 'e' => $this->b64((string) $details['rsa']['e'])];
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $record = $this->activeDatabaseSigningKey();
        $pem = $record === null
            ? base64_decode((string) config('plugin.sand-iam.app.oidc_private_key_base64', ''), true)
            : (new OidcLogoutTokenCipher())->decrypt((string) $record->encrypted_private_key);
        $key = is_string($pem) ? openssl_pkey_get_private($pem) : false;
        if ($key === false) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        if ($record !== null) {
            $jwk = $record->public_jwk;
            if (is_string($jwk)) $jwk = json_decode($jwk, true);
            if (!is_array($jwk) || !hash_equals((string) ($jwk['n'] ?? ''), $this->b64((string) ($details['rsa']['n'] ?? ''))) || !hash_equals((string) ($jwk['e'] ?? ''), $this->b64((string) ($details['rsa']['e'] ?? '')))) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        }
        return $key;
    }

    /** @param array<string,mixed> $jwk */
    private function jwkPem(array $jwk): string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401);
        $rsa = "\x30" . $this->derLength(strlen($this->derInteger($this->b64d($jwk['n'])) . $this->derInteger($this->b64d($jwk['e'])))) . $this->derInteger($this->b64d($jwk['n'])) . $this->derInteger($this->b64d($jwk['e']));
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $spki = "\x30" . $this->derLength(strlen($algorithm) + strlen("\x03") + strlen($this->derLength(strlen($rsa) + 1)) + strlen($rsa) + 1) . $algorithm . "\x03" . $this->derLength(strlen($rsa) + 1) . "\x00" . $rsa;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $value): string { $value = ltrim($value, "\0"); if ($value === '' || (ord($value[0]) & 0x80)) $value = "\0" . $value; return "\x02" . $this->derLength(strlen($value)) . $value; }
    private function derLength(int $length): string { if ($length < 128) return chr($length); $out = ''; while ($length > 0) { $out = chr($length & 0xff) . $out; $length >>= 8; } return chr(0x80 | strlen($out)) . $out; }
    private function issuer(): string { $issuer = rtrim((string) config('plugin.sand-iam.app.oidc_issuer', ''), '/'); if (!preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/api/sand-iam/v1$#', $issuer)) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503); return $issuer; }
    private function userinfoAudience(): string { return $this->issuer() . '/oauth/userinfo'; }
    private function kid(): string { $kid = (string) config('plugin.sand-iam.app.oidc_kid', ''); if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $kid)) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503); return $kid; }
    private function requireConfiguration(): void { $this->issuer(); if ($this->activeDatabaseSigningKey() === null) $this->kid(); $this->privateKey(); if ((string) config('plugin.sand-iam.app.auth_pepper', '') === '' || strlen((string) config('plugin.sand-iam.app.oidc_subject_key', '')) < 32) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503); }
    private function subject(int $applicationId, int $identityId): string { return rtrim(strtr(base64_encode(hash_hmac('sha256', $this->issuer() . "\0" . $applicationId . "\0" . $identityId, (string) config('plugin.sand-iam.app.oidc_subject_key', ''), true)), '+/', '-_'), '='); }
    private function hash(string $value): string { return hash_hmac('sha256', $value, (string) config('plugin.sand-iam.app.auth_pepper', '')); }
    private function encryptNonce(string $nonce): string { $key = $this->nonceKey(); $nonceBytes = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return $this->b64($nonceBytes . sodium_crypto_secretbox($nonce, $nonceBytes, $key)); }
    private function decryptNonce(string $value): string { $wire = $this->b64d($value); $length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; if (strlen($wire) <= $length) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503); $plain = sodium_crypto_secretbox_open(substr($wire, $length), substr($wire, 0, $length), $this->nonceKey()); if ($plain === false) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503); return $plain; }
    private function nonceKey(): string { if (!function_exists('sodium_crypto_secretbox')) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503); return hash('sha256', 'sand-iam:oidc-nonce:' . (string) config('plugin.sand-iam.app.auth_pepper', ''), true); }
    private function oidcClockSkewSeconds(): int { return max(0, min(3600, (int) config('plugin.sand-iam.app.oidc_signing_key_clock_skew_seconds', 300))); }
    private function activeDatabaseSigningKey(): ?OidcSigningKey
    {
        $records = OidcSigningKey::where('state', 'active')->select()->all();
        if (count($records) > 1) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        // A managed key history without an active signer is an operator error,
        // not a reason to silently resume the pre-rotation deployment key.
        if ($records === [] && OidcSigningKey::count() > 0) throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        if ($records === []) return null;
        $record = $records[0];
        if ((string) ($record->algorithm ?? 'RS256') !== 'RS256' || !is_string($record->encrypted_private_key ?? null) || (string) $record->encrypted_private_key === '') throw new ApiException('SAND_IAM_OIDC_SIGNING_UNAVAILABLE', 503);
        return $record;
    }
    private function s256(string $value): string { return $this->b64(hash('sha256', $value, true)); }
    private function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function b64d(string $value): string { $decoded = base64_decode(strtr($value, '-_', '+/'), true); if ($decoded === false) throw new ApiException('SAND_IAM_OAUTH_TOKEN_INVALID', 401); return $decoded; }
    private function expired(mixed $value): bool { return $value === null || $value === '' || strtotime((string) $value) <= time(); }
    private function now(): string { return date('Y-m-d H:i:s'); }
    /** @param array<string,mixed> $context */
    private function auditClient(OAuthClient $client, string $action, string $outcome, string $requestId, array $context): void { $application = Application::find((int) $client->application_id); $this->auditWriter->write('oauth_client', (string) $client->id, $application ? (int) $application->organization_id : null, (int) $client->application_id, $action, 'oauth_client', (int) $client->id, $outcome, $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)), $context); }
    /** @param array<string,string> $parameters */
    private function appendQuery(string $uri, array $parameters): string { $separator = str_contains($uri, '?') ? '&' : '?'; return $uri . $separator . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986); }
}
