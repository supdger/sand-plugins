<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace plugin\sandadmin\service {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    final class Permission { public function __construct(string $name, string $code) {} }
}

namespace plugin\sandadmin\basic {
    class BaseController {}
}

namespace support {
    class Request
    {
        /** @param array<string,mixed> $input */
        public function __construct(private array $input = []) {}
        public function input(string $key, mixed $default = null): mixed { return $this->input[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return $default; }
    }
    class Response {}
}

namespace plugin\SandIam\app\admin\support {
    final class AdminOrganizationAccess {}
}

namespace plugin\SandIam\app\model {
    final class AuditLog {}
    final class AuditArchive {}
}

namespace {
    use plugin\SandIam\app\admin\controller\AuditLogController;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    final class AuditFilterQuery
    {
        /** @var list<array{0:string,1:mixed,2:mixed}> */
        public array $conditions = [];

        public function where(string $field, mixed $operatorOrValue, mixed $value = null): self
        {
            $this->conditions[] = [$field, $operatorOrValue, $value];
            return $this;
        }
    }

    function auditFilterAssert(bool $condition, string $message): void
    {
        if ($condition) return;
        fwrite(STDERR, "audit resource filter non-PG test failed: {$message}\n");
        exit(1);
    }

    require dirname(__DIR__) . '/app/admin/controller/AuditLogController.php';
    $controller = new AuditLogController();
    $applyFilters = new ReflectionMethod($controller, 'applyFilters');

    $invalidQuery = new AuditFilterQuery();
    try {
        $applyFilters->invoke($controller, $invalidQuery, new Request(['resource_id' => '42oops']));
        auditFilterAssert(false, 'malformed resource_id was accepted');
    } catch (ApiException $exception) {
        auditFilterAssert($exception->getCode() === 400, 'malformed resource_id did not return a validation error');
    }
    auditFilterAssert($invalidQuery->conditions === [], 'malformed resource_id changed the query');

    $overflowQuery = new AuditFilterQuery();
    try {
        $applyFilters->invoke($controller, $overflowQuery, new Request(['resource_id' => (string) PHP_INT_MAX . '0']));
        auditFilterAssert(false, 'resource_id above PHP_INT_MAX was accepted');
    } catch (ApiException $exception) {
        auditFilterAssert($exception->getCode() === 400, 'resource_id above PHP_INT_MAX did not return a validation error');
    }
    auditFilterAssert($overflowQuery->conditions === [], 'resource_id above PHP_INT_MAX changed the query');

    $maximumQuery = new AuditFilterQuery();
    $applyFilters->invoke($controller, $maximumQuery, new Request(['resource_id' => (string) PHP_INT_MAX]));
    auditFilterAssert(
        $maximumQuery->conditions === [['resource_id', PHP_INT_MAX, null]],
        'resource_id equal to PHP_INT_MAX was not accepted exactly',
    );

    $validQuery = new AuditFilterQuery();
    $applyFilters->invoke($controller, $validQuery, new Request(['resource_id' => '42']));
    auditFilterAssert(
        $validQuery->conditions === [['resource_id', 42, null]],
        'valid resource_id did not add the exact integer query condition',
    );

    $emptyQuery = new AuditFilterQuery();
    $applyFilters->invoke($controller, $emptyQuery, new Request());
    auditFilterAssert($emptyQuery->conditions === [], 'empty resource_id unexpectedly filtered the query');

    echo "audit resource filter non-PG checks passed\n";
}
