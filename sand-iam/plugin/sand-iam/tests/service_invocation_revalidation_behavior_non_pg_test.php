<?php
declare(strict_types=1);

namespace InvocationTest {
    final class State {
        public static array $rows = [];
        public static array $audits = [];
        public static ?array $transaction = null;
        public static bool $auditFailure = false;
        public static bool $auditApiFailure = false;
        public static array $claims = [];
    }
    class Model {
        public function __construct(private array $values) {}
        public function __get(string $key): mixed { return $this->values[$key] ?? null; }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
        public static function create(array $values): static {
            $values['id'] = max([0, ...array_keys(State::$rows[static::class] ?? [])]) + 1;
            State::$rows[static::class][$values['id']] = $values;
            return new static($values);
        }
        public function save(array $values): void {
            $this->values = array_replace($this->values, $values);
            State::$rows[static::class][$this->id] = $this->values;
        }
    }
    final class Query {
        private array $filters = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value): self { $this->filters[$key] = $value; return $this; }
        public function whereNull(string $key): self { return $this->where($key, null); }
        public function lock(bool $lock): self { check(State::$transaction !== null, 'Lock outside transaction'); return $this; }
        public function find(): ?object {
            foreach (State::$rows[$this->model] ?? [] as $row) {
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) !== $value) continue 2;
                return new ($this->model)($row);
            }
            return null;
        }
    }
    function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \InvocationTest\Model {}
    class Organization extends \InvocationTest\Model {}
    class Environment extends \InvocationTest\Model {}
    class Credential extends \InvocationTest\Model {}
    class WorkloadClient extends \InvocationTest\Model {}
    class ServiceGrant extends \InvocationTest\Model {}
    class ServiceAction extends \InvocationTest\Model {}
    class Service extends \InvocationTest\Model {}
    class ServiceInvocationOperation extends \InvocationTest\Model {}
}
namespace think\facade {
    class Db {
        public static function startTrans(): void {
            \InvocationTest\check(\InvocationTest\State::$transaction === null, 'Leaked transaction');
            \InvocationTest\State::$transaction = [\InvocationTest\State::$rows, \InvocationTest\State::$audits];
        }
        public static function commit(): void { \InvocationTest\State::$transaction = null; }
        public static function rollback(): void {
            if (\InvocationTest\State::$transaction !== null) [\InvocationTest\State::$rows, \InvocationTest\State::$audits] = \InvocationTest\State::$transaction;
            \InvocationTest\State::$transaction = null;
        }
    }
}
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public function write(...$arguments): void {
            \InvocationTest\State::$audits[] = $arguments;
            if (\InvocationTest\State::$auditApiFailure) throw new \plugin\sandadmin\exception\ApiException('audit api unavailable', 503);
            if (\InvocationTest\State::$auditFailure) throw new \RuntimeException('audit unavailable');
        }
    }
}
namespace plugin\SandIam\app\runtime {
    // This test starts after context verification; it does not test its signature.
    class IdentityContextProvider {
        public function verifyForService(...$arguments): array { return \InvocationTest\State::$claims; }
    }
}
namespace {
    use InvocationTest\State;
    use function InvocationTest\check;
    use plugin\SandIam\app\model\{Application, Organization, Environment, Credential, WorkloadClient, ServiceGrant, ServiceAction, Service, ServiceInvocationOperation};
    use plugin\SandIam\app\runtime\{ServiceInvocationAuthorizer, ServiceInvocationFactResolver, ServiceInvocationFactResolverRegistry};
    use plugin\sandadmin\exception\ApiException;
    $root = dirname(__DIR__) . '/app/';
    foreach (['service/RequestId.php', 'security/NetworkPolicy.php', 'security/ServiceGrantConstraintNormalizer.php', 'runtime/ServiceInvocationFactResolver.php', 'runtime/ServiceInvocationFactResolverRegistry.php', 'runtime/ResolvedInvocationFacts.php', 'runtime/ServiceInvocationAuthorizer.php'] as $file) require $root . $file;
    $scope = ['organization_id' => 1, 'application_id' => 2, 'environment_id' => 3, 'workload_client_id' => 4];
    State::$claims = $scope + ['credential_id' => 5, 'context_id' => 'context-one', 'audience' => 'records-api', 'grant_ids' => [6], 'action_grants' => ['read' => ['grant_id' => 6, 'service_code' => 'records']]];
    State::$rows = [
        Organization::class => [1 => ['id' => 1, 'status' => 1]],
        Application::class => [2 => ['id' => 2, 'organization_id' => 1, 'status' => 1]],
        Environment::class => [3 => ['id' => 3, 'application_id' => 2, 'status' => 1]],
        WorkloadClient::class => [4 => ['id' => 4, 'environment_id' => 3, 'audience' => 'records-api', 'status' => 1]],
        Credential::class => [5 => ['id' => 5, 'workload_client_id' => 4, 'status' => 1, 'revoked_time' => null, 'expire_time' => null]],
        ServiceGrant::class => [6 => ['id' => 6, 'workload_client_id' => 4, 'audience' => 'records-api', 'status' => 1, 'revoked_time' => null, 'expire_time' => null, 'service_action_id' => 7, 'data_class' => 'internal', 'quota_policy' => [], 'network_policy' => []]],
        ServiceAction::class => [7 => ['id' => 7, 'service_id' => 8, 'code' => 'read', 'status' => 1]],
        Service::class => [8 => ['id' => 8, 'code' => 'records', 'status' => 1]],
        ServiceInvocationOperation::class => [9 => $scope + ['id' => 9, 'credential_id' => 5, 'context_id' => 'context-one', 'grant_id' => 6, 'service_code' => 'records', 'audience' => 'records-api', 'action_code' => 'read', 'outcome' => 'allowed', 'resource_type' => 'record', 'resource_ref' => '42', 'operation_id' => 'operation-one', 'data_class' => 'internal']],
    ];
    $resolver = new class($scope) implements ServiceInvocationFactResolver {
        public function __construct(private array $scope) {}
        public function resolve(string $serviceCode, string $actionCode, string $resourceKey): array {
            return $this->scope + ['resource_type' => 'record', 'resource_ref' => $resourceKey, 'data_class' => 'internal'];
        }
    };
    $service = new ServiceInvocationAuthorizer(factResolvers: new ServiceInvocationFactResolverRegistry(['records' => $resolver]));
    $invoke = static fn (): array => $service->revalidateInvocation(9, 'verified-fixture', 'records', 'records-api', 'read', 'records', '42', '192.0.2.1', 'revalidate-test');
    check($invoke()['authorization_id'] === 9 && State::$transaction === null, 'Active invocation rejected');
    foreach ([[ServiceGrant::class, 6, 'status', 2, 403], [ServiceGrant::class, 6, 'revoked_time', '2020-01-01 00:00:00', 403], [ServiceGrant::class, 6, 'expire_time', '2020-01-01 00:00:00', 403], [Credential::class, 5, 'status', 2, 401]] as [$model, $id, $field, $value, $code]) {
        $baseline = State::$rows;
        State::$rows[$model][$id][$field] = $value;
        try { $invoke(); throw new \RuntimeException('Revoked invocation allowed'); }
        catch (ApiException $exception) { check($exception->getCode() === $code, 'Wrong revocation error'); }
        check(State::$transaction === null && end(State::$audits)[7] === 'denied', 'Rejection not committed with denied audit');
        State::$rows = $baseline;
        check($invoke()['authorization_id'] === 9, 'Restored invocation rejected');
    }
    State::$rows[ServiceGrant::class][6]['status'] = 2;
    $before = [State::$rows, State::$audits];
    State::$auditFailure = true;
    try { $invoke(); throw new \RuntimeException('Audit failure ignored'); }
    catch (\RuntimeException $exception) { check($exception->getMessage() === 'audit unavailable', $exception->getMessage()); }
    check(State::$transaction === null && [State::$rows, State::$audits] === $before, 'Denied audit failure leaked transaction or partial audit');
    State::$auditFailure = false;
    try { $invoke(); throw new \RuntimeException('Retry allowed revoked grant'); }
    catch (ApiException $exception) { check($exception->getCode() === 403, 'Retry did not retain revocation'); }
    check(State::$transaction === null && end(State::$audits)[7] === 'denied', 'Retry failed to close transaction');
    $authorize = static fn (): array => $service->authorizeInvocation('verified-fixture', 'records', 'records-api', 'read', 'records', '42', '192.0.2.1', 'new-operation', 'authorize-test');
    $before = [State::$rows, State::$audits];
    State::$auditFailure = true;
    try { $authorize(); throw new \RuntimeException('Authorization audit failure ignored'); }
    catch (\RuntimeException $exception) { check($exception->getMessage() === 'audit unavailable', $exception->getMessage()); }
    check(State::$transaction === null && [State::$rows, State::$audits] === $before, 'Authorization denial audit failure leaked transaction');
    State::$auditFailure = false;
    try { $authorize(); throw new \RuntimeException('Authorization accepted revoked grant'); }
    catch (ApiException $exception) { check($exception->getCode() === 403, 'Wrong authorization retry error'); }
    check(State::$transaction === null && end(State::$audits)[4] === 'service.invoke.authorize' && end(State::$audits)[7] === 'denied', 'Authorization retry audit missing');
    echo "Service invocation live revocation and denial recovery non-PG behavior PASS\n";
    State::$rows[ServiceGrant::class][6]['status'] = 1;
    foreach (['auditFailure', 'auditApiFailure'] as $failure) {
        $before = [State::$rows, State::$audits];
        State::${$failure} = true;
        try { $authorize(); throw new \RuntimeException('First authorization audit fault ignored'); }
        catch (\RuntimeException $exception) { check(str_starts_with($exception->getMessage(), 'audit'), $exception->getMessage()); }
        check(State::$transaction === null && [State::$rows, State::$audits] === $before, 'First authorization audit fault persisted operation or leaked transaction');
        State::${$failure} = false;
    }
    $first = $authorize();
    check($first['authorization_id'] === 10 && $first['replayed'] === false, 'First authorization result incorrect');
    $rows = State::$rows;
    $replayed = $authorize();
    check($replayed['authorization_id'] === 10 && $replayed['replayed'] === true && State::$rows === $rows, 'Authorization replay created a new operation');
    foreach (['context_id' => 'another-context', 'credential_id' => 99] as $field => $value) {
        $before = [State::$rows, State::$audits];
        $claims = State::$claims;
        State::$claims[$field] = $value;
        try { $authorize(); throw new \RuntimeException('Different context reused operation'); }
        catch (ApiException $exception) { check($exception->getCode() === 409, 'Replay lost context conflict'); }
        check(State::$rows === $before[0] && State::$transaction === null, 'Conflicting replay changed operation');
        State::$claims = $claims;
    }
    State::$rows[ServiceGrant::class][6]['status'] = 2;
    $rows = State::$rows;
    try { $authorize(); throw new \RuntimeException('Revoked grant replay allowed'); }
    catch (ApiException $exception) { check($exception->getCode() === 403, 'Replay revocation error changed'); }
    check(State::$rows === $rows && State::$transaction === null, 'Revoked replay changed original operation');
    State::$rows[ServiceGrant::class][6]['status'] = 1;
    check($authorize()['authorization_id'] === 10, 'Restored grant replay lost original operation');
    echo "Service invocation first authorization, replay and fault rollback non-PG behavior PASS\n";
}
