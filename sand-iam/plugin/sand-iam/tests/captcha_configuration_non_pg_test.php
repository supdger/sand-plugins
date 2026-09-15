<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace support {
    class Response {
        public array $headers = [];
        public function __construct(public array $data) {}
        public function withHeader(string $key, string $value): self { $this->headers[$key] = $value; return $this; }
    }
    class Request {
        public function __construct(private array $query) {}
        public function get(string $key): mixed { return $this->query[$key] ?? null; }
        public function getRealIp(): string { return '192.0.2.1'; }
    }
}
namespace plugin\sandadmin\basic {
    class BaseController { public function success(array $data): \support\Response { return new \support\Response($data); } }
}
namespace plugin\SandIam\app\model {
    class Record {
        public static array $records = [];
        public function __construct(public array $values) {}
        public function __get(string $key): mixed { return $this->values[$key] ?? null; }
        public static function alias(string $alias): Query { return new Query(static::class); }
        public static function where(string $field, mixed $value): Query { return (new Query(static::class))->where($field, $value); }
    }
    class Query {
        private array $filters = [];
        public function __construct(private string $model) {}
        public function join(string $table, string $condition): self { return $this; }
        public function field(string $fields): self { return $this; }
        public function where(string $field, mixed $value): self { $this->filters[$field] = $value; return $this; }
        public function find(): ?Record {
            foreach (Record::$records[$this->model] ?? [] as $row) {
                foreach ($this->filters as $key => $value) if ($row->$key !== $value) continue 2;
                return $row;
            }
            return null;
        }
    }
    final class Application extends Record {}
    final class AuthPolicy extends Record {}
    final class ApplicationExperience extends Record {}
    final class ApplicationNetworkPolicy extends Record {}
}
namespace plugin\SandIam\app\security {
    final class NetworkPolicy { public static function allows(array $policy, string $ip): bool { return false; } }
}
namespace plugin\SandIam\app\service {
    final class AuditWriter { public function write(...$args): void {} }
    final class MessageProviderService {
        public static bool $available = true;
        public static int $calls = 0;
        public function publicChallenge(int $applicationId, string $action): array {
            ++self::$calls;
            if (!self::$available) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_CAPTCHA_UNAVAILABLE', 503);
            return ['kind' => 'turnstile', 'site_key' => 'offline', 'action' => $action, 'application_binding' => "app-$applicationId"];
        }
    }
}
namespace {
    use plugin\SandIam\app\model\{Record, Application, AuthPolicy, ApplicationExperience, ApplicationNetworkPolicy};
    use plugin\SandIam\app\service\MessageProviderService;
    use plugin\SandIam\app\api\controller\AuthController;
    use plugin\sandadmin\exception\ApiException;
    require __DIR__ . '/../app/service/HumanAuthService.php';
    require __DIR__ . '/../app/api/controller/AuthController.php';
    $configuration = ['auth_pepper' => 'offline-only', 'application_experience_enabled' => 1, 'application_network_policy_enabled' => 1];
    function config(string $key, mixed $default = null): mixed {
        global $configuration;
        return $configuration[substr($key, strlen('plugin.sand-iam.app.'))] ?? $default;
    }
    $checks = 0;
    function check(bool $condition): void { global $checks; if (!$condition) throw new \RuntimeException('assertion failed'); ++$checks; }
    $application = new Application(['id' => 12, 'organization_id' => 5, 'organization.code' => 'org',
        'organization.status' => 1, 'application.code' => 'app', 'application.status' => 1]);
    Record::$records[Application::class] = [$application];
    $policy = new AuthPolicy(['application_id' => 12, 'status' => 1, 'require_captcha' => 0, 'registration_enabled' => 1]);
    Record::$records[AuthPolicy::class] = [$policy];
    $query = ['organization_code' => 'org', 'application_code' => 'app', 'action' => 'login'];
    function request(array $query): \support\Response { return (new AuthController())->captchaConfiguration(new \support\Request($query)); }
    function denied(array $query, int $status): void {
        try { request($query); } catch (ApiException $error) { check($error->getCode() === $status); return; }
        throw new \RuntimeException('expected rejection');
    }
    $result = request($query);
    check($result->data === ['required' => false]);
    check($result->headers === ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    check(MessageProviderService::$calls === 0);
    $policy->values['require_captcha'] = 1;
    check(request($query)->data['widget']['application_binding'] === 'app-12');
    check(request(array_replace($query, ['action' => 'register']))->data['widget']['action'] === 'register');
    MessageProviderService::$available = false;
    check(request($query)->data === ['required' => true, 'available' => false]);
    MessageProviderService::$available = true;
    denied(array_replace($query, ['application_code' => 'other']), 401);
    denied(array_replace($query, ['action' => 'other']), 400);
    denied(array_replace($query, ['organization_code' => []]), 400);
    $application->values['organization.status'] = 2;
    denied($query, 401);
    $application->values['organization.status'] = 1;
    $policy->values['registration_enabled'] = 0;
    denied(array_replace($query, ['action' => 'register']), 403);
    Record::$records[ApplicationExperience::class] = [new ApplicationExperience(['application_id' => 12, 'status' => 1, 'login_methods' => ['passkey']])];
    denied($query, 403);
    Record::$records[ApplicationExperience::class] = [];
    Record::$records[ApplicationNetworkPolicy::class] = [new ApplicationNetworkPolicy(['application_id' => 12, 'status' => 1])];
    denied($query, 403);
    echo "Captcha configuration: $checks offline checks passed\n";
}
