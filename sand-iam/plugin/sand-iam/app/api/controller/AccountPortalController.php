<?php

declare(strict_types=1);

namespace plugin\SandIam\app\api\controller;

use support\Response;

/**
 * Serves the two reviewed, immutable portal assets when plugin static serving
 * is disabled by the host. No request data is mapped to a filesystem path.
 */
final class AccountPortalController
{
    public function index(): Response
    {
        return $this->asset('index.html', 'text/html; charset=utf-8', true);
    }

    public function script(): Response
    {
        return $this->asset('account.js', 'text/javascript; charset=utf-8', false);
    }

    private function asset(string $name, string $contentType, bool $document): Response
    {
        $path = dirname(__DIR__, 3) . '/public/account/' . $name;
        $body = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($body)) {
            return response('账户门户资源不可用', 503, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'no-store',
                'Referrer-Policy' => 'no-referrer',
            ]);
        }
        $headers = [
            'Content-Type' => $contentType,
            'Cache-Control' => $document ? 'no-store' : 'public, max-age=300',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($document) {
            // 登录外观只允许已校验的 HTTPS Logo；其余资源仍限制为当前门户。
            $headers['Content-Security-Policy'] = "default-src 'self'; connect-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
        }
        return response($body, 200, $headers);
    }
}
