<?php

declare(strict_types=1);

namespace plugin\SandIam\app\exception;

use plugin\sandadmin\app\exception\Handler as SandAdminHandler;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\exception\SystemException;
use support\Log;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

final class Handler extends SandAdminHandler
{
    public function report(Throwable $exception)
    {
        if ($exception instanceof ApiException || $exception instanceof SystemException) {
            return;
        }

        $context = [
            'exception_type' => $exception::class,
            'exception_file' => basename($exception->getFile()),
            'exception_line' => $exception->getLine(),
        ];
        if ($request = \request()) {
            $uri = (string) $request->uri();
            $context['request_method'] = $request->method();
            $context['request_path'] = explode('?', $uri, 2)[0];
            $context['client_ip'] = $request->getRealIp();
        }

        Log::error('SandIAM request failed', $context);
    }

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof ApiException) {
            $code = (int) $exception->getCode();
            $response = $this->response($code !== 0 ? $code : 500, $exception->getMessage());
            if ($code >= 400 && $code <= 599) {
                return $response->withStatus($code);
            }

            return $response;
        }

        if ($exception instanceof SystemException) {
            return $this->response(403, '权限不足，无法访问或操作')->withStatus(403);
        }

        return $this->response(500, 'Server internal error');
    }

    private function response(int $code, string $message): Response
    {
        return new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], json_encode([
            'code' => $code,
            'message' => $message,
            'type' => 'failed',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
