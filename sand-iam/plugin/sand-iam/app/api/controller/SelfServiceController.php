<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\service\SelfServiceService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class SelfServiceController extends BaseController
{
    public function profile(Request $request): Response
    {
        return $this->success((new SelfServiceService())->profile($this->bearer($request)));
    }

    public function updateProfile(Request $request): Response
    {
        $accessToken = $this->bearer($request);
        $displayName = $request->post('display_name');
        if (!is_string($displayName)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 显示名称必须是文本', 400);
        }
        return $this->success((new SelfServiceService())->updateProfile(
            $accessToken,
            $displayName,
            $this->requestId($request),
        ), '个人资料已更新');
    }

    public function connections(Request $request): Response
    {
        return $this->success((new SelfServiceService())->connections($this->bearer($request)));
    }

    public function security(Request $request): Response
    {
        return $this->success((new SelfServiceService())->securityOverview($this->bearer($request)));
    }

    private function bearer(Request $request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (strncasecmp($authorization, 'Bearer ', 7) !== 0 || trim(substr($authorization, 7)) === '') {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        return trim(substr($authorization, 7));
    }

    private function requestId(Request $request): string
    {
        return RequestId::fromRequestCached($request);
    }
}
