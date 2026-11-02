<?php

declare(strict_types=1);

namespace plugin\SandIamC05Business\app\controller;

use LogicException;
use plugin\SandIamC05Business\app\BusinessAuditWriter;
use plugin\SandIamC05Business\app\WorkItem;
use Webman\Http\Request;
use Webman\Http\Response;

final class WorkItemController
{
    public function inspect(Request $request): Response
    {
        $item = $request->sandIamC05WorkItem ?? null;
        $decision = $request->sandIamAuthorization ?? null;
        if (!$item instanceof WorkItem || !is_array($decision)) {
            throw new LogicException('C05 route provider did not receive resolved authorization state');
        }
        $requestId = (string) ($decision['request_id'] ?? '');
        $identityId = (string) ($decision['identity_id'] ?? '');
        $auditId = (new BusinessAuditWriter())->write('allowed', $item, null, $identityId, $requestId);

        return json([
            'allowed' => true,
            'business_audit_id' => $auditId,
            'item' => ['id' => $item->id, 'state' => $item->state, 'version' => $item->version],
            'authorization' => [
                'api_code' => (string) ($decision['api_code'] ?? ''),
                'request_id' => $requestId,
                'scope_checked' => true,
            ],
        ]);
    }
}
