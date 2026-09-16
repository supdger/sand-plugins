<?php

declare(strict_types=1);

namespace plugin\SandIamC05Business\app\middleware;

use plugin\SandIamC05Business\app\BusinessAuditWriter;
use plugin\SandIamC05Business\app\WorkItem;
use plugin\sandadmin\exception\ApiException;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class AuthorizationProblemMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $response = $handler($request);
        $exception = $response->exception();
        if (!$exception instanceof ApiException) {
            return $response;
        }

        $code = explode(':', $exception->getMessage(), 2)[0];
        if ($code !== 'SAND_IAM_ROUTE_NOT_REGISTERED') {
            return $response;
        }
        $requestId = trim((string) $request->header('X-Request-Id', ''));
        $item = $request->sandIamC05WorkItem ?? null;
        $auditId = null;
        try {
            $resolved = $item instanceof WorkItem
                ? $item
                : (new \plugin\SandIamC05Business\app\WorkItemRepository())->findOrFail((int) $request->route?->param('id'));
            $auditId = (new BusinessAuditWriter())->write(
                'denied',
                $resolved,
                $code,
                'unknown',
                $requestId,
            );
        } catch (Throwable) {
            // The authorization result remains fail-closed if the business audit store is unavailable.
        }
        return new Response(
            403,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            json_encode(['allowed' => false, 'error' => $code, 'business_audit_id' => $auditId], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
