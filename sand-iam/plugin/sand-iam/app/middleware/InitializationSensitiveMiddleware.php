<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** Keeps rejected initialization manifests out of generic request logs. */
final class InitializationSensitiveMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        $requestId = RequestId::fromRequestCached($request);
        try { return $handler($request); }
        catch (ApiException $exception) { return $this->failure($request, $requestId, $this->code($exception->getMessage()), (int) $exception->getCode() ?: 400); }
        catch (\Throwable) { return $this->failure($request, $requestId, 'SAND_IAM_INITIALIZATION_OPERATION_FAILED', 500); }
    }

    private function failure(Request $request, string $requestId, string $code, int $status): Response
    {
        $admin = $request->header('check_admin', []);
        try { (new AuditWriter())->write('admin', is_array($admin) ? (string) ($admin['id'] ?? 0) : 'redacted', null, null, 'initialization.operation', 'initialization_run', null, 'failed', $requestId, ['error_code' => $code]); } catch (\Throwable) {}
        $status = min(max($status, 400), 599);
        return json(['code' => $status, 'msg' => $code])->withStatus($status)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    private function code(string $value): string
    {
        $match = preg_match('/^(SAND_IAM_[A-Z0-9_]{1,96})/', $value, $matches);
        return $match === 1 ? $matches[1] : 'SAND_IAM_INITIALIZATION_OPERATION_FAILED';
    }
}
