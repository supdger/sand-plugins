<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\developer\ManagementApiCatalog;
use plugin\SandIam\app\service\OnboardingService;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\SandIam\app\webhook\EventCatalog;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class DeveloperController extends BaseController
{
    #[Permission('SandIAM 开发者接入预检', 'sand_iam:onboarding:preview')]
    public function onboardingPreview(Request $request): Response
    {
        $manifest = $request->post('manifest', null);
        if (!is_array($manifest)) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_ONBOARDING_MANIFEST_INVALID: manifest 必须是 JSON 对象', 400);
        $result = (new OnboardingService())->preview($manifest);
        $this->onboardingAccess($request)->assertOrganization((int) $result['organization_id']);
        if ($result['application_id'] !== null) $this->onboardingAccess($request)->assertApplication((int) $result['application_id']);
        unset($result['manifest']);
        return $this->success($result);
    }

    #[Permission('SandIAM 开发者接入应用', 'sand_iam:onboarding:apply')]
    public function onboardingApply(Request $request): Response
    {
        $manifest = $request->post('manifest', null);
        if (!is_array($manifest) || $request->post('apply', false) !== true) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_ONBOARDING_APPLY_REQUIRED: 必须传 apply: true 和 manifest 才会写入', 400);
        $admin = $request->header('check_admin', []);
        $preview = (new OnboardingService())->preview($manifest);
        $this->onboardingAccess($request)->assertOrganization((int) $preview['organization_id']);
        if ($preview['application_id'] !== null) $this->onboardingAccess($request)->assertApplication((int) $preview['application_id']);
        $result = (new OnboardingService())->apply($manifest, trim((string) $request->post('preview_hash', '')), is_array($admin) ? (int) ($admin['id'] ?? 0) : 0, (string) $request->header('X-Request-Id', ''));
        return $this->success($result, ($result['replayed'] ?? false) ? '请求已处理；凭证明文不会再次显示' : '接入已完成；凭证只显示一次')->withHeader('Cache-Control', 'no-store');
    }

    private function onboardingAccess(Request $request): AdminOrganizationAccess
    {
        $token = $request->header('check_admin', []);
        return new AdminOrganizationAccess(is_array($token) ? (int) ($token['id'] ?? 0) : 0, is_array($token) ? $token : null);
    }
    #[Permission('SandIAM 管理 API 文档', 'sand_iam:developer:openapi')]
    public function openApi(Request $request): Response
    {
        return $this->success(ManagementApiCatalog::openApi());
    }

    #[Permission('SandIAM 事件目录', 'sand_iam:developer:events')]
    public function events(Request $request): Response
    {
        $events = [];
        foreach (EventCatalog::all() as $code => $definition) $events[] = ['code' => $code] + $definition;
        return $this->success(['schema_version' => 1, 'events' => $events]);
    }
}
