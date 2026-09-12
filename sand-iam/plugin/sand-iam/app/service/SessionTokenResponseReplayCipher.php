<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\sandadmin\exception\ApiException;

/**
 * Seals a short-lived session-token response for same-request transport recovery.
 * The database ciphertext is useless without the consumed one-time credential,
 * the request id, and the deployment pepper.
 */
final class SessionTokenResponseReplayCipher
{
    /** @param array<string,mixed> $response */
    public function seal(array $response, string $recoverySecret, string $requestId, string $context, int $ttlSeconds): string
    {
        $this->requireSodium('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
        $this->assertInputs($recoverySecret, $requestId, $context);
        if ($ttlSeconds < 1 || $ttlSeconds > 300) throw new ApiException('SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE', 503);
        $payload = json_encode([
            'expires_at' => time() + $ttlSeconds,
            'response' => $response,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $payload,
            $this->aad($context, $requestId),
            $nonce,
            $this->key($recoverySecret, $requestId, $context),
        );
        return 'v1.' . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    /** @return array<string,mixed> */
    public function open(string $envelope, string $recoverySecret, string $requestId, string $context, ?int $now = null): array
    {
        try {
            $this->requireSodium('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt');
            $this->assertInputs($recoverySecret, $requestId, $context);
            if (!str_starts_with($envelope, 'v1.')) throw new \RuntimeException('invalid envelope');
            $encoded = substr($envelope, 3);
            $wire = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
            $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if ($wire === false || strlen($wire) < $nonceSize + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
                throw new \RuntimeException('invalid ciphertext');
            }
            $canonical = rtrim(strtr(base64_encode($wire), '+/', '-_'), '=');
            if (!hash_equals($canonical, $encoded)) throw new \RuntimeException('non-canonical ciphertext');
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($wire, $nonceSize),
                $this->aad($context, $requestId),
                substr($wire, 0, $nonceSize),
                $this->key($recoverySecret, $requestId, $context),
            );
            if ($plain === false) throw new \RuntimeException('authentication failed');
            $payload = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
            $response = is_array($payload) ? ($payload['response'] ?? null) : null;
            if (!is_array($response) || !is_int($payload['expires_at'] ?? null) || $payload['expires_at'] < ($now ?? time())) {
                throw new \RuntimeException('expired or malformed response');
            }
            return $response;
        } catch (\Throwable $exception) {
            if ($exception instanceof ApiException) throw $exception;
            throw new ApiException('SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE', 401);
        }
    }

    private function assertInputs(string $recoverySecret, string $requestId, string $context): void
    {
        if ($recoverySecret === '' || $requestId === '' || preg_match('/^[a-z0-9._-]{1,64}$/', $context) !== 1) {
            throw new ApiException('SAND_IAM_AUTH_SESSION_RETRY_UNAVAILABLE', 503);
        }
    }

    private function aad(string $context, string $requestId): string
    {
        return 'sand-iam:session-response-retry:v1|' . $context . '|' . $requestId;
    }

    private function key(string $recoverySecret, string $requestId, string $context): string
    {
        $pepper = (string) config('plugin.sand-iam.app.auth_pepper', '');
        if ($pepper === '') throw new ApiException('SAND_IAM_AUTH_CONFIGURATION_UNAVAILABLE', 503);
        return hash_hmac('sha256', 'session-response-retry-key:v1|' . $context . '|' . $requestId . '|' . $recoverySecret, $pepper, true);
    }

    private function requireSodium(string $function): void
    {
        if (!function_exists($function)) throw new ApiException('SAND_IAM_AUTH_CONFIGURATION_UNAVAILABLE', 503);
    }
}
