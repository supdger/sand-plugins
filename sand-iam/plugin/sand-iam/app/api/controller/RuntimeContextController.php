<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\runtime\EnvironmentReferenceVerifier;
use plugin\SandIam\app\runtime\IdentityContextProvider;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

final class RuntimeContextController extends BaseController
{
    public function issue(Request $request): Response
    {
        $credential = trim((string) $request->header('Authorization', ''));
        $credential = str_starts_with($credential, 'Bearer ') ? substr($credential, 7) : (string) $request->post('credential', '');
        $actions = $request->post('actions', []);
        if (!is_array($actions)) {
            throw new ApiException('SAND_IAM_SERVICE_ACTION_FORBIDDEN: actions must be an array', 403);
        }
        return $this->success((new IdentityContextProvider())->issue($credential, trim((string) $request->post('audience', '')), $actions, is_array($request->post('subject_scope', null)) ? $request->post('subject_scope') : null, (string) $request->header('X-Request-Id', '')));
    }

    public function verify(Request $request): Response
    {
        return $this->success((new IdentityContextProvider())->verify((string) $request->post('context', ''), trim((string) $request->post('audience', '')), trim((string) $request->post('action', ''))));
    }

    public function verifyEnvironment(Request $request): Response
    {
        return $this->success((new EnvironmentReferenceVerifier())->verifyEnvironmentReference((int) $request->post('application_id', 0), (int) $request->post('environment_id', 0)));
    }
}
