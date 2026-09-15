<?php

declare(strict_types=1);

namespace SyncOwnershipTest {
    final class Statement extends \PDOStatement {
        public function __construct(private readonly bool $result) {}
        public function execute(?array $params = null): bool { return true; }
        public function fetchColumn(int $column = 0): mixed { return $this->result; }
    }
    final class Database extends \PDO {
        public bool $alive = true;
        public bool $transaction = false;
        public bool $lockAvailable = true;
        public bool $unlockWorks = true;
        public int $unlocks = 0;
        public function __construct() {}
        public function inTransaction(): bool { return $this->transaction; }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false {
            if (!$this->alive) throw new \RuntimeException('connection closed');
            return new Statement(true);
        }
        public function prepare(string $query, array $options = []): \PDOStatement|false {
            if (!$this->alive) throw new \RuntimeException('connection closed');
            if (str_contains($query, 'pg_advisory_unlock')) { $this->unlocks++; return new Statement($this->unlockWorks); }
            return new Statement($this->lockAvailable);
        }
    }
    final class Connection {
        public bool $replaceOnStart = false;
        public bool $closed = false;
        public int $commits = 0;
        public string $type = 'pgsql';
        public function __construct(public Database $pdo = new Database()) {}
        public function getConfig(string $key): mixed { return $key === 'type' ? $this->type : false; }
        public function getPdo(): Database { return $this->pdo; }
        public function query(string $sql, array $bind, bool $master): array { return []; }
        public function startTrans(): void {
            if ($this->replaceOnStart) $this->pdo = new Database();
            $this->pdo->transaction = true;
        }
        public function commit(): void { $this->pdo->transaction = false; $this->commits++; }
        public function rollback(): void { $this->pdo->transaction = false; }
        public function close(): void { $this->closed = true; }
    }
    final class Query {
        private array $filters = [];
        public function __construct(private readonly string $model) {}
        public function where(string $field, mixed $value): self { $this->filters[$field] = $value; return $this; }
        public function lock(bool $value): self { return $this; }
        public function find(): ?object {
            foreach ($this->model::$rows as $row) {
                foreach ($this->filters as $key => $value) if (($row->$key ?? null) !== $value) continue 2;
                return $row;
            }
            return null;
        }
    }
    function check(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace think\facade {
    final class Db {
        public static \SyncOwnershipTest\Connection $connection;
        public static function connect(): \SyncOwnershipTest\Connection { return self::$connection; }
    }
}
namespace plugin\SandIam\app\model {
    abstract class Record {
        public static function where(string $key, mixed $value): \SyncOwnershipTest\Query { return (new \SyncOwnershipTest\Query(static::class))->where($key, $value); }
    }
    final class SyncConnector extends Record { public static array $rows = []; }
    final class SyncRun extends Record { public static array $rows = []; }
}
namespace {
    use SyncOwnershipTest\Connection;
    use SyncOwnershipTest\Database;
    use function SyncOwnershipTest\check;
    use plugin\SandIam\app\service\SyncRunOwnership;
    use plugin\SandIam\app\model\{SyncConnector, SyncRun};
    use plugin\sandadmin\exception\ApiException;
    use think\facade\Db;
    require dirname(__DIR__) . '/app/service/SyncRunOwnership.php';
    SyncConnector::$rows = [(object) ['id' => 1, 'application_id' => 10]];
    SyncRun::$rows = [(object) ['id' => 2, 'sync_connector_id' => 1, 'application_id' => 10, 'state' => 'running']];
    $denied = static function (callable $operation, string $code): void {
        try { $operation(); } catch (ApiException $e) { check($e->getMessage() === $code, 'wrong rejection'); return; }
        throw new \RuntimeException('missing rejection: ' . $code);
    };
    Db::$connection = new Connection();
    $owner = SyncRunOwnership::acquire(1, 10);
    $denied(static fn () => SyncRunOwnership::acquire(1, 10), 'SAND_IAM_SYNC_ALREADY_RUNNING');
    check($owner->transaction(2, static fn ($connector, $run) => $run->id) === 2, 'owned transaction failed');
    check(Db::$connection->commits === 1, 'owned transaction not committed');
    SyncRun::$rows[0]->state = 'failed';
    $writes = 0;
    $denied(static function () use ($owner, &$writes) { return $owner->transaction(2, static function () use (&$writes) { $writes++; }); }, 'SAND_IAM_SYNC_RUN_OWNERSHIP_LOST');
    check($writes === 0, 'terminal run reached mutation');
    $owner->release(); $owner->release();
    check(Db::$connection->pdo->unlocks === 1, 'release not idempotent');
    SyncRun::$rows[0]->state = 'running';
    foreach (['replacement', 'disconnect', 'start_replacement'] as $fault) {
        Db::$connection = new Connection();
        $original = Db::$connection->pdo;
        $owner = SyncRunOwnership::acquire(1, 10);
        if ($fault === 'replacement') Db::$connection->pdo = new Database();
        if ($fault === 'disconnect') $original->alive = false;
        if ($fault === 'start_replacement') Db::$connection->replaceOnStart = true;
        $writes = 0;
        $denied(static function () use ($owner, &$writes) { return $owner->transaction(2, static function () use (&$writes) { $writes++; }); }, 'SAND_IAM_SYNC_RUN_OWNERSHIP_LOST');
        check($writes === 0 && Db::$connection->commits === 0, 'lost session reached mutation or commit');
        $owner->release();
        if ($fault !== 'disconnect') check($original->unlocks === 1, 'unlock used replacement connection');
    }
    Db::$connection = new Connection();
    Db::$connection->pdo->lockAvailable = false;
    $denied(static fn () => SyncRunOwnership::acquire(1, 10), 'SAND_IAM_SYNC_ALREADY_RUNNING');
    Db::$connection = new Connection();
    $owner = SyncRunOwnership::acquire(1, 10);
    Db::$connection->pdo->unlockWorks = false;
    $owner->release();
    check(Db::$connection->closed, 'possibly locked connection returned to pool');
    echo "Sync run ownership implementation regression PASS (PDO/ORM substitutes, no database)\n";
}
