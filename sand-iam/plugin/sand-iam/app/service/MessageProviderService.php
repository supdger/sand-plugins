<?php

declare(strict_types=1);

namespace plugin\SandIam\app\service;

use plugin\SandIam\app\model\MessageProvider;
use plugin\SandIam\app\model\MessageProviderApplication;
use plugin\sandadmin\exception\ApiException;

final class MessageProviderService
{
    public function __construct(private readonly MessageProviderConfigCipher $cipher = new MessageProviderConfigCipher())
    {
    }

    /**
     * @param array<string,mixed> $context
     * @return bool false means no database-backed provider is mounted
     */
    public function sendCode(int $applicationId, string $channel, string $destination, string $code, array $context): bool
    {
        $type = $channel === 'phone' ? 'sms' : $channel;
        return $this->sendMessage($applicationId, $type, 'verification', $destination, $code, $context);
    }

    /** @param array<string,mixed> $context */
    public function sendMessage(int $applicationId, string $type, string $mountPurpose, string $destination, string $content, array $context = []): bool
    {
        if ((int) config('plugin.sand-iam.app.message_provider_enabled', 0) !== 1) return false;
        $resolved = $this->provider($applicationId, $type, $mountPurpose);
        if ($resolved === null) return false;
        [$provider, $mount] = $resolved;
        $driver = $this->driver((string) $provider->driver_code, 'send');
        $templateCodes = is_array($mount->template_codes ?? null) ? $mount->template_codes : [];
        $templatePurpose = (string) ($context['purpose'] ?? $mountPurpose);
        $driver::send($destination, $content, $context + ['application_id' => $applicationId, 'provider_type' => $type, 'template_code' => (string) ($templateCodes[$templatePurpose] ?? $templateCodes[$mountPurpose] ?? '')], $this->cipher->decrypt((string) $provider->encrypted_config));
        return true;
    }

    /** @param array<string,mixed> $context */
    public function verifyCaptcha(int $applicationId, string $token, string $action, array $context = []): void
    {
        if ((int) config('plugin.sand-iam.app.message_provider_enabled', 0) !== 1) throw new ApiException('SAND_IAM_CAPTCHA_UNAVAILABLE', 503);
        if ($token === '') throw new ApiException('SAND_IAM_CAPTCHA_REQUIRED', 400);
        $resolved = $this->provider($applicationId, 'captcha', $action);
        if ($resolved === null) throw new ApiException('SAND_IAM_CAPTCHA_UNAVAILABLE', 503);
        [$provider] = $resolved;
        $driver = $this->driver((string) $provider->driver_code, 'verify');
        $accepted = $driver::verify($token, $context + ['application_id' => $applicationId, 'action' => $action], $this->cipher->decrypt((string) $provider->encrypted_config));
        if ($accepted !== true) throw new ApiException('SAND_IAM_CAPTCHA_INVALID', 400);
    }

    /** @param array<string,mixed> $context */
    public function test(MessageProvider $provider, string $destinationOrToken, array $context = []): void
    {
        $config = $this->cipher->decrypt((string) $provider->encrypted_config);
        if ((string) $provider->provider_type === 'captcha') {
            $driver = $this->driver((string) $provider->driver_code, 'verify');
            if ($driver::verify($destinationOrToken, $context + ['test' => true], $config) !== true) throw new ApiException('SAND_IAM_CAPTCHA_INVALID', 400);
            return;
        }
        $driver = $this->driver((string) $provider->driver_code, 'send');
        $driver::send($destinationOrToken, (string) random_int(10_000_000, 99_999_999), $context + ['test' => true, 'provider_type' => (string) $provider->provider_type], $config);
    }

    /** @return null|array{0:MessageProvider,1:MessageProviderApplication} */
    private function provider(int $applicationId, string $type, string $purpose): ?array
    {
        $mounts = MessageProviderApplication::where('application_id', $applicationId)->where('status', 1)->order('priority', 'asc')->order('id', 'asc')->select();
        foreach ($mounts as $mount) {
            $purposes = is_array($mount->purposes ?? null) ? $mount->purposes : [];
            if (!in_array($purpose, $purposes, true) && !in_array('*', $purposes, true)) continue;
            $provider = MessageProvider::where('id', (int) $mount->message_provider_id)->where('provider_type', $type)->where('status', 1)->find();
            if ($provider !== null && (int) $provider->organization_id === (int) $mount->organization_id && (string) ($provider->encrypted_config ?? '') !== '') return [$provider, $mount];
        }
        return null;
    }

    /** @return class-string */
    private function driver(string $code, string $method): string
    {
        $registry = config('plugin.sand-iam.app.message_drivers', []);
        if (is_string($registry)) $registry = json_decode($registry, true);
        $class = is_array($registry) && is_string($registry[$code] ?? null) ? $registry[$code] : '';
        if ($class === '' || !class_exists($class) || !is_callable([$class, $method])) throw new ApiException('SAND_IAM_MESSAGE_PROVIDER_UNAVAILABLE', 503);
        return $class;
    }
}
