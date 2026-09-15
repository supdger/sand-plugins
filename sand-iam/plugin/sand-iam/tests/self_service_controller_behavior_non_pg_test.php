<?php
declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace support {
    class Request {
        public function __construct(private array $body, private string $authorization = 'Bearer user-token') {}
        public function post(string $key, mixed $default = null): mixed { return array_key_exists($key, $this->body) ? $this->body[$key] : $default; }
        public function header(string $key, mixed $default = null): mixed { return $key === 'Authorization' ? $this->authorization : $default; }
    }
    class Response { public function __construct(public mixed $data) {} }
}
namespace plugin\sandadmin\basic {
    class BaseController { protected function success(mixed $data, string $message = ''): \support\Response { return new \support\Response($data); } }
}
namespace plugin\SandIam\app\service {
    class RequestId { public static function fromRequestCached(\support\Request $request): string { return 'profile-input-test'; } }
    class SelfServiceService {
        public static array $writes = [];
        public function updateProfile(string $token, string $name, string $requestId): array {
            self::$writes[] = [$token, $name, $requestId];
            return ['display_name' => $name];
        }
    }
}
namespace {
    use plugin\SandIam\app\api\controller\SelfServiceController;
    use plugin\SandIam\app\service\SelfServiceService;
    use plugin\sandadmin\exception\ApiException;
    require dirname(__DIR__) . '/app/api/controller/SelfServiceController.php';
    $controller = new SelfServiceController();
    foreach ([123, true, false, null, 1.5, [], ['name' => 'wrong shape']] as $value) {
        try {
            $controller->updateProfile(new \support\Request(['display_name' => $value]));
            throw new \RuntimeException('non-text display name accepted');
        } catch (ApiException $error) {
            if ($error->getCode() !== 400) throw $error;
        }
        if (SelfServiceService::$writes !== []) throw new \RuntimeException('invalid input reached profile write service');
    }
    try {
        $controller->updateProfile(new \support\Request(['display_name' => []], ''));
        throw new \RuntimeException('missing bearer accepted');
    } catch (ApiException $error) {
        if ($error->getCode() !== 401) throw $error;
    }
    $result = $controller->updateProfile(new \support\Request(['display_name' => '  用户名称  ']));
    if ($result->data['display_name'] !== '  用户名称  '
        || SelfServiceService::$writes !== [['user-token', '  用户名称  ', 'profile-input-test']]) {
        throw new \RuntimeException('valid text or authenticated request context changed');
    }
    echo "Self service controller input behavior PASS (offline)\n";
}
