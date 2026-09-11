<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\sandadmin\service {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Permission { public function __construct(string $name, string $code) {} }
}

namespace support {
    final class Response { public function __construct(public mixed $data = null) {} }

    final class Request
    {
        /** @param array<string,mixed> $payload @param array<string,mixed> $headers */
        public function __construct(private array $payload, private array $headers) {}
        /** @return array<string,mixed> */ public function post(): array { return $this->payload; }
        /** @return array<string,mixed> */ public function get(): array { return $this->payload; }
        public function header(string $name, mixed $default = null): mixed { return $this->headers[$name] ?? $default; }
    }
}

namespace plugin\sandadmin\basic {
    use support\Response;
    class BaseController { protected function success(mixed $data = null, string $message = ''): Response { return new Response($data); } }
}

namespace plugin\SandIam\app\admin\support {
    use plugin\sandadmin\exception\ApiException;

    final class AdminOrganizationAccess
    {
        /** @param array<string,mixed> $admin */
        public function __construct(private int $adminId, private array $admin) {}
        public function isSuperAdmin(): bool { return $this->adminId === 1 || (int) ($this->admin['is_super'] ?? 0) === 1; }
        public function assertSuperAdmin(): void
        {
            if ($this->isSuperAdmin()) return;
            throw new ApiException('SAND_IAM_SUPER_ADMIN_REQUIRED', 401);
        }
    }
}

namespace plugin\SandIam\app\acceptance {
    final class AcceptanceFixtureService
    {
        /** @var list<array<string,mixed>> */ public static array $calls = [];
        /** @param array<string,mixed> $payload @return array<string,mixed> */
        public function cleanup(array $payload, int $adminId, string $requestId): array
        {
            self::$calls[] = ['method' => 'cleanup', 'admin_id' => $adminId, 'request_id' => $requestId];
            return ['replayed' => false];
        }
        /** @param array<string,mixed> $payload @return array<string,mixed> */
        public function status(array $payload, string $requestId): array
        {
            self::$calls[] = ['method' => 'status', 'request_id' => $requestId];
            return ['residual' => []];
        }
    }
}

namespace {
    use plugin\SandIam\app\acceptance\AcceptanceFixtureService;
    use plugin\SandIam\app\admin\controller\AcceptanceFixtureController;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    $acceptanceFixtureConfig = 0;
    function config(string $key, mixed $default = null): mixed
    {
        global $acceptanceFixtureConfig;
        return $key === 'plugin.sand-iam.app.acceptance_fixture_cleanup_enabled' ? $acceptanceFixtureConfig : $default;
    }

    function acceptanceFixtureControllerAssert(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }

    /** @param callable():mixed $operation */
    function acceptanceFixtureControllerExpect(callable $operation, string $code): void
    {
        try { $operation(); } catch (ApiException $exception) {
            acceptanceFixtureControllerAssert(str_contains($exception->getMessage(), $code), "unexpected controller error: {$exception->getMessage()}");
            return;
        }
        acceptanceFixtureControllerAssert(false, "expected {$code}");
    }

    require_once dirname(__DIR__) . '/app/admin/controller/AcceptanceFixtureController.php';
    $controller = new AcceptanceFixtureController();
    $request = new Request([], ['check_admin' => ['id' => 1, 'is_super' => 1], 'X-Request-Id' => 'acceptance-controller-20260828']);
    acceptanceFixtureControllerExpect(static fn () => $controller->cleanup($request), 'SAND_IAM_ACCEPTANCE_FIXTURE_DISABLED');
    acceptanceFixtureControllerAssert(AcceptanceFixtureService::$calls === [], 'disabled gate constructed or called the cleanup service');

    $acceptanceFixtureConfig = 1;
    $ordinary = new Request([], ['check_admin' => ['id' => 7, 'is_super' => 0], 'X-Request-Id' => 'acceptance-controller-20260828']);
    acceptanceFixtureControllerExpect(static fn () => $controller->status($ordinary), 'SAND_IAM_ACCEPTANCE_FIXTURE_SUPER_ADMIN_REQUIRED');
    acceptanceFixtureControllerAssert(AcceptanceFixtureService::$calls === [], 'ordinary administrator reached fixture status service');

    $response = $controller->cleanup($request);
    acceptanceFixtureControllerAssert($response->data['replayed'] === false && count(AcceptanceFixtureService::$calls) === 1, 'enabled super administrator did not reach fixture cleanup');

    $reflection = new \ReflectionClass(AcceptanceFixtureController::class);
    $cleanupAttributes = $reflection->getMethod('cleanup')->getAttributes();
    $statusAttributes = $reflection->getMethod('status')->getAttributes();
    $webhookEventAttributes = $reflection->getMethod('webhookEvent')->getAttributes();
    acceptanceFixtureControllerAssert(count($cleanupAttributes) === 1 && count($statusAttributes) === 1 && count($webhookEventAttributes) === 1, 'acceptance endpoints are missing permission attributes');

    echo 'acceptance fixture controller non-PG checks passed' . PHP_EOL;
}
