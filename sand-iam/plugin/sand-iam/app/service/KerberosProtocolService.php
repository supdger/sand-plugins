<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\kerberos\SpnegoVerifier;
use plugin\SandIam\app\kerberos\UnavailableSpnegoVerifier;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\Identity;
use plugin\SandIam\app\model\IdentityBinding;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\sandadmin\exception\ApiException;

final class KerberosProtocolService
{
    private readonly SpnegoVerifier $verifier;

    public function __construct(?SpnegoVerifier $verifier = null)
    {
        $this->verifier = $verifier ?? $this->configuredVerifier();
    }

    /** @return array{tokens:array<string,mixed>,response_token:?string} */
    public function negotiate(string $providerCode, string $applicationCode, string $authorization, string $channelBinding, string $ip, string $userAgent, string $requestId): array
    {
        $this->enabled();
        [$provider, $application] = $this->provider($providerCode, $applicationCode);
        if (strncasecmp($authorization, 'Negotiate ', 10) !== 0) throw new ApiException('SAND_IAM_KERBEROS_CHALLENGE_REQUIRED', 401);
        $token = trim(substr($authorization, 10));
        if ($token === '' || strlen($token) > 65536 || base64_decode($token, true) === false) throw new ApiException('SAND_IAM_KERBEROS_TOKEN_INVALID', 401);
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $token)) throw new ApiException('SAND_IAM_KERBEROS_TOKEN_INVALID', 401);
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $channelBinding)) throw new ApiException('SAND_IAM_KERBEROS_CHANNEL_BINDING_REQUIRED', 401);

        $config = (new FederationConfigCipher())->decrypt($provider->encrypted_config);
        $result = $this->verifier->verify($token, $config, ['channel_binding' => $channelBinding, 'remote_ip' => $ip]);
        $principal = $this->principal((string) ($result['principal'] ?? ''), $config);
        if (($result['mutual_auth'] ?? false) !== true || ($result['channel_binding'] ?? false) !== true || ($result['replay_protected'] ?? false) !== true) throw new ApiException('SAND_IAM_KERBEROS_VERIFICATION_INCOMPLETE', 401);
        if (!is_string($result['service_principal'] ?? null) || !hash_equals((string) ($config['service_principal'] ?? ''), (string) $result['service_principal'])) throw new ApiException('SAND_IAM_KERBEROS_SERVICE_PRINCIPAL_MISMATCH', 401);

        $binding = IdentityBinding::where('identity_provider_id', (int) $provider->id)
            ->where('application_id', (int) $application->id)
            ->where('subject', $principal)
            ->where('source_state', 'active')
            ->where('status', 1)
            ->find();
        $identity = $binding === null ? null : Identity::where('id', (int) $binding->identity_id)->where('application_id', (int) $application->id)->where('status', 1)->find();
        if ($binding === null || $identity === null) throw new ApiException('SAND_IAM_KERBEROS_ACCOUNT_NOT_LINKED', 403);

        $tokens = (new HumanAuthService())->issueFederatedSession($application, $identity, $binding, $ip, $userAgent, $requestId);
        $responseToken = isset($result['response_token']) && is_string($result['response_token']) && preg_match('/^[A-Za-z0-9+\/=]{1,65536}$/', $result['response_token']) ? $result['response_token'] : null;
        return ['tokens' => $tokens, 'response_token' => $responseToken];
    }

    /** @param array<string,mixed> $config */
    private function principal(string $value, array $config): string
    {
        if ($value === '' || strlen($value) > 191 || trim($value) !== $value || preg_match('/[\x00-\x20\x7f]/', $value) || substr_count($value, '@') !== 1) throw new ApiException('SAND_IAM_KERBEROS_PRINCIPAL_INVALID', 401);
        [$account, $realm] = explode('@', $value, 2);
        $realm = strtoupper($realm);
        if ($account === '' || !preg_match('/^[A-Z0-9][A-Z0-9.-]{1,127}$/', $realm)) throw new ApiException('SAND_IAM_KERBEROS_PRINCIPAL_INVALID', 401);
        $allowed = $config['allowed_realms'] ?? [];
        if (!is_array($allowed) || !in_array($realm, $allowed, true)) throw new ApiException('SAND_IAM_KERBEROS_REALM_NOT_ALLOWED', 403);
        return $account . '@' . $realm;
    }

    /** @return array{0:IdentityProvider,1:Application} */
    private function provider(string $providerCode, string $applicationCode): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $providerCode) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/', $applicationCode)) throw new ApiException('SAND_IAM_KERBEROS_PROVIDER_UNAVAILABLE', 404);
        $provider = IdentityProvider::where('public_code', $providerCode)->where('provider_type', 'kerberos')->where('status', 1)->find();
        $application = $provider === null ? null : Application::where('code', $applicationCode)->where('organization_id', (int) $provider->organization_id)->where('status', 1)->find();
        $organization = $application === null ? null : Organization::where('id', (int) $application->organization_id)->where('status', 1)->find();
        $mount = $provider === null || $application === null ? null : IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', (int) $application->id)->where('status', 1)->find();
        if ($provider === null || $application === null || $organization === null || $mount === null) throw new ApiException('SAND_IAM_KERBEROS_PROVIDER_UNAVAILABLE', 404);
        return [$provider, $application];
    }

    private function configuredVerifier(): SpnegoVerifier
    {
        $class = trim((string) config('plugin.sand-iam.app.kerberos_verifier', ''));
        if ($class === '' || !class_exists($class)) return new UnavailableSpnegoVerifier();
        $verifier = new $class();
        return $verifier instanceof SpnegoVerifier ? $verifier : new UnavailableSpnegoVerifier();
    }

    private function enabled(): void { if ((int) config('plugin.sand-iam.app.kerberos_enabled', 0) !== 1) throw new ApiException('SAND_IAM_KERBEROS_DISABLED', 403); }
}
