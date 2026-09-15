<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception { final class ApiException extends \RuntimeException {} }
namespace plugin\sandadmin\service { #[\Attribute(\Attribute::TARGET_METHOD)] final class Permission { public function __construct(string $name, string $code) {} } }
namespace support {
    final class Request
    {
        public function __construct(private array $post, private array $headers) {}
        public function post(): array { return $this->post; }
        public function input(string $key, mixed $default = null): mixed { return $this->post[$key] ?? $default; }
        public function header(string $key, mixed $default = null): mixed { return $this->headers[$key] ?? $default; }
    }
    final class Response { public function __construct(public mixed $data, public string $message = '') {} }
}
namespace plugin\sandadmin\basic {
    class BaseController
    {
        protected function success(mixed $data = null, string $message = ''): \support\Response { return new \support\Response($data, $message); }
    }
}
namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        public static bool $fail = false;
        public static array $writes = [];
        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId): void
        {
            self::$writes[] = compact('actorType', 'actorRef', 'organizationId', 'applicationId', 'action', 'resourceType', 'resourceId', 'outcome', 'requestId');
            if (self::$fail) throw new \RuntimeException('injected audit failure');
        }
    }
}
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    final class GrantRecord
    {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->{$key} = $value; }
        public function save(array $values): void { foreach ($values as $key => $value) $this->{$key} = $value; }
        public function toArray(): array { return get_object_vars($this); }
        public function isEmpty(): bool { return !isset($this->id); }
    }
    final class ModelQuery
    {
        private array $conditions = [];
        public function __construct(private string $model, string $field, mixed $value) { $this->conditions[$field] = $value; }
        public function where(string $field, mixed $value): self { $this->conditions[$field] = $value; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?GrantRecord
        {
            foreach (($this->model)::$rows as $row) {
                foreach ($this->conditions as $field => $value) if (($row->{$field} ?? null) !== $value) continue 2;
                return $row;
            }
            return null;
        }
    }
    final class ServiceGrant
    {
        public static array $rows = [];
        public static function create(array $values): GrantRecord { $id = count(self::$rows) + 1; return self::$rows[$id] = new GrantRecord(['id' => $id, ...$values]); }
        public static function find(int $id): ?GrantRecord { return self::$rows[$id] ?? null; }
        public static function findOrEmpty(int $id): GrantRecord { return self::find($id) ?? new GrantRecord([]); }
    }
    final class SecurityOperation
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): ModelQuery { return new ModelQuery(self::class, $field, $value); }
        public static function create(array $values): GrantRecord { $id = count(self::$rows) + 1; return self::$rows[$id] = new GrantRecord(['id' => $id, ...$values]); }
    }
    final class WorkloadClient
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): ModelQuery { return new ModelQuery(self::class, $field, $value); }
        public static function find(int $id): ?GrantRecord { return self::$rows[$id] ?? null; }
    }
    final class ServiceAction
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): ModelQuery { return new ModelQuery(self::class, $field, $value); }
    }
    final class Service
    {
        public static array $rows = [];
        public static function where(string $field, mixed $value): ModelQuery { return new ModelQuery(self::class, $field, $value); }
    }
    final class Environment
    {
        public static array $rows = [];
        public static function find(int $id): ?GrantRecord { return self::$rows[$id] ?? null; }
    }
    final class Application
    {
        public static array $rows = [];
        public static function find(int $id): ?GrantRecord { return self::$rows[$id] ?? null; }
    }
}
namespace think\facade {
    final class Db
    {
        private static array $snapshots = [];
        public static function startTrans(): void { self::$snapshots[] = serialize([\plugin\SandIam\app\model\ServiceGrant::$rows, \plugin\SandIam\app\model\SecurityOperation::$rows, \plugin\SandIam\app\service\AuditWriter::$writes]); }
        public static function commit(): void { array_pop(self::$snapshots); }
        public static function rollback(): void
        {
            [$grants, $operations, $audits] = unserialize(array_pop(self::$snapshots), ['allowed_classes' => true]);
            \plugin\SandIam\app\model\ServiceGrant::$rows = $grants;
            \plugin\SandIam\app\model\SecurityOperation::$rows = $operations;
            \plugin\SandIam\app\service\AuditWriter::$writes = $audits;
        }
    }
}
namespace plugin\SandIam\app\admin\support {
    final class AdminOrganizationAccess
    {
        public static bool $allow = true;
        public function __construct(int $adminId, ?array $adminInfo) {}
        public function isSuperAdmin(): bool { return true; }
        public function organizationIds(): array { return []; }
        public function assertSuperAdmin(): void {}
        public function assertApplication(int $applicationId): void
        {
            if (!self::$allow || $applicationId !== 20) throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_APPLICATION_ACCESS_DENIED', 403);
        }
    }
}
namespace {
    use plugin\SandIam\app\admin\controller\ServiceGrantController;
    use plugin\SandIam\app\admin\support\AdminOrganizationAccess;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Environment;
    use plugin\SandIam\app\model\GrantRecord;
    use plugin\SandIam\app\model\SecurityOperation;
    use plugin\SandIam\app\model\Service;
    use plugin\SandIam\app\model\ServiceAction;
    use plugin\SandIam\app\model\ServiceGrant;
    use plugin\SandIam\app\model\WorkloadClient;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\exception\ApiException;
    use support\Request;

