<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\sandadmin\service {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Permission
    {
        public function __construct(string $name, string $code) {}
    }
}

namespace support {
    class Request {}
    class Response {}
}

namespace plugin\SandIam\app\admin\support {
    abstract class ApplicationResourceController
    {
        public function index(\support\Request $request): \support\Response { return new \support\Response(); }
        public function read(\support\Request $request): \support\Response { return new \support\Response(); }
        public function save(\support\Request $request): \support\Response { return new \support\Response(); }
        public function update(\support\Request $request): \support\Response { return new \support\Response(); }
        public function disable(\support\Request $request): \support\Response { return new \support\Response(); }
        protected function payload(\support\Request $request, bool $updating): array { return []; }
    }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter {}
}

namespace plugin\SandIam\app\model {
    final class InMemoryQuery
    {
        /** @var array<string, mixed> */
        private array $filters = [];

        /** @param class-string $model */
        public function __construct(private readonly string $model) {}

        public function where(string $field, mixed $operatorOrValue, mixed $value = null): self
        {
            $this->filters[$field] = $value === null ? $operatorOrValue : $value;
            return $this;
        }

        public function join(string $table, string $on): self { return $this; }
        public function field(string $fields): self { return $this; }
        public function order(string $field, string $direction = 'asc'): self { return $this; }
        /** @return list<mixed> */
        public function column(string $field): array { return []; }

        public function find(): ?object
        {
            foreach ($this->select() as $row) return $row;
            return null;
        }

        /** @return list<object> */
        public function select(): array
        {
            return array_values(array_filter($this->model::$rows, function (object $row): bool {
                foreach ($this->filters as $field => $value) {
                    if (($row->{$field} ?? null) !== $value) return false;
                }
                return true;
            }));
        }
    }

    trait InMemoryModel
    {
        /** @var list<object> */
        public static array $rows = [];
        public static function where(string $field, mixed $operatorOrValue, mixed $value = null): InMemoryQuery
        {
            return (new InMemoryQuery(static::class))->where($field, $operatorOrValue, $value);
        }
        public static function alias(string $alias): InMemoryQuery { return new InMemoryQuery(static::class); }
    }

    final class Application { use InMemoryModel; }
    final class Identity { use InMemoryModel; }
    final class IdentityGroupMember { use InMemoryModel; }
    final class IdentityRole { use InMemoryModel; }
    final class Policy { use InMemoryModel; }
    final class PolicyVersion { use InMemoryModel; }
    final class Resource { use InMemoryModel; }
    final class ApplicationBusinessAction { use InMemoryModel; }
    final class ApiResource { use InMemoryModel; }
}

namespace {
    require_once dirname(__DIR__) . '/app/runtime/ScopeMatcher.php';
    require_once dirname(__DIR__) . '/app/service/RequestId.php';
    require_once dirname(__DIR__) . '/app/runtime/PolicyAuthorizer.php';
    require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';
    require_once dirname(__DIR__) . '/app/admin/controller/ApiResourceController.php';

    use plugin\SandIam\app\admin\controller\ApiResourceController;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\ApplicationBusinessAction;
    use plugin\SandIam\app\model\Identity;
    use plugin\SandIam\app\model\Policy;
    use plugin\SandIam\app\model\Resource;
    use plugin\SandIam\app\runtime\PolicyAuthorizer;
    use plugin\SandIam\app\runtime\ScopeMatcher;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\exception\ApiException;

    function hotfixAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
    }

    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    Application::$rows = [(object) ['id' => 1, 'organization_id' => 9, 'status' => 1]];
    Identity::$rows = [(object) ['id' => 2, 'application_id' => 1, 'status' => 1]];
    Resource::$rows = [(object) ['id' => 3, 'application_id' => 1, 'code' => 'record', 'status' => 1]];
    Policy::$rows = [];

    $simulation = (new PolicyAuthorizer(new AuditWriter(), new ScopeMatcher()))->simulate(
        1,
        2,
        'record',
        'record.read',
        'read',
        [],
        'hotfix-01',
    );
    hotfixAssert($simulation['allowed'] === false, 'empty candidate rules must fail closed');
    hotfixAssert($simulation['code'] === 'SAND_IAM_POLICY_DENIED', 'empty candidate rules must return the stable denial code');
    hotfixAssert($simulation['matched_rules'] === [] && $simulation['missing_context'] === [], 'empty candidate rules must return an empty explanation without a warning');

    ApplicationBusinessAction::$rows = [(object) ['application_id' => 1, 'code' => 'record.read', 'status' => 1]];
    $controller = (new \ReflectionClass(ApiResourceController::class))->newInstanceWithoutConstructor();
    $assertReferences = new \ReflectionMethod(ApiResourceController::class, 'assertReferences');
    $validPayload = [
        'application_id' => 1,
        'resource_id' => 3,
        'action' => 'record.read',
        'operation' => 'read',
        'api_version' => 'v1',
        'audience' => 'sand-ai/api/v1',
        'required_scope' => 'record:read',
        'risk_level' => 'medium',
        'description' => '公开接口目录回归夹具',
    ];
    $assertReferences->invoke($controller, $validPayload, null);

    try {
        $invalidPayload = $validPayload;
        $invalidPayload['audience'] = '/sand-ai/api/v1';
        $assertReferences->invoke($controller, $invalidPayload, null);
        hotfixAssert(false, 'audience without an alphanumeric first character must be rejected');
    } catch (ApiException $exception) {
        hotfixAssert($exception->getCode() === 400, 'invalid audience must retain the 400 business validation code');
    }

    restore_error_handler();
    echo "SandIAM authority hotfix behavior checks passed: 5/5\n";
}
