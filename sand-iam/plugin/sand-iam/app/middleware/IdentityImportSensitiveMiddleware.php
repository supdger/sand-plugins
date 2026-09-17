<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use Webman\Http\Response;

/** Keeps uploaded CSV rows and personal identifiers out of generic request/error logs. */
final class IdentityImportSensitiveMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        try { return $handler($request); }
        catch (ApiException $exception) { return $this->failure($request, $this->code($exception->getMessage()), (int) $exception->getCode() ?: 400); }
        catch (\Throwable) { return $this->failure($request, 'SAND_IAM_IMPORT_OPERATION_FAILED', 500); }
    }
    private function failure(Request $request, string $code, int $status): Response
    {
        $candidate = (string) $request->header('X-Request-Id', ''); $requestId = preg_match('/^[A-Za-z0-9_-]{8,96}$/', $candidate) ? $candidate : bin2hex(random_bytes(16)); $admin = $request->header('check_admin', []);
        try { (new AuditWriter())->write('admin', is_array($admin) ? (string) ($admin['id'] ?? 0) : 'redacted', null, null, 'identity_import.preview', 'identity_import_job', null, 'failed', $requestId, ['error_code' => $code]); } catch (\Throwable) { /* never rethrow CSV contents */ }
        $status = min(max($status, 400), 599); return json(['code' => $status, 'msg' => $code])->withStatus($status)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
    private function code(string $value): string { return preg_match('/^SAND_IAM_[A-Z0-9_]{1,96}$/', $value) ? $value : 'SAND_IAM_IMPORT_OPERATION_FAILED'; }
}
