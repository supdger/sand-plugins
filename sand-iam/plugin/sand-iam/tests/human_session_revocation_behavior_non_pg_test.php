<?php
declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class Record {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public function save(array $values): void { foreach ($values as $key => $value) $this->$key = $value; }
        public static function where(string $field, mixed $value): Query { return (new Query(static::class))->where($field, $value); }
        public static function find(int $id): ?self { return static::$rows[$id] ?? null; }
    }
    class Query {
        private array $conditions = [];
        public function __construct(private string $model) {}
        public function where(string $field, mixed $value): self { $this->conditions[$field] = $value; return $this; }
        public static ?\Closure $beforeLock = null;
        public function lock(bool $lock): self {
            if (!\think\facade\Db::$active || !$lock) throw new \RuntimeException('lock outside transaction');
            if (self::$beforeLock !== null) { $callback = self::$beforeLock; self::$beforeLock = null; $callback(); }
            if ($this->model === AuthSession::class) \think\facade\Db::$sessionLocked = true;
            if ($this->model === AuthRefreshToken::class && !\think\facade\Db::$sessionLocked) throw new \RuntimeException('refresh locked before session');
            return $this;
        }
        private function matches(Record $row): bool {
            foreach ($this->conditions as $field => $value) if (($row->$field ?? null) !== $value) return false;
            return true;
        }
        public function find(): ?Record {
            foreach ($this->model::$rows as $row) if ($this->matches($row)) return $row;
            return null;
        }
        public function update(array $values): int {
            if ($this->model === AuthRefreshToken::class && AuthRefreshToken::$fail) throw new \RuntimeException('refresh storage unavailable');
            $count = 0;
            foreach ($this->model::$rows as $row) if ($this->matches($row)) { $row->save($values); $count++; }
            return $count;
        }
    }
    class Application extends Record { public static array $rows = []; }
    class Organization extends Record { public static array $rows = []; }
    class Identity extends Record { public static array $rows = []; }
    class IdentityAuth extends Record { public static array $rows = []; }
    class AuthSession extends Record { public static array $rows = []; }
    class AuthRefreshToken extends Record { public static array $rows = []; public static bool $fail = false; }
}
namespace plugin\SandIam\app\service {
    class RequestId { public static function normalize(string $value): string { return $value; } }
    class IdempotencyService {
        public static function fingerprint(array $value): string { return hash('sha256', serialize($value)); }
        public function execute(mixed ...$args): array {
            \think\facade\Db::startTrans();
            try {
                $result = $args[count($args) - 1]();
                \think\facade\Db::commit();
                return ['replayed' => false, 'result' => $result['result']];
            } catch (\Throwable $error) { \think\facade\Db::rollback(); throw $error; }
        }
    }
    class AuditWriter {
        public static array $events = [];
        public static bool $fail = false;
        public function write(mixed ...$values): void {
            if (self::$fail) throw new \RuntimeException('audit unavailable');
            self::$events[] = $values;
        }
    }
}
namespace think\facade {
    class Db {
        public static bool $active = false;
        public static bool $sessionLocked = false;
        private static string $snapshot;
        public static function startTrans(): void {
            if (self::$active) throw new \RuntimeException('previous transaction not released');
            self::$sessionLocked = false;
            self::$snapshot = serialize([\plugin\SandIam\app\model\AuthSession::$rows, \plugin\SandIam\app\model\AuthRefreshToken::$rows, \plugin\SandIam\app\service\AuditWriter::$events]);
            self::$active = true;
        }
        public static function commit(): void { self::$active = false; }
        public static function rollback(): void {
            [\plugin\SandIam\app\model\AuthSession::$rows, \plugin\SandIam\app\model\AuthRefreshToken::$rows, \plugin\SandIam\app\service\AuditWriter::$events] = unserialize(self::$snapshot);
            self::$active = false;
        }
    }
}
namespace {
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Organization;
    use plugin\SandIam\app\model\Identity;
    use plugin\SandIam\app\model\IdentityAuth;
    use plugin\SandIam\app\model\AuthSession;
    use plugin\SandIam\app\model\AuthRefreshToken;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\HumanAuthService;
    use plugin\sandadmin\exception\ApiException;
    function config(string $key, mixed $default = null): mixed {
        return $key === 'plugin.sand-iam.app.auth_pepper' ? 'offline-test-pepper' : $default;
    }
    function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    function resetRows(): void {
        Application::$rows = [10 => new Application(['id' => 10, 'organization_id' => 20, 'status' => 1])];
        Organization::$rows = [20 => new Organization(['id' => 20, 'status' => 1])];
        Identity::$rows = [30 => new Identity(['id' => 30, 'application_id' => 10, 'status' => 1])];
        IdentityAuth::$rows = [1 => new IdentityAuth(['identity_id' => 30, 'application_id' => 10, 'status' => 1, 'pepper_version' => 'v1'])];
        AuthSession::$rows = AuthRefreshToken::$rows = AuditWriter::$events = [];
        foreach ([1, 2] as $id) {
            AuthSession::$rows[$id] = new AuthSession(['id' => $id, 'identity_id' => 30, 'application_id' => 10, 'status' => 1, 'revoked_time' => null,
                'access_token_hash' => hash_hmac('sha256', 'token:token-' . $id, 'offline-test-pepper'), 'access_expire_time' => '2099-01-01 00:00:00',
                'pepper_version' => 'v1', 'auth_method' => 'local_password']);
            AuthRefreshToken::$rows[$id] = new AuthRefreshToken(['session_id' => $id, 'status' => 1, 'revoked_time' => null, 'used_time' => null,
                'token_hash' => hash_hmac('sha256', 'token:refresh-' . $id, 'offline-test-pepper')]);
        }
    }
    require dirname(__DIR__) . '/app/service/HumanAuthService.php';
    $service = new HumanAuthService();
    foreach (['logout', 'revoke'] as $operation) {
        foreach (['refresh', 'audit'] as $failure) {
            resetRows();
            $before = serialize([AuthSession::$rows, AuthRefreshToken::$rows, AuditWriter::$events]);
            AuthRefreshToken::$fail = $failure === 'refresh';
            AuditWriter::$fail = $failure === 'audit';
            try {
                if ($operation === 'logout') $service->logout('token-1', 'logout-test');
                else $service->revokeSession('token-1', 2, 'revoke-test');
                throw new \RuntimeException('revocation failure hidden');
            } catch (\RuntimeException $error) {
                check($error->getMessage() === ($failure === 'refresh' ? 'refresh storage unavailable' : 'audit unavailable'), 'unexpected revocation failure');
            } finally { AuthRefreshToken::$fail = AuditWriter::$fail = false; }
            check(serialize([AuthSession::$rows, AuthRefreshToken::$rows, AuditWriter::$events]) === $before && !\think\facade\Db::$active, 'failed revocation left partial state');
            if ($operation === 'logout') $service->logout('token-1', 'logout-retry');
            else $service->revokeSession('token-1', 2, 'revoke-retry');
            $id = $operation === 'logout' ? 1 : 2;
            check(AuthSession::$rows[$id]->status === 2 && AuthRefreshToken::$rows[$id]->status === 2 && count(AuditWriter::$events) === 1, 'revocation retry incomplete');
            try { $service->authenticatedSession('token-' . $id); throw new \RuntimeException('revoked token accepted'); }
            catch (ApiException $error) { check($error->getCode() === 401, 'revoked token status changed'); }
            try { $service->refresh('refresh-' . $id, '127.0.0.1', 'revoked-refresh'); throw new \RuntimeException('revoked refresh accepted'); }
            catch (ApiException $error) { check($error->getCode() === 401, 'revoked refresh status changed'); }
        }
    }
    foreach (['delete', 'rebind'] as $change) {
        resetRows();
        \plugin\SandIam\app\model\Query::$beforeLock = static function () use ($change): void {
            if ($change === 'delete') unset(AuthRefreshToken::$rows[1]);
            else AuthRefreshToken::$rows[1]->session_id = 2;
        };
        try { $service->refresh('refresh-1', '127.0.0.1', 'changed-refresh'); throw new \RuntimeException('changed refresh binding accepted'); }
        catch (ApiException $error) { check($error->getCode() === 401, 'changed refresh binding status changed'); }
        check(AuthSession::$rows[1]->status === 1 && AuthSession::$rows[2]->status === 1 && AuditWriter::$events === [], 'changed refresh binding revoked unrelated session');
    }
    resetRows();
    AuthRefreshToken::$rows[1]->status = 2;
    AuthRefreshToken::$rows[1]->used_time = '2026-09-14 12:00:00';
    try { $service->refresh('refresh-1', '127.0.0.1', 'used-refresh'); throw new \RuntimeException('used refresh accepted'); }
    catch (ApiException $error) { check($error->getMessage() === 'SAND_IAM_AUTH_REFRESH_REPLAY_DETECTED', 'used refresh error changed'); }
    check(AuthSession::$rows[1]->status === 2 && AuthSession::$rows[2]->status === 1 && count(AuditWriter::$events) === 1, 'used refresh must revoke its own family');
    echo "Human session revocation behavior PASS (offline real service)\n";
}
