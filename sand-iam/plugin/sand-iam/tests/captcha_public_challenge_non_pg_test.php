<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}
namespace plugin\SandIam\app\service {
    final class MessageProviderConfigCipher {
        public function decrypt(string $value): array { return json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
    }
}
namespace plugin\SandIam\app\model {
    final class Query {
        public function __construct(private array $rows) {}
        public function where(string $key, mixed $value): self {
            $this->rows = array_filter($this->rows, static fn (object $row): bool => $row->$key === $value);
            return $this;
        }
        public function order(string $key, string $direction): self {
            usort($this->rows, static fn (object $a, object $b): int => $a->$key <=> $b->$key);
            return $this;
        }
        public function select(): array { return $this->rows; }
        public function find(): ?object { return array_values($this->rows)[0] ?? null; }
    }
    class MessageProvider {
        public string $provider_type;
        public string $driver_code;
        public string $encrypted_config;
        public static array $rows = [];
        public static function where(string $key, mixed $value): Query { return (new Query(static::$rows))->where($key, $value); }
    }
    final class MessageProviderApplication extends MessageProvider { public static array $rows = []; }
}
namespace {
    use plugin\SandIam\app\model\MessageProvider;
    use plugin\SandIam\app\model\MessageProviderApplication;
    use plugin\SandIam\app\service\MessageProviderService;
    use plugin\sandadmin\exception\ApiException;
    require __DIR__ . '/../app/service/MessageProviderService.php';
    final class OfflineChallengeDriver {
        public static array $context = [];
        public static function publicChallenge(array $context, array $config): array {
            return ['kind' => 'turnstile', 'site_key' => $config['site_key'], 'action' => $context['action'],
                'application_binding' => 'binding-' . $context['application_id'], 'secret_key' => 'must-not-escape'];
        }
        public static function verify(string $token, array $context, array $config): bool { self::$context = $context; return $token === $config['site_key']; }
        public static function send(string $destination, string $content, array $context, array $config): void { self::$context = $context; }
    }
    $enabled = 1;
    function config(string $key, mixed $default = null): mixed {
        global $enabled;
        return match ($key) {
            'plugin.sand-iam.app.message_provider_enabled' => $enabled,
            'plugin.sand-iam.app.message_drivers' => ['offline' => OfflineChallengeDriver::class],
            default => $default,
        };
    }
    $checks = 0;
    function check(bool $condition): void { global $checks; if (!$condition) throw new \RuntimeException('assertion failed'); ++$checks; }
    function rejected(callable $operation): void {
        try { $operation(); } catch (ApiException $error) { check(in_array($error->getCode(), [400, 503], true)); return; }
        throw new \RuntimeException('expected rejection');
    }
    MessageProvider::$rows = [
        (object) ['id' => 1, 'organization_id' => 5, 'provider_type' => 'captcha', 'status' => 1, 'driver_code' => 'offline', 'encrypted_config' => '{"site_key":"login-key"}'],
        (object) ['id' => 2, 'organization_id' => 5, 'provider_type' => 'captcha', 'status' => 1, 'driver_code' => 'offline', 'encrypted_config' => '{"site_key":"register-key"}'],
    ];
    MessageProviderApplication::$rows = [
        (object) ['id' => 1, 'application_id' => 12, 'organization_id' => 5, 'message_provider_id' => 1, 'status' => 1, 'priority' => 1, 'purposes' => ['login']],
        (object) ['id' => 2, 'application_id' => 12, 'organization_id' => 5, 'message_provider_id' => 2, 'status' => 1, 'priority' => 1, 'purposes' => ['register']],
    ];
    $service = new MessageProviderService();
    $login = $service->publicChallenge(12, 'login');
    check($login === ['kind' => 'turnstile', 'site_key' => 'login-key', 'action' => 'login', 'application_binding' => 'binding-12']);
    check($service->publicChallenge(12, 'register')['site_key'] === 'register-key');
    $service->verifyCaptcha(12, 'login-key', 'login');
    $service->verifyCaptcha(12, 'login-key', 'login', ['application_id' => 99, 'action' => 'register', 'ip' => '192.0.2.1']);
    check(OfflineChallengeDriver::$context === ['application_id' => 12, 'action' => 'login', 'ip' => '192.0.2.1']);
    MessageProviderApplication::$rows[0]->template_codes = ['notice' => 'configured-template', 'login' => 'fallback-template'];
    check($service->sendMessage(12, 'captcha', 'login', 'destination', 'content', ['application_id' => 99, 'provider_type' => 'wrong', 'template_code' => 'wrong-template', 'purpose' => 'notice', 'trace' => 'trace']));
    check(OfflineChallengeDriver::$context === ['application_id' => 12, 'provider_type' => 'captcha', 'template_code' => 'configured-template', 'purpose' => 'notice', 'trace' => 'trace']);
    check($service->sendMessage(12, 'captcha', 'login', 'destination', 'content', ['purpose' => 'unknown']));
    check(OfflineChallengeDriver::$context['template_code'] === 'fallback-template');
    $provider = new MessageProvider();
    $provider->provider_type = 'captcha';
    $provider->driver_code = 'offline';
    $provider->encrypted_config = '{"site_key":"login-key"}';
    $service->test($provider, 'login-key', ['test' => false, 'trace' => 'trace']);
    check(OfflineChallengeDriver::$context === ['test' => true, 'trace' => 'trace']);
    $provider->provider_type = 'email';
    $service->test($provider, 'destination', ['test' => false, 'provider_type' => 'wrong', 'trace' => 'trace']);
    check(OfflineChallengeDriver::$context === ['test' => true, 'provider_type' => 'email', 'trace' => 'trace']);
    rejected(static fn () => $service->verifyCaptcha(12, 'register-key', 'login'));
    rejected(static fn () => $service->publicChallenge(13, 'login'));
    rejected(static fn () => $service->publicChallenge(12, 'other'));
    MessageProvider::$rows[0]->organization_id = 99;
    rejected(static fn () => $service->publicChallenge(12, 'login'));
    MessageProvider::$rows[0]->organization_id = 5;
    MessageProvider::$rows[0]->status = 2;
    rejected(static fn () => $service->publicChallenge(12, 'login'));
    MessageProvider::$rows[0]->status = 1;
    MessageProviderApplication::$rows[0]->status = 2;
    rejected(static fn () => $service->publicChallenge(12, 'login'));
    $enabled = 0;
    rejected(static fn () => $service->publicChallenge(12, 'register'));
    echo "Captcha public projection: $checks checks passed; no database or network used\n";
}
