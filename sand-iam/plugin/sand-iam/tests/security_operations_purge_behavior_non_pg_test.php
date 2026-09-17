<?php

declare(strict_types=1);

namespace SecurityOperationsPurgeTest {
    final class State
    {
        /** @var array<string,list<array<string,mixed>>> */
        public static array $tables = [];
        /** @var list<array<int,mixed>> */
        public static array $audits = [];
        public static bool $enabled = true;
        public static bool $failAudit = false;
        public static ?string $failDeleteTable = null;
    }

    final class Result
    {
        /** @param list<array<string,mixed>> $rows */
        public function __construct(private array $rows) {}
        /** @return list<array<string,mixed>> */
        public function toArray(): array { return $this->rows; }
    }

    final class TableQuery
    {
        /** @var list<callable(array<string,mixed>):bool> */
        private array $filters = [];
        private int $limit = PHP_INT_MAX;

        public function __construct(private string $table) {}
        public function where(string $field, mixed $operator, mixed $value = null): self
        {
            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }
            $this->filters[] = static function (array $row) use ($field, $operator, $value): bool {
                $actual = $row[$field] ?? null;
                return match ($operator) {
                    '=' => $actual === $value,
                    '<' => (string) $actual < (string) $value,
                    default => throw new \RuntimeException('unsupported operator'),
                };
            };
            return $this;
        }
        /** @param list<int> $values */
        public function whereIn(string $field, array $values): self
        {
            $this->filters[] = static fn (array $row): bool => in_array((int) ($row[$field] ?? 0), $values, true);
            return $this;
        }
        public function field(string $fields): self { return $this; }
        public function order(string $field): self { return $this; }
        public function limit(int $limit): self { $this->limit = $limit; return $this; }
        public function lock(string $lock): self { return $this; }
        public function select(): Result { return new Result(array_slice($this->matching(), 0, $this->limit)); }
        public function delete(): int
        {
            if (State::$failDeleteTable === $this->table) {
                throw new \RuntimeException("delete failed for {$this->table}");
            }
            $deleted = 0;
            State::$tables[$this->table] = array_values(array_filter(
                State::$tables[$this->table] ?? [],
                function (array $row) use (&$deleted): bool {
                    if (!$this->matches($row)) return true;
                    ++$deleted;
                    return false;
                },
            ));
            return $deleted;
        }
        /** @return list<array<string,mixed>> */
        private function matching(): array
        {
            return array_values(array_filter(State::$tables[$this->table] ?? [], fn (array $row): bool => $this->matches($row)));
        }
        /** @param array<string,mixed> $row */
        private function matches(array $row): bool
        {
            foreach ($this->filters as $filter) if (!$filter($row)) return false;
            return true;
        }
    }

    final class PolicyQuery
    {
        /** @var array<string,mixed> */
        private array $filters = [];
        public function where(string $field, mixed $value): self { $this->filters[$field] = $value; return $this; }
        public function lock(bool $lock): self { return $this; }
        public function find(): ?object
        {
            foreach (State::$tables['sand_iam_audit_retention_policy'] ?? [] as $row) {
                foreach ($this->filters as $field => $value) if (($row[$field] ?? null) !== $value) continue 2;
                return (object) $row;
            }
            return null;
        }
    }

    function reset(array $archives, array $hot): void
    {
        State::$tables = [
            'sand_iam_audit_retention_policy' => [[
                'organization_id' => 7,
                'status' => 1,
                'purge_enabled' => 1,
                'retention_days' => 30,
            ]],
            'sand_iam_audit_archive' => $archives,
            'sand_iam_audit_log' => $hot,
        ];
        State::$audits = [];
        State::$enabled = true;
        State::$failAudit = false;
        State::$failDeleteTable = null;
    }

    function confirmation(): string
    {
        $cutoff = date('Y-m-d', time() - 30 * 86400);
        return hash('sha256', "sand-iam-audit-purge\0" . 7 . "\0{$cutoff}");
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
    }
}

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}

namespace plugin\SandIam\app\model {
    class AuditRetentionPolicy
    {
        public static function where(string $field, mixed $value): \SecurityOperationsPurgeTest\PolicyQuery
        {
            return (new \SecurityOperationsPurgeTest\PolicyQuery())->where($field, $value);
        }
    }
    class AuditArchive {}
    class AuditLog {}
    class SecurityAlert {}
}

