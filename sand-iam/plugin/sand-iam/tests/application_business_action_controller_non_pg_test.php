<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\sandadmin\service {
    #[\Attribute(\Attribute::TARGET_METHOD)] final class Permission { public function __construct(string $name, string $code) {} }
}

namespace plugin\SandIam\app\admin\support {
    use support\Request;
    use support\Response;
    abstract class ApplicationResourceController
    {
        protected function find(Request $request): object { return (object) []; }
        protected function audit(string $verb, int $id, Request $request): void {}
        protected function access(): object { return new class { public function assertApplication(int $id): void {} }; }
        public function save(Request $request): Response { return new Response(); }
        public function update(Request $request): Response { return new Response(); }
        public function disable(Request $request): Response { return new Response(); }
        public function index(Request $request): Response { return new Response(); }
        public function read(Request $request): Response { return new Response(); }
        protected function success(mixed $data = null, string $message = ''): Response { return new Response(); }
    }
}

namespace plugin\SandIam\app\model {
    final class Application {}
    final class ApplicationBusinessAction {}
    final class ApiResource {}
    final class Policy {}
}

namespace support {
    class Request {}
    class Response {}
}

namespace {
    require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';
    require_once dirname(__DIR__) . '/app/admin/controller/ApplicationBusinessActionController.php';

    use plugin\SandIam\app\admin\controller\ApplicationBusinessActionController;
    use plugin\sandadmin\exception\ApiException;

    function t28Controller(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }

    $controller = new ApplicationBusinessActionController();
    $method = new ReflectionMethod($controller, 'throwWriteFailure');
    try {
        $method->invoke($controller, new RuntimeException('SQLSTATE[23505]: duplicate key value violates unique constraint'));
        t28Controller(false, 'duplicate action write did not fail');
    } catch (ApiException $exception) {
        t28Controller($exception->getCode() === 409, 'duplicate action write did not return conflict status');
        t28Controller(str_starts_with($exception->getMessage(), 'SAND_IAM_APPLICATION_ACTION_CONFLICT:'), 'duplicate action write leaked SQL instead of stable code');
        t28Controller(!str_contains(strtolower($exception->getMessage()), 'sqlstate'), 'duplicate action write leaked SQL text');
    }

    echo "application business action controller non-PG checks passed\n";
}
