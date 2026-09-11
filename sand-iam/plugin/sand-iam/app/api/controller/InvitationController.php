<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\IdentityInvitationService;
use plugin\sandadmin\basic\BaseController;
use support\Request;
use support\Response;

final class InvitationController extends BaseController
{
    public function accept(Request $request): Response
    {
        return $this->success((new IdentityInvitationService())->accept((string) $request->post('token', ''), ['username' => $request->post('username', ''), 'display_name' => $request->post('display_name', ''), 'password' => $request->post('password', '')], substr((string) $request->header('X-Request-Id', ''), 0, 96)), '邀请已接受，请使用新账号登录')->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
