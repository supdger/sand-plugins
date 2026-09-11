<?php

declare(strict_types=1);

namespace plugin\SandIam\app\middleware;

use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use support\Log;
use support\Request;
use support\Response;

/** Prevent browsers and intermediaries from retaining portal identities, tokens, or authentication failures. */
final class PortalSensitiveResponseMiddleware
{
    public function process(Request $request, callable $handler): Response
    {
        $requestId = RequestId::fromRequestCached($request);
        try {
            return $this->secure($handler($request));
        } catch (ApiException $exception) {
            return $this->failure($requestId, $this->code($exception->getMessage()), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('SandIAM portal request failed', [
                'request_id' => $requestId,
                'exception_type' => $exception::class,
                'exception_file' => basename($exception->getFile()),
                'exception_line' => $exception->getLine(),
            ]);
            throw $exception;
        }
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    private function failure(string $requestId, string $code, int $status): Response
    {
        $status = min(max($status, 400), 599);
        return $this->secure(json([
            'code' => $status,
            'msg' => $code,
            'request_id' => $requestId,
        ])->withStatus($status));
    }

    private function code(string $value): string
    {
        if (preg_match('/^(SAND_IAM_(?:AUTH|MFA|PASSKEY|FEDERATION)_[A-Z0-9_]{1,96})/', $value, $matches) === 1) {
            return $matches[1];
        }

        return 'SAND_IAM_PORTAL_OPERATION_FAILED';
    }
}
