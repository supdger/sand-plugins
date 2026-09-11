<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\ApplicationResourceController;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\ApplicationExperience;
use plugin\SandIam\app\model\IdentityProvider;
use plugin\SandIam\app\model\IdentityProviderApplication;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class ApplicationExperienceController extends ApplicationResourceController
{
    protected string $modelClass = ApplicationExperience::class;
    protected array $writeFields = ['application_id', 'brand_name', 'logo_url', 'primary_color', 'theme_mode', 'default_locale', 'terms_url', 'privacy_url', 'registration_mode', 'login_methods', 'registration_fields', 'status'];
    protected array $requiredFields = ['application_id', 'brand_name'];
    protected string $resourceType = 'application_experience';
    protected ?string $keywordField = 'brand_name';

    #[Permission('SandIAM 登录外观列表', 'sand_iam:application_experience:index')] public function index(Request $request): Response { return parent::index($request); }
    #[Permission('SandIAM 登录外观读取', 'sand_iam:application_experience:read')] public function read(Request $request): Response { return parent::read($request); }
    #[Permission('SandIAM 登录外观保存', 'sand_iam:application_experience:save')] public function save(Request $request): Response { return parent::save($request); }
    #[Permission('SandIAM 登录外观更新', 'sand_iam:application_experience:update')] public function update(Request $request): Response { return parent::update($request); }
    #[Permission('SandIAM 登录外观停用', 'sand_iam:application_experience:disable')] public function disable(Request $request): Response { return parent::disable($request); }

    protected function assertReferences(array $payload, ?object $existing = null): void
    {
        $applicationId = (int) ($payload['application_id'] ?? $existing?->application_id ?? 0);
        if (!Application::where('id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 400);
        if ($existing === null && ApplicationExperience::where('application_id', $applicationId)->find()) throw new ApiException('SAND_IAM_APPLICATION_EXPERIENCE_EXISTS: 该应用已有登录外观，请直接编辑', 409);

        $brandName = trim((string) ($payload['brand_name'] ?? $existing?->brand_name ?? ''));
        if ($brandName === '' || mb_strlen($brandName) > 128) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 应用显示名称须为 1–128 个字符', 400);
        $color = (string) ($payload['primary_color'] ?? $existing?->primary_color ?? '#1677ff');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 品牌主色须为 #RRGGBB', 400);
        $theme = (string) ($payload['theme_mode'] ?? $existing?->theme_mode ?? 'system');
        if (!in_array($theme, ['light', 'dark', 'system'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 主题模式无效', 400);
        $locale = (string) ($payload['default_locale'] ?? $existing?->default_locale ?? 'zh-CN');
        if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $locale)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 默认语言格式无效', 400);
        $registration = (string) ($payload['registration_mode'] ?? $existing?->registration_mode ?? 'disabled');
        if (!in_array($registration, ['open', 'invite', 'disabled'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 注册方式无效', 400);
        foreach (['logo_url', 'terms_url', 'privacy_url'] as $field) {
            $url = trim((string) ($payload[$field] ?? $existing?->$field ?? ''));
            if ($url !== '' && !$this->safeHttpsUrl($url)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 对外链接必须是有效 HTTPS 地址', 400);
        }

        $methods = $this->stringList($payload['login_methods'] ?? $existing?->login_methods ?? ['password'], 20);
        if ($methods === []) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 至少配置一种登录方式', 400);
        foreach ($methods as $method) {
            if (in_array($method, ['password', 'passkey'], true)) continue;
            if (!preg_match('/^(oidc|oauth2|saml|kerberos):([A-Za-z0-9_-]{20,128})$/', $method, $matches)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 登录方式代码无效', 400);
            $provider = IdentityProvider::where('public_code', $matches[2])->where('provider_type', $matches[1])->where('status', 1)->find();
            if ($provider === null || !IdentityProviderApplication::where('identity_provider_id', (int) $provider->id)->where('application_id', $applicationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 登录方式引用的身份源未挂载或已停用', 400);
        }
        if ($registration === 'open' && !in_array('password', $methods, true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 开放注册必须启用密码登录', 400);
        $fields = $this->stringList($payload['registration_fields'] ?? $existing?->registration_fields ?? ['username', 'display_name', 'email'], 4);
        foreach ($fields as $field) if (!in_array($field, ['username', 'display_name', 'email', 'phone'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 注册字段无效', 400);
        if (!in_array('username', $fields, true) || (!in_array('email', $fields, true) && !in_array('phone', $fields, true))) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 注册字段必须包含用户名，以及邮箱或手机号中的至少一项', 400);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    protected function normalizePayload(array $payload, ?object $existing = null): array
    {
        if (array_key_exists('login_methods', $payload)) $payload['login_methods'] = $this->stringList($payload['login_methods'], 20);
        if (array_key_exists('registration_fields', $payload)) $payload['registration_fields'] = $this->stringList($payload['registration_fields'], 4);
        return $payload;
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $maximum): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value) || count($value) > $maximum) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 配置项必须是有限字符串数组', 400);
        $result = [];
        foreach ($value as $item) {
            if (!is_string($item)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 配置项包含无效内容', 400);
            $item = trim($item);
            if ($item === '' || mb_strlen($item) > 160) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 配置项包含无效内容', 400);
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    private function safeHttpsUrl(string $url): bool
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) return false;
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null;
    }
}