    function request(): object { return new class { public function header(string $key, mixed $default = null): mixed { return $key === 'check_admin' ? ['id' => 77] : $default; } }; }
    function serviceGrantAtomicAssert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    function serviceGrantAtomicRequest(array $payload, string $requestId): Request { return new Request($payload, ['check_admin' => ['id' => 77], 'X-Request-Id' => $requestId]); }

    $plugin = dirname(__DIR__);
    require $plugin . '/app/service/RequestId.php';
    require $plugin . '/app/service/IdempotencyService.php';
    require $plugin . '/app/admin/support/AdminResourceController.php';
    require $plugin . '/app/security/NetworkPolicy.php';
    require $plugin . '/app/security/ServiceGrantConstraintNormalizer.php';
    require $plugin . '/app/admin/controller/ServiceGrantController.php';

    WorkloadClient::$rows = [7 => new GrantRecord(['id' => 7, 'status' => 1, 'audience' => 'provider-test', 'environment_id' => 30])];
    ServiceAction::$rows = [9 => new GrantRecord(['id' => 9, 'status' => 1, 'service_id' => 8])];
    Service::$rows = [8 => new GrantRecord(['id' => 8, 'status' => 1])];
    Environment::$rows = [30 => new GrantRecord(['id' => 30, 'application_id' => 20])];
    Application::$rows = [20 => new GrantRecord(['id' => 20, 'organization_id' => 10])];
    $payload = ['workload_client_id' => 7, 'service_action_id' => 9, 'audience' => 'provider-test', 'status' => 1];
    $controller = new ServiceGrantController();

    AuditWriter::$fail = true;
    try {
        $controller->save(serviceGrantAtomicRequest($payload, 'c04-audit-failure-probe'));
        throw new \RuntimeException('audit failure unexpectedly committed a service grant');
    } catch (\RuntimeException $exception) {
        serviceGrantAtomicAssert($exception->getMessage() === 'injected audit failure', 'audit failure changed unexpectedly');
    }
    serviceGrantAtomicAssert(ServiceGrant::$rows === [] && SecurityOperation::$rows === [] && AuditWriter::$writes === [], 'audit failure retained a grant, audit, or idempotency record');

