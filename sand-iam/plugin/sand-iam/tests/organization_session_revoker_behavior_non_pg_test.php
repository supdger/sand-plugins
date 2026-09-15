<?php
declare(strict_types=1);

namespace OrganizationRevokerTest {
    final class State {
        public static array $rows = [];
        public static array $audits = [];
        public static ?array $transaction = null;
        public static string $failure = '';
    }
    class Model {
        public function __construct(private array $values) {}
        public function __get(string $key): mixed { return $this->values[$key] ?? null; }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
        public static function whereIn(string $key, array $values): Query { return (new Query(static::class))->whereIn($key, $values); }
        public function save(array $values): void {
            $this->values = array_replace($this->values, $values);
            State::$rows[static::class][$this->id] = $this->values;
        }
    }
    final class Query {
        private array $filters = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value): self { return $this->whereIn($key, [$value]); }
        public function whereIn(string $key, array $values): self { $this->filters[$key] = $values; return $this; }
        public function lock(bool $lock): self { check(State::$transaction !== null, 'Lock outside transaction'); return $this; }
        private function rows(): array {
            return array_filter(State::$rows[$this->model] ?? [], function (array $row): bool {
                foreach ($this->filters as $key => $values) if (!in_array($row[$key] ?? null, $values, true)) return false;
                return true;
            });
        }
        public function find(): ?object { $row = array_values($this->rows())[0] ?? null; return $row === null ? null : new ($this->model)($row); }
        public function column(string $key): array { return array_column($this->rows(), $key); }
        public function update(array $values): int {
            $rows = $this->rows();
            foreach ($rows as $id => $row) {
                State::$rows[$this->model][$id] = array_replace($row, $values);
                if (State::$failure === $this->model) throw new \RuntimeException('injected row failure');
            }
            return count($rows);
        }
    }
    function check(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Organization extends \OrganizationRevokerTest\Model {}
    class Application extends \OrganizationRevokerTest\Model {}
    class AuthSession extends \OrganizationRevokerTest\Model {}
    class AuthRefreshToken extends \OrganizationRevokerTest\Model {}
}
namespace think\facade {
    class Db {
        public static function startTrans(): void {
            \OrganizationRevokerTest\check(\OrganizationRevokerTest\State::$transaction === null, 'Leaked transaction');
            \OrganizationRevokerTest\State::$transaction = [\OrganizationRevokerTest\State::$rows, \OrganizationRevokerTest\State::$audits];
        }
        public static function commit(): void { \OrganizationRevokerTest\State::$transaction = null; }
        public static function rollback(): void {
            if (\OrganizationRevokerTest\State::$transaction !== null) [\OrganizationRevokerTest\State::$rows, \OrganizationRevokerTest\State::$audits] = \OrganizationRevokerTest\State::$transaction;
            \OrganizationRevokerTest\State::$transaction = null;
        }
    }
}
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public function write(...$arguments): void {
            \OrganizationRevokerTest\State::$audits[] = $arguments;
            if (\OrganizationRevokerTest\State::$failure === 'audit') throw new \RuntimeException('injected audit failure');
        }
    }
}
namespace {
    use OrganizationRevokerTest\State;
    use function OrganizationRevokerTest\check;
    use plugin\SandIam\app\model\{Organization, Application, AuthSession, AuthRefreshToken};
    use plugin\SandIam\app\service\OrganizationHumanSessionRevoker;
    require dirname(__DIR__) . '/app/service/OrganizationHumanSessionRevoker.php';
    State::$rows = [
        Organization::class => [1 => ['id' => 1, 'status' => 1], 2 => ['id' => 2, 'status' => 1]],
        Application::class => [10 => ['id' => 10, 'organization_id' => 1], 11 => ['id' => 11, 'organization_id' => 1], 12 => ['id' => 12, 'organization_id' => 2]],
        AuthSession::class => [20 => ['id' => 20, 'application_id' => 10, 'status' => 1], 21 => ['id' => 21, 'application_id' => 11, 'status' => 1], 22 => ['id' => 22, 'application_id' => 12, 'status' => 1], 23 => ['id' => 23, 'application_id' => 10, 'status' => 2]],
        AuthRefreshToken::class => [30 => ['id' => 30, 'session_id' => 20, 'status' => 1], 31 => ['id' => 31, 'session_id' => 21, 'status' => 1], 32 => ['id' => 32, 'session_id' => 22, 'status' => 1], 33 => ['id' => 33, 'session_id' => 20, 'status' => 2]],
    ];
    $service = new OrganizationHumanSessionRevoker();
    $disable = static fn (): array => $service->disable(1, ['name' => 'Disabled tenant'], 77, 'organization-disable');
    foreach ([AuthRefreshToken::class, AuthSession::class, 'audit'] as $failure) {
        State::$failure = $failure;
        $before = [State::$rows, State::$audits];
        try { $disable(); throw new \RuntimeException('Failure ignored'); }
        catch (\RuntimeException $exception) { check(str_starts_with($exception->getMessage(), 'injected'), $exception->getMessage()); }
        check(State::$transaction === null && [State::$rows, State::$audits] === $before, 'Failed disable left partial state');
    }
    State::$failure = '';
    $before = State::$rows;
    $result = $disable();
    check($result === ['changed' => true, 'application_count' => 2, 'session_count' => 2, 'refresh_token_count' => 2], 'Disable counts incorrect');
    check(State::$rows[Organization::class][1]['status'] === 2, 'Organization stayed active');
    foreach ([20, 21] as $id) check(State::$rows[AuthSession::class][$id]['status'] === 2 && State::$rows[AuthSession::class][$id]['revoked_time'] !== null, 'Session stayed active');
    foreach ([30, 31] as $id) check(State::$rows[AuthRefreshToken::class][$id]['status'] === 2 && State::$rows[AuthRefreshToken::class][$id]['revoked_time'] !== null, 'Refresh token stayed active');
    foreach ([[Organization::class, 2], [AuthSession::class, 22], [AuthSession::class, 23], [AuthRefreshToken::class, 32], [AuthRefreshToken::class, 33]] as [$model, $id]) check(State::$rows[$model][$id] === $before[$model][$id], 'Unrelated or previously revoked row changed');
    check(count(State::$audits) === 1 && State::$audits[0][2] === 1 && State::$audits[0][4] === 'organization.disable' && State::$audits[0][9]['session_count'] === 2, 'Disable audit scope/count lost');
    $before = [State::$rows, State::$audits];
    check($disable()['changed'] === false && [State::$rows, State::$audits] === $before && State::$transaction === null, 'Repeat disable changed records');
    try { $service->disable(999, [], 77, 'missing'); throw new \RuntimeException('Missing organization accepted'); }
    catch (\plugin\sandadmin\exception\ApiException $exception) { check($exception->getCode() === 400, 'Missing organization error changed'); }
    check([State::$rows, State::$audits] === $before && State::$transaction === null, 'Missing organization left transaction');
    echo "Organization disable session revocation and recovery non-PG behavior PASS\n";
}
