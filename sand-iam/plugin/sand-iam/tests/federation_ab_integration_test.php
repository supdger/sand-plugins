<?php

declare(strict_types=1);

use plugin\SandIam\app\federation\FederationHttpAdapter;
use plugin\SandIam\app\federation\LdapDirectoryAdapter;
use plugin\SandIam\app\federation\SamlAssertionVerifier;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuditLog;
use plugin\SandIam\app\model\AuthRefreshToken;
use plugin\SandIam\app\model\AuthSession;
use plugin\SandIam\app\model\DirectorySyncRun;
use plugin\SandIam\app\model\FederationTransaction;
use plugin\SandIam\app\model\FederationHandoff;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\model\ScimGroupMember;
use plugin\SandIam\app\model\ScimToken;
use plugin\SandIam\app\service\FederationConfigCipher;
use plugin\SandIam\app\service\FederationService;
use plugin\SandIam\app\service\ScimService;
use plugin\SandIam\app\api\controller\FederationController;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;
use think\facade\Db;

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "IAM-T04 federation PostgreSQL integration failed: SandAdmin host dependencies are unavailable at {$hostRoot}\n");
    exit(1);
}
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

function t04Fail(string $message): never
{
    fwrite(STDERR, "IAM-T04 federation PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function t04Assert(bool $condition, string $message): void
{
    if (!$condition) t04Fail($message);
}

function t04Expect(callable $callback, string $expected): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $expected)) return;
        t04Fail("expected {$expected}, received {$exception->getMessage()}");
    }
    t04Fail("expected {$expected}, but no exception was thrown");
}

function t04Hash(string $value): string
{
    return hash_hmac('sha256', $value, (string) getenv('SAND_IAM_AUTH_PEPPER'));
}

/** @param array<string,mixed> $attributes */
function t04Session(array $attributes): AuthSession
{
    $ipHash = t04Hash('fixture-ip:' . (string) ($attributes['access_token_hash'] ?? ''));
    return AuthSession::create(['ip_hash' => $ipHash] + $attributes);
}

function t04B64(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @param list<array<string,mixed>> $entries */
final class T04Ldap implements LdapDirectoryAdapter
{
    /** @param null|\Closure():void $afterRead */
    public function __construct(private readonly array $entries, private readonly bool $complete = true, private readonly ?\Closure $afterRead = null)
    {
    }

    public function page(array $config, ?string $cursor): array
    {
        if ($this->afterRead !== null) ($this->afterRead)();
        return ['entries' => $this->entries, 'cursor' => null, 'complete' => $this->complete];
    }
}

final class T04LdapFailure implements LdapDirectoryAdapter
{
    public function page(array $config, ?string $cursor): array
    {
        throw new ApiException('SAND_IAM_DIRECTORY_TRANSPORT_FAILED', 503);
    }
}

final class T04Http implements FederationHttpAdapter
{
    /** @param array<string,mixed> $token @param array<string,mixed> $claims @param null|\Closure():void $beforeClaims */
    public function __construct(private readonly array $token, private readonly array $claims, private readonly ?\Closure $beforeClaims = null, private readonly array $jwks = [])
    {
    }

    public function json(string $method, string $url, array $headers = [], array $form = []): array
    {
        if ($method === 'POST') return $this->token;
        if ($this->beforeClaims !== null) ($this->beforeClaims)();
        if (str_contains($url, 'jwks')) return ['keys' => $this->jwks];
        return $this->claims;
    }
}

final class T04Saml implements SamlAssertionVerifier
{
    /** @param null|\Closure():void $beforeReturn */
    public function __construct(private readonly ?\Closure $beforeReturn = null)
    {
    }

    public function start(array $config, string $acs, string $relayState): array
    {
        return ['request_id' => 't04-saml-request', 'redirect_uri' => $acs];
    }

    public function verify(string $samlResponse, array $config, string $expectedRequestId, string $expectedRecipient): array
    {
        if ($this->beforeReturn !== null) ($this->beforeReturn)();
        return ['sub' => 't04-saml-subject', 'assertion_id' => 't04-saml-assertion'];
    }
}

/** @param array<string,mixed> $config @param array<string,mixed> $mapping */
function t04Provider(int $organizationId, int $applicationId, string $code, string $type, array $config, array $mapping): IdentityProvider
{
    $provider = IdentityProvider::create([
        'organization_id' => $organizationId,
        'application_id' => $applicationId,
        'scope_type' => 'application',
        'code' => $code,
        'name' => 'T04 ' . $code,
        'provider_type' => 'local',
        'status' => 1,
    ]);
    IdentityProviderApplication::create([
        'identity_provider_id' => (int) $provider->id,
        'application_id' => $applicationId,
        'organization_id' => $organizationId,
        'provider_scope_application_key' => $applicationId,
        'status' => 1,
    ]);
    (new FederationService())->configureProvider((int) $provider->id, $type, $config, $mapping, 'create', 't04-configure-' . $code);
    $provider = IdentityProvider::find((int) $provider->id);
    if ($provider === null) t04Fail('configured provider disappeared');
    return $provider;
}

function t04LdapService(array $entries, bool $complete = true, ?\Closure $afterRead = null): FederationService
{
    return new FederationService(ldap: new T04Ldap($entries, $complete, $afterRead));
}

function t04FederatedSession(int $applicationId, IdentityBinding $binding, string $suffix): void
{
    $session = t04Session([
        'application_id' => $applicationId,
        'identity_id' => (int) $binding->identity_id,
        'identity_binding_id' => (int) $binding->id,
        'auth_method' => 'federation',
        'access_token_hash' => hash('sha256', 't04-access-' . $suffix),
        'refresh_token_hash' => hash('sha256', 't04-refresh-' . $suffix),
        'pepper_version' => 't04-v1',
        'access_expire_time' => date('Y-m-d H:i:s', time() + 900),
        'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400),
        'ip_hash' => hash('sha256', 't04-ip-' . $suffix),
        'status' => 1,
    ]);
    AuthRefreshToken::create(['session_id' => (int) $session->id, 'token_hash' => hash('sha256', 't04-refresh-token-' . $suffix), 'pepper_version' => 't04-v1', 'expire_time' => date('Y-m-d H:i:s', time() + 86400), 'status' => 1]);
}

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$organization = Organization::create(['code' => 't04-runtime-org', 'name' => 'T04 federation organization', 'status' => 1]);
$application = Application::create(['organization_id' => (int) $organization->id, 'code' => 't04-runtime-app', 'name' => 'T04 federation app', 'status' => 1]);
$mapping = ['subject' => 'uid', 'username' => 'uid', 'display_name' => 'cn', 'active' => 'enabled'];
$ldapConfig = ['disable_grace_seconds' => 86400, 'observed_drop_safety_threshold' => 0.5];
$directory = [
    ['uid' => 'alice', 'cn' => 'Alice', 'enabled' => 'true'],
    ['uid' => 'bob', 'cn' => 'Bob', 'enabled' => 'true'],
    ['uid' => 'carol', 'cn' => 'Carol', 'enabled' => 'true'],
];

// A successful full read creates identity and binding atomically per provider.
$provider = t04Provider((int) $organization->id, (int) $application->id, 't04-ldap-main', 'ldap', $ldapConfig, $mapping);
$first = t04LdapService($directory)->syncLdap((int) $provider->id, (int) $application->id, 't04-ldap-first');
t04Assert($first['state'] === 'succeeded' && $first['processed_count'] === 3, 'full LDAP run did not create all three bindings');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $provider->id)->count() === 3, 'successful LDAP run left incomplete binding set');

