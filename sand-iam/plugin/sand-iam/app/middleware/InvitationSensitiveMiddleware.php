<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\service\AuditWriter;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use Webman\Http\Response;

/** Prevents invitation targets, tokens and chosen passwords from reaching generic error logs. */
final class InvitationSensitiveMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        try { return $handler($request); }
        catch (ApiException $exception) { return $this->failure($request, $this->safeCode($exception->getMessage()), (int) $exception->getCode() ?: 400); }
        catch (\Throwable) { return $this->failure($request, 'SAND_IAM_INVITATION_OPERATION_FAILED', 500); }
    }
    private function failure(Request $request, string $code, int $status): Response
    {
        $candidate = (string) $request->header('X-Request-Id', ''); $requestId = preg_match('/^[A-Za-z0-9_-]{8,96}$/', $candidate) ? $candidate : bin2hex(random_bytes(16));
        $admin = $request->header('check_admin', []); $actorType = is_array($admin) && isset($admin['id']) ? 'admin' : 'application_user'; $actor = $actorType === 'admin' ? (string) $admin['id'] : 'redacted';
        try { (new AuditWriter())->write($actorType, $actor, null, null, 'identity_invitation.operation', 'identity_invitation', null, 'failed', $requestId, ['error_code' => $code]); } catch (\Throwable) { /* never rethrow request secrets */ }
        $status = min(max($status, 400), 599); return json(['code' => $status, 'msg' => $code])->withStatus($status)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
    private function safeCode(string $value): string { return preg_match('/^SAND_IAM_[A-Z0-9_]{1,96}$/', $value) ? $value : 'SAND_IAM_INVITATION_OPERATION_FAILED'; }
}
