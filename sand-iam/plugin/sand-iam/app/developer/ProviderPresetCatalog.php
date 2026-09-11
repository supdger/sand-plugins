<?php

declare(strict_types=1);

namespace plugin\SandIam\app\developer;

use plugin\sandadmin\exception\ApiException;

/**
 * Verified provider metadata only. A preset is never a saved identity provider
 * and never carries a credential; final configuration remains subject to the
 * federation service's existing HTTPS, DNS/SSRF and TLS controls.
 */
final class ProviderPresetCatalog
{
    private const LAST_VERIFIED_AT = '2026-08-22';

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return array_values(self::PRESETS);
    }

    /** @return array<string,mixed> */
    public static function read(string $code): array
    {
        $preset = self::PRESETS[trim($code)] ?? null;
        if (!is_array($preset)) throw new ApiException('SAND_IAM_IDP_PRESET_NOT_FOUND', 404);

        return $preset;
    }

    /**
     * Produces an unsaved, secret-free federation configuration draft.
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function draft(string $code, array $input): array
    {
        self::rejectSecrets($input);
        $preset = self::read($code);
        if (($preset['compatibility'] ?? null) !== 'compatible') {
            throw new ApiException(($preset['compatibility'] ?? null) === 'manual_required'
                ? 'SAND_IAM_IDP_PRESET_MANUAL_REQUIRED'
                : 'SAND_IAM_IDP_PRESET_UNSUPPORTED', 400);
        }

        $clientId = self::text($input, 'client_id');
        $redirectUri = self::https($input, 'redirect_uri');
        $handoffReturnUris = self::httpsList($input['handoff_return_uris'] ?? null);
        $config = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => implode(' ', $preset['default_scopes']),
            'handoff_return_uris' => $handoffReturnUris,
        ];
        if ($preset['protocol'] === 'oauth2') {
            foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $field) {
                $config[$field] = $preset['endpoints'][$field];
            }
        } else {
            $tenantId = isset($preset['tenant_required']) && $preset['tenant_required'] === true ? self::tenant($input) : null;
            $config['issuer'] = self::template((string) $preset['issuer_template'], $tenantId);
            $config['discovery_url'] = self::template((string) $preset['endpoints']['discovery'], $tenantId);
        }

        return [
            'preset_code' => $preset['code'],
            'draft_only' => true,
            'save_performed' => false,
            'provider_type' => $preset['protocol'],
            'config' => $config,
            'attribute_mapping' => $preset['claim_mapping'],
            'required_secret_fields' => array_values(array_map(static fn (array $field): string => $field['key'], array_filter($preset['required_config'], static fn (array $field): bool => $field['secret'] === true))),
            'next_step' => '将草稿与密钥在联合身份源敏感配置接口中人工确认；本接口不保存、回显或启用密钥。',
        ];
    }

    /** @param array<string,mixed> $input */
    private static function rejectSecrets(array $input): void
    {
        foreach ($input as $key => $value) {
            if (preg_match('/(?:secret|password|private[_-]?key|token)/i', (string) $key) === 1) {
                throw new ApiException('SAND_IAM_IDP_PRESET_SECRET_NOT_ALLOWED', 400);
            }
            if (is_array($value)) self::rejectSecrets($value);
        }
    }

    /** @param array<string,mixed> $input */
    private static function text(array $input, string $field): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new ApiException('SAND_IAM_IDP_PRESET_DRAFT_INVALID', 400);
        }

        return $value;
    }

    /** @param array<string,mixed> $input */
    private static function https(array $input, string $field): string
    {
        $value = self::text($input, $field);
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ((int) ($parts['port'] ?? 443)) < 1 || ((int) ($parts['port'] ?? 443)) > 65535) {
            throw new ApiException('SAND_IAM_IDP_PRESET_DRAFT_INVALID', 400);
        }

        return $value;
    }

    /** @return list<string> */
    private static function httpsList(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 32) throw new ApiException('SAND_IAM_IDP_PRESET_DRAFT_INVALID', 400);
        $result = [];
        foreach ($value as $uri) {
            $result[] = self::https(['uri' => $uri], 'uri');
        }
        if (count(array_unique($result, SORT_STRING)) !== count($result)) throw new ApiException('SAND_IAM_IDP_PRESET_DRAFT_INVALID', 400);

        return $result;
    }

    /** @param array<string,mixed> $input */
    private static function tenant(array $input): string
    {
        $value = self::text($input, 'tenant_id');
        if (preg_match('/^[A-Za-z0-9.-]{1,128}$/', $value) !== 1) throw new ApiException('SAND_IAM_IDP_PRESET_DRAFT_INVALID', 400);

        return $value;
    }

    private static function template(string $value, ?string $tenantId): string
    {
        return str_replace('{tenant_id}', (string) $tenantId, $value);
    }

    /** @var array<string,array<string,mixed>> */
    private const PRESETS = [
        'github_oauth2' => [
            'code' => 'github_oauth2', 'name' => 'GitHub OAuth 应用', 'protocol' => 'oauth2', 'compatibility' => 'compatible',
            'endpoints' => ['authorization_endpoint' => 'https://github.com/login/oauth/authorize', 'token_endpoint' => 'https://github.com/login/oauth/access_token', 'userinfo_endpoint' => 'https://api.github.com/user', 'jwks_uri' => null, 'discovery' => null],
            'default_scopes' => ['read:user', 'user:email'], 'claim_mapping' => ['subject' => 'id', 'username' => 'login', 'display_name' => 'name', 'email' => 'email'],
            'required_config' => [['key' => 'client_id', 'secret' => false], ['key' => 'client_secret', 'secret' => true], ['key' => 'redirect_uri', 'secret' => false], ['key' => 'handoff_return_uris', 'secret' => false]],
            'regions' => ['全球'], 'last_verified_at' => self::LAST_VERIFIED_AT,
            'official_source_urls' => ['https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps'],
        ],
        'google_oidc' => [
            'code' => 'google_oidc', 'name' => 'Google OpenID Connect 登录', 'protocol' => 'oidc', 'compatibility' => 'compatible', 'issuer_template' => 'https://accounts.google.com',
            'endpoints' => ['authorization_endpoint' => null, 'token_endpoint' => null, 'userinfo_endpoint' => null, 'jwks_uri' => null, 'discovery' => 'https://accounts.google.com/.well-known/openid-configuration'],
            'default_scopes' => ['openid', 'profile', 'email'], 'claim_mapping' => ['subject' => 'sub', 'username' => 'email', 'display_name' => 'name', 'email' => 'email'],
            'required_config' => [['key' => 'client_id', 'secret' => false], ['key' => 'client_secret', 'secret' => true], ['key' => 'redirect_uri', 'secret' => false], ['key' => 'handoff_return_uris', 'secret' => false]],
            'regions' => ['全球'], 'last_verified_at' => self::LAST_VERIFIED_AT,
            'official_source_urls' => ['https://developers.google.com/identity/openid-connect/reference'],
        ],
        'microsoft_entra_oidc' => [
            'code' => 'microsoft_entra_oidc', 'name' => 'Microsoft Entra ID 登录', 'protocol' => 'oidc', 'compatibility' => 'compatible', 'tenant_required' => true, 'issuer_template' => 'https://login.microsoftonline.com/{tenant_id}/v2.0',
            'endpoints' => ['authorization_endpoint' => null, 'token_endpoint' => null, 'userinfo_endpoint' => null, 'jwks_uri' => null, 'discovery' => 'https://login.microsoftonline.com/{tenant_id}/v2.0/.well-known/openid-configuration'],
            'default_scopes' => ['openid', 'profile', 'email'], 'claim_mapping' => ['subject' => 'sub', 'username' => 'preferred_username', 'display_name' => 'name', 'email' => 'email'],
            'required_config' => [['key' => 'tenant_id', 'secret' => false], ['key' => 'client_id', 'secret' => false], ['key' => 'client_secret', 'secret' => true], ['key' => 'redirect_uri', 'secret' => false], ['key' => 'handoff_return_uris', 'secret' => false]],
            'regions' => ['全球', '中国（Azure 中国需人工核对独立云端点）'], 'last_verified_at' => self::LAST_VERIFIED_AT,
            'official_source_urls' => ['https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc'],
        ],
        'feishu_user_authorization' => [
            'code' => 'feishu_user_authorization', 'name' => '飞书用户授权', 'protocol' => 'oauth2', 'compatibility' => 'manual_required',
            'endpoints' => ['authorization_endpoint' => null, 'token_endpoint' => null, 'userinfo_endpoint' => null, 'jwks_uri' => null, 'discovery' => null], 'default_scopes' => [], 'claim_mapping' => [], 'required_config' => [],
            'regions' => ['中国', '全球（飞书/Lark 租户差异）'], 'last_verified_at' => self::LAST_VERIFIED_AT,
            'official_source_urls' => ['https://open.feishu.cn/document/server-docs/authentication-management/login-state-management/get'],
            'manual_reason' => '当前仅核对到官方用户信息资料；未确认可直接套入 SandIAM 通用 OAuth2/OIDC 的完整端点和声明。',
        ],
        'dingtalk_login' => [
            'code' => 'dingtalk_login', 'name' => '钉钉登录', 'protocol' => 'oauth2', 'compatibility' => 'manual_required',
            'endpoints' => ['authorization_endpoint' => null, 'token_endpoint' => null, 'userinfo_endpoint' => null, 'jwks_uri' => null, 'discovery' => null], 'default_scopes' => [], 'claim_mapping' => [], 'required_config' => [],
            'regions' => ['中国'], 'last_verified_at' => self::LAST_VERIFIED_AT,
            'official_source_urls' => ['https://open.dingtalk.com/tutorial/'], 'manual_reason' => '官方教程可确认登录场景，但当前未确认可直接套入的 OAuth/OIDC 端点与声明契约。',
        ],
        'wecom_web_authorization' => [
            'code' => 'wecom_web_authorization', 'name' => '企业微信网页授权', 'protocol' => 'oauth2', 'compatibility' => 'manual_required',
            'endpoints' => ['authorization_endpoint' => null, 'token_endpoint' => null, 'userinfo_endpoint' => null, 'jwks_uri' => null, 'discovery' => null], 'default_scopes' => [], 'claim_mapping' => [], 'required_config' => [],
            'regions' => ['中国'], 'last_verified_at' => self::LAST_VERIFIED_AT,
            'official_source_urls' => ['https://developer.work.weixin.qq.com/document/'], 'manual_reason' => '当前未能从官方页面确认可直接套入 SandIAM 通用 OAuth2/OIDC 的端点和声明。',
        ],
        'wechat_open_platform_login' => [
            'code' => 'wechat_open_platform_login', 'name' => '微信开放平台网站登录', 'protocol' => 'oauth2', 'compatibility' => 'manual_required',
            'endpoints' => ['authorization_endpoint' => null, 'token_endpoint' => null, 'userinfo_endpoint' => null, 'jwks_uri' => null, 'discovery' => null], 'default_scopes' => [], 'claim_mapping' => [], 'required_config' => [],
            'regions' => ['中国'], 'last_verified_at' => self::LAST_VERIFIED_AT,
            'official_source_urls' => ['https://developers.weixin.qq.com/doc/oplatform/Website_App/WeChat_Login/Wechat_Login.html'], 'manual_reason' => '当前未能从官方页面确认可直接套入 SandIAM 通用 OAuth2/OIDC 的端点和声明。',
        ],
    ];
}