// Duplicate subject and a sharp observed-count drop both become partial before
// any binding or session mutation, and both leave an auditable outcome.
$beforePartial = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('source_state', 'active')->count();
$duplicate = t04LdapService([$directory[0], $directory[0]])->syncLdap((int) $provider->id, (int) $application->id, 't04-ldap-duplicate');
t04Assert($duplicate['state'] === 'partial' && $duplicate['failed_count'] === 1, 'duplicate LDAP subject was not made partial');
$drop = t04LdapService([$directory[0]])->syncLdap((int) $provider->id, (int) $application->id, 't04-ldap-drop');
t04Assert($drop['state'] === 'partial' && $drop['complete'] === false, 'unsafe observed-count drop was not made partial');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('source_state', 'active')->count() === $beforePartial, 'partial LDAP run changed binding source state');
$partialAudits = AuditLog::where('action', 'identity_provider.ldap_sync')->where('outcome', 'failed')->select()->all();
$partialAuditCount = count(array_filter($partialAudits, static function (AuditLog $audit): bool {
    $context = $audit->context;
    if (is_string($context)) $context = json_decode($context, true);
    return is_array($context) && ($context['sync_state'] ?? null) === 'partial';
}));
t04Assert($partialAuditCount >= 2, 'partial LDAP runs were not audited with the normalized failed outcome');
t04Expect(static fn () => (new FederationService(ldap: new T04LdapFailure()))->syncLdap((int) $provider->id, (int) $application->id, 't04-ldap-failed'), 'SAND_IAM_DIRECTORY_TRANSPORT_FAILED');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('source_state', 'active')->count() === $beforePartial && DirectorySyncRun::where('identity_provider_id', (int) $provider->id)->order('id', 'desc')->value('state') === 'failed', 'failed LDAP run changed bindings or did not record failure');

// missing_since belongs to each binding. A reappearance clears its own grace
// clock; only the same binding is disabled after its independently aged grace.
$graceProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-ldap-grace', 'ldap', $ldapConfig, $mapping);
$graceDirectory = [
    ['uid' => 'grace-alice', 'cn' => 'Grace Alice', 'enabled' => 'true'],
    ['uid' => 'grace-bob', 'cn' => 'Grace Bob', 'enabled' => 'true'],
];
t04Assert(t04LdapService($graceDirectory)->syncLdap((int) $graceProvider->id, (int) $application->id, 't04-grace-first')['state'] === 'succeeded', 'grace fixture did not sync');
$bobSubject = 'ldap:v1:' . t04B64(hash('sha256', "ldap-bytes:v1\0grace-bob", true));
$bobBinding = IdentityBinding::where('identity_provider_id', (int) $graceProvider->id)->where('subject', $bobSubject)->find();
t04Assert($bobBinding !== null, 'LDAP Bob binding is missing');
t04FederatedSession((int) $application->id, $bobBinding, 'bob');
$upstreamDisabled = [$graceDirectory[0], ['uid' => 'grace-bob', 'cn' => 'Grace Bob', 'enabled' => 'disabled']];
t04Assert(t04LdapService($upstreamDisabled)->syncLdap((int) $graceProvider->id, (int) $application->id, 't04-source-disabled')['state'] === 'succeeded', 'explicit LDAP source disable did not succeed');
$bobBinding = IdentityBinding::find((int) $bobBinding->id);
t04Assert($bobBinding !== null && $bobBinding->source_state === 'disabled' && (int) AuthSession::where('identity_binding_id', (int) $bobBinding->id)->value('status') === 2, 'explicit LDAP source disable did not revoke binding session');
t04Assert(t04LdapService($graceDirectory)->syncLdap((int) $graceProvider->id, (int) $application->id, 't04-source-reactivate')['state'] === 'succeeded', 'explicit LDAP source reactivation did not succeed');
$bobBinding = IdentityBinding::find((int) $bobBinding->id);
t04Assert($bobBinding !== null && $bobBinding->source_state === 'active' && $bobBinding->missing_since === null, 'explicit LDAP source reactivation did not restore binding state');
t04FederatedSession((int) $application->id, $bobBinding, 'bob-reactivated');
$missing = t04LdapService([$graceDirectory[0]])->syncLdap((int) $graceProvider->id, (int) $application->id, 't04-grace-missing');
$bobBinding = IdentityBinding::find((int) $bobBinding->id);
t04Assert($missing['state'] === 'succeeded' && $bobBinding !== null && $bobBinding->missing_since !== null && $bobBinding->source_state === 'active', 'first LDAP absence did not set binding-only grace state');
t04Assert(AuthSession::where('identity_binding_id', (int) $bobBinding->id)->where('status', 1)->count() === 1, 'first LDAP absence revoked session before grace elapsed');
t04Assert(t04LdapService($graceDirectory)->syncLdap((int) $graceProvider->id, (int) $application->id, 't04-grace-reappear')['state'] === 'succeeded', 'LDAP reappearance did not succeed');
$bobBinding = IdentityBinding::find((int) $bobBinding->id);
t04Assert($bobBinding !== null && $bobBinding->missing_since === null && $bobBinding->source_state === 'active', 'LDAP reappearance did not clear only its binding grace clock');
t04LdapService([$graceDirectory[0]])->syncLdap((int) $graceProvider->id, (int) $application->id, 't04-grace-missing-again');
Db::execute('UPDATE sand_iam_identity_binding SET missing_since = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 86401), (int) $bobBinding->id]);
$expired = t04LdapService([$graceDirectory[0]])->syncLdap((int) $graceProvider->id, (int) $application->id, 't04-grace-expire');
$bobBinding = IdentityBinding::find((int) $bobBinding->id);
$bobIdentity = Identity::find((int) $bobBinding->identity_id);
t04Assert($expired['state'] === 'succeeded' && $bobBinding !== null && $bobBinding->source_state === 'disabled', 'expired LDAP absence did not disable its binding');
t04Assert($bobIdentity !== null && (int) $bobIdentity->status === 1, 'directory disable mutated shared identity state');
$bobSessionIds = AuthSession::where('identity_binding_id', (int) $bobBinding->id)->column('id');
t04Assert(AuthSession::whereIn('id', $bobSessionIds)->where('status', 1)->count() === 0 && AuthRefreshToken::whereIn('session_id', $bobSessionIds)->where('status', 1)->count() === 0, 'expired LDAP absence did not revoke session and refresh token');
$graceProvider = IdentityProvider::find((int) $graceProvider->id);
t04Assert($graceProvider !== null && $graceProvider->last_sync_time !== null, 'successful final reconciliation did not set provider last_sync_time');

// Equivalent race interleaving: the fake directory changes provider version
// after network read but before the final locked transaction. No JIT row may
// survive, and the run is marked failed rather than reconciled.
$driftProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-ldap-drift', 'ldap', $ldapConfig, $mapping);
t04Expect(static fn () => t04LdapService([['uid' => 'drift', 'cn' => 'Drift', 'enabled' => 'true']], true, static function () use ($driftProvider): void { IdentityProvider::where('id', (int) $driftProvider->id)->update(['config_version' => (int) $driftProvider->config_version + 1]); })->syncLdap((int) $driftProvider->id, (int) $application->id, 't04-ldap-drift'), 'SAND_IAM_DIRECTORY_SYNC_FAILED');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $driftProvider->id)->count() === 0, 'LDAP version drift left a JIT binding');
t04Assert(Identity::where('application_id', (int) $application->id)->where('code', 'drift')->count() === 0, 'LDAP version drift left an orphan identity');
t04Assert(DirectorySyncRun::where('identity_provider_id', (int) $driftProvider->id)->value('state') === 'failed', 'LDAP version drift did not mark the run failed');