namespace think\facade {
    final class Db
    {
        private static ?string $snapshot = null;
        public static function startTrans(): void
        {
            self::$snapshot = serialize([
                \SecurityOperationsPurgeTest\State::$tables,
                \SecurityOperationsPurgeTest\State::$audits,
            ]);
        }
        public static function commit(): void { self::$snapshot = null; }
        public static function rollback(): void
        {
            if (self::$snapshot === null) return;
            [
                \SecurityOperationsPurgeTest\State::$tables,
                \SecurityOperationsPurgeTest\State::$audits,
            ] = unserialize(self::$snapshot);
            self::$snapshot = null;
        }
        public static function table(string $table): \SecurityOperationsPurgeTest\TableQuery
        {
            return new \SecurityOperationsPurgeTest\TableQuery($table);
        }
    }
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        public function write(mixed ...$values): void
        {
            \SecurityOperationsPurgeTest\State::$audits[] = $values;
            if (\SecurityOperationsPurgeTest\State::$failAudit) {
                throw new \RuntimeException('purge audit unavailable');
            }
        }
    }
}

namespace {
    use plugin\SandIam\app\service\SecurityOperationsService;
    use plugin\sandadmin\exception\ApiException;
    use SecurityOperationsPurgeTest\State;
    use function SecurityOperationsPurgeTest\check;
    use function SecurityOperationsPurgeTest\confirmation;
    use function SecurityOperationsPurgeTest\reset;

    function config(string $key, mixed $default = null): mixed
    {
        return $key === 'plugin.sand-iam.app.audit_purge_enabled'
            ? (State::$enabled ? 1 : 0)
            : $default;
    }

    require dirname(__DIR__) . '/app/service/SecurityOperationsService.php';

