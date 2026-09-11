<?php

declare(strict_types=1);

namespace plugin\SandIam\app\admin\controller;

use plugin\SandIam\app\acceptance\AcceptanceFixtureService;
use plugin\SandIam\app\acceptance\AcceptanceFixtureWebhookEventService;
use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\service\Permission;
use support\Request;
use support\Response;

final class AcceptanceFixtureController extends BaseController
{
    #[Permission('SandIAM 验收数据清理', 'sand_iam:acceptance_fixture:cleanup')]
    public function cleanup(Request $request): Response
    {
        [$adminId] = $this->guard($request);
        return $this->success((new AcceptanceFixtureService())->cleanup(
            $request->post(),
            $adminId,
            (string) $request->header('X-Request-Id', ''),
        ));
    }

    #[Permission('SandIAM 验收数据状态读取', 'sand_iam:acceptance_fixture:read')]
    public function status(Request $request): Response
    {
        $this->guard($request);
        $payload = $request->get();
        if (!is_array($payload)) $payload = [];
        return $this->success((new AcceptanceFixtureService())->status(
            $payload,
            (string) $request->header('X-Request-Id', ''),
        ));
    }

    #[Permission('SandIAM 验收事件触发', 'sand_iam:acceptance_fixture:cleanup')]
    public function webhookEvent(Request $request): Response
    {
        [$adminId] = $this->guard($request);
        return $this->success((new AcceptanceFixtureWebhookEventService())->trigger(
            $request->post(),
            $adminId,
            (string) $request->header('X-Request-Id', ''),
        ));
    }

    /** @return array{int,array<string,mixed>} */
    private function guard(Request $request): array
    {
        if ((int) config('plugin.sand-iam.app.acceptance_fixture_cleanup_enabled', 0) !== 1) {
            throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_DISABLED: 验收数据清理功能未开启', 400);
        }
        $token = $request->header('check_admin', []);
        $admin = is_array($token) ? $token : [];
        $adminId = (int) ($admin['id'] ?? 0);
        $access = new AdminOrganizationAccess($adminId, $admin);
        if (!$access->isSuperAdmin()) {
            try {
                $access->assertSuperAdmin();
            } catch (ApiException) {
                throw new ApiException('SAND_IAM_ACCEPTANCE_FIXTURE_SUPER_ADMIN_REQUIRED: 仅平台超级管理员可以操作验收数据', 401);
            }
        }
        return [$adminId, $admin];
    }
}