// A lifecycle stop during the external read is also checked inside the final
// locked boundary. Mount/application/provider status is not inferred from a
// stale pre-read object, so no identity or session may be created.
$stoppedProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-ldap-stopped', 'ldap', $ldapConfig, $mapping);
t04Expect(static fn () => t04LdapService([['uid' => 'stopped', 'cn' => 'Stopped', 'enabled' => 'true']], true, static function () use ($stoppedProvider, $application): void { IdentityProviderApplication::where('identity_provider_id', (int) $stoppedProvider->id)->where('application_id', (int) $application->id)->update(['status' => 2]); })->syncLdap((int) $stoppedProvider->id, (int) $application->id, 't04-ldap-stopped'), 'SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $stoppedProvider->id)->count() === 0 && Identity::where('application_id', (int) $application->id)->where('code', 'stopped')->count() === 0 && DirectorySyncRun::where('identity_provider_id', (int) $stoppedProvider->id)->value('state') === 'failed', 'mount disable during LDAP read created state or marked the run successful');

// OIDC/OAuth/SAML each use an interleaved real PostgreSQL provider update after
// external verification and before their final short transaction. The final
// provider lock must reject the stale transaction before JIT persistence.
$cipher = new FederationConfigCipher();
$browser = 't04-browser-binding';
$nonce = 't04-oidc-nonce';
$oidcProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-oidc', 'oidc', ['issuer' => 'https://issuer.example.test', 'authorization_endpoint' => 'https://issuer.example.test/authorize', 'token_endpoint' => 'https://issuer.example.test/token', 'jwks_uri' => 'https://issuer.example.test/jwks', 'client_id' => 't04-oidc-client', 'redirect_uri' => 'https://app.example.test/oidc', 'handoff_return_uris' => ['https://client.example.test/federation/complete']], $mapping);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false || !openssl_pkey_export($key, $privateKey)) t04Fail('could not create OIDC test key');
$detail = openssl_pkey_get_details($key);
if (!is_array($detail) || !isset($detail['rsa']['n'], $detail['rsa']['e'])) t04Fail('OIDC test key details unavailable');
$header = t04B64(json_encode(['alg' => 'RS256', 'kid' => 't04-kid'], JSON_THROW_ON_ERROR));
$claims = t04B64(json_encode(['iss' => 'https://issuer.example.test', 'sub' => 't04-oidc-subject', 'aud' => 't04-oidc-client', 'exp' => time() + 300, 'iat' => time(), 'nonce' => $nonce], JSON_THROW_ON_ERROR));
openssl_sign($header . '.' . $claims, $signature, $privateKey, OPENSSL_ALGO_SHA256);
$idToken = $header . '.' . $claims . '.' . t04B64($signature);
$oidcState = 'fi_' . str_repeat('a', 48);
FederationTransaction::create(['application_id' => (int) $application->id, 'identity_provider_id' => (int) $oidcProvider->id, 'provider_config_version' => (int) $oidcProvider->config_version, 'protocol' => 'oidc', 'purpose' => 'login', 'state_hash' => t04Hash('state:' . $oidcState), 'browser_binding_hash' => t04Hash('browser:' . $browser), 'nonce_hash' => t04Hash('nonce:' . $nonce), 'encrypted_pkce_verifier' => $cipher->encrypt(['verifier' => str_repeat('v', 43)]), 'redirect_uri' => 'https://app.example.test/oidc', 'expire_time' => date('Y-m-d H:i:s', time() + 300), 'status' => 1]);
$oidcHttp = new T04Http(['id_token' => $idToken], [], static function () use ($oidcProvider): void { IdentityProvider::where('id', (int) $oidcProvider->id)->update(['config_version' => (int) $oidcProvider->config_version + 1]); }, [['kty' => 'RSA', 'kid' => 't04-kid', 'use' => 'sig', 'alg' => 'RS256', 'n' => t04B64($detail['rsa']['n']), 'e' => t04B64($detail['rsa']['e'])]]);
t04Expect(static fn () => (new FederationService(http: $oidcHttp))->completeOidc($oidcState, 't04-code', $browser, '127.0.0.41', 't04-agent', 't04-oidc'), 'SAND_IAM_FEDERATION_STATE_INVALID');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $oidcProvider->id)->count() === 0, 'OIDC stale final transaction persisted a binding');

$oauthProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-oauth', 'oauth2', ['authorization_endpoint' => 'https://oauth.example.test/authorize', 'token_endpoint' => 'https://oauth.example.test/token', 'userinfo_endpoint' => 'https://oauth.example.test/userinfo', 'client_id' => 't04-oauth-client', 'redirect_uri' => 'https://app.example.test/oauth', 'handoff_return_uris' => ['https://client.example.test/federation/complete']], $mapping);
$oauthState = 'fo_' . str_repeat('b', 48);
FederationTransaction::create(['application_id' => (int) $application->id, 'identity_provider_id' => (int) $oauthProvider->id, 'provider_config_version' => (int) $oauthProvider->config_version, 'protocol' => 'oauth2', 'purpose' => 'login', 'state_hash' => t04Hash('oauth2-state:' . $oauthState), 'browser_binding_hash' => t04Hash('browser:' . $browser), 'encrypted_pkce_verifier' => $cipher->encrypt(['verifier' => str_repeat('w', 43)]), 'redirect_uri' => 'https://app.example.test/oauth', 'expire_time' => date('Y-m-d H:i:s', time() + 300), 'status' => 1]);
$oauthHttp = new T04Http(['access_token' => 't04-oauth-access'], ['uid' => 't04-oauth-subject', 'cn' => 'OAuth User'], static function () use ($oauthProvider): void { IdentityProvider::where('id', (int) $oauthProvider->id)->update(['config_version' => (int) $oauthProvider->config_version + 1]); });
t04Expect(static fn () => (new FederationService(http: $oauthHttp))->completeOauth2($oauthState, 't04-code', $browser, '127.0.0.42', 't04-agent', 't04-oauth'), 'SAND_IAM_FEDERATION_STATE_INVALID');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $oauthProvider->id)->count() === 0, 'OAuth stale final transaction persisted a binding');

$samlProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-saml', 'saml', ['entity_id' => 'https://sp.example.test', 'sso_url' => 'https://idp.example.test/sso', 'acs_url' => 'https://app.example.test/saml', 'idp_x509cert' => 't04-idp-cert', 'sp_x509cert' => 't04-sp-cert', 'sp_private_key' => 't04-private-key', 'handoff_return_uris' => ['https://client.example.test/federation/complete']], $mapping);
$samlReplayProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-saml-replay', 'saml', ['entity_id' => 'https://sp.example.test', 'sso_url' => 'https://idp.example.test/sso', 'acs_url' => 'https://app.example.test/saml', 'idp_x509cert' => 't04-idp-cert', 'sp_x509cert' => 't04-sp-cert', 'sp_private_key' => 't04-private-key', 'handoff_return_uris' => ['https://client.example.test/federation/complete']], $mapping);
$samlReplayService = new FederationService(saml: new T04Saml());
$samlReplayChallenge = str_repeat('r', 43);
$samlReplayState = 'fs_' . str_repeat('d', 48);
FederationTransaction::create(['application_id' => (int) $application->id, 'identity_provider_id' => (int) $samlReplayProvider->id, 'provider_config_version' => (int) $samlReplayProvider->config_version, 'protocol' => 'saml', 'purpose' => 'login', 'state_hash' => t04Hash('saml-relay:' . $samlReplayState), 'browser_binding_hash' => t04Hash('browser:' . $browser), 'saml_request_id' => 't04-saml-replay-request-1', 'redirect_uri' => 'https://app.example.test/saml', 'handoff_return_uri' => 'https://client.example.test/federation/complete', 'handoff_state' => 't04-saml-replay-state-1', 'handoff_code_challenge' => $samlReplayChallenge, 'expire_time' => date('Y-m-d H:i:s', time() + 300), 'status' => 1]);
$samlReplayResult = $samlReplayService->completeSaml($samlReplayState, 't04-saml-replay-response-1', $browser, '127.0.0.43', 't04-agent', 't04-saml-replay-1');
t04Assert(isset($samlReplayResult['code']) && FederationTransaction::where('identity_provider_id', (int) $samlReplayProvider->id)->value('assertion_hash') === t04Hash('saml-assertion:t04-saml-assertion'), 'SAML assertion acceptance did not persist its replay marker');
$samlReplayState = 'fs_' . str_repeat('e', 48);
$samlReplayTransaction = FederationTransaction::create(['application_id' => (int) $application->id, 'identity_provider_id' => (int) $samlReplayProvider->id, 'provider_config_version' => (int) $samlReplayProvider->config_version, 'protocol' => 'saml', 'purpose' => 'login', 'state_hash' => t04Hash('saml-relay:' . $samlReplayState), 'browser_binding_hash' => t04Hash('browser:' . $browser), 'saml_request_id' => 't04-saml-replay-request-2', 'redirect_uri' => 'https://app.example.test/saml', 'handoff_return_uri' => 'https://client.example.test/federation/complete', 'handoff_state' => 't04-saml-replay-state-2', 'handoff_code_challenge' => $samlReplayChallenge, 'expire_time' => date('Y-m-d H:i:s', time() + 300), 'status' => 1]);
t04Expect(static fn () => $samlReplayService->completeSaml($samlReplayState, 't04-saml-replay-response-2', $browser, '127.0.0.43', 't04-agent', 't04-saml-replay-2'), 'SAND_IAM_SAML_ASSERTION_REPLAYED');
$samlReplayTransaction = FederationTransaction::find((int) $samlReplayTransaction->id);
t04Assert($samlReplayTransaction !== null && (int) $samlReplayTransaction->status === 1 && $samlReplayTransaction->assertion_hash === null, 'replayed SAML assertion consumed or marked the second transaction');
$samlState = 'fs_' . str_repeat('c', 48);
FederationTransaction::create(['application_id' => (int) $application->id, 'identity_provider_id' => (int) $samlProvider->id, 'provider_config_version' => (int) $samlProvider->config_version, 'protocol' => 'saml', 'purpose' => 'login', 'state_hash' => t04Hash('saml-relay:' . $samlState), 'browser_binding_hash' => t04Hash('browser:' . $browser), 'saml_request_id' => 't04-saml-request', 'redirect_uri' => 'https://app.example.test/saml', 'expire_time' => date('Y-m-d H:i:s', time() + 300), 'status' => 1]);
t04Expect(static fn () => (new FederationService(saml: new T04Saml(static function () use ($samlProvider): void { IdentityProvider::where('id', (int) $samlProvider->id)->update(['config_version' => (int) $samlProvider->config_version + 1]); })))->completeSaml($samlState, 't04-saml-response', $browser, '127.0.0.43', 't04-agent', 't04-saml'), 'SAND_IAM_FEDERATION_STATE_INVALID');
t04Assert(IdentityBinding::where('identity_provider_id', (int) $samlProvider->id)->count() === 0, 'SAML stale final transaction persisted a binding');

