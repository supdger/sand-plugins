<?php

declare(strict_types=1);

namespace support {
    final class Request {
        public function __construct(private array $body) {}
        public function post(): array { return $this->body; }
        public function getRealIp(): string { return '192.0.2.10'; }
    }
    final class Response {}
}
namespace plugin\sandadmin\basic {
    class BaseController {
        protected function success(mixed $data): \support\Response { return new \support\Response(); }
    }
}
namespace plugin\SandIam\app\service {
    final class RequestId {
        public static function fromRequestCached(\support\Request $request): string { return 'verification-input-001'; }
    }
    final class HumanAuthService {
        public static array $calls = [];
        public function requestVerification(array $payload, string $requestId): void {
            self::$calls[] = [$payload, $requestId];
        }
    }
}
namespace {
    require dirname(__DIR__) . '/app/api/controller/AuthController.php';

    use plugin\SandIam\app\api\controller\AuthController;
    use plugin\SandIam\app\service\HumanAuthService;

    function verifyInput(bool $condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }

    $controller = new AuthController();
    foreach ([true, 1, 'true'] as $forged) {
        $controller->requestVerification(new \support\Request([
            'identifier' => 'user', 'channel' => 'email', 'purpose' => 'password_reset',
            '_password_reset_endpoint' => $forged, '_ip' => '198.51.100.1',
        ]));
        [$payload, $requestId] = HumanAuthService::$calls[array_key_last(HumanAuthService::$calls)];
        verifyInput($payload['_password_reset_endpoint'] === false, 'public verification endpoint trusted caller reset marker');
        verifyInput($payload['_ip'] === '192.0.2.10', 'caller replaced transport IP');
        verifyInput($payload['purpose'] === 'password_reset', 'service must reject unsupported purpose, not silently change it');
        verifyInput($requestId === 'verification-input-001', 'request identity changed');
    }
    $controller->requestVerification(new \support\Request(['identifier' => 'user', 'channel' => 'phone', 'purpose' => 'phone_verify']));
    [$normal] = HumanAuthService::$calls[array_key_last(HumanAuthService::$calls)];
    verifyInput($normal['purpose'] === 'phone_verify' && $normal['channel'] === 'phone', 'normal verification changed');
    $controller->forgotPassword(new \support\Request(['identifier' => 'user', 'channel' => 'email',
        'purpose' => 'email_verify', '_password_reset_endpoint' => false]));
    [$reset] = HumanAuthService::$calls[array_key_last(HumanAuthService::$calls)];
    verifyInput($reset['purpose'] === 'password_reset' && $reset['_password_reset_endpoint'] === true, 'dedicated reset endpoint lost authority');
    echo "verification endpoint input non-PG tests: PASS\n";
}