    reset(
        [
            ['id' => 10, 'original_audit_id' => 100, 'organization_id' => 7, 'original_create_time' => '2020-01-01 00:00:00', 'delete_time' => null],
            ['id' => 11, 'original_audit_id' => 101, 'organization_id' => 7, 'original_create_time' => '2020-01-02 00:00:00', 'delete_time' => '2025-01-01 00:00:00'],
            ['id' => 12, 'original_audit_id' => 102, 'organization_id' => 8, 'original_create_time' => '2020-01-03 00:00:00', 'delete_time' => null],
        ],
        [
            ['id' => 100, 'organization_id' => 7, 'create_time' => '2020-01-01 00:00:00'],
            ['id' => 101, 'organization_id' => 7, 'create_time' => '2020-01-02 00:00:00'],
            ['id' => 102, 'organization_id' => 8, 'create_time' => '2020-01-03 00:00:00'],
        ],
    );
    $result = (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-behavior');
    check($result === ['purged' => 2, 'physical_rows' => 4], 'purge counts do not distinguish events and physical rows');
    check(array_column(State::$tables['sand_iam_audit_archive'], 'id') === [12], 'raw purge did not remove active and historical soft-deleted archive rows');
    check(array_column(State::$tables['sand_iam_audit_log'], 'id') === [102], 'purge escaped the authorized organization or left selected hot rows');
    check(count(State::$audits) === 1 && (State::$audits[0][9]['physical_rows'] ?? null) === 4, 'purge audit did not record physical row count');

    reset(
        [['id' => 20, 'original_audit_id' => 200, 'organization_id' => 7, 'original_create_time' => '2020-01-01 00:00:00', 'delete_time' => null]],
        [['id' => 200, 'organization_id' => 8, 'create_time' => '2020-01-01 00:00:00']],
    );
    $before = serialize(State::$tables);
    try {
        (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-conflict');
        throw new \RuntimeException('cross-organization archive mapping was accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_AUDIT_PURGE_SCOPE_CONFLICT'), 'scope conflict returned the wrong error');
    }
    check(serialize(State::$tables) === $before && State::$audits === [], 'scope conflict did not roll back every mutation');

    reset(
        [
            ['id' => 30, 'original_audit_id' => 300, 'organization_id' => 7, 'original_create_time' => '2020-01-01 00:00:00', 'delete_time' => null],
            ['id' => 31, 'original_audit_id' => 301, 'organization_id' => 7, 'original_create_time' => '2020-01-02 00:00:00', 'delete_time' => null],
            ['id' => 32, 'original_audit_id' => 302, 'organization_id' => 7, 'original_create_time' => '2999-01-01 00:00:00', 'delete_time' => null],
        ],
        [
            ['id' => 300, 'organization_id' => 7, 'create_time' => '2020-01-01 00:00:00'],
            ['id' => 301, 'organization_id' => 7, 'create_time' => '2020-01-02 00:00:00'],
            ['id' => 302, 'organization_id' => 7, 'create_time' => '2999-01-01 00:00:00'],
        ],
    );
    $limited = (new SecurityOperationsService())->purgeBatch(7, confirmation(), 1, 'purge-limit');
    check($limited === ['purged' => 1, 'physical_rows' => 2], 'purge did not honor the event batch limit');
    check(array_column(State::$tables['sand_iam_audit_archive'], 'id') === [31, 32], 'purge removed an unselected or not-yet-due archive');
    check(array_column(State::$tables['sand_iam_audit_log'], 'id') === [301, 302], 'purge removed an unselected or not-yet-due hot row');
    $remainingDue = (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-cutoff');
    check($remainingDue === ['purged' => 1, 'physical_rows' => 2], 'purge did not isolate the remaining due event');
    check(array_column(State::$tables['sand_iam_audit_archive'], 'id') === [32], 'purge removed a not-yet-due archive');
    check(array_column(State::$tables['sand_iam_audit_log'], 'id') === [302], 'purge removed a not-yet-due hot row');

    reset(
        [['id' => 40, 'original_audit_id' => 400, 'organization_id' => 7, 'original_create_time' => '2020-01-01 00:00:00', 'delete_time' => null]],
        [['id' => 400, 'organization_id' => 7, 'create_time' => '2999-01-01 00:00:00']],
    );
    $before = serialize(State::$tables);
    try {
        (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-hot-time');
        throw new \RuntimeException('hot retention conflict was accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_AUDIT_PURGE_SCOPE_CONFLICT'), 'hot retention conflict returned the wrong error');
    }
    check(serialize(State::$tables) === $before, 'hot retention conflict changed persisted rows');

    reset(
        [['id' => 50, 'original_audit_id' => 500, 'organization_id' => 7, 'original_create_time' => '2020-01-01 00:00:00', 'delete_time' => null]],
        [['id' => 500, 'organization_id' => 7, 'create_time' => '2020-01-01 00:00:00']],
    );
    State::$failAudit = true;
    $before = serialize(State::$tables);
    try {
        (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-audit-failure');
        throw new \RuntimeException('purge audit failure was hidden');
    } catch (\RuntimeException $exception) {
        check($exception->getMessage() === 'purge audit unavailable', 'purge audit failure returned the wrong error');
    }
    check(serialize(State::$tables) === $before && State::$audits === [], 'purge audit failure did not roll back deleted rows and audit');

    reset(
        [['id' => 60, 'original_audit_id' => 600, 'organization_id' => 7, 'original_create_time' => '2020-01-01 00:00:00', 'delete_time' => null]],
        [['id' => 600, 'organization_id' => 7, 'create_time' => '2020-01-01 00:00:00']],
    );
    State::$failDeleteTable = 'sand_iam_audit_archive';
    $before = serialize(State::$tables);
    try {
        (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-second-delete-failure');
        throw new \RuntimeException('second table delete failure was hidden');
    } catch (\RuntimeException $exception) {
        check($exception->getMessage() === 'delete failed for sand_iam_audit_archive', 'second table delete returned the wrong error');
    }
    check(serialize(State::$tables) === $before && State::$audits === [], 'second table delete failure did not restore the first table');

    reset(
        [['id' => 70, 'original_audit_id' => 700, 'organization_id' => 7, 'original_create_time' => '2020-01-01 00:00:00', 'delete_time' => '2025-01-01 00:00:00']],
        [],
    );
    $archiveOnly = (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-archive-only');
    check($archiveOnly === ['purged' => 1, 'physical_rows' => 1], 'archive-only historical soft delete returned the wrong counts');
    check(State::$tables['sand_iam_audit_archive'] === [] && State::$tables['sand_iam_audit_log'] === [], 'archive-only historical soft delete was not physically removed');

    reset([], []);
    State::$enabled = false;
    try {
        (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-disabled');
        throw new \RuntimeException('disabled deployment purge was accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 403 && str_contains($exception->getMessage(), 'SAND_IAM_AUDIT_PURGE_DISABLED'), 'disabled deployment returned the wrong error');
    }
    State::$enabled = true;
    State::$tables['sand_iam_audit_retention_policy'][0]['purge_enabled'] = 0;
    try {
        (new SecurityOperationsService())->purgeBatch(7, confirmation(), 10, 'purge-policy-disabled');
        throw new \RuntimeException('disabled policy purge was accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 403 && str_contains($exception->getMessage(), 'SAND_IAM_AUDIT_PURGE_DISABLED'), 'disabled policy returned the wrong error');
    }
    State::$tables['sand_iam_audit_retention_policy'][0]['purge_enabled'] = 1;
    try {
        (new SecurityOperationsService())->purgeBatch(7, str_repeat('0', 64), 10, 'purge-bad-confirmation');
        throw new \RuntimeException('invalid confirmation was accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 403 && str_contains($exception->getMessage(), 'SAND_IAM_AUDIT_PURGE_CONFIRMATION_INVALID'), 'invalid confirmation returned the wrong error');
    }

    echo "security operations physical purge behavior PASS (non-PG)\n";
}
