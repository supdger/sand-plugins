<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\AuthPolicy;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AuthPolicyController extends ApplicationResourceController
{
    protected string $modelClass = AuthPolicy::class;
    protected array $writeFields = ['application_id', 'registration_enabled', 'password_min_length', 'password_max_length', 'require_uppercase', 'require_lowercase', 'require_digit', 'require_symbol', 'require_email_verification', 'require_phone_verification', 'require_captcha', 'access_token_ttl_seconds', 'refresh_token_ttl_seconds', 'verification_ttl_seconds', 'max_login_failures', 'lock_seconds', 'rate_limit_per_minute', 'webauthn_rp_id', 'webauthn_allowed_origins', 'webauthn_user_verification', 'status'];
    protected array $requiredFields = ['application_id'];
    protected string $resourceType = 'auth_policy';
    #[Permission('SandIAM 认证策略列表', 'sand_iam:auth_policy:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 认证策略读取', 'sand_iam:auth_policy:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 认证策略保存', 'sand_iam:auth_policy:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 认证策略更新', 'sand_iam:auth_policy:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 认证策略停用', 'sand_iam:auth_policy:disable')] public function disable(Request $request): Response { return parent::disable($request); }
    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属应用不存在或已停用', 400);
        if ($existing === null && AuthPolicy::where('application_id', $applicationId)->find()) {
            throw new ApiException('SAND_IAM_AUTH_POLICY_EXISTS: 该应用已有认证策略，请直接编辑', 409);
        }
        foreach (['password_min_length' => [12, 128], 'password_max_length' => [12, 128], 'access_token_ttl_seconds' => [60, 3600], 'refresh_token_ttl_seconds' => [300, 7776000], 'verification_ttl_seconds' => [60, 3600], 'max_login_failures' => [3, 20], 'lock_seconds' => [60, 86400], 'rate_limit_per_minute' => [1, 1000]] as $field => [$min, $max]) {
            if (isset($payload[$field]) && ((int) $payload[$field] < $min || (int) $payload[$field] > $max)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 认证策略参数超出允许范围', 400);
        }
        foreach (['registration_enabled', 'require_uppercase', 'require_lowercase', 'require_digit', 'require_symbol', 'require_email_verification', 'require_phone_verification', 'require_captcha', 'status'] as $field) {
            if (isset($payload[$field]) && !in_array((int) $payload[$field], [1, 2], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 认证策略开关值无效', 400);
        }
        $minimumLength = (int) ($payload['password_min_length'] ?? $existing?->password_min_length ?? 12);
        $maximumLength = (int) ($payload['password_max_length'] ?? $existing?->password_max_length ?? 128);
        if ($minimumLength > $maximumLength) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 密码最小长度不能大于最大长度', 400);
        if (array_key_exists('webauthn_user_verification', $payload) && !in_array((string) $payload['webauthn_user_verification'], ['required', 'preferred', 'discouraged'], true)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 通行密钥用户验证策略无效', 400);
        }
        $rpId = trim((string) ($payload['webauthn_rp_id'] ?? $existing?->webauthn_rp_id ?? ''));
        $origins = $payload['webauthn_allowed_origins'] ?? $existing?->webauthn_allowed_origins ?? [];
        if (is_string($origins)) $origins = json_decode($origins, true);
        if ($rpId !== '' && !preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/', $rpId)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 通行密钥 RP ID 必须是小写有效域名', 400);
        }
        if (!is_array($origins) || count($origins) > 20) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 通行密钥允许来源必须是最多 20 项的数组', 400);
        }
        if (($rpId === '') !== ($origins === [])) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 通行密钥 RP ID 和允许来源必须同时配置或同时留空', 400);
        }
        foreach ($origins as $origin) {
            if (!is_string($origin) || !preg_match('#^https://[a-z0-9.-]+(?::[0-9]{1,5})?$#', $origin)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 通行密钥允许来源必须是小写 HTTPS 源且不能包含路径', 400);
            $host = (string) parse_url($origin, PHP_URL_HOST);
            $port = parse_url($origin, PHP_URL_PORT);
            if (($host !== $rpId && !str_ends_with($host, '.' . $rpId)) || ($port !== null && ($port < 1 || $port > 65535))) {
                throw new ApiException('SAND_IAM_VALIDATION_ERROR: 允许来源域名必须等于 RP ID 或其子域，端口必须有效', 400);
            }
        }
    }
}
