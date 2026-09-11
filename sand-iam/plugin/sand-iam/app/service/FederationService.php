<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\federation\FederationHttpAdapter;
use plugin\SandIam\app\federation\LdapDirectoryAdapter;
use plugin\SandIam\app\federation\NativeFederationHttpAdapter;
use plugin\SandIam\app\federation\NativeLdapDirectoryAdapter;
use plugin\SandIam\app\federation\OneLoginSamlAssertionVerifier;
use plugin\SandIam\app\federation\SamlAssertionVerifier;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\DirectorySyncRun;
use plugin\SandIam\app\model\FederationTransaction;
use plugin\SandIam\app\model\FederationHandoff;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\IdentityAuth;
use plugin\SandIam\app\model\ScimResource;
use plugin\SandIam\app\model\ScimToken;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;

/** Application-scoped external identity and directory boundary. */
final class FederationService
{
    public function __construct(
        private readonly FederationConfigCipher $cipher = new FederationConfigCipher(),
        private readonly FederationHttpAdapter $http = new NativeFederationHttpAdapter(),
        private readonly LdapDirectoryAdapter $ldap = new NativeLdapDirectoryAdapter(),
        private readonly SamlAssertionVerifier $saml = new OneLoginSamlAssertionVerifier(),
        private readonly AuditWriter $audit = new AuditWriter(),
    ) {
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $mapping */
    public function configureProvider(int $providerId, string $type, array $config, array $mapping, string $conflictPolicy, string $requestId): void
    {
        if (!in_array($type, ['oidc', 'oauth2', 'saml', 'ldap', 'scim', 'kerberos'], true) || !in_array($conflictPolicy, ['reject', 'create'], true)) {
            throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        }
        $this->validateMapping($mapping);
        $this->validateConfig($type, $config);
        Db::startTrans();
        try {
            $provider = IdentityProvider::where('id', $providerId)->lock(true)->find();
            if ($provider === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND', 404);
            $oldConfig = $provider->encrypted_config ? $this->cipher->decrypt($provider->encrypted_config) : [];
            $hasState = IdentityBinding::where('identity_provider_id', (int) $provider->id)->count() > 0 || ScimResource::where('identity_provider_id', (int) $provider->id)->count() > 0 || ScimToken::where('identity_provider_id', (int) $provider->id)->count() > 0 || FederationTransaction::where('identity_provider_id', (int) $provider->id)->count() > 0;
            if (($provider->provider_type !== 'local' && (string) $provider->provider_type !== $type) || ($hasState && $this->identityDomainChanged((string) $provider->provider_type, $type, $oldConfig, $config, $provider->attribute_mapping, $mapping))) throw new ApiException('SAND_IAM_FEDERATION_IDENTITY_DOMAIN_IMMUTABLE', 409);
            $provider->save(['provider_type' => $type, 'encrypted_config' => $this->cipher->encrypt($config), 'attribute_mapping' => $mapping, 'conflict_policy' => $conflictPolicy, 'config_version' => (int) $provider->config_version + 1]);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
        $this->auditProvider($provider, null, 'identity_provider.configure', 'succeeded', $requestId, ['provider_type' => $type]);
    }

    /** @return array{state:string,redirect_uri:string,expire_time:string} */
    public function startOidc(string $publicCode, string $applicationCode, string $returnUri, string $state, string $handoffChallenge, string $browserBinding, string $requestId, string $purpose = 'login', string $accessToken = ''): array
    {
        [$provider, $application] = $this->publicProvider($publicCode, $applicationCode, 'oidc');
        $applicationId = (int) $application->id;
        if ($browserBinding === '' || strlen($browserBinding) > 256) throw new ApiException('SAND_IAM_FEDERATION_BROWSER_STATE_INVALID', 400);
        $config = $this->cipher->decrypt($provider->encrypted_config);
        $handoff = $this->handoffStartContext($config, $returnUri, $state, $handoffChallenge);
        $link = $this->linkStartContext($purpose, $accessToken, $application);
        $discovery = $this->discovery($config);
        $state = 'fi_' . bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(24));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $callback = (string) ($config['redirect_uri'] ?? '');
        $this->httpsUri($callback);
        $transaction = FederationTransaction::create([
            'application_id' => $applicationId,
            'identity_provider_id' => (int) $provider->id,
            'provider_config_version' => (int) $provider->config_version,
            'protocol' => 'oidc',
            'purpose' => $purpose,
            'state_hash' => $this->hash('state:' . $state),
            'browser_binding_hash' => $this->hash('browser:' . $browserBinding),
            'nonce_hash' => $this->hash('nonce:' . $nonce),
            'encrypted_pkce_verifier' => $this->cipher->encrypt(['verifier' => $verifier]),
            'redirect_uri' => $callback,
            'handoff_return_uri' => $handoff['return_uri'],
            'handoff_state' => $handoff['state'],
            'handoff_code_challenge' => $handoff['code_challenge'],
            'link_identity_id' => $link['identity_id'],
            'link_session_id' => $link['session_id'],
            'expire_time' => date('Y-m-d H:i:s', time() + 600),
            'status' => 1,
        ]);
        $scope = trim((string) ($config['scope'] ?? 'openid profile email'));
        if (!str_contains(' ' . $scope . ' ', ' openid ')) $scope = 'openid ' . $scope;
        $parameters = ['response_type' => 'code', 'client_id' => (string) $config['client_id'], 'redirect_uri' => $callback, 'scope' => $scope, 'state' => $state, 'nonce' => $nonce, 'code_challenge_method' => 'S256', 'code_challenge' => $this->b64(hash('sha256', $verifier, true))];
        $this->auditProvider($provider, $applicationId, 'identity_provider.oidc_start', 'succeeded', $requestId, ['transaction_id' => (int) $transaction->id]);
        return ['state' => $state, 'redirect_uri' => $this->appendQuery((string) $discovery['authorization_endpoint'], $parameters), 'expire_time' => (string) $transaction->expire_time];
    }

    /** @return array<string,mixed> */
    public function completeOidc(string $state, string $code, string $browserBinding, string $ip, string $userAgent, string $requestId): array
    {
        if (!preg_match('/^fi_[a-f0-9]{48}$/', $state) || $code === '' || strlen($code) > 4096 || $browserBinding === '') throw new ApiException('SAND_IAM_FEDERATION_CALLBACK_INVALID', 400);
        // Never hold a transaction row lock during metadata, token or JWKS I/O.
        // The final short transaction consumes state exactly once after claims
        // are validated and re-checks browser binding before issuing a session.
        $transaction = FederationTransaction::where('state_hash', $this->hash('state:' . $state))->where('protocol', 'oidc')->find();
        if ($transaction === null || (int) $transaction->status !== 1 || $transaction->consumed_time !== null || strtotime((string) $transaction->expire_time) <= time() || !hash_equals((string) $transaction->browser_binding_hash, $this->hash('browser:' . $browserBinding))) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
        $provider = $this->provider((int) $transaction->identity_provider_id, (int) $transaction->application_id, 'oidc');
        if ((int) $provider->config_version !== (int) $transaction->provider_config_version) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
        try {
            $config = $this->cipher->decrypt($provider->encrypted_config);
            $discovery = $this->discovery($config);
            $verifier = $this->cipher->decrypt($transaction->encrypted_pkce_verifier)['verifier'] ?? '';
            if (!is_string($verifier) || $verifier === '') throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            $token = $this->http->json('POST', (string) $discovery['token_endpoint'], [], ['grant_type' => 'authorization_code', 'client_id' => (string) $config['client_id'], 'client_secret' => (string) ($config['client_secret'] ?? ''), 'code' => $code, 'redirect_uri' => (string) $transaction->redirect_uri, 'code_verifier' => $verifier]);
            $claims = $this->verifyExternalIdToken((string) ($token['id_token'] ?? ''), $discovery, (string) $config['client_id'], (string) ($transaction->nonce_hash ?? ''));
        } catch (\Throwable $exception) { $this->auditCallbackFailure($provider, (int) $transaction->application_id, 'oidc', $requestId); throw $exception; }
        Db::startTrans();
        try {
            $transaction = FederationTransaction::where('state_hash', $this->hash('state:' . $state))->where('protocol', 'oidc')->lock(true)->find();
            if ($transaction === null || (int) $transaction->status !== 1 || $transaction->consumed_time !== null || strtotime((string) $transaction->expire_time) <= time() || !hash_equals((string) $transaction->browser_binding_hash, $this->hash('browser:' . $browserBinding))) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            $provider = $this->lockedProvider((int) $transaction->identity_provider_id, (int) $transaction->application_id, 'oidc');
            if ((int) $provider->config_version !== (int) $transaction->provider_config_version) {
                throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            }
            $identity = $this->linkCallbackIdentity($transaction, $provider, (string) $claims['sub'], $claims, $requestId);
            $binding = $this->activeBinding($provider, (int) $transaction->application_id, (string) $claims['sub']);
            $application = Application::where('id', (int) $transaction->application_id)->where('status', 1)->find();
            if ($application === null) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 404);
            $transaction->save(['status' => 2, 'consumed_time' => $this->now(), 'encrypted_pkce_verifier' => null]);
            $result = $this->createHandoff($transaction, $provider, $identity, $binding);
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            $this->auditCallbackFailure($provider, (int) $transaction->application_id, 'oidc', $requestId);
            throw $exception;
        }
        $this->auditProvider($provider, (int) $transaction->application_id, 'identity_provider.oidc_callback', 'succeeded', $requestId, ['identity_id' => (int) $identity->id]);
        return $result;
    }

    /** @return array{state:string,redirect_uri:string,expire_time:string} */
    public function startOauth2(string $publicCode, string $applicationCode, string $returnUri, string $state, string $handoffChallenge, string $browserBinding, string $requestId, string $purpose = 'login', string $accessToken = ''): array
    {
        [$provider, $application] = $this->publicProvider($publicCode, $applicationCode, 'oauth2');
        if ($browserBinding === '' || strlen($browserBinding) > 256) throw new ApiException('SAND_IAM_FEDERATION_BROWSER_STATE_INVALID', 400);
        $config = $this->cipher->decrypt($provider->encrypted_config);
        $handoff = $this->handoffStartContext($config, $returnUri, $state, $handoffChallenge);
        $link = $this->linkStartContext($purpose, $accessToken, $application);
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'client_id', 'redirect_uri'] as $field) if (!is_string($config[$field] ?? null) || (string) $config[$field] === '') throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'redirect_uri'] as $field) $this->httpsUri((string) $config[$field]);
        $state = 'fo_' . bin2hex(random_bytes(24));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $transaction = FederationTransaction::create(['application_id' => (int) $application->id, 'identity_provider_id' => (int) $provider->id, 'provider_config_version' => (int) $provider->config_version, 'protocol' => 'oauth2', 'purpose' => $purpose, 'state_hash' => $this->hash('oauth2-state:' . $state), 'browser_binding_hash' => $this->hash('browser:' . $browserBinding), 'encrypted_pkce_verifier' => $this->cipher->encrypt(['verifier' => $verifier]), 'redirect_uri' => (string) $config['redirect_uri'], 'handoff_return_uri' => $handoff['return_uri'], 'handoff_state' => $handoff['state'], 'handoff_code_challenge' => $handoff['code_challenge'], 'link_identity_id' => $link['identity_id'], 'link_session_id' => $link['session_id'], 'expire_time' => date('Y-m-d H:i:s', time() + 600), 'status' => 1]);
        $params = ['response_type' => 'code', 'client_id' => (string) $config['client_id'], 'redirect_uri' => (string) $config['redirect_uri'], 'state' => $state, 'code_challenge_method' => 'S256', 'code_challenge' => $this->b64(hash('sha256', $verifier, true))];
        if (is_string($config['scope'] ?? null) && trim((string) $config['scope']) !== '') $params['scope'] = trim((string) $config['scope']);
        $this->auditProvider($provider, (int) $application->id, 'identity_provider.oauth2_start', 'succeeded', $requestId, ['transaction_id' => (int) $transaction->id]);
        return ['state' => $state, 'redirect_uri' => $this->appendQuery((string) $config['authorization_endpoint'], $params), 'expire_time' => (string) $transaction->expire_time];
    }

    /** @return array<string,mixed> */
    public function completeOauth2(string $state, string $code, string $browserBinding, string $ip, string $userAgent, string $requestId): array
    {
        if (!preg_match('/^fo_[a-f0-9]{48}$/', $state) || $code === '' || strlen($code) > 4096 || $browserBinding === '') throw new ApiException('SAND_IAM_FEDERATION_CALLBACK_INVALID', 400);
        $transaction = FederationTransaction::where('state_hash', $this->hash('oauth2-state:' . $state))->where('protocol', 'oauth2')->find();
        if ($transaction === null || (int) $transaction->status !== 1 || $transaction->consumed_time !== null || strtotime((string) $transaction->expire_time) <= time() || !hash_equals((string) $transaction->browser_binding_hash, $this->hash('browser:' . $browserBinding))) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
        $provider = $this->provider((int) $transaction->identity_provider_id, (int) $transaction->application_id, 'oauth2');
        if ((int) $provider->config_version !== (int) $transaction->provider_config_version) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
        try {
            $config = $this->cipher->decrypt($provider->encrypted_config);
            $verifier = $this->cipher->decrypt($transaction->encrypted_pkce_verifier)['verifier'] ?? '';
            if (!is_string($verifier) || $verifier === '') throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            $token = $this->http->json('POST', (string) $config['token_endpoint'], [], ['grant_type' => 'authorization_code', 'client_id' => (string) $config['client_id'], 'client_secret' => (string) ($config['client_secret'] ?? ''), 'code' => $code, 'redirect_uri' => (string) $transaction->redirect_uri, 'code_verifier' => $verifier]);
            $accessToken = (string) ($token['access_token'] ?? '');
            if ($accessToken === '' || strlen($accessToken) > 8192) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_RESPONSE_INVALID', 502);
            $claims = $this->http->json('GET', (string) $config['userinfo_endpoint'], ['Authorization' => 'Bearer ' . $accessToken]);
            $subject = $this->mapped($provider, $claims, 'subject');
            if ($subject === '') throw new ApiException('SAND_IAM_FEDERATION_MAPPING_INVALID', 400);
        } catch (\Throwable $exception) { $this->auditCallbackFailure($provider, (int) $transaction->application_id, 'oauth2', $requestId); throw $exception; }
        Db::startTrans();
        try {
            $transaction = FederationTransaction::where('state_hash', $this->hash('oauth2-state:' . $state))->where('protocol', 'oauth2')->lock(true)->find();
            if ($transaction === null || (int) $transaction->status !== 1 || $transaction->consumed_time !== null || strtotime((string) $transaction->expire_time) <= time() || !hash_equals((string) $transaction->browser_binding_hash, $this->hash('browser:' . $browserBinding))) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            $provider = $this->lockedProvider((int) $transaction->identity_provider_id, (int) $transaction->application_id, 'oauth2');
            if ((int) $provider->config_version !== (int) $transaction->provider_config_version) {
                throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            }
            $identity = $this->linkCallbackIdentity($transaction, $provider, $subject, $claims, $requestId);
            $binding = $this->activeBinding($provider, (int) $transaction->application_id, $subject);
            $application = Application::where('id', (int) $transaction->application_id)->where('status', 1)->find();
            if ($application === null) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 404);
            $transaction->save(['status' => 2, 'consumed_time' => $this->now(), 'encrypted_pkce_verifier' => null]);
            $result = $this->createHandoff($transaction, $provider, $identity, $binding);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); $this->auditCallbackFailure($provider, (int) $transaction->application_id, 'oauth2', $requestId); throw $exception; }
        $this->auditProvider($provider, (int) $transaction->application_id, 'identity_provider.oauth2_callback', 'succeeded', $requestId, ['identity_id' => (int) $identity->id]);
        return $result;
    }

    /** @return array{relay_state:string,redirect_uri:string,expire_time:string} */
    public function startSaml(string $publicCode, string $applicationCode, string $returnUri, string $state, string $handoffChallenge, string $browserBinding, string $requestId, string $purpose = 'login', string $accessToken = ''): array
    {
        [$provider, $application] = $this->publicProvider($publicCode, $applicationCode, 'saml');
        $applicationId = (int) $application->id;
        if ($browserBinding === '' || strlen($browserBinding) > 256) throw new ApiException('SAND_IAM_FEDERATION_BROWSER_STATE_INVALID', 400);
        $config = $this->cipher->decrypt($provider->encrypted_config);
        $handoff = $this->handoffStartContext($config, $returnUri, $state, $handoffChallenge);
        $link = $this->linkStartContext($purpose, $accessToken, $application);
        $acs = (string) ($config['acs_url'] ?? '');
        $this->httpsUri($acs);
        $relayState = 'fs_' . bin2hex(random_bytes(24));
        $start = $this->saml->start($config, $acs, $relayState);
        $protocolRequestId = (string) ($start['request_id'] ?? '');
        if ($protocolRequestId === '' || strlen($protocolRequestId) > 255) throw new ApiException('SAND_IAM_SAML_VERIFIER_UNAVAILABLE', 503);
        $transaction = FederationTransaction::create(['application_id' => $applicationId, 'identity_provider_id' => (int) $provider->id, 'provider_config_version' => (int) $provider->config_version, 'protocol' => 'saml', 'purpose' => $purpose, 'state_hash' => $this->hash('saml-relay:' . $relayState), 'browser_binding_hash' => $this->hash('browser:' . $browserBinding), 'saml_request_id' => $protocolRequestId, 'redirect_uri' => $acs, 'handoff_return_uri' => $handoff['return_uri'], 'handoff_state' => $handoff['state'], 'handoff_code_challenge' => $handoff['code_challenge'], 'link_identity_id' => $link['identity_id'], 'link_session_id' => $link['session_id'], 'expire_time' => date('Y-m-d H:i:s', time() + 600), 'status' => 1]);
        $this->auditProvider($provider, $applicationId, 'identity_provider.saml_start', 'succeeded', $requestId, ['transaction_id' => (int) $transaction->id]);
        return ['relay_state' => $relayState, 'redirect_uri' => (string) $start['redirect_uri'], 'expire_time' => (string) $transaction->expire_time];
    }

    /** @return array<string,mixed> */
    public function completeSaml(string $relayState, string $samlResponse, string $browserBinding, string $ip, string $userAgent, string $requestTraceId): array
    {
        if (!preg_match('/^fs_[a-f0-9]{48}$/', $relayState) || $samlResponse === '' || $browserBinding === '') throw new ApiException('SAND_IAM_FEDERATION_CALLBACK_INVALID', 400);
        $transaction = FederationTransaction::where('state_hash', $this->hash('saml-relay:' . $relayState))->where('protocol', 'saml')->find();
        if ($transaction === null || (int) $transaction->status !== 1 || $transaction->consumed_time !== null || strtotime((string) $transaction->expire_time) <= time() || !hash_equals((string) $transaction->browser_binding_hash, $this->hash('browser:' . $browserBinding))) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
        $provider = $this->provider((int) $transaction->identity_provider_id, (int) $transaction->application_id, 'saml');
        if ((int) $provider->config_version !== (int) $transaction->provider_config_version) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
        try {
            $config = $this->cipher->decrypt($provider->encrypted_config);
            // A production verifier must validate XMLDSig, InResponseTo, Audience,
            // Recipient, Conditions, issuer and assertion replay before returning.
            $claims = $this->saml->verify($samlResponse, $config, (string) $transaction->saml_request_id, (string) $transaction->redirect_uri);
            $subject = (string) ($claims['sub'] ?? '');
            if ($subject === '') throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
        } catch (\Throwable $exception) { $this->auditCallbackFailure($provider, (int) $transaction->application_id, 'saml', $requestTraceId); throw $exception; }
        Db::startTrans();
        try {
            $transaction = FederationTransaction::where('state_hash', $this->hash('saml-relay:' . $relayState))->where('protocol', 'saml')->lock(true)->find();
            if ($transaction === null || (int) $transaction->status !== 1 || $transaction->consumed_time !== null || strtotime((string) $transaction->expire_time) <= time() || !hash_equals((string) $transaction->browser_binding_hash, $this->hash('browser:' . $browserBinding))) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            $provider = $this->lockedProvider((int) $transaction->identity_provider_id, (int) $transaction->application_id, 'saml');
            if ((int) $provider->config_version !== (int) $transaction->provider_config_version) {
                throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
            }
            $identity = $this->linkCallbackIdentity($transaction, $provider, $subject, $claims, $requestTraceId);
            $assertionId = (string) ($claims['assertion_id'] ?? '');
            if ($assertionId === '') throw new ApiException('SAND_IAM_SAML_ASSERTION_INVALID', 401);
            $replay = FederationTransaction::where('identity_provider_id', (int) $provider->id)->where('protocol', 'saml')->where('assertion_hash', $this->hash('saml-assertion:' . $assertionId))->find();
            if ($replay !== null) throw new ApiException('SAND_IAM_SAML_ASSERTION_REPLAYED', 401);
            $binding = $this->activeBinding($provider, (int) $transaction->application_id, $subject);
            $application = Application::where('id', (int) $transaction->application_id)->where('status', 1)->find();
            if ($application === null) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 404);
            $transaction->save(['status' => 2, 'consumed_time' => $this->now(), 'assertion_hash' => $this->hash('saml-assertion:' . $assertionId)]);
            $result = $this->createHandoff($transaction, $provider, $identity, $binding);
            Db::commit();
        } catch (\Throwable $exception) { Db::rollback(); $this->auditCallbackFailure($provider, (int) $transaction->application_id, 'saml', $requestTraceId); throw $exception; }
        $this->auditProvider($provider, (int) $transaction->application_id, 'identity_provider.saml_callback', 'succeeded', $requestTraceId, ['identity_id' => (int) $identity->id]);
        return $result;
    }

    /** @return array<string,mixed> */
    public function exchangeHandoff(string $providerCode, string $applicationCode, string $code, string $returnUri, string $verifier, string $ip, string $userAgent, string $requestId): array
    {
        $provider = null;
        $auditApplicationId = null;
        try {
            if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $providerCode) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/', $applicationCode) || !preg_match('/^fh_[a-f0-9]{64}$/', $code) || !$this->validHandoffUri($returnUri) || !$this->validHandoffVerifier($verifier)) {
                throw new ApiException('SAND_IAM_FEDERATION_HANDOFF_INVALID', 401);
            }
            Db::startTrans();
            try {
                $handoff = FederationHandoff::where('code_hash', $this->hash('handoff:' . $code))->lock(true)->find();
                if ($handoff === null || (int) $handoff->status !== 1 || $handoff->consumed_time !== null || strtotime((string) $handoff->expire_time) <= time()) throw new ApiException('SAND_IAM_FEDERATION_HANDOFF_INVALID', 401);
                $auditApplicationId = (int) $handoff->application_id;
                $provider = IdentityProvider::where('id', (int) $handoff->identity_provider_id)->lock(true)->find();
                if ($provider === null || !hash_equals((string) $provider->public_code, $providerCode)) throw new ApiException('SAND_IAM_FEDERATION_HANDOFF_INVALID', 401);
                $provider = $this->lockedProvider((int) $provider->id, (int) $handoff->application_id, (string) $provider->provider_type);
                $application = Application::where('id', (int) $handoff->application_id)->where('code', $applicationCode)->where('status', 1)->lock(true)->find();
                $binding = $application === null ? null : IdentityBinding::where('id', (int) $handoff->identity_binding_id)->where('application_id', (int) $application->id)->where('identity_provider_id', (int) $provider->id)->where('status', 1)->where('source_state', 'active')->lock(true)->find();
                $identity = $binding === null ? null : Identity::where('id', (int) $binding->identity_id)->where('application_id', (int) $application->id)->where('status', 1)->lock(true)->find();
                if ($application === null || $binding === null || $identity === null || (int) $handoff->organization_id !== (int) $application->organization_id || (int) $provider->config_version !== (int) $handoff->provider_config_version || !hash_equals((string) $handoff->return_uri, $returnUri) || !hash_equals((string) $handoff->code_challenge, $this->b64(hash('sha256', $verifier, true)))) {
                    throw new ApiException('SAND_IAM_FEDERATION_HANDOFF_INVALID', 401);
                }
                $handoff->save(['status' => 2, 'consumed_time' => $this->now()]);
                $result = (new MfaService())->hasEnabledFactor((int) $application->id, (int) $identity->id)
                    ? (new MfaService())->beginFederatedLogin($application, $identity, $binding, $requestId)
                    : (new HumanAuthService())->issueFederatedSessionInTransaction($application, $identity, $binding, $ip, $userAgent, $requestId);
                Db::commit();
            } catch (\Throwable $exception) {
                Db::rollback();
                throw $exception;
            }
            $this->auditProvider($provider, (int) $application->id, 'identity_provider.handoff_exchange', 'succeeded', $requestId, ['identity_id' => (int) $identity->id, 'identity_binding_id' => (int) $binding->id]);
            return $result;
        } catch (\Throwable $exception) {
            if ($provider instanceof IdentityProvider && is_int($auditApplicationId)) $this->auditProvider($provider, $auditApplicationId, 'identity_provider.handoff_exchange', 'failed', $requestId, []);
            if ($exception instanceof ApiException && str_starts_with($exception->getMessage(), 'SAND_IAM_FEDERATION_HANDOFF_INVALID')) throw $exception;
            throw new ApiException('SAND_IAM_FEDERATION_HANDOFF_INVALID', 401);
        }
    }

    public function unlink(string $accessToken, int $bindingId, string $requestId): void
    {
        $session = (new HumanAuthService())->authenticatedSession($accessToken);
        if ($bindingId <= 0 || $session->step_up_time === null || strtotime((string) $session->step_up_time) < time() - 300) throw new ApiException('SAND_IAM_FEDERATION_STEP_UP_REQUIRED', 401);
        $provider = null;
        $applicationId = (int) $session->application_id;
        Db::startTrans();
        try {
            $session = AuthSession::where('id', (int) $session->id)->where('status', 1)->lock(true)->find();
            $identity = $session === null ? null : Identity::where('id', (int) $session->identity_id)->where('application_id', (int) $session->application_id)->where('status', 1)->lock(true)->find();
            $application = $session === null ? null : Application::where('id', (int) $session->application_id)->where('status', 1)->lock(true)->find();
            $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            $binding = $identity === null ? null : IdentityBinding::where('id', $bindingId)->where('application_id', (int) $identity->application_id)->where('identity_id', (int) $identity->id)->where('status', 1)->lock(true)->find();
            $provider = $binding === null ? null : IdentityProvider::where('id', (int) $binding->identity_provider_id)->where('status', 1)->lock(true)->find();
            $mount = $provider === null || $application === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $application->id)->where('organization_id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
            if (!$this->liveLinkSession($session, $identity, $applicationId) || $application === null || $organization === null || $binding === null || $provider === null || $mount === null || (int) $provider->organization_id !== (int) $application->organization_id) throw new ApiException('SAND_IAM_FEDERATION_UNLINK_INVALID', 401);
            $otherBindings = 0;
            foreach (IdentityBinding::where('application_id', (int) $identity->application_id)->where('identity_id', (int) $identity->id)->where('status', 1)->where('source_state', 'active')->where('id', '<>', $bindingId)->lock(true)->select() as $candidate) {
                $candidateProvider = IdentityProvider::where('id', (int) $candidate->identity_provider_id)->where('status', 1)->lock(true)->find();
                $candidateMount = $candidateProvider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $candidateProvider->id)->where('application_id', (int) $identity->application_id)->where('organization_id', (int) $application->organization_id)->where('status', 1)->lock(true)->find();
                if ($candidateProvider !== null && $candidateMount !== null && (int) $candidateProvider->organization_id === (int) $application->organization_id && ((string) $candidateProvider->scope_type !== 'application' || (int) $candidateProvider->application_id === (int) $application->id)) $otherBindings++;
            }
            $passwordAuthIds = IdentityAuth::where('application_id', (int) $identity->application_id)->where('identity_id', (int) $identity->id)->where('status', 1)->where('pepper_version', (string) config('plugin.sand-iam.app.auth_pepper_version', 'v1'))->lock(true)->column('id');
            $password = count($passwordAuthIds);
            $passkeys = (new MfaService())->hasUsablePasskey($application, (int) $identity->id) ? 1 : 0;
            if ($otherBindings + $password + $passkeys < 1) throw new ApiException('SAND_IAM_FEDERATION_LAST_LOGIN_METHOD', 409);
            $binding->save(['status' => 2]);
            $sessionIds = AuthSession::where('identity_binding_id', $bindingId)->where('status', 1)->lock(true)->column('id');
            if ($sessionIds !== []) {
                AuthSession::whereIn('id', $sessionIds)->update(['status' => 2, 'revoked_time' => $this->now()]);
                AuthRefreshToken::whereIn('session_id', $sessionIds)->where('status', 1)->update(['status' => 2, 'revoked_time' => $this->now()]);
            }
            Db::commit();
        } catch (\Throwable $exception) {
            Db::rollback();
            if ($provider instanceof IdentityProvider) $this->auditProvider($provider, $applicationId, 'identity_provider.account_unlink', 'failed', $requestId, ['reason' => 'validation_failed']);
            throw $exception;
        }
        $this->auditProvider($provider, $applicationId, 'identity_provider.account_unlink', 'succeeded', $requestId, ['identity_id' => (int) $identity->id, 'identity_binding_id' => $bindingId]);
    }

    /** @return array<string,mixed> */
    public function syncLdap(int $providerId, int $applicationId, string $requestId): array
    {
        $provider = $this->provider($providerId, $applicationId, 'ldap');
        $config = $this->cipher->decrypt($provider->encrypted_config);
        $run = DirectorySyncRun::create(['application_id' => $applicationId, 'identity_provider_id' => (int) $provider->id, 'provider_config_version' => (int) $provider->config_version, 'state' => 'running', 'cursor_before' => null, 'start_time' => $this->now()]);
        try {
            // RFC2696 cookies are connection-local paging state, never durable
            // incremental-sync cursors. A failed page is retried from page one.
            $page = $this->ldap->page($config, null);
            $processed = 0;
            $failed = 0;
            /** @var array<string,array{subject:string,entry:array<string,mixed>,source_active:bool}> $observed */
            $observed = [];
            foreach ($page['entries'] as $entry) {
                try {
                    $subject = $this->mapped($provider, $entry, 'subject');
                    if ($subject === '') throw new ApiException('SAND_IAM_DIRECTORY_MAPPING_INVALID', 400);
                    $stableSubject = $this->normalizedSubject('ldap', $subject);
                    // A single sync result must have one authoritative record
                    // per stable subject. Duplicates are ambiguous, so make the
                    // entire run partial before any directory state is changed.
                    if (isset($observed[$stableSubject])) throw new ApiException('SAND_IAM_DIRECTORY_DUPLICATE_SUBJECT', 409);
                    $sourceActive = strtolower($this->mapped($provider, $entry, 'active'));
                    $observed[$stableSubject] = [
                        'subject' => $subject,
                        'entry' => $entry,
                        'source_active' => !in_array($sourceActive, ['false', '0', 'disabled'], true),
                    ];
                } catch (\Throwable) { $failed++; }
            }
            $state = $failed > 0 || !$page['complete'] ? 'partial' : 'succeeded';
            // Never mass-disable on an empty successful result: an accidental
            // filter/ACL regression is indistinguishable from a directory wipe.
            if ($state === 'succeeded' && $observed === []) $state = 'partial';
            Db::startTrans();
            try {
                $lockedRun = DirectorySyncRun::where('id',(int)$run->id)->lock(true)->find();
                if ($lockedRun === null) throw new ApiException('SAND_IAM_DIRECTORY_SYNC_FAILED', 503);
                $lockedProvider = $this->lockedProvider((int) $provider->id, $applicationId, 'ldap');
                if ((int) $lockedProvider->config_version !== (int) $lockedRun->provider_config_version) throw new ApiException('SAND_IAM_DIRECTORY_SYNC_FAILED', 503);
                if ($state === 'succeeded') {
                    $previous = DirectorySyncRun::where('identity_provider_id',(int)$provider->id)->where('application_id',$applicationId)->where('state','succeeded')->whereNotNull('observed_count')->order('id','desc')->lock(true)->find();
                    if ($previous !== null && $this->directoryObservedDropExceedsThreshold(count($observed), (int) $previous->observed_count, $config)) $state = 'partial';
                }
                if ($state === 'succeeded') {
                    // Locking the provider serializes all JIT creation and
                    // directory reconciliation. The state changes below are
                    // one transaction, so a version drift or database failure
                    // cannot leave an identity without its binding.
                    foreach ($observed as $stableSubject => $record) {
                        $this->applyLdapEntry($lockedProvider, $applicationId, $stableSubject, $record['subject'], $record['entry'], $record['source_active'], $requestId);
                        $processed++;
                    }
                    $grace = max(86400, (int) ($config['disable_grace_seconds'] ?? 86400));
                    $missing = IdentityBinding::where('identity_provider_id',(int)$provider->id)->where('application_id',$applicationId)->where('source_state','active')->whereNotIn('subject', array_keys($observed))->lock(true)->select()->all();
                    foreach ($missing as $binding) { if ($binding->missing_since === null) { $binding->save(['missing_since'=>$this->now()]); continue; } if (strtotime((string)$binding->missing_since) <= time()-$grace) { $binding->save(['source_state'=>'disabled','source_updated_time'=>$this->now()]); $this->revokeBindingSessions((int)$binding->id); } }
                    $lockedProvider->save(['last_sync_time'=>$this->now()]);
                }
                $lockedRun->save(['state'=>$state,'cursor_after'=>null,'observed_count'=>count($observed),'processed_count'=>$processed,'failed_count'=>$failed,'finish_time'=>$this->now()]);
                Db::commit();
            } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
            $this->auditProvider(
                $provider,
                $applicationId,
                'identity_provider.ldap_sync',
                $state === 'succeeded' ? 'succeeded' : 'failed',
                $requestId,
                ['run_id' => (int) $run->id, 'sync_state' => $state, 'processed_count' => $processed, 'failed_count' => $failed],
            );
            return ['run_id' => (int) $run->id, 'state' => $state, 'processed_count' => $processed, 'failed_count' => $failed, 'complete' => $state === 'succeeded' && (bool) $page['complete']];
        } catch (\Throwable $exception) {
            $run->save(['state' => 'failed', 'error_code' => $this->errorCode($exception), 'finish_time' => $this->now()]);
            $this->auditProvider($provider, $applicationId, 'identity_provider.ldap_sync', 'failed', $requestId, ['run_id' => (int) $run->id, 'error_code' => $this->errorCode($exception)]);
            throw $exception;
        }
    }

    private function provider(int $id, int $applicationId, string $type): IdentityProvider
    {
        $provider = IdentityProvider::where('id', $id)->where('provider_type', $type)->where('status', 1)->find();
        return $this->assertProviderAvailable($provider, $applicationId, false);
    }

    private function lockedProvider(int $id, int $applicationId, string $type): IdentityProvider
    {
        $provider = IdentityProvider::where('id', $id)->where('provider_type', $type)->where('status', 1)->lock(true)->find();
        return $this->assertProviderAvailable($provider, $applicationId, true);
    }

    private function assertProviderAvailable(?IdentityProvider $provider, int $applicationId, bool $lock): IdentityProvider
    {
        $mountQuery = $provider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('status', 1);
        if ($mountQuery !== null && $lock) $mountQuery->lock(true);
        $mount = $mountQuery?->find();
        $appQuery = $mount === null ? null : Application::where('id', $applicationId)->where('status', 1);
        if ($appQuery !== null && $lock) $appQuery->lock(true);
        $app = $appQuery?->find();
        $organizationQuery = $app === null ? null : Organization::where('id', (int) $app->organization_id)->where('status', 1);
        if ($organizationQuery !== null && $lock) $organizationQuery->lock(true);
        $organization = $organizationQuery?->find();
        if ($provider === null || $mount === null || $app === null || $organization === null || (int) $provider->organization_id !== (int) $app->organization_id) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 404);
        return $provider;
    }

    /** @return array{0:IdentityProvider,1:Application} */
    private function publicProvider(string $publicCode, string $applicationCode, string $type): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $publicCode) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/', $applicationCode)) {
            throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 404);
        }
        $provider = IdentityProvider::where('public_code', $publicCode)->where('provider_type', $type)->where('status', 1)->find();
        $application = $provider === null ? null : Application::where('code', $applicationCode)->where('organization_id', (int) $provider->organization_id)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        $mount = $application === null || $provider === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $application->id)->where('status', 1)->find();
        if ($provider === null || $application === null || $organization === null || $mount === null) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE', 404);
        return [$provider, $application];
    }

    /** @return array<string,mixed> */
    private function discovery(array $config): array
    {
        $discovery = $config['discovery_url'] ?? null;
        $data = is_string($discovery) && $discovery !== '' ? $this->http->json('GET', $discovery) : $config;
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) if (!is_string($data[$field] ?? null) || $data[$field] === '') throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        if (is_string($discovery) && $discovery !== '' && (!is_string($config['issuer'] ?? null) || !hash_equals((string) $config['issuer'], (string) $data['issuer']))) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) $this->httpsUri((string) $data[$field]);
        return $data;
    }

    /** @return array<string,mixed> */
    private function verifyExternalIdToken(string $token, array $discovery, string $clientId, string $nonceHash): array
    {
        [$head, $body, $signature] = array_pad(explode('.', $token, 3), 3, '');
        try { $header = json_decode($this->b64d($head), true, 16, JSON_THROW_ON_ERROR); $claims = json_decode($this->b64d($body), true, 32, JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401); }
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null) || ($claims['iss'] ?? '') !== $discovery['issuer'] || !is_string($claims['sub'] ?? null) || !is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null) || $claims['exp'] <= time() || $claims['iat'] > time() + 60) throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401);
        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $audiences, true) || (isset($claims['azp']) && ($claims['azp'] ?? '') !== $clientId) || (count($audiences) > 1 && ($claims['azp'] ?? '') !== $clientId)) throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401);
        if (!is_string($claims['nonce'] ?? null) || !hash_equals($nonceHash, $this->hash('nonce:' . $claims['nonce']))) throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401);
        $keys = $this->http->json('GET', (string) $discovery['jwks_uri'])['keys'] ?? null;
        if (!is_array($keys)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_RESPONSE_INVALID', 502);
        $matches = array_values(array_filter($keys, static fn (mixed $candidate): bool => is_array($candidate) && hash_equals((string) ($candidate['kid'] ?? ''), (string) $header['kid']) && (($candidate['use'] ?? 'sig') === 'sig') && (($candidate['alg'] ?? 'RS256') === 'RS256')));
        if (count($matches) !== 1) throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401);
        $jwk = $matches[0];
        if ($jwk === null || openssl_verify($head . '.' . $body, $this->b64d($signature), $this->jwkPem($jwk), OPENSSL_ALGO_SHA256) !== 1) throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401);
        return $claims;
    }

    /** @return array{identity_id:?int,session_id:?int} */
    private function linkStartContext(string $purpose, string $accessToken, Application $application): array
    {
        if ($purpose === 'login') return ['identity_id' => null, 'session_id' => null];
        if ($purpose !== 'link') throw new ApiException('SAND_IAM_FEDERATION_LINK_INVALID', 400);
        $session = (new HumanAuthService())->authenticatedSession($accessToken);
        if ((int) $session->application_id !== (int) $application->id || $session->step_up_time === null || strtotime((string) $session->step_up_time) < time() - 300) throw new ApiException('SAND_IAM_FEDERATION_STEP_UP_REQUIRED', 401);
        return ['identity_id' => (int) $session->identity_id, 'session_id' => (int) $session->id];
    }

    /** @param array<string,mixed> $claims */
    private function linkCallbackIdentity(FederationTransaction $transaction, IdentityProvider $provider, string $subject, array $claims, string $requestId): Identity
    {
        if ((string) $transaction->purpose !== 'link') return $this->linkIdentity($provider, (int) $transaction->application_id, $subject, $claims, $requestId);
        $identityId = (int) ($transaction->link_identity_id ?? 0);
        $sessionId = (int) ($transaction->link_session_id ?? 0);
        $session = AuthSession::where('id', $sessionId)->where('application_id', (int) $transaction->application_id)->where('identity_id', $identityId)->where('status', 1)->lock(true)->find();
        $identity = $session === null ? null : Identity::where('id', $identityId)->where('application_id', (int) $transaction->application_id)->where('status', 1)->lock(true)->find();
        if (!$this->liveLinkSession($session, $identity, (int) $transaction->application_id)) {
            throw new ApiException('SAND_IAM_FEDERATION_STEP_UP_REQUIRED', 401);
        }
        $subject = $this->normalizedSubject((string) $provider->provider_type, $subject);
        $binding = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $transaction->application_id)->where('subject', $subject)->lock(true)->find();
        if ($binding !== null && (int) $binding->identity_id !== (int) $identity->id) {
            throw new ApiException('SAND_IAM_FEDERATION_LINK_CONFLICT', 409);
        }
        if ($binding === null) IdentityBinding::create(['application_id' => (int) $transaction->application_id, 'identity_id' => (int) $identity->id, 'identity_provider_id' => (int) $provider->id, 'subject' => $subject, 'source_state' => 'active', 'source_updated_time' => $this->now(), 'status' => 1]);
        elseif ((int) $binding->status !== 1 || (string) $binding->source_state !== 'active') {
            throw new ApiException('SAND_IAM_FEDERATION_BINDING_DISABLED', 409);
        }
        $this->auditProvider($provider, (int) $transaction->application_id, 'identity_provider.account_link', 'succeeded', $requestId, ['identity_id' => (int) $identity->id]);
        return $identity;
    }

    /** @param array<string,mixed> $claims */
    private function linkIdentity(IdentityProvider $provider, int $applicationId, string $subject, array $claims, string $requestId): Identity
    {
        $subject = $this->normalizedSubject((string) $provider->provider_type, $subject);
        $binding = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('subject', $subject)->where('status', 1)->where('source_state', 'active')->find();
        if ($binding !== null) {
            $identity = Identity::where('id', (int) $binding->identity_id)->where('application_id', $applicationId)->where('status', 1)->find();
            if ($identity === null) throw new ApiException('SAND_IAM_FEDERATION_BINDING_INVALID', 409);
            return $identity;
        }
        $disabledBinding = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('subject', $subject)->find();
        if ($disabledBinding !== null) throw new ApiException('SAND_IAM_FEDERATION_BINDING_DISABLED', 409);
        if ((string) $provider->conflict_policy === 'reject') throw new ApiException('SAND_IAM_FEDERATION_ACCOUNT_LINK_REQUIRED', 409);
        $username = $this->mapped($provider, $claims, 'username');
        if ($username === '') $username = 'federated-' . substr(hash('sha256', (int) $provider->id . "\0" . $subject), 0, 32);
        $identity = Identity::where('application_id', $applicationId)->where('code', $username)->find();
        // Username, email and phone are mutable identifiers, never linkage
        // evidence. Existing local accounts require a separate authenticated
        // step-up linking flow and are deliberately not auto-linked here.
        if ($identity !== null) throw new ApiException('SAND_IAM_FEDERATION_ACCOUNT_LINK_REQUIRED', 409);
        // Callers hold the provider lock inside their final transaction. That
        // serializes a previously absent subject and keeps identity creation
        // inseparable from binding creation.
        $existing = IdentityBinding::where('identity_provider_id',(int)$provider->id)->where('application_id',$applicationId)->where('subject',$subject)->where('status',1)->where('source_state','active')->lock(true)->find();
        if ($existing !== null) {
            $identity = Identity::where('id',(int)$existing->identity_id)->where('application_id',$applicationId)->where('status',1)->find();
            if ($identity === null) throw new ApiException('SAND_IAM_FEDERATION_BINDING_INVALID',409);
        } else {
            if ($identity === null) $identity = Identity::create(['application_id' => $applicationId, 'code' => substr($username, 0, 128), 'display_name' => substr($this->mapped($provider, $claims, 'display_name') ?: $username, 0, 128), 'status' => 1]);
            IdentityBinding::create(['application_id' => $applicationId, 'identity_id' => (int) $identity->id, 'identity_provider_id' => (int) $provider->id, 'subject' => $subject, 'source_state' => 'active', 'source_updated_time' => $this->now(), 'status' => 1]);
        }
        $this->auditProvider($provider, $applicationId, 'identity_provider.account_link', 'succeeded', $requestId, ['identity_id' => (int) $identity->id]);
        return $identity;
    }

    /** @param array<string,mixed> $entry */
    private function applyLdapEntry(IdentityProvider $provider, int $applicationId, string $stableSubject, string $subject, array $entry, bool $sourceActive, string $requestId): void
    {
        $binding = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('subject', $stableSubject)->lock(true)->find();
        if ($binding !== null) {
            $binding->save(['source_state' => $sourceActive ? 'active' : 'disabled', 'source_updated_time' => $this->now(), 'missing_since' => null]);
            if (!$sourceActive) $this->revokeBindingSessions((int) $binding->id);
            return;
        }
        if (!$sourceActive) return;
        $entryRequestId = hash('sha256', "identity_provider.ldap_entry\0" . $requestId . "\0" . $stableSubject);
        $this->linkIdentity($provider, $applicationId, $subject, $entry, $entryRequestId);
    }

    /** @param array<string,mixed> $config */
    private function directoryObservedDropExceedsThreshold(int $current, int $previous, array $config): bool
    {
        if ($previous <= 0) return false;
        $threshold = $config['observed_drop_safety_threshold'] ?? 0.5;
        if (!is_int($threshold) && !is_float($threshold) && !is_string($threshold)) return true;
        $threshold = (float) $threshold;
        if ($threshold <= 0.0 || $threshold >= 1.0) return true;
        return ($previous - $current) / $previous > $threshold;
    }

    private function activeBinding(IdentityProvider $provider, int $applicationId, string $subject): IdentityBinding
    {
        $subject = $this->normalizedSubject((string) $provider->provider_type, $subject);
        $binding = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('subject', $subject)->where('status', 1)->where('source_state', 'active')->find();
        if ($binding === null) throw new ApiException('SAND_IAM_FEDERATION_BINDING_INVALID', 409);
        return $binding;
    }

    private function normalizedSubject(string $protocol, string $subject): string
    {
        if ($protocol === 'ldap') return 'ldap:v1:' . rtrim(strtr(base64_encode(hash('sha256', "ldap-bytes:v1\0" . $subject, true)), '+/', '-_'), '=');
        if ($subject === '' || strlen($subject) > 191 || trim($subject) !== $subject || str_contains($subject, "\0") || !preg_match('//u', $subject) || preg_match('/[\x00-\x1f\x7f]/', $subject)) throw new ApiException('SAND_IAM_FEDERATION_MAPPING_INVALID', 400);
        return $subject;
    }

    private function revokeBindingSessions(int $bindingId): void
    {
        $sessionIds = AuthSession::where('identity_binding_id',$bindingId)->where('status',1)->column('id');
        if ($sessionIds === []) return;
        AuthSession::whereIn('id',$sessionIds)->update(['status'=>2,'revoked_time'=>$this->now()]);
        AuthRefreshToken::whereIn('session_id',$sessionIds)->where('status',1)->update(['status'=>2,'revoked_time'=>$this->now()]);
    }

    /** @param array<string,mixed> $source */
    private function mapped(IdentityProvider $provider, array $source, string $field): string
    {
        $mapping = $provider->attribute_mapping; if (is_string($mapping)) $mapping = json_decode($mapping, true); if (!is_array($mapping)) return '';
        $key = $mapping[$field] ?? ($field === 'subject' ? 'sub' : $field);
        if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/', $key)) return '';
        $value = $source;
        foreach (explode('.', $key) as $part) {
            $lookup = (string) $provider->provider_type === 'ldap' ? strtolower($part) : $part;
            if (!is_array($value) || !array_key_exists($lookup, $value)) return '';
            $value = $value[$lookup];
        }
        if (!is_scalar($value)) return '';
        $result = (string) $value;
        return $field === 'subject' ? $result : trim($result);
    }

    /** @param array<string,mixed> $mapping */
    private function validateMapping(array $mapping): void { foreach ($mapping as $name => $source) if (!in_array($name, ['subject', 'username', 'display_name', 'email', 'active'], true) || !is_string($source) || !preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,127}$/', $source)) throw new ApiException('SAND_IAM_FEDERATION_MAPPING_INVALID', 400); }
    /** @param array<string,mixed> $config @return array{return_uri:string,state:string,code_challenge:string} */
    private function handoffStartContext(array $config, string $returnUri, string $state, string $handoffChallenge): array
    {
        $allowed = $config['handoff_return_uris'] ?? null;
        if (!is_array($allowed) || count($allowed) < 1 || count($allowed) > 32 || !$this->validHandoffUri($returnUri) || strlen($state) < 1 || strlen($state) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $state) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $handoffChallenge)) {
            throw new ApiException('SAND_IAM_FEDERATION_HANDOFF_REQUEST_INVALID', 400);
        }
        $matches = 0;
        foreach ($allowed as $registered) {
            if (!is_string($registered) || !$this->validHandoffUri($registered)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
            if (hash_equals($registered, $returnUri)) $matches++;
        }
        if (count(array_unique($allowed, SORT_STRING)) !== count($allowed)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        if ($matches !== 1) throw new ApiException('SAND_IAM_FEDERATION_HANDOFF_REQUEST_INVALID', 400);
        return ['return_uri' => $returnUri, 'state' => $state, 'code_challenge' => $handoffChallenge];
    }
    /** @return array{return_uri:string,code:string,state:string} */
    private function createHandoff(FederationTransaction $transaction, IdentityProvider $provider, Identity $identity, IdentityBinding $binding): array
    {
        $returnUri = (string) ($transaction->handoff_return_uri ?? '');
        $state = (string) ($transaction->handoff_state ?? '');
        $challenge = (string) ($transaction->handoff_code_challenge ?? '');
        if (!$this->validHandoffUri($returnUri) || $state === '' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge)) throw new ApiException('SAND_IAM_FEDERATION_STATE_INVALID', 400);
        $code = 'fh_' . bin2hex(random_bytes(32));
        FederationHandoff::create([
            'organization_id' => (int) $provider->organization_id,
            'application_id' => (int) $transaction->application_id,
            'identity_provider_id' => (int) $provider->id,
            'identity_binding_id' => (int) $binding->id,
            'provider_config_version' => (int) $transaction->provider_config_version,
            'code_hash' => $this->hash('handoff:' . $code),
            'return_uri' => $returnUri,
            'code_challenge' => $challenge,
            'expire_time' => date('Y-m-d H:i:s', time() + 120),
            'status' => 1,
        ]);
        return ['return_uri' => $returnUri, 'code' => $code, 'state' => $state];
    }
    /** @param array<string,mixed> $config */
    private function validateConfig(string $type, array $config): void
    {
        if (count($config) > 32 || json_encode($config) === false) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        if (in_array($type, ['oidc', 'oauth2', 'saml'], true)) {
            $returnUris = $config['handoff_return_uris'] ?? null;
            if (!is_array($returnUris) || count($returnUris) < 1 || count($returnUris) > 32) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
            foreach ($returnUris as $returnUri) if (!is_string($returnUri) || !$this->validHandoffUri($returnUri)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
            if (count(array_unique($returnUris, SORT_STRING)) !== count($returnUris)) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        }
        if ($type === 'oidc' && (!is_string($config['client_id'] ?? null) || (!is_string($config['discovery_url'] ?? null) && !is_string($config['issuer'] ?? null)))) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        if ($type === 'oauth2') foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'client_id', 'redirect_uri'] as $field) if (!is_string($config[$field] ?? null) || trim((string) $config[$field]) === '') throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400);
        if ($type === 'saml') foreach (['entity_id', 'sso_url', 'acs_url', 'idp_x509cert', 'sp_x509cert', 'sp_private_key'] as $field) if (!is_string($config[$field] ?? null) || trim((string) $config[$field]) === '') throw new ApiException('SAND_IAM_SAML_CONFIGURATION_INVALID', 400);
        if ($type === 'kerberos') {
            if (!is_string($config['service_principal'] ?? null) || !preg_match('/^HTTP\/[A-Za-z0-9.-]+@[A-Z0-9][A-Z0-9.-]{1,127}$/', (string) $config['service_principal'])) throw new ApiException('SAND_IAM_KERBEROS_CONFIGURATION_INVALID', 400);
            if (!is_string($config['keytab_ref'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', (string) $config['keytab_ref'])) throw new ApiException('SAND_IAM_KERBEROS_CONFIGURATION_INVALID', 400);
            $realms = $config['allowed_realms'] ?? null;
            if (!is_array($realms) || $realms === [] || count($realms) > 20 || array_filter($realms, static fn (mixed $realm): bool => !is_string($realm) || !preg_match('/^[A-Z0-9][A-Z0-9.-]{1,127}$/', $realm)) || count(array_unique($realms, SORT_STRING)) !== count($realms)) throw new ApiException('SAND_IAM_KERBEROS_CONFIGURATION_INVALID', 400);
            if (($config['require_channel_binding'] ?? null) !== true || ($config['require_replay_cache'] ?? null) !== true || ($config['require_mutual_auth'] ?? null) !== true) throw new ApiException('SAND_IAM_KERBEROS_CONFIGURATION_INVALID', 400);
        }
    }
    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function identityDomainChanged(string $beforeType, string $afterType, array $before, array $after, mixed $beforeMapping, array $afterMapping): bool
    {
        if ($beforeType !== $afterType) return true;
        $keys = match ($afterType) {
            'oidc' => ['issuer', 'client_id'],
            'oauth2' => ['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint', 'client_id'],
            'saml' => ['entity_id', 'sp_entity_id'],
            'ldap' => ['uri', 'base_dn'],
            'kerberos' => ['service_principal', 'keytab_ref', 'allowed_realms'],
            default => [],
        };
        foreach ($keys as $key) {
            $beforeValue = is_array($before[$key] ?? null) ? json_encode(array_values($before[$key]), JSON_THROW_ON_ERROR) : (string) ($before[$key] ?? '');
            $afterValue = is_array($after[$key] ?? null) ? json_encode(array_values($after[$key]), JSON_THROW_ON_ERROR) : (string) ($after[$key] ?? '');
            if (!hash_equals($beforeValue, $afterValue)) return true;
        }
        $oldMapping = is_string($beforeMapping) ? json_decode($beforeMapping, true) : $beforeMapping;
        if (!is_array($oldMapping)) $oldMapping = [];
        return !hash_equals((string) ($oldMapping['subject'] ?? ''), (string) ($afterMapping['subject'] ?? ''));
    }
    private function httpsUri(string $value): void { $parts = parse_url($value); if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ((int) ($parts['port'] ?? 443)) < 1 || ((int) ($parts['port'] ?? 443)) > 65535) throw new ApiException('SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID', 400); }
    private function validHandoffUri(string $value): bool
    {
        if (strlen($value) < 8 || strlen($value) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $value)) return false;
        try { $this->httpsUri($value); } catch (ApiException) { return false; }
        $query = parse_url($value, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            foreach (explode('&', $query) as $part) {
                $key = rawurldecode(explode('=', $part, 2)[0]);
                if (in_array(strtolower($key), ['code', 'state'], true)) return false;
            }
        }
        return true;
    }

    private function liveLinkSession(?AuthSession $session, ?Identity $identity, int $applicationId): bool
    {
        return $session !== null
            && $identity !== null
            && (int) $session->application_id === $applicationId
            && (int) $session->identity_id === (int) $identity->id
            && (int) $session->status === 1
            && $session->revoked_time === null
            && strtotime((string) $session->access_expire_time) > time()
            && hash_equals((string) config('plugin.sand-iam.app.auth_pepper_version', 'v1'), (string) ($session->pepper_version ?? ''))
            && $session->step_up_time !== null
            && strtotime((string) $session->step_up_time) >= time() - 300;
    }
    private function validHandoffVerifier(string $value): bool { return strlen($value) >= 43 && strlen($value) <= 128 && (bool) preg_match('/^[A-Za-z0-9._~-]+$/', $value); }
    private function hash(string $value): string { $pepper = (string) config('plugin.sand-iam.app.auth_pepper', ''); if ($pepper === '') throw new ApiException('SAND_IAM_FEDERATION_CONFIGURATION_UNAVAILABLE', 503); return hash_hmac('sha256', $value, $pepper); }
    private function now(): string { return date('Y-m-d H:i:s'); }
    private function b64(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private function b64d(string $value): string { $decoded = base64_decode(strtr($value, '-_', '+/'), true); if ($decoded === false) throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401); return $decoded; }
    private function appendQuery(string $url, array $parameters): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986); }
    private function errorCode(\Throwable $exception): string { return substr(preg_replace('/[^A-Z0-9_]/', '_', strtoupper(strtok($exception->getMessage(), ':'))) ?: 'SAND_IAM_DIRECTORY_SYNC_FAILED', 0, 96); }
    /** @return array<string,mixed> */
    private function identityResponse(Identity $identity, IdentityProvider $provider): array { return ['identity' => ['id' => (int) $identity->id, 'code' => (string) $identity->code, 'display_name' => (string) $identity->display_name], 'application_id' => (int) $identity->application_id, 'identity_provider_id' => (int) $provider->id]; }
    private function auditProvider(IdentityProvider $provider, ?int $applicationId, string $action, string $outcome, string $requestId, array $context): void
    {
        $application = $applicationId === null ? null : Application::where('id', $applicationId)->where('organization_id', (int) $provider->organization_id)->find();
        $this->audit->write('identity_provider', (string) $provider->id, (int) $provider->organization_id, $application ? (int) $application->id : null, $action, 'identity_provider', (int) $provider->id, $outcome, $requestId !== '' ? substr($requestId, 0, 96) : bin2hex(random_bytes(16)), $context);
    }
    private function auditCallbackFailure(IdentityProvider $provider, int $applicationId, string $protocol, string $requestId): void
    {
        $this->auditProvider($provider, $applicationId, 'identity_provider.' . $protocol . '_callback', 'failed', $requestId, ['reason' => 'callback_rejected']);
    }
    /** @param array<string,mixed> $jwk */
    private function jwkPem(array $jwk): string { if (($jwk['kty'] ?? '') !== 'RSA' || !is_string($jwk['n'] ?? null) || !is_string($jwk['e'] ?? null)) throw new ApiException('SAND_IAM_FEDERATION_TOKEN_INVALID', 401); $n = $this->derInteger($this->b64d($jwk['n'])); $e = $this->derInteger($this->b64d($jwk['e'])); $rsa = "\x30" . $this->derLength(strlen($n . $e)) . $n . $e; $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; $spki = "\x30" . $this->derLength(strlen($algorithm) + strlen("\x03") + strlen($this->derLength(strlen($rsa) + 1)) + strlen($rsa) + 1) . $algorithm . "\x03" . $this->derLength(strlen($rsa) + 1) . "\x00" . $rsa; return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"; }
    private function derInteger(string $value): string { $value = ltrim($value, "\0"); if ($value === '' || (ord($value[0]) & 0x80)) $value = "\0" . $value; return "\x02" . $this->derLength(strlen($value)) . $value; }
    private function derLength(int $length): string { if ($length < 128) return chr($length); $out = ''; while ($length > 0) { $out = chr($length & 0xff) . $out; $length >>= 8; } return chr(0x80 | strlen($out)) . $out; }
}
