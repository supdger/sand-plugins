<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\OAuthClient;
use plugin\SandIam\app\model\OAuthRegistrationToken;
use plugin\sandadmin\exception\ApiException;

final class OAuthDynamicRegistrationService
{
    public function __construct(private readonly AuditWriter $audit = new AuditWriter())
    {
    }

    /** @param list<string> $redirectHosts @param list<string> $scopes @return array{id:int,token:string,expire_time:string,max_uses:int} */
    public function issueToken(int $applicationId, string $name, array $redirectHosts, array $scopes, int $ttlHours, int $maxUses, string $actor, string $requestId): array
    {
        $this->enabled();
        $application = $this->application($applicationId);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 128) throw new ApiException('SAND_IAM_DCR_TOKEN_NAME_INVALID', 400);
        $redirectHosts = $this->hosts($redirectHosts);
        $scopes = $this->scopes($scopes);
        if ($ttlHours < 1 || $ttlHours > 168 || $maxUses < 1 || $maxUses > 1000) throw new ApiException('SAND_IAM_DCR_TOKEN_POLICY_INVALID', 400);
        $token = 'siam_dcr_' . rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $expireTime = date('Y-m-d H:i:s', time() + $ttlHours * 3600);

        $record = OAuthRegistrationToken::create([
            'application_id' => $applicationId,
            'name' => $name,
            'token_hash' => $this->tokenHash($token),
            'allowed_redirect_hosts' => $redirectHosts,
            'allowed_scopes' => $scopes,
            'max_uses' => $maxUses,
            'used_count' => 0,
            'expire_time' => $expireTime,
            'issued_by' => $actor,
            'status' => 1,
        ]);
        $this->audit($application, 'oauth.dynamic_registration_token.issue', (int) $record->id, $actor, $requestId, ['max_uses' => $maxUses, 'expire_time' => $expireTime]);

