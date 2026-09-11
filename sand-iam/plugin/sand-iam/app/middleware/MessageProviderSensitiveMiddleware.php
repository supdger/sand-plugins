<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\model\MessageProvider;
use plugin\SandIam\app\service\AuditWriter;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Request;
use support\Response;

/** Keeps provider config, test destinations and captcha tokens out of error reporting. */
final class MessageProviderSensitiveMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        $requestId = RequestId::fromRequestCached($request);
        try {
            return $handler($request);
        } catch (ApiException $exception) {
            return $this->failure($request, $requestId, $this->safeCode($exception->getMessage()), (int) $exception->getCode() ?: 400);
        } catch (\Throwable) {
            return $this->failure($request, $requestId, 'SAND_IAM_MESSAGE_PROVIDER_OPERATION_FAILED', 500);
        }
    }

    private function failure(Request $request, string $requestId, string $code, int $status): Response
    {
        $providerId = (int) $request->post('id', 0);
        $provider = $providerId > 0 ? MessageProvider::find($providerId) : null;
        try {
            (new AuditWriter())->write('admin', 'redacted', $provider ? (int) $provider->organization_id : null, null, 'message_provider.sensitive_operation', 'message_provider', $providerId ?: null, 'failed', $requestId, ['error_code' => $code]);
        } catch (\Throwable) {
            // The error boundary must never rethrow request secrets.
        }
        $status = min(max($status, 400), 599);
        return json(['code' => $status, 'msg' => $code])->withStatus($status)->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    private function safeCode(string $value): string
    {
        return preg_match('/^SAND_IAM_[A-Z0-9_]{1,96}$/', $value) ? $value : 'SAND_IAM_MESSAGE_PROVIDER_OPERATION_FAILED';
    }
}
