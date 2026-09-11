<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\model\Application;
use plugin\SandIam\app\model\MessageProvider;
use plugin\SandIam\app\model\MessageProviderApplication;
use plugin\SandIam\app\model\Organization;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\MessageProviderConfigCipher;
use plugin\SandIam\app\service\MessageProviderService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class MessageProviderController extends BaseController
{
    #[Permission('SandIAM 消息服务列表', 'sand_iam:message_provider:index')]
    public function index(Request $request): Response
    {
        $query = MessageProvider::order('id', 'desc');
        $access = $this->access($request);
        if (!$access->isSuperAdmin()) {
            $ids = $access->organizationIds();
            if ($ids === []) $query->whereRaw('1 = 0'); else $query->whereIn('organization_id', $ids);
        }
        $organizationId = (int) $request->input('organization_id', 0);
        if ($organizationId > 0) { $access->assertOrganization($organizationId); $query->where('organization_id', $organizationId); }
        $type = (string) $request->input('provider_type', '');
        if ($type !== '') $query->where('provider_type', $this->type($type));
        $keyword = trim((string) $request->input('keywords', ''));
        if ($keyword !== '') $query->whereLike('name', '%' . $keyword . '%');
        $result = $query->paginate(['page' => max(1, (int) $request->input('page', 1)), 'list_rows' => min(100, max(1, (int) $request->input('limit', 20)))])->toArray();
        $result['data'] = array_map(fn (array $item): array => $this->safeProvider($item), $result['data'] ?? []);
        return $this->success($result);
    }

    #[Permission('SandIAM 消息服务读取', 'sand_iam:message_provider:read')]
    public function read(Request $request): Response { return $this->success($this->safeProvider($this->provider($request)->toArray())); }

    #[Permission('SandIAM 消息服务保存', 'sand_iam:message_provider:save')]
    public function save(Request $request): Response
    {
        $organizationId = (int) $request->post('organization_id', 0);
        $this->access($request)->assertOrganization($organizationId);
        if (!Organization::where('id', $organizationId)->where('status', 1)->find()) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属客户主体不存在或已停用', 400);
        $code = trim((string) $request->post('code', ''));
        $name = trim((string) $request->post('name', ''));
        $driver = trim((string) $request->post('driver_code', ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{1,63}$/', $code) || !preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $driver) || $name === '' || mb_strlen($name) > 128) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 消息服务名称、系统代码或驱动代码无效', 400);
        try {
            $provider = MessageProvider::create(['organization_id' => $organizationId, 'code' => $code, 'name' => $name, 'provider_type' => $this->type((string) $request->post('provider_type', '')), 'driver_code' => $driver, 'config_version' => 0, 'status' => 1]);
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFLICT', 409);
            throw $exception;
        }
        $this->audit($request, 'message_provider.create', (int) $provider->id, $organizationId, null);
        return $this->success(['id' => (int) $provider->id], '消息服务已创建，请继续填写供应商配置');
    }

    #[Permission('SandIAM 消息服务更新', 'sand_iam:message_provider:update')]
    public function update(Request $request): Response
    {
        $provider = $this->provider($request);
        $name = trim((string) $request->post('name', ''));
        if ($name === '' || mb_strlen($name) > 128) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 消息服务名称须为 1–128 个字符', 400);
        $provider->save(['name' => $name, 'status' => $this->status((int) $request->post('status', (int) $provider->status))]);
        $this->audit($request, 'message_provider.update', (int) $provider->id, (int) $provider->organization_id, null);
        return $this->success('消息服务已更新');
    }

    #[Permission('SandIAM 消息服务停用', 'sand_iam:message_provider:disable')]
    public function disable(Request $request): Response
    {
        $provider = $this->provider($request);
        $provider->save(['status' => 2]);
        MessageProviderApplication::where('message_provider_id', (int) $provider->id)->where('status', 1)->update(['status' => 2]);
        $this->audit($request, 'message_provider.disable', (int) $provider->id, (int) $provider->organization_id, null);
        return $this->success('消息服务及其应用挂载已停用');
    }

    #[Permission('SandIAM 消息服务配置', 'sand_iam:message_provider:configure')]
    public function configure(Request $request): Response
    {
        $provider = $this->provider($request);
        $config = $request->post('config', []);
        if (!is_array($config) || $config === [] || count($config) > 32) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID', 400);
        $provider->save(['encrypted_config' => (new MessageProviderConfigCipher())->encrypt($config), 'config_version' => (int) $provider->config_version + 1]);
        $this->audit($request, 'message_provider.configure', (int) $provider->id, (int) $provider->organization_id, null);
        return $this->success(['id' => (int) $provider->id, 'config_version' => (int) $provider->config_version], '供应商配置已加密保存，之后不再显示原值')->withHeader('Cache-Control', 'no-store');
    }

    #[Permission('SandIAM 消息服务测试', 'sand_iam:message_provider:test')]
    public function test(Request $request): Response
    {
        $provider = $this->provider($request);
        $destination = trim((string) $request->post('destination_or_token', ''));
        if ($destination === '' || strlen($destination) > 2048) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 测试接收地址或挑战令牌不能为空', 400);
        (new MessageProviderService())->test($provider, $destination, ['request_id' => $this->requestId($request)]);
        $this->audit($request, 'message_provider.test', (int) $provider->id, (int) $provider->organization_id, null);
        return $this->success('测试成功；接收地址、验证码和挑战令牌未写入审计');
    }

    #[Permission('SandIAM 消息服务应用挂载', 'sand_iam:message_provider_mount:save')]
    public function mount(Request $request): Response
    {
        $provider = MessageProvider::where('id', (int) $request->post('message_provider_id', 0))->where('status', 1)->find();
        $application = Application::where('id', (int) $request->post('application_id', 0))->where('status', 1)->find();
        if ($provider === null || $application === null || (int) $provider->organization_id !== (int) $application->organization_id) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 消息服务和接入应用必须属于同一客户主体且均已启用', 400);
        $this->access($request)->assertApplication((int) $application->id);
        $purposes = $this->purposes($request->post('purposes', []));
        $templateCodes = $this->templateCodes($request->post('template_codes', []));
        $priority = max(1, min(1000, (int) $request->post('priority', 100)));
        $mount = MessageProviderApplication::where('message_provider_id', (int) $provider->id)->where('application_id', (int) $application->id)->find();
        if ($mount === null) $mount = MessageProviderApplication::create(['message_provider_id' => (int) $provider->id, 'application_id' => (int) $application->id, 'organization_id' => (int) $application->organization_id, 'purposes' => $purposes, 'template_codes' => $templateCodes, 'priority' => $priority, 'status' => 1]);
        else $mount->save(['purposes' => $purposes, 'template_codes' => $templateCodes, 'priority' => $priority, 'status' => 1]);
        $this->audit($request, 'message_provider.mount', (int) $mount->id, (int) $application->organization_id, (int) $application->id);
        return $this->success(['id' => (int) $mount->id], '应用使用规则已保存');
    }

    #[Permission('SandIAM 消息服务应用解绑', 'sand_iam:message_provider_mount:disable')]
    public function unmount(Request $request): Response
    {
        $mount = MessageProviderApplication::find((int) $request->post('id', 0));
        if ($mount === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 应用使用规则不存在', 404);
        $this->access($request)->assertApplication((int) $mount->application_id);
        $mount->save(['status' => 2]);
        $this->audit($request, 'message_provider.unmount', (int) $mount->id, (int) $mount->organization_id, (int) $mount->application_id);
        return $this->success('应用已停止使用该消息服务');
    }

    #[Permission('SandIAM 消息服务可选项', 'sand_iam:message_provider:index')]
    public function options(Request $request): Response
    {
        $application = Application::where('id', (int) $request->input('application_id', 0))->where('status', 1)->find();
        if ($application === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 所属接入应用不存在或已停用', 404);
        $this->access($request)->assertApplication((int) $application->id);
        $providers = MessageProvider::where('organization_id', (int) $application->organization_id)->where('status', 1)->order('name', 'asc')->select();
        return $this->success(array_map(fn (MessageProvider $provider): array => ['id' => (int) $provider->id, 'name' => (string) $provider->name, 'provider_type' => (string) $provider->provider_type, 'config_configured' => (string) ($provider->encrypted_config ?? '') !== ''], $providers->all()));
    }

    #[Permission('SandIAM 消息服务应用列表', 'sand_iam:message_provider_mount:index')]
    public function mounts(Request $request): Response
    {
        $applicationId = (int) $request->input('application_id', 0);
        $this->access($request)->assertApplication($applicationId);
        $mounts = MessageProviderApplication::where('application_id', $applicationId)->order('priority', 'asc')->order('id', 'asc')->select();
        $result = [];
        foreach ($mounts as $mount) {
            $provider = MessageProvider::find((int) $mount->message_provider_id);
            $result[] = ['id' => (int) $mount->id, 'message_provider_id' => (int) $mount->message_provider_id, 'message_provider_name' => $provider ? (string) $provider->name : '已删除的消息服务', 'provider_type' => $provider ? (string) $provider->provider_type : '', 'purposes' => is_array($mount->purposes ?? null) ? $mount->purposes : [], 'template_codes' => is_array($mount->template_codes ?? null) ? $mount->template_codes : [], 'priority' => (int) $mount->priority, 'status' => (int) $mount->status];
        }
        return $this->success($result);
    }

    private function provider(Request $request): MessageProvider
    {
        $provider = MessageProvider::find((int) $request->input('id', $request->post('id', 0)));
        if ($provider === null) throw new ApiException('SAND_IAM_RESOURCE_NOT_FOUND: 消息服务不存在或当前账号无权访问', 404);
        $this->access($request)->assertOrganization((int) $provider->organization_id);
        return $provider;
    }

    /** @param array<string,mixed> $provider @return array<string,mixed> */
    private function safeProvider(array $provider): array
    {
        return ['id' => (int) ($provider['id'] ?? 0), 'organization_id' => (int) ($provider['organization_id'] ?? 0), 'code' => (string) ($provider['code'] ?? ''), 'name' => (string) ($provider['name'] ?? ''), 'provider_type' => (string) ($provider['provider_type'] ?? ''), 'driver_code' => (string) ($provider['driver_code'] ?? ''), 'config_version' => (int) ($provider['config_version'] ?? 0), 'config_configured' => !empty($provider['encrypted_config']), 'status' => (int) ($provider['status'] ?? 0), 'create_time' => $provider['create_time'] ?? null, 'update_time' => $provider['update_time'] ?? null];
    }

    private function type(string $value): string
    {
        if (!in_array($value, ['email', 'sms', 'captcha', 'notification'], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 服务类型只支持邮件、短信、人机验证或站外通知', 400);
        return $value;
    }
    private function status(int $value): int { if (!in_array($value, [1, 2], true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 状态无效', 400); return $value; }
    /** @return list<string> */
    private function purposes(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value) || $value === [] || count($value) > 16) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 使用场景须为 1–16 项数组', 400);
        $allowed = ['*', 'verification', 'invitation', 'register', 'login', 'password_reset', 'security_alert', 'account_notice'];
        $result = [];
        foreach ($value as $purpose) { if (!is_string($purpose) || !in_array($purpose, $allowed, true)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 使用场景无效', 400); $result[] = $purpose; }
        return array_values(array_unique($result));
    }
    /** @return array<string,string> */
    private function templateCodes(mixed $value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value) || count($value) > 16) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 模板代码须为对象且最多 16 项', 400);
        $result = [];
        foreach ($value as $purpose => $code) {
            if (!is_string($purpose) || !is_string($code) || !in_array($purpose, ['verification', 'invitation', 'email_verify', 'phone_verify', 'password_reset', 'security_alert', 'account_notice'], true) || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $code)) throw new ApiException('SAND_IAM_VALIDATION_ERROR: 模板用途或供应商模板代码无效', 400);
            $result[$purpose] = $code;
        }
        return $result;
    }
    private function access(Request $request): AdminOrganizationAccess { $token = $request->header('check_admin', []); return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null); }
    private function requestId(Request $request): string { return RequestId::fromRequestCached($request); }
    private function audit(Request $request, string $action, int $resourceId, int $organizationId, ?int $applicationId): void
    {
        $token = $request->header('check_admin', []);
        (new AuditWriter())->write('admin', (string) (is_array($token) ? ($token['id'] ?? 0) : 0), $organizationId, $applicationId, $action, 'message_provider', $resourceId, 'succeeded', $this->requestId($request));
    }
}