        return ['id' => (int) $record->id, 'token' => $token, 'expire_time' => $expireTime, 'max_uses' => $maxUses];
    }

    public function revokeToken(int $id, int $applicationId, string $actor, string $requestId): void
    {
        $this->enabled();
        $application = $this->application($applicationId);
        $token = OAuthRegistrationToken::where('id', $id)->where('application_id', $applicationId)->find();
        if ($token === null) throw new ApiException('SAND_IAM_DCR_TOKEN_NOT_FOUND', 404);
        $token->save(['status' => 2, 'revoked_time' => date('Y-m-d H:i:s')]);
        $this->audit($application, 'oauth.dynamic_registration_token.revoke', $id, $actor, $requestId);
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function register(string $initialAccessToken, array $metadata, string $ip, string $requestId): array
    {
        $this->enabled();
        if (!preg_match('/^siam_dcr_[A-Za-z0-9_-]{48}$/', $initialAccessToken)) throw new ApiException('SAND_IAM_DCR_ACCESS_DENIED', 401);
        if (count($metadata) > 32) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        $redirectUris = $this->redirectUris($metadata['redirect_uris'] ?? null, (string) ($metadata['application_type'] ?? 'web'));
        $grantTypes = $this->grantTypes($metadata['grant_types'] ?? ['authorization_code']);
        $responseTypes = $this->responseTypes($metadata['response_types'] ?? ['code'], $grantTypes);
        $authMethod = (string) ($metadata['token_endpoint_auth_method'] ?? ((string) ($metadata['application_type'] ?? 'web') === 'native' ? 'none' : 'client_secret_basic'));
        if (!in_array($authMethod, ['none', 'client_secret_basic', 'client_secret_post'], true)) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        $applicationType = (string) ($metadata['application_type'] ?? ($authMethod === 'none' ? 'native' : 'web'));
        if (!in_array($applicationType, ['web', 'native'], true) || ($applicationType === 'native' && $authMethod !== 'none') || ($applicationType === 'web' && $authMethod === 'none')) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        $name = trim((string) ($metadata['client_name'] ?? '动态注册客户端'));
        if ($name === '' || mb_strlen($name) > 128) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        $requestedScopes = $this->scopeString((string) ($metadata['scope'] ?? 'openid profile'));
        $frontchannelLogoutUri = $this->logoutUri($metadata['frontchannel_logout_uri'] ?? null, $redirectUris);
        $backchannelLogoutUri = $this->logoutUri($metadata['backchannel_logout_uri'] ?? null, $redirectUris);
        $frontchannelSessionRequired = filter_var($metadata['frontchannel_logout_session_required'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $backchannelSessionRequired = filter_var($metadata['backchannel_logout_session_required'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        $tokenRecord = OAuthRegistrationToken::where('token_hash', $this->tokenHash($initialAccessToken))->find();
        if ($tokenRecord === null) throw new ApiException('SAND_IAM_DCR_ACCESS_DENIED', 401);
        $requestId = $this->requestId($requestId);
        $fingerprint = IdempotencyService::fingerprint([
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'response_types' => $responseTypes,
            'token_endpoint_auth_method' => $authMethod,
            'application_type' => $applicationType,
            'client_name' => $name,
            'scope' => $requestedScopes,
            'frontchannel_logout_uri' => $frontchannelLogoutUri,
            'frontchannel_logout_session_required' => $frontchannelSessionRequired,
            'backchannel_logout_uri' => $backchannelLogoutUri,
            'backchannel_logout_session_required' => $backchannelSessionRequired,
        ]);
        try {
            $result = (new IdempotencyService())->execute(
                'oauth_dcr_token',
                (string) $tokenRecord->id,
                'oauth.dynamic_registration',
                $requestId,
                $fingerprint,
                'oauth_client',
                function () use ($tokenRecord, $initialAccessToken, $redirectUris, $grantTypes, $responseTypes, $authMethod, $applicationType, $name, $requestedScopes, $frontchannelLogoutUri, $frontchannelSessionRequired, $backchannelLogoutUri, $backchannelSessionRequired, $ip, $requestId): array {
                    $token = OAuthRegistrationToken::where('id', (int) $tokenRecord->id)->where('token_hash', $this->tokenHash($initialAccessToken))->lock(true)->find();
                    if ($token === null || (int) $token->status !== 1 || $token->expire_time === null || strtotime((string) $token->expire_time) <= time() || (int) $token->used_count >= (int) $token->max_uses) throw new ApiException('SAND_IAM_DCR_ACCESS_DENIED', 401);
                    $application = Application::where('id', (int) $token->application_id)->where('status', 1)->lock(true)->find();
                    if ($application === null) throw new ApiException('SAND_IAM_DCR_ACCESS_DENIED', 401);
                    $this->assertRedirectHosts($redirectUris, $token->allowed_redirect_hosts);
                    $allowedScopes = is_array($token->allowed_scopes ?? null) ? array_values($token->allowed_scopes) : [];
                    if (array_diff($requestedScopes, $allowedScopes) !== []) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);

                    $clientId = 'dcr_' . bin2hex(random_bytes(16));
                    $secret = $authMethod === 'none' ? null : 'siam_cs_' . bin2hex(random_bytes(32));
                    $client = OAuthClient::create([
                        'application_id' => (int) $application->id,
                        'code' => $clientId,
                        'name' => $name,
                        'client_type' => $authMethod === 'none' ? 'public' : 'confidential',
                        'secret_hash' => $secret === null ? null : $this->secretHash($secret),
                        'secret_version' => $secret === null ? null : 'v1',
                        'redirect_uris' => $redirectUris,
                        'post_logout_redirect_uris' => [],
                        'frontchannel_logout_uri' => $frontchannelLogoutUri,
                        'frontchannel_logout_session_required' => $frontchannelSessionRequired,
                        'backchannel_logout_uri' => $backchannelLogoutUri,
                        'backchannel_logout_session_required' => $backchannelSessionRequired,
                        'allowed_scopes' => $requestedScopes,
                        'allowed_audiences' => [],
                        'default_audience' => null,
                        'registration_source' => 'dynamic',
                        'dynamic_registration_token_id' => (int) $token->id,
                        'status' => 1,
                    ]);
                    $usedCount = (int) $token->used_count + 1;
                    $token->save(['used_count' => $usedCount, 'last_used_time' => date('Y-m-d H:i:s'), 'last_used_ip_hash' => $this->ipHash($ip), 'status' => $usedCount >= (int) $token->max_uses ? 2 : 1]);
                    $this->audit($application, 'oauth.dynamic_registration', (int) $client->id, 'dynamic_client', $requestId, ['token_id' => (int) $token->id, 'grant_types' => $grantTypes, 'auth_method' => $authMethod]);

                    $response = [
                        'client_id' => $clientId,
                        'client_id_issued_at' => time(),
                        'client_secret_expires_at' => 0,
                        'redirect_uris' => $redirectUris,
                        'grant_types' => $grantTypes,
                        'response_types' => $responseTypes,
                        'token_endpoint_auth_method' => $authMethod,
                        'application_type' => $applicationType,
                        'client_name' => $name,
                        'scope' => implode(' ', $requestedScopes),
                    ];
                    if ($secret !== null) $response['client_secret'] = $secret;
                    if ($frontchannelLogoutUri !== null) {
                        $response['frontchannel_logout_uri'] = $frontchannelLogoutUri;
                        $response['frontchannel_logout_session_required'] = $frontchannelSessionRequired;
                    }
                    if ($backchannelLogoutUri !== null) {
                        $response['backchannel_logout_uri'] = $backchannelLogoutUri;
                        $response['backchannel_logout_session_required'] = $backchannelSessionRequired;
                    }
                    return ['resource_id' => (int) $client->id, 'result' => $response];
                },
            );
        } catch (\Throwable $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, '23505') || str_contains($message, 'unique')) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
            throw $exception;
        }
        return $result['result'];
    }

    /** @return list<string> */
    private function redirectUris(mixed $value, string $applicationType): array
    {
        if (!is_array($value) || $value === [] || count($value) > 20 || !in_array($applicationType, ['web', 'native'], true)) throw new ApiException('SAND_IAM_DCR_INVALID_REDIRECT_URI', 400);
        $result = [];
        foreach ($value as $uri) {
            if (!is_string($uri) || strlen($uri) > 2048 || !filter_var($uri, FILTER_VALIDATE_URL) || str_contains($uri, '*')) throw new ApiException('SAND_IAM_DCR_INVALID_REDIRECT_URI', 400);
            $parts = parse_url($uri);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $loopback = in_array($host, ['127.0.0.1', '::1'], true);
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || $host === '' || ($scheme !== 'https' && !($applicationType === 'native' && $scheme === 'http' && $loopback))) throw new ApiException('SAND_IAM_DCR_INVALID_REDIRECT_URI', 400);
            $result[] = $uri;
        }
        return array_values(array_unique($result));
    }

    /** @return list<string> */
    private function grantTypes(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 2 || array_filter($value, static fn (mixed $grant): bool => !is_string($grant) || !in_array($grant, ['authorization_code', 'refresh_token'], true))) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        $value = array_values(array_unique($value));
        if (in_array('refresh_token', $value, true) && !in_array('authorization_code', $value, true)) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        return $value;
    }

    /** @param list<string> $grantTypes @return list<string> */
    private function responseTypes(mixed $value, array $grantTypes): array
    {
        if (!is_array($value) || $value !== ['code'] || !in_array('authorization_code', $grantTypes, true)) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        return ['code'];
    }

    /** @return list<string> */
    private function scopeString(string $value): array
    {
        $scopes = array_values(array_unique(array_filter(explode(' ', trim($value)))));
        try {
            return $this->scopes($scopes);
        } catch (ApiException) {
            throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        }
    }

    /** @param list<string> $value @return list<string> */
    private function scopes(array $value): array
    {
        if ($value === [] || count($value) > 30 || array_filter($value, static fn (mixed $scope): bool => !is_string($scope) || !preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $scope))) throw new ApiException('SAND_IAM_DCR_TOKEN_POLICY_INVALID', 400);
        return array_values(array_unique($value));
    }

    /** @param list<string> $value @return list<string> */
    private function hosts(array $value): array
    {
        if ($value === [] || count($value) > 50) throw new ApiException('SAND_IAM_DCR_TOKEN_POLICY_INVALID', 400);
        $hosts = [];
        foreach ($value as $host) {
            $host = strtolower(trim((string) $host));
            if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|127\.0\.0\.1|::1)$/', $host)) throw new ApiException('SAND_IAM_DCR_TOKEN_POLICY_INVALID', 400);
            $hosts[] = $host;
        }
        return array_values(array_unique($hosts));
    }

    private function assertRedirectHosts(array $redirectUris, mixed $allowedHosts): void
    {
        if (!is_array($allowedHosts) || $allowedHosts === []) throw new ApiException('SAND_IAM_DCR_ACCESS_DENIED', 401);
        foreach ($redirectUris as $uri) {
            $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
            if (!in_array($host, $allowedHosts, true)) throw new ApiException('SAND_IAM_DCR_INVALID_REDIRECT_URI', 400);
        }
    }

    /** @param list<string> $redirectUris */
    private function logoutUri(mixed $value, array $redirectUris): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        $parts = parse_url($value);
        if (($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
        $origin = $this->origin($value);
        foreach ($redirectUris as $redirectUri) if (hash_equals($origin, $this->origin($redirectUri))) return $value;
        throw new ApiException('SAND_IAM_DCR_INVALID_CLIENT_METADATA', 400);
    }

    private function origin(string $uri): string
    {
        $parts = parse_url($uri);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return $scheme . '://' . $host . ':' . $port;
    }

    private function application(int $id): Application
    {
        $application = Application::where('id', $id)->where('status', 1)->find();
        if ($application === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404);
        return $application;
    }

    private function tokenHash(string $token): string { return hash_hmac('sha256', 'dcr-token:' . $token, $this->pepper()); }
    private function ipHash(string $ip): string { return hash_hmac('sha256', 'dcr-ip:' . $ip, $this->pepper()); }
    private function secretHash(string $secret): string { return password_hash(hash_hmac('sha256', 'client-secret:' . $secret, $this->pepper()), defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT); }
    private function pepper(): string { $pepper = (string) config('plugin.sand-iam.app.auth_pepper', ''); if (strlen($pepper) < 32) throw new ApiException('SAND_IAM_OIDC_CONFIGURATION_UNAVAILABLE', 503); return $pepper; }
    private function enabled(): void { if ((int) config('plugin.sand-iam.app.oauth_dynamic_registration_enabled', 0) !== 1) throw new ApiException('SAND_IAM_DCR_DISABLED', 403); }
    private function requestId(string $value): string { return preg_match('/^[A-Za-z0-9_.:-]{8,96}$/', $value) ? $value : 'req_' . bin2hex(random_bytes(16)); }
    /** @param array<string,mixed> $context */ private function audit(Application $application, string $action, int $id, string $actor, string $requestId, array $context = []): void { $this->audit->write('admin', $actor, (int) $application->organization_id, (int) $application->id, $action, 'oauth_client', $id, 'succeeded', $this->requestId($requestId), $context); }
}
