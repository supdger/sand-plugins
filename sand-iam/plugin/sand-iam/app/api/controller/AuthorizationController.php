<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\runtime\ApplicationAuthorizationService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class AuthorizationController extends BaseController
{
    public function decide(Request $request): Response
    {
        $attributes = $request->post('attributes', []);
        if (!is_array($attributes)) {
            throw new ApiException('SAND_IAM_VALIDATION_ERROR: 授权属性必须是 JSON 对象', 400);
        }
        $decision = (new ApplicationAuthorizationService())->decide(
            $this->bearerToken($request),
            trim((string) $request->post('organization_code', '')),
            trim((string) $request->post('application_code', '')),
            trim((string) $request->post('api_code', '')),
            trim((string) $request->post('api_version', 'v1')),
            $attributes,
            RequestId::fromRequest($request),
        );
        return $this->success($decision)->withHeader('Cache-Control', 'no-store');
    }

    private function bearerToken(Request $request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        $token = trim(substr($authorization, 7));
        if ($token === '') {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }
        return $token;
    }
}