    AuditWriter::$fail = false;
    $requestId = 'c04-success-replay-001';
    $first = $controller->save(serviceGrantAtomicRequest($payload, $requestId));
    $replayed = $controller->save(serviceGrantAtomicRequest($payload, $requestId));
    serviceGrantAtomicAssert($first->data === ['id' => 1] && $replayed->data === ['id' => 1], 'completed request did not return the original id response');
    serviceGrantAtomicAssert(count(ServiceGrant::$rows) === 1 && count(SecurityOperation::$rows) === 1 && count(AuditWriter::$writes) === 1, 'completed request replay created another grant or audit');
    try {
        $controller->save(serviceGrantAtomicRequest($payload + ['quota_policy' => ['max_invocation_attempts' => 2, 'window_seconds' => 60]], $requestId));
        throw new \RuntimeException('different payload reused a completed request id');
    } catch (ApiException $exception) {
        serviceGrantAtomicAssert($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_IDEMPOTENCY_CONFLICT'), 'different request payload lost the idempotency conflict');
    }
    $beforeDenied = [count(ServiceGrant::$rows), count(SecurityOperation::$rows), count(AuditWriter::$writes)];
    AdminOrganizationAccess::$allow = false;
    try {
        $controller->save(serviceGrantAtomicRequest($payload, $requestId));
        throw new \RuntimeException('replay bypassed the current organization permission');
    } catch (ApiException $exception) {
        serviceGrantAtomicAssert($exception->getCode() === 403, 'replay permission denial changed');
    }
    AdminOrganizationAccess::$allow = true;
    try {
        $controller->save(serviceGrantAtomicRequest(['workload_client_id' => 999, 'service_action_id' => 9, 'audience' => 'provider-test'], 'c04-invalid-reference-004'));
        throw new \RuntimeException('missing client reference was accepted');
    } catch (ApiException $exception) {
        serviceGrantAtomicAssert($exception->getCode() === 400, 'reference rejection changed');
    }
    serviceGrantAtomicAssert($beforeDenied === [count(ServiceGrant::$rows), count(SecurityOperation::$rows), count(AuditWriter::$writes)], 'permission or reference rejection wrote state');
    echo "service grant atomic create non-pg test passed\n";
    $active = serialize([ServiceGrant::$rows, SecurityOperation::$rows, AuditWriter::$writes]);
    foreach (['revoke', 'disable'] as $method) {
        [ServiceGrant::$rows, SecurityOperation::$rows, AuditWriter::$writes] = unserialize($active);
        AuditWriter::$fail = true;
        try {
            $controller->{$method}(serviceGrantAtomicRequest(['id' => 1], 'c04-revoke-failure'));
            throw new \RuntimeException('revoke audit failure ignored');
        } catch (\RuntimeException $exception) {
            serviceGrantAtomicAssert($exception->getMessage() === 'injected audit failure', $exception->getMessage());
        }
        serviceGrantAtomicAssert(serialize([ServiceGrant::$rows, SecurityOperation::$rows, AuditWriter::$writes]) === $active, 'failed revoke retained grant state or audit');
        AuditWriter::$fail = false;
        $result = $controller->{$method}(serviceGrantAtomicRequest(['id' => 1], 'c04-revoke-recovered'));
        serviceGrantAtomicAssert($result->data === '已撤销' && ServiceGrant::$rows[1]->status === 2 && ServiceGrant::$rows[1]->revoked_time !== null, 'revoke recovery did not revoke grant');
        $audit = end(AuditWriter::$writes);
        serviceGrantAtomicAssert($audit['action'] === 'service_grant.revoke' && $audit['organizationId'] === 10 && $audit['applicationId'] === 20 && $audit['resourceId'] === 1, 'revoke audit scope lost');
        AdminOrganizationAccess::$allow = false;
        $before = serialize([ServiceGrant::$rows, AuditWriter::$writes]);
        try { $controller->{$method}(serviceGrantAtomicRequest(['id' => 1], 'c04-revoke-denied')); throw new \RuntimeException('denied revoke accepted'); }
        catch (ApiException $exception) { serviceGrantAtomicAssert($exception->getCode() === 403, 'wrong revoke permission rejection'); }
        serviceGrantAtomicAssert(serialize([ServiceGrant::$rows, AuditWriter::$writes]) === $before, 'denied revoke changed state');
        AdminOrganizationAccess::$allow = true;
    }
    echo "service grant revoke/disable audit rollback non-pg test passed\n";
    [ServiceGrant::$rows, SecurityOperation::$rows, AuditWriter::$writes] = unserialize($active);
    $changes = [
        'id' => 1,
        'quota_policy' => ['max_invocation_attempts' => 2, 'window_seconds' => 60],
        'data_class' => 'internal',
        'expire_time' => '2099-01-01 00:00:00',
    ];
    AuditWriter::$fail = true;
    try { $controller->update(serviceGrantAtomicRequest($changes, 'c04-update-failed')); throw new \RuntimeException('update audit failure ignored'); }
    catch (\RuntimeException $exception) { serviceGrantAtomicAssert($exception->getMessage() === 'injected audit failure', $exception->getMessage()); }
    serviceGrantAtomicAssert(serialize([ServiceGrant::$rows, SecurityOperation::$rows, AuditWriter::$writes]) === $active, 'failed update retained changed constraints or audit');
    AuditWriter::$fail = false;
    $result = $controller->update(serviceGrantAtomicRequest($changes, 'c04-update-recovered'));
    serviceGrantAtomicAssert($result->data === '更新成功' && ServiceGrant::$rows[1]->quota_policy === $changes['quota_policy'] && ServiceGrant::$rows[1]->expire_time === $changes['expire_time'], 'update recovery lost constraints');
    $audit = end(AuditWriter::$writes);
    serviceGrantAtomicAssert($audit['action'] === 'service_grant.update' && $audit['applicationId'] === 20, 'update audit lost action or scope');
    foreach (['workload_client_id' => 8, 'service_action_id' => 10, 'audience' => 'other'] as $field => $value) {
        $before = serialize([ServiceGrant::$rows, AuditWriter::$writes]);
        try { $controller->update(serviceGrantAtomicRequest(['id' => 1, $field => $value], 'c04-immutable')); throw new \RuntimeException('immutable grant field changed'); }
        catch (ApiException $exception) { serviceGrantAtomicAssert($exception->getCode() === 409, 'immutable rejection changed'); }
        serviceGrantAtomicAssert(serialize([ServiceGrant::$rows, AuditWriter::$writes]) === $before, 'immutable rejection wrote state');
    }
    echo "service grant update audit rollback non-pg test passed\n";
}
