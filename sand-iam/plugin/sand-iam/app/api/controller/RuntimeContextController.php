<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\runtime\IdentityContextProvider;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class RuntimeContextController extends BaseController
{
    public function issue(Request $request): Response
    {
        $actions = $request->post('actions', []);
        if (!is_array($actions)) {
            throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: actions must be an array', 403);
        }
        return $this->success((new IdentityContextProvider())->issue($this->bearerCredential($request), trim((string) $request->post('audience', '')), $actions, is_array($request->post('subject_scope', null)) ? $request->post('subject_scope') : null, RequestId::fromRequest($request), (string) $request->getRealIp(true), trim((string) $request->post('service_code', ''))))
            ->withHeader('Cache-Control', 'no-store');
    }

    public function verify(Request $request): Response
    {
        return $this->success((new IdentityContextProvider())->verify((string) $request->post('context', ''), trim((string) $request->post('audience', '')), trim((string) $request->post('action', '')), RequestId::fromRequest($request), (string) $request->getRealIp(true)))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function bearerCredential(Request $request): string
    {
        $authorization = trim((string) $request->header('Authorization', ''));
        if (!str_starts_with($authorization, 'Bearer ')) {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }

        $credential = trim(substr($authorization, 7));
        if ($credential === '') {
            throw new ApiException('SAND_IAM_AUTHENTICATION_FAILED', 401);
        }

        return $credential;
    }
}
