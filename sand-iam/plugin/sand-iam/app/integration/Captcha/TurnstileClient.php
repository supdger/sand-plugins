<?php

declare(strict_types=1);

namespace plugin\SandIam\app\integration\Captcha;

use Closure;
use plugin\sandadmin\exception\ApiException;

final class TurnstileClient
{
    public function __construct(private readonly ?Closure $transport = null) {}

    public static function publicChallenge(array $context, array $config): array
    {
        $action = $context['action'] ?? null;
        $applicationId = $context['application_id'] ?? null;
        if (!is_int($applicationId) || $applicationId < 1 || !in_array($action, ['login', 'register'], true)) {
            throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID', 400);
        }
        if (!is_string($config['site_key'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $config['site_key'])
            || !is_string($config['secret_key'] ?? null) || $config['secret_key'] === '' || strlen($config['secret_key']) > 4096
            || !is_array($config['hostnames'] ?? null) || $config['hostnames'] === [] || count($config['hostnames']) > 100) {
            throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID', 400);
        }
        foreach ($config['hostnames'] as $hostname) {
            if (!is_string($hostname) || strlen($hostname) > 253
                || !preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/D', $hostname)) {
                throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_CONFIG_INVALID', 400);
            }
        }
        return [
            'kind' => 'turnstile', 'site_key' => $config['site_key'], 'action' => $action,
            'application_binding' => hash_hmac('sha256', 'sand-iam:' . $applicationId . ':' . $action, $config['secret_key']),
        ];
    }

    public static function verify(string $token, array $context, array $config): bool
    {
        return (new self())->validate($token, $context, $config);
    }

    public function validate(string $token, array $context, array $config): bool
    {
        $challenge = self::publicChallenge($context, $config);
        if ($token === '' || strlen($token) > 2048) return false;
        try {
            $response = ($this->transport ?? self::transmit(...))([
                'secret' => $config['secret_key'], 'response' => $token,
            ]);
            if (!is_array($response) || ($response['status'] ?? null) !== 200
                || !is_string($response['body'] ?? null) || strlen($response['body']) > 65536) {
                throw new \RuntimeException();
            }
            $result = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($result) || !is_bool($result['success'] ?? null)) throw new \RuntimeException();
        } catch (\Throwable) {
            throw new ApiException('SAND_IAM_CAPTCHA_UNAVAILABLE', 503);
        }
        return $result['success'] === true
            && is_string($result['hostname'] ?? null) && in_array($result['hostname'], $config['hostnames'], true)
            && ($result['action'] ?? null) === $challenge['action']
            && is_string($result['cdata'] ?? null) && hash_equals($challenge['application_binding'], $result['cdata']);
    }

    private static function transmit(array $body): array
    {
        $curl = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if ($curl === false) throw new \RuntimeException();
        $response = '';
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($body),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                    if (strlen($response) + strlen($chunk) > 65536) return 0;
                    $response .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (curl_exec($curl) === false) throw new \RuntimeException();
            return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $response];
        } finally {
            curl_close($curl);
        }
    }
}
