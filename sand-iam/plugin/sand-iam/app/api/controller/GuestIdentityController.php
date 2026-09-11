<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use plugin\SandIam\app\runtime\IdentityContextProvider;
use plugin\SandIam\app\service\GuestIdentityService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\basic\BaseController;
use support\Request;
use support\Response;

final class GuestIdentityController extends BaseController
{
    public function upsert(Request $request): Response
    {
        $context = trim((string) $request->header('X-SandIAM-Context', $request->post('context', '')));
        $requestId = RequestId::fromRequest($request);
        $claims = (new IdentityContextProvider())->verify($context, 'sand-iam', 'identity.guest.upsert', $requestId, (string) $request->getRealIp(true));
        return $this->success((new GuestIdentityService())->upsert((int) $claims['application_id'], (string) $request->post('external_guest_id', ''), (string) $request->post('display_name', '访客'), (string) $claims['context_id'], $requestId))->withHeader('Cache-Control', 'no-store');
    }
}
