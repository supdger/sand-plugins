<?php
declare(strict_types=1);

namespace MfaManagementTest {
    final class State {
        public static array $rows = [], $audits = [];
        public static ?array $transaction = null;
        public static bool $fail = false;
    }
    class Record {
        public function __construct(private array $values) {}
        public function __get(string $key): mixed { return $this->values[$key] ?? null; }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
        public function save(array $values): void { State::$rows[static::class][$this->id] = $this->values = array_replace($this->values, $values); }
    }
    final class Query {
        private array $filters = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value): self { $this->filters[$key] = $value; return $this; }
        public function whereNull(string $key): self { return $this->where($key, null); }
        private function matches(array $row): bool {
            foreach ($this->filters as $key => $value) if (($row[$key] ?? null) !== $value) return false;
            return true;
        }
        public function find(): ?Record {
            foreach (State::$rows[$this->model] ?? [] as $row) if ($this->matches($row)) return new $this->model($row);
            return null;
        }
        public function update(array $values): void {
            foreach (State::$rows[$this->model] ?? [] as $id => $row) if ($this->matches($row)) State::$rows[$this->model][$id] = array_replace($row, $values);
        }
    }
    function check(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \MfaManagementTest\Record {}
    class Identity extends \MfaManagementTest\Record {}
    class MfaFactor extends \MfaManagementTest\Record {}
    class MfaRecoveryCode extends \MfaManagementTest\Record {}
    class WebauthnCredential extends \MfaManagementTest\Record {}
}
namespace think\facade {
    use MfaManagementTest\State;
    class Db {
        public static function startTrans(): void { \MfaManagementTest\check(State::$transaction === null, 'Nested transaction'); State::$transaction = [State::$rows, State::$audits]; }
        public static function commit(): void { State::$transaction = null; }
        public static function rollback(): void { if (State::$transaction !== null) [State::$rows, State::$audits] = State::$transaction; State::$transaction = null; }
    }
}
namespace plugin\SandIam\app\service {
    use MfaManagementTest\State;
    use plugin\SandIam\app\model\{Application, Identity};
    class HumanAuthService {
        public function authenticatedPrincipal(string $token): array { return [new Application(['id' => 2, 'organization_id' => 1]), new Identity(['id' => 3])]; }
        public function assertCurrentPassword(string $token, string $password, string $ip, string $requestId): void {
            if ($password !== 'fixture-password') throw new \plugin\sandadmin\exception\ApiException('password rejected', 401);
        }
    }
    class AuditWriter {
        public function write(mixed ...$args): void { State::$audits[] = $args; if (State::$fail) throw new \RuntimeException('audit failure'); }
    }
}
namespace {
    use MfaManagementTest\State;
    use function MfaManagementTest\check;
    use plugin\SandIam\app\model\{MfaFactor, MfaRecoveryCode, WebauthnCredential};
    use plugin\SandIam\app\service\MfaService;
    use plugin\sandadmin\exception\ApiException;
    require __DIR__ . '/../app/service/MfaService.php';
    $factor = ['id' => 4, 'application_id' => 2, 'identity_id' => 3, 'name' => 'First', 'status' => 1, 'revoked_time' => null];
    State::$rows = [
        MfaFactor::class => [4 => $factor],
        WebauthnCredential::class => [4 => $factor],
        MfaRecoveryCode::class => [
            5 => ['id' => 5, 'application_id' => 2, 'identity_id' => 3, 'factor_id' => 4, 'status' => 1],
            6 => ['id' => 6, 'application_id' => 9, 'identity_id' => 3, 'factor_id' => 4, 'status' => 1],
        ],
    ];
    $baseline = [State::$rows, State::$audits];
    $service = new MfaService();
    foreach (['totp' => MfaFactor::class, 'passkey' => WebauthnCredential::class] as $type => $model) {
        foreach (['rename', 'revoke'] as $method) {
            [State::$rows, State::$audits] = $baseline;
            $operation = static fn () => $method === 'rename'
                ? $service->rename('fixture', 4, 'Second', 'manage', $type)
                : $service->revoke('fixture', 4, 'fixture-password', 'manage', $type);
            State::$fail = true;
            try { $operation(); throw new \RuntimeException('Audit failure ignored'); }
            catch (\RuntimeException $exception) { check($exception->getMessage() === 'audit failure', $exception->getMessage()); }
            check([State::$rows, State::$audits] === $baseline && State::$transaction === null, 'Failed management retained state or audit');
            State::$fail = false;
            $operation();
            check(State::$transaction === null && count(State::$audits) === 1, 'Success transaction or audit incorrect');
            check(State::$audits[0][2] === 1 && State::$audits[0][3] === 2, 'Audit scope incorrect');
            check(State::$rows[$model][4][$method === 'rename' ? 'name' : 'status'] === ($method === 'rename' ? 'Second' : 2), 'Management result missing');
            check(State::$rows[MfaRecoveryCode::class][5]['status'] === ($method === 'revoke' && $type === 'totp' ? 2 : 1), 'Recovery code handling incorrect');
            check(State::$rows[MfaRecoveryCode::class][6] === $baseline[0][MfaRecoveryCode::class][6], 'Foreign application changed');
        }
        [State::$rows, State::$audits] = $baseline;
        try { $service->revoke('fixture', 4, 'wrong', 'denied', $type); throw new \RuntimeException('Wrong password accepted'); }
        catch (ApiException $exception) { check($exception->getCode() === 401, 'Wrong password error'); }
        check([State::$rows, State::$audits] === $baseline && State::$transaction === null, 'Password rejection changed state');
        foreach ([['application_id' => 9], ['identity_id' => 9], ['status' => 2], null] as $change) {
            foreach (['rename', 'revoke'] as $method) {
                [State::$rows, State::$audits] = $baseline;
                if ($change === null) unset(State::$rows[$model][4]);
                else State::$rows[$model][4] = array_replace(State::$rows[$model][4], $change);
                $before = [State::$rows, State::$audits];
                try {
                    if ($method === 'rename') $service->rename('fixture', 4, 'Forbidden', 'scope-denied', $type);
                    else $service->revoke('fixture', 4, 'fixture-password', 'scope-denied', $type);
                    throw new \RuntimeException('Unavailable factor accepted');
                } catch (ApiException $exception) {
                    check($exception->getCode() === 404 && $exception->getMessage() === 'SAND_IAM_MFA_FACTOR_NOT_FOUND', 'Factor rejection disclosed scope or used wrong error');
                }
                check([State::$rows, State::$audits] === $before && State::$transaction === null, 'Rejected factor management changed state');
            }
        }
    }
    echo "MFA TOTP/passkey management audit atomicity non-PG behavior PASS\n";
    echo "MFA management application/identity/status/missing isolation: 16 cases PASS\n";
}
