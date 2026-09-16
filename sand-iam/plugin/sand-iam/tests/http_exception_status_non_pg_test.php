<?php

declare(strict_types=1);

namespace Webman\Http {
    class Request
    {
        public function __construct(private array $payload = [])
        {
        }

        public function method(): string
        {
            return 'POST';
        }

        public function uri(): string
        {
            return '/api/sand-iam/v1/auth/register?code=query-secret';
        }

        public function getRealIp(): string
        {
            return '127.0.0.1';
        }

        public function all(): array
        {
            return $this->payload;
        }
    }

    class Response
    {
        public function __construct(
            public int $status = 200,
            public array $headers = [],
            public string $body = '',
        )
        {
        }

        public function withStatus(int $status): static
        {
            $clone = clone $this;
            $clone->status = $status;
            return $clone;
        }
    }
}

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException
    {
    }
}

namespace plugin\sandadmin\app\exception {
    class Handler
    {
    }
}

namespace support {
    final class Log
    {
        public static array $entries = [];

        public static function error(string $message, array $context = []): void
        {
            self::$entries[] = [$message, $context];
        }
    }
}

namespace {
    use plugin\SandIam\app\exception\Handler;
    use plugin\sandadmin\exception\ApiException;
    use support\Log;
    use Webman\Http\Request;

    $currentRequest = null;
    function request(): ?Request
    {
        global $currentRequest;
        return $currentRequest;
    }

    require dirname(__DIR__) . '/app/exception/Handler.php';

    $handler = new Handler();
    $request = new Request([
        'password' => 'password-secret',
        'challenge' => 'challenge-secret',
        'totp' => '123456',
    ]);
    $currentRequest = $request;

    foreach ([400, 401, 403, 409, 500, 503] as $status) {
        $response = $handler->render($request, new ApiException('business failure', $status));
        if ($response->status !== $status) {
            fwrite(STDERR, "ApiException {$status} did not preserve its HTTP status\n");
            exit(1);
        }
        if (str_contains($response->body, 'request_param') || str_contains($response->body, 'password-secret')) {
            fwrite(STDERR, "ApiException response exposed request parameters\n");
            exit(1);
        }
    }

    foreach ([0, 200, 399, 600] as $code) {
        $response = $handler->render($request, new ApiException('invalid status', $code));
        if ($response->status !== 200) {
            fwrite(STDERR, "invalid ApiException code {$code} changed the host HTTP status\n");
            exit(1);
        }
    }

    $response = $handler->render($request, new RuntimeException('unexpected challenge-secret failure', 503));
    if ($response->status !== 200) {
        fwrite(STDERR, "non-ApiException changed the host HTTP status\n");
        exit(1);
    }
    if ($response->body !== '{"code":500,"message":"Server internal error","type":"failed"}') {
        fwrite(STDERR, "non-ApiException response was not sanitized\n");
        exit(1);
    }

    $handler->report(new RuntimeException('unexpected password-secret failure', 503));
    $logs = json_encode(Log::$entries, JSON_UNESCAPED_SLASHES);
    foreach (['password-secret', 'challenge-secret', '123456', 'query-secret', 'request_param'] as $secret) {
        if (str_contains((string) $logs, $secret)) {
            fwrite(STDERR, "exception report exposed {$secret}\n");
            exit(1);
        }
    }
    if (!str_contains((string) $logs, '/api/sand-iam/v1/auth/register')) {
        fwrite(STDERR, "exception report omitted the sanitized request path\n");
        exit(1);
    }

    $config = require dirname(__DIR__) . '/config/exception.php';
    if (($config[''] ?? null) !== Handler::class) {
        fwrite(STDERR, "SandIAM exception config does not use its HTTP status handler\n");
        exit(1);
    }

    echo "SandIAM HTTP exception status checks passed\n";
}
