<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace {
    use plugin\SandIam\app\service\SessionTokenResponseReplayCipher;
    use plugin\sandadmin\exception\ApiException;

    // behavior-test-gate: observable-behavior

    function config(string $key, mixed $default = null): mixed
    {
        return $key === 'plugin.sand-iam.app.auth_pepper'
            ? 'test-only-session-response-replay-pepper-with-sufficient-entropy'
            : $default;
    }

    require_once dirname(__DIR__) . '/app/service/SessionTokenResponseReplayCipher.php';

    function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }

    function expectApiException(callable $callback, string $message, int $code): void
    {
        try {
            $callback();
        } catch (ApiException $exception) {
            assertTrue($exception->getMessage() === $message, 'unexpected error: ' . $exception->getMessage());
            assertTrue($exception->getCode() === $code, 'unexpected status: ' . $exception->getCode());
            return;
        }
        throw new \RuntimeException("expected {$message}");
    }

    $cipher = new SessionTokenResponseReplayCipher();
    $recoverySecret = 'siam_rt_' . str_repeat('a', 64);
    $requestId = 'session-response-loss-001';
    $context = 'refresh';
    $response = [
        'session_id' => 42,
        'access_token' => 'siam_at_' . str_repeat('b', 64),
        'refresh_token' => 'siam_rt_' . str_repeat('c', 64),
        'expires_in' => 900,
    ];

    $sealedAt = time();
    $envelope = $cipher->seal($response, $recoverySecret, $requestId, $context, 30);
    assertTrue(str_starts_with($envelope, 'v1.'), 'ciphertext envelope version is missing');
    assertTrue(!str_contains($envelope, $recoverySecret), 'recovery secret leaked into ciphertext');
    assertTrue(!str_contains($envelope, $response['access_token']), 'access token leaked into ciphertext');
    assertTrue(!str_contains($envelope, $response['refresh_token']), 'new refresh token leaked into ciphertext');
    assertTrue($cipher->open($envelope, $recoverySecret, $requestId, $context, $sealedAt) === $response, 'same-request response recovery failed');

    foreach ([
        ['siam_rt_' . str_repeat('d', 64), $requestId, $context],
        [$recoverySecret, 'session-response-loss-002', $context],
        [$recoverySecret, $requestId, 'passkey-authentication-finish'],
    ] as [$wrongSecret, $wrongRequestId, $wrongContext]) {
        expectApiException(
            static fn (): array => $cipher->open($envelope, $wrongSecret, $wrongRequestId, $wrongContext, $sealedAt),
            'SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE',
            401,
        );
    }

    $last = substr($envelope, -1);
    $tampered = substr($envelope, 0, -1) . ($last === 'A' ? 'B' : 'A');
    expectApiException(
        static fn (): array => $cipher->open($tampered, $recoverySecret, $requestId, $context, $sealedAt),
        'SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE',
        401,
    );
    expectApiException(
        static fn (): array => $cipher->open($envelope, $recoverySecret, $requestId, $context, $sealedAt + 31),
        'SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE',
        401,
    );
    foreach ([0, 301] as $invalidTtl) {
        expectApiException(
            static fn (): string => $cipher->seal($response, $recoverySecret, $requestId, $context, $invalidTtl),
            'SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE',
            503,
        );
    }
    expectApiException(
        static fn (): string => $cipher->seal($response, $recoverySecret, $requestId, 'invalid context', 30),
        'SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE',
        503,
    );

    echo "session token response replay cipher behavior passed\n";
}