// Handoff codes are HMAC-only, PKCE-bound, application/provider-bound and
// consumed under a row lock before a local session can be issued.
$handoffBinding = IdentityBinding::where('identity_provider_id', (int) $provider->id)->where('source_state', 'active')->find();
t04Assert($handoffBinding !== null, 'handoff fixture binding is missing');
$provider->save(['public_code' => 't04-handoff-provider-public-code']);
$provider = IdentityProvider::find((int) $provider->id);
t04Assert($provider !== null, 'handoff provider disappeared');
$handoffCode = 'fh_' . str_repeat('d', 64);
$handoffVerifier = str_repeat('v', 43);
FederationHandoff::create(['organization_id' => (int) $organization->id, 'application_id' => (int) $application->id, 'identity_provider_id' => (int) $provider->id, 'identity_binding_id' => (int) $handoffBinding->id, 'provider_config_version' => (int) $provider->config_version, 'code_hash' => t04Hash('handoff:' . $handoffCode), 'return_uri' => 'https://client.example.test/federation/complete', 'code_challenge' => t04B64(hash('sha256', $handoffVerifier, true)), 'expire_time' => date('Y-m-d H:i:s', time() + 120), 'status' => 1]);
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $handoffCode, 'https://evil.example.test/callback', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-uri'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, 'other-app', $handoffCode, 'https://client.example.test/federation/complete', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-app'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $handoffCode, 'https://client.example.test/federation/complete', str_repeat('x', 43), '127.0.0.51', 't04-agent', 't04-handoff-verifier'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
$handoffResult = (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $handoffCode, 'https://client.example.test/federation/complete', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-success');
t04Assert(isset($handoffResult['access_token'], $handoffResult['refresh_token']) && !str_contains(json_encode(FederationHandoff::where('code_hash', t04Hash('handoff:' . $handoffCode))->find()?->toArray(), JSON_THROW_ON_ERROR), $handoffCode), 'handoff did not issue a local session or persisted its plaintext code');
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $handoffCode, 'https://client.example.test/federation/complete', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-replay'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
$expiredCode = 'fh_' . str_repeat('e', 64);
FederationHandoff::create(['organization_id' => (int) $organization->id, 'application_id' => (int) $application->id, 'identity_provider_id' => (int) $provider->id, 'identity_binding_id' => (int) $handoffBinding->id, 'provider_config_version' => (int) $provider->config_version, 'code_hash' => t04Hash('handoff:' . $expiredCode), 'return_uri' => 'https://client.example.test/federation/complete', 'code_challenge' => t04B64(hash('sha256', $handoffVerifier, true)), 'expire_time' => date('Y-m-d H:i:s', time() - 1), 'status' => 1]);
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $expiredCode, 'https://client.example.test/federation/complete', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-expired'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
$providerDriftCode = 'fh_' . str_repeat('b', 64);
FederationHandoff::create(['organization_id' => (int) $organization->id, 'application_id' => (int) $application->id, 'identity_provider_id' => (int) $provider->id, 'identity_binding_id' => (int) $handoffBinding->id, 'provider_config_version' => (int) $provider->config_version, 'code_hash' => t04Hash('handoff:' . $providerDriftCode), 'return_uri' => 'https://client.example.test/federation/complete', 'code_challenge' => t04B64(hash('sha256', $handoffVerifier, true)), 'expire_time' => date('Y-m-d H:i:s', time() + 120), 'status' => 1]);
$provider->save(['config_version' => (int) $provider->config_version + 1]);
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $providerDriftCode, 'https://client.example.test/federation/complete', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-provider-drift'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
$provider = IdentityProvider::find((int) $provider->id);
t04Assert($provider !== null, 'handoff provider disappeared after version drift');
$mountDriftCode = 'fh_' . str_repeat('c', 64);
FederationHandoff::create(['organization_id' => (int) $organization->id, 'application_id' => (int) $application->id, 'identity_provider_id' => (int) $provider->id, 'identity_binding_id' => (int) $handoffBinding->id, 'provider_config_version' => (int) $provider->config_version, 'code_hash' => t04Hash('handoff:' . $mountDriftCode), 'return_uri' => 'https://client.example.test/federation/complete', 'code_challenge' => t04B64(hash('sha256', $handoffVerifier, true)), 'expire_time' => date('Y-m-d H:i:s', time() + 120), 'status' => 1]);
$handoffMount = IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $application->id)->find();
t04Assert($handoffMount !== null, 'handoff provider mount is missing');
$handoffMount->save(['status' => 2]);
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $mountDriftCode, 'https://client.example.test/federation/complete', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-mount-drift'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
$handoffMount->save(['status' => 1]);
t04Assert(FederationHandoff::where('code_hash', t04Hash('handoff:' . $handoffCode))->value('consumed_time') !== null, 'handoff success was not consumed');
$disabledCode = 'fh_' . str_repeat('f', 64);
FederationHandoff::create(['organization_id' => (int) $organization->id, 'application_id' => (int) $application->id, 'identity_provider_id' => (int) $provider->id, 'identity_binding_id' => (int) $handoffBinding->id, 'provider_config_version' => (int) $provider->config_version, 'code_hash' => t04Hash('handoff:' . $disabledCode), 'return_uri' => 'https://client.example.test/federation/complete', 'code_challenge' => t04B64(hash('sha256', $handoffVerifier, true)), 'expire_time' => date('Y-m-d H:i:s', time() + 120), 'status' => 1]);
$handoffBinding->save(['source_state' => 'disabled']);
t04Expect(static fn () => (new FederationService())->exchangeHandoff((string) $provider->public_code, (string) $application->code, $disabledCode, 'https://client.example.test/federation/complete', $handoffVerifier, '127.0.0.51', 't04-agent', 't04-handoff-binding'), 'SAND_IAM_FEDERATION_HANDOFF_INVALID');
t04Assert(FederationHandoff::where('code_hash', t04Hash('handoff:' . $disabledCode))->value('consumed_time') === null, 'rejected disabled binding consumed the handoff');
t04Assert(AuditLog::where('action', 'identity_provider.handoff_exchange')->where('outcome', 'failed')->count() >= 4, 'handoff rejections were not audited');

