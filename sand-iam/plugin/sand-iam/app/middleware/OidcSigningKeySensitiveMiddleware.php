<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** Prevents security-operation errors from being cached by admin clients or proxies. */
final class OidcSigningKeySensitiveMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        $requestId = RequestId::fromRequestCached($request);
        try { return $handler($request)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache'); }
        catch (ApiException $exception) { return $this->failure($request, $requestId, $exception->getMessage(), (int) $exception->getCode() ?: 400); }
        catch (\Throwable) { return $this->failure($request, $requestId, 'SAND_IAM_OIDC_SIGNING_KEY_OPERATION_FAILED', 500); }
    }

    private function failure(Request $request, string $requestId, string $message, int $status): Response
    {
        $code = preg_match('/^(SAND_IAM_[A-Z0-9_]{1,96})/', $message, $match) === 1 ? $match[1] : 'SAND_IAM_OIDC_SIGNING_KEY_OPERATION_FAILED';
        $admin = $request->header('check_admin', []);
        try { (new AuditWriter())->write('admin', is_array($admin) ? (string) ($admin['id'] ?? 0) : 'redacted', null, null, 'oidc.signing_key.operation', 'oidc_signing_key', null, 'failed', $requestId, ['error_code' => $code]); } catch (\Throwable) {}
        $status = min(max($status, 400), 599);
        return json(['code' => $status, 'msg' => $code])->withStatus($status)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