// A federated unlink needs the session's short step-up proof, revokes sessions
// bound to the removed source, and cannot remove the final login method.
$unlinkIdentity = Identity::create(['application_id' => (int) $application->id, 'code' => 't04-unlink', 'display_name' => 'T04 unlink', 'status' => 1]);
$unlinkTarget = IdentityBinding::create(['application_id' => (int) $application->id, 'identity_id' => (int) $unlinkIdentity->id, 'identity_provider_id' => (int) $provider->id, 'subject' => 't04-unlink-target', 'source_state' => 'active', 'status' => 1]);
IdentityBinding::create(['application_id' => (int) $application->id, 'identity_id' => (int) $unlinkIdentity->id, 'identity_provider_id' => (int) $provider->id, 'subject' => 't04-unlink-alternative', 'source_state' => 'active', 'status' => 1]);
$unlinkToken = 't04-unlink-access';
$unlinkSession = t04Session(['application_id' => (int) $application->id, 'identity_id' => (int) $unlinkIdentity->id, 'identity_binding_id' => (int) $unlinkTarget->id, 'auth_method' => 'federation', 'access_token_hash' => t04Hash('token:' . $unlinkToken), 'refresh_token_hash' => t04Hash('token:t04-unlink-refresh'), 'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'), 'access_expire_time' => date('Y-m-d H:i:s', time() + 900), 'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400), 'step_up_time' => date('Y-m-d H:i:s'), 'step_up_method' => 'mfa', 'status' => 1]);
(new FederationService())->unlink($unlinkToken, (int) $unlinkTarget->id, 't04-unlink-success');
t04Assert((int) IdentityBinding::find((int) $unlinkTarget->id)?->status === 2 && (int) AuthSession::find((int) $unlinkSession->id)?->status === 2, 'unlink did not disable the binding and revoke its session');
$lastIdentity = Identity::create(['application_id' => (int) $application->id, 'code' => 't04-last', 'display_name' => 'T04 last', 'status' => 1]);
$lastBinding = IdentityBinding::create(['application_id' => (int) $application->id, 'identity_id' => (int) $lastIdentity->id, 'identity_provider_id' => (int) $provider->id, 'subject' => 't04-last-binding', 'source_state' => 'active', 'status' => 1]);
$lastToken = 't04-last-access';
t04Session(['application_id' => (int) $application->id, 'identity_id' => (int) $lastIdentity->id, 'identity_binding_id' => (int) $lastBinding->id, 'auth_method' => 'federation', 'access_token_hash' => t04Hash('token:' . $lastToken), 'refresh_token_hash' => t04Hash('token:t04-last-refresh'), 'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'), 'access_expire_time' => date('Y-m-d H:i:s', time() + 900), 'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400), 'step_up_time' => date('Y-m-d H:i:s'), 'step_up_method' => 'password', 'status' => 1]);
t04Expect(static fn () => (new FederationService())->unlink($lastToken, (int) $lastBinding->id, 't04-unlink-last'), 'SAND_IAM_FEDERATION_LAST_LOGIN_METHOD');

// Browser linking is tied to the current application session and a recent
// step-up; it never infers the target identity from external claims.
$oauthProvider = IdentityProvider::find((int) $oauthProvider->id);
t04Assert($oauthProvider !== null, 'link provider disappeared');
$oauthProvider->save(['public_code' => 't04-link-oauth-provider-public-code']);
$oauthProvider = IdentityProvider::find((int) $oauthProvider->id);
$linkToken = 't04-link-access';
t04Session(['application_id' => (int) $application->id, 'identity_id' => (int) $lastIdentity->id, 'identity_binding_id' => (int) $lastBinding->id, 'auth_method' => 'federation', 'access_token_hash' => t04Hash('token:' . $linkToken), 'refresh_token_hash' => t04Hash('token:t04-link-refresh'), 'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'), 'access_expire_time' => date('Y-m-d H:i:s', time() + 900), 'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400), 'step_up_time' => date('Y-m-d H:i:s'), 'step_up_method' => 'mfa', 'status' => 1]);
$noStepToken = 't04-link-no-step';
t04Session(['application_id' => (int) $application->id, 'identity_id' => (int) $lastIdentity->id, 'identity_binding_id' => (int) $lastBinding->id, 'auth_method' => 'federation', 'access_token_hash' => t04Hash('token:' . $noStepToken), 'refresh_token_hash' => t04Hash('token:t04-link-no-step-refresh'), 'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'), 'access_expire_time' => date('Y-m-d H:i:s', time() + 900), 'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400), 'status' => 1]);
t04Expect(static fn () => (new FederationService())->startOauth2((string) $oauthProvider->public_code, (string) $application->code, 'https://client.example.test/federation/complete', 't04-link-state', t04B64(hash('sha256', str_repeat('q', 43), true)), 't04-link-browser', 't04-link-no-step', 'link', $noStepToken), 'SAND_IAM_FEDERATION_STEP_UP_REQUIRED');
$linkStart = (new FederationService())->startOauth2((string) $oauthProvider->public_code, (string) $application->code, 'https://client.example.test/federation/complete', 't04-link-state', t04B64(hash('sha256', str_repeat('q', 43), true)), 't04-link-browser', 't04-link-start', 'link', $linkToken);
$linkTransaction = FederationTransaction::where('state_hash', t04Hash('oauth2-state:' . $linkStart['state']))->find();
t04Assert($linkTransaction !== null && $linkTransaction->purpose === 'link' && (int) $linkTransaction->link_identity_id === (int) $lastIdentity->id && $linkTransaction->link_session_id !== null, 'link start did not bind the current stepped-up session and identity');
$linkResult = (new FederationService(http: new T04Http(['access_token' => 't04-link-upstream-token'], ['uid' => 't04-link-subject', 'cn' => 'Linked User'])))->completeOauth2($linkStart['state'], 't04-link-code', 't04-link-browser', '127.0.0.52', 't04-agent', 't04-link-callback');
t04Assert(isset($linkResult['code'], $linkResult['return_uri']) && !isset($linkResult['access_token'], $linkResult['refresh_token']) && (int) IdentityBinding::where('identity_provider_id', (int) $oauthProvider->id)->where('subject', 't04-link-subject')->value('identity_id') === (int) $lastIdentity->id, 'callback did not atomically link only the stepped-up identity or leaked a session token');

// The callback repeats the live-session assertion after all external I/O.  An
// expired or revoked session cannot use a transaction that was started while it
// was valid, and an occupied provider subject cannot be linked to another ID.
$linkGuardIdentity = Identity::create(['application_id' => (int) $application->id, 'code' => 't04-link-guard', 'display_name' => 'T04 link guard', 'status' => 1]);
$linkGuardBinding = IdentityBinding::create(['application_id' => (int) $application->id, 'identity_id' => (int) $linkGuardIdentity->id, 'identity_provider_id' => (int) $provider->id, 'subject' => 't04-link-guard', 'source_state' => 'active', 'status' => 1]);
$expiredLinkToken = 't04-link-expired';
$expiredLinkSession = t04Session(['application_id' => (int) $application->id, 'identity_id' => (int) $linkGuardIdentity->id, 'identity_binding_id' => (int) $linkGuardBinding->id, 'auth_method' => 'federation', 'access_token_hash' => t04Hash('token:' . $expiredLinkToken), 'refresh_token_hash' => t04Hash('token:t04-link-expired-refresh'), 'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'), 'access_expire_time' => date('Y-m-d H:i:s', time() + 900), 'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400), 'step_up_time' => date('Y-m-d H:i:s'), 'step_up_method' => 'mfa', 'status' => 1]);
$expiredLinkStart = (new FederationService())->startOauth2((string) $oauthProvider->public_code, (string) $application->code, 'https://client.example.test/federation/complete', 't04-link-expired-state', t04B64(hash('sha256', str_repeat('e', 43), true)), 't04-link-expired-browser', 't04-link-expired-start', 'link', $expiredLinkToken);
$expiredLinkSession->save(['access_expire_time' => date('Y-m-d H:i:s', time() - 1)]);
t04Expect(static fn () => (new FederationService(http: new T04Http(['access_token' => 't04-link-expired-upstream'], ['uid' => 't04-link-expired-subject', 'cn' => 'Expired link'])))->completeOauth2($expiredLinkStart['state'], 't04-link-expired-code', 't04-link-expired-browser', '127.0.0.53', 't04-agent', 't04-link-expired-callback'), 'SAND_IAM_FEDERATION_STEP_UP_REQUIRED');
$revokedLinkToken = 't04-link-revoked';
$revokedLinkSession = t04Session(['application_id' => (int) $application->id, 'identity_id' => (int) $linkGuardIdentity->id, 'identity_binding_id' => (int) $linkGuardBinding->id, 'auth_method' => 'federation', 'access_token_hash' => t04Hash('token:' . $revokedLinkToken), 'refresh_token_hash' => t04Hash('token:t04-link-revoked-refresh'), 'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'), 'access_expire_time' => date('Y-m-d H:i:s', time() + 900), 'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400), 'step_up_time' => date('Y-m-d H:i:s'), 'step_up_method' => 'mfa', 'status' => 1]);
$revokedLinkStart = (new FederationService())->startOauth2((string) $oauthProvider->public_code, (string) $application->code, 'https://client.example.test/federation/complete', 't04-link-revoked-state', t04B64(hash('sha256', str_repeat('r', 43), true)), 't04-link-revoked-browser', 't04-link-revoked-start', 'link', $revokedLinkToken);
$revokedLinkSession->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
t04Expect(static fn () => (new FederationService(http: new T04Http(['access_token' => 't04-link-revoked-upstream'], ['uid' => 't04-link-revoked-subject', 'cn' => 'Revoked link'])))->completeOauth2($revokedLinkStart['state'], 't04-link-revoked-code', 't04-link-revoked-browser', '127.0.0.54', 't04-agent', 't04-link-revoked-callback'), 'SAND_IAM_FEDERATION_STEP_UP_REQUIRED');
$conflictLinkToken = 't04-link-conflict';
t04Session(['application_id' => (int) $application->id, 'identity_id' => (int) $linkGuardIdentity->id, 'identity_binding_id' => (int) $linkGuardBinding->id, 'auth_method' => 'federation', 'access_token_hash' => t04Hash('token:' . $conflictLinkToken), 'refresh_token_hash' => t04Hash('token:t04-link-conflict-refresh'), 'pepper_version' => (string) getenv('SAND_IAM_AUTH_PEPPER_VERSION'), 'access_expire_time' => date('Y-m-d H:i:s', time() + 900), 'refresh_expire_time' => date('Y-m-d H:i:s', time() + 86400), 'step_up_time' => date('Y-m-d H:i:s'), 'step_up_method' => 'mfa', 'status' => 1]);
$conflictLinkStart = (new FederationService())->startOauth2((string) $oauthProvider->public_code, (string) $application->code, 'https://client.example.test/federation/complete', 't04-link-conflict-state', t04B64(hash('sha256', str_repeat('c', 43), true)), 't04-link-conflict-browser', 't04-link-conflict-start', 'link', $conflictLinkToken);
t04Expect(static fn () => (new FederationService(http: new T04Http(['access_token' => 't04-link-conflict-upstream'], ['uid' => 't04-link-subject', 'cn' => 'Conflict link'])))->completeOauth2($conflictLinkStart['state'], 't04-link-conflict-code', 't04-link-conflict-browser', '127.0.0.55', 't04-agent', 't04-link-conflict-callback'), 'SAND_IAM_FEDERATION_LINK_CONFLICT');

// This invokes the actual controller response path: browser callbacks only
// redirect with the one-time handoff code and state, never a local token body.
$redirect = (new \ReflectionMethod(FederationController::class, 'handoffRedirect'))->invoke(new FederationController(), ['return_uri' => 'https://client.example.test/federation/complete', 'code' => 'fh_controller', 'state' => 't04-controller-state']);
$location = (string) $redirect->getHeader('Location');
t04Assert($redirect->getStatusCode() === 303 && $redirect->rawBody() === '' && !str_contains($location, 'access_token') && !str_contains($location, 'refresh_token') && str_contains($location, 'code=fh_controller'), 'browser callback controller did not return a token-free 303 handoff redirect');

// SCIM group membership is application-scoped and uses status transitions,
// not delete/reinsert, so a scoped unique key remains stable on remove/add.
$scimProvider = t04Provider((int) $organization->id, (int) $application->id, 't04-scim', 'scim', [], $mapping);
$scim = new ScimService();
$token = $scim->issueToken((int) $scimProvider->id, (int) $application->id, 't04-default-expiry', 't04-scim-token');
t04Assert(ScimToken::find((int) $token['id'])?->expire_time !== null, 'SCIM default token expiry is null');
$sourceExtension = 'urn:sand:params:scim:schemas:extension:source:1.0';
$scimUserA = $scim->createUser($scimProvider, (int) $application->id, ['externalId' => 't04-scim-a', $sourceExtension => ['sourceKey' => 't04-source-a'], 'userName' => 't04.scim.a', 'displayName' => 'T04 SCIM A', 'active' => true], 't04-scim-a');
$scimUserB = $scim->createUser($scimProvider, (int) $application->id, ['externalId' => 't04-scim-b', $sourceExtension => ['sourceKey' => 't04-source-b'], 'userName' => 't04.scim.b', 'displayName' => 'T04 SCIM B', 'active' => true], 't04-scim-b');
t04Expect(static fn () => $scim->createUser($scimProvider, (int) $application->id, ['externalId' => 't04-scim-duplicate', $sourceExtension => ['sourceKey' => 't04-source-duplicate'], 'userName' => 'T04.SCIM.A', 'displayName' => 'T04 duplicate', 'active' => true], 't04-scim-duplicate'), 'SAND_IAM_SCIM_CONFLICT');
t04Expect(static fn () => $scim->patchUser($scimProvider, (int) $application->id, (string) $scimUserB['id'], ['Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 'T04.SCIM.A']]], (string) $scimUserB['meta']['version'], 't04-scim-duplicate-patch'), 'SAND_IAM_SCIM_CONFLICT');
$scimUserBRecord = ScimResource::where('scim_id', (string) $scimUserB['id'])->find();
$legacyAttributes = $scimUserBRecord?->source_attributes;
if (is_string($legacyAttributes)) $legacyAttributes = json_decode($legacyAttributes, true);
if (!is_array($legacyAttributes) || $scimUserBRecord === null) throw new RuntimeException('SCIM user B source attributes unavailable');
$legacyAttributes['userName'] = 'T04.SCIM.A';
$scimUserBRecord->save(['source_attributes' => $legacyAttributes]);
$legacyDisabled = $scim->patchUser($scimProvider, (int) $application->id, (string) $scimUserB['id'], ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]], (string) $scimUserB['meta']['version'], 't04-scim-legacy-duplicate-disable');
t04Assert($legacyDisabled['active'] === false, 'SCIM legacy duplicate userName blocked source deactivation');
$scimUserB = $scim->patchUser($scimProvider, (int) $application->id, (string) $scimUserB['id'], ['Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 't04.scim.b'], ['op' => 'replace', 'path' => 'active', 'value' => true]]], (string) $legacyDisabled['meta']['version'], 't04-scim-legacy-duplicate-restore');
$scimGroup = $scim->createGroup($scimProvider, (int) $application->id, ['externalId' => 't04-scim-group', $sourceExtension => ['sourceKey' => 't04-source-group'], 'displayName' => 'T04 SCIM Group', 'members' => [['value' => $scimUserA['id']]]], 't04-scim-group');
$groupId = (int) \plugin\SandIam\app\model\ScimGroup::where('scim_id', (string) $scimGroup['id'])->value('id');
$scimGroup = $scim->patchGroup($scimProvider, (int) $application->id, (string) $scimGroup['id'], ['Operations' => [['op' => 'replace', 'value' => ['externalId' => 't04-scim-group-editable', 'displayName' => 'T04 SCIM Group Updated', 'members' => ['value' => $scimUserA['id']]]]]], (string) $scimGroup['meta']['version'], 't04-scim-pathless-single');
t04Assert(($scimGroup['externalId'] ?? null) === 't04-scim-group-editable' && count($scimGroup['members']) === 1, 'SCIM pathless group PATCH did not update display attributes and a single member object');
$version = (string) $scimGroup['meta']['version'];
$scimGroup = $scim->replaceGroup($scimProvider, (int) $application->id, (string) $scimGroup['id'], ['externalId' => 't04-scim-group', 'displayName' => 'T04 SCIM Group', 'members' => [['value' => $scimUserA['id']]]], $version, 't04-scim-nochange');
t04Assert(ScimGroupMember::where('group_id', $groupId)->where('application_id', (int) $application->id)->count() === 1 && ScimGroupMember::where('group_id', $groupId)->where('application_id', (int) $application->id)->where('status', 1)->count() === 1, 'SCIM unchanged group replacement duplicated an application-scoped member');
$version = (string) $scimGroup['meta']['version'];
$scimGroup = $scim->patchGroup($scimProvider, (int) $application->id, (string) $scimGroup['id'], ['Operations' => [['op' => 'add', 'value' => ['members' => [['value' => $scimUserB['id']]]]]]], $version, 't04-scim-pathless-array');
t04Assert(count($scimGroup['members']) === 2, 'SCIM pathless group PATCH did not add a member array');
$version = (string) $scimGroup['meta']['version'];
$scimGroup = $scim->replaceGroup($scimProvider, (int) $application->id, (string) $scimGroup['id'], ['externalId' => 't04-scim-group', 'displayName' => 'T04 SCIM Group', 'members' => [['value' => $scimUserA['id']], ['value' => $scimUserB['id']]]], $version, 't04-scim-add');
t04Assert(ScimGroupMember::where('group_id', $groupId)->where('application_id', (int) $application->id)->where('status', 1)->count() === 2, 'SCIM group add did not preserve application-scoped active members');
$scimGroup = $scim->replaceGroup($scimProvider, (int) $application->id, (string) $scimGroup['id'], ['externalId' => 't04-scim-group', 'displayName' => 'T04 SCIM Group', 'members' => [['value' => $scimUserB['id']]]], (string) $scimGroup['meta']['version'], 't04-scim-remove');
$removedMember = ScimGroupMember::withTrashed()->where('group_id', $groupId)->where('application_id', (int) $application->id)->where('status', 2)->find();
t04Assert($removedMember !== null && $removedMember->delete_time !== null, 'SCIM group remove did not soft-delete the member row');
$removedMemberId = (int) $removedMember->id;
$scimGroup = $scim->replaceGroup($scimProvider, (int) $application->id, (string) $scimGroup['id'], ['externalId' => 't04-scim-group', 'displayName' => 'T04 SCIM Group', 'members' => [['value' => $scimUserA['id']], ['value' => $scimUserB['id']]]], (string) $scimGroup['meta']['version'], 't04-scim-restore');
$restoredMember = ScimGroupMember::withTrashed()->where('id', $removedMemberId)->find();
t04Assert($restoredMember !== null && $restoredMember->delete_time === null && ScimGroupMember::withTrashed()->where('group_id', $groupId)->where('application_id', (int) $application->id)->count() === 2 && ScimGroupMember::where('group_id', $groupId)->where('application_id', (int) $application->id)->where('status', 1)->count() === 2, 'SCIM group restore did not clear delete_time on the original member row without duplication');
$otherApplication = Application::create(['organization_id' => (int) $organization->id, 'code' => 't04-scim-other', 'name' => 'T04 SCIM other application', 'status' => 1]);
t04Expect(static fn () => $scim->createGroup($scimProvider, (int) $otherApplication->id, ['externalId' => 't04-cross-app', $sourceExtension => ['sourceKey' => 't04-cross-source'], 'displayName' => 'T04 Cross application', 'members' => []], 't04-scim-cross-app'), 'SAND_IAM_FEDERATION_PROVIDER_UNAVAILABLE');

echo "federation A/B PostgreSQL integration passed\n";
