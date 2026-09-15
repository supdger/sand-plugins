<?php

declare(strict_types=1);

namespace SyncRunCompletionTest {
    final class State
    {
        public static string $auditFailure = 'none';
        public static string $failedSaveFailure = 'none';
        public static bool $driverFailure = false;
        public static bool $logFailure = false;
        public static array $audits = [];
        public static array $logs = [];
    }

    #[\AllowDynamicProperties]
    class Record
    {
        public function __construct(array $values = [])
        {
            foreach ($values as $key => $value) {
                $this->$key = $value;
            }
        }

        public function save(array $values): bool
        {
            foreach ($values as $key => $value) {
                $this->$key = $value;
            }
            static::$rows[$this->id] = $this;
            return true;
        }

        public static function create(array $values): static
        {
            $values['id'] ??= max([0, ...array_keys(static::$rows)]) + 1;
            return static::$rows[$values['id']] = new static($values);
        }

        public static function where(string $field, mixed $value): Query
        {
            return (new Query(static::class))->where($field, $value);
        }

        public function refresh(): static
        {
            $stored = static::$rows[$this->id] ?? null;
            if ($stored === null) {
                throw new \RuntimeException('cannot refresh missing record');
            }
            foreach (array_keys(get_object_vars($this)) as $key) {
                unset($this->$key);
            }
            foreach (get_object_vars($stored) as $key => $value) {
                $this->$key = $value;
            }
            return $this;
        }
    }

    final class Query
    {
        private array $filters = [];

        public function __construct(private readonly string $model) {}

        public function where(string $field, mixed $value): self
        {
            $this->filters[] = [$field, $value];
            return $this;
        }

        public function lock(bool $lock): self { return $this; }
        public function order(string $field, string $direction): self { return $this; }
        public function limit(int $limit): self { return $this; }

        private function rows(): array
        {
            return array_values(array_filter(
                $this->model::$rows,
                function (Record $row): bool {
                    foreach ($this->filters as [$field, $value]) {
                        if (($row->$field ?? null) !== $value) {
                            return false;
                        }
                    }
                    return true;
                },
            ));
        }

        public function find(): ?Record { return $this->rows()[0] ?? null; }
        public function select(): Result { return new Result($this->rows()); }
    }

    final class Result implements \Countable, \IteratorAggregate
    {
        public function __construct(private readonly array $rows) {}
        public function count(): int { return count($this->rows); }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

namespace plugin\sandadmin\exception {
    class ApiException extends \RuntimeException {}
}

namespace support {
    final class Log
    {
        public static function error(string $message, array $context = []): void
        {
            \SyncRunCompletionTest\State::$logs[] = [$message, $context];
            if (\SyncRunCompletionTest\State::$logFailure) {
                throw new \RuntimeException('logger unavailable');
            }
        }

        public static function warning(string $message, array $context = []): void
        {
            \SyncRunCompletionTest\State::$logs[] = [$message, $context];
        }
    }
}

namespace plugin\SandIam\app\model {
    class Application extends \SyncRunCompletionTest\Record { public static array $rows = []; }
    class Identity extends \SyncRunCompletionTest\Record { public static array $rows = []; }
    class IdentityGroup extends \SyncRunCompletionTest\Record { public static array $rows = []; }
    class IdentityGroupMember extends \SyncRunCompletionTest\Record { public static array $rows = []; }
    class SyncConnector extends \SyncRunCompletionTest\Record { public static array $rows = []; }
    class SyncOutbox extends \SyncRunCompletionTest\Record { public static array $rows = []; }
    class SyncResource extends \SyncRunCompletionTest\Record { public static array $rows = []; }

    class SyncRun extends \SyncRunCompletionTest\Record
    {
        public static array $rows = [];

        public function save(array $values): bool
        {
            if (($values['state'] ?? null) === 'failed') {
                if (\SyncRunCompletionTest\State::$failedSaveFailure === 'throw') {
                    throw new \RuntimeException('failed run persistence unavailable');
                }
                if (\SyncRunCompletionTest\State::$failedSaveFailure === 'false') {
                    return false;
                }
            }
            return parent::save($values);
        }
    }
}

namespace think\facade {
    final class Db
    {
        public static bool $active = false;
        private static string $snapshot = '';

        public static function startTrans(): void
        {
            if (self::$active) {
                throw new \RuntimeException('nested transaction');
            }
            self::$snapshot = serialize([
                \plugin\SandIam\app\model\SyncConnector::$rows,
                \plugin\SandIam\app\model\SyncRun::$rows,
                \SyncRunCompletionTest\State::$audits,
            ]);
            self::$active = true;
        }

        public static function commit(): void
        {
            self::$active = false;
            self::$snapshot = '';
        }

        public static function rollback(): void
        {
            [
                \plugin\SandIam\app\model\SyncConnector::$rows,
                \plugin\SandIam\app\model\SyncRun::$rows,
                \SyncRunCompletionTest\State::$audits,
            ] = unserialize(self::$snapshot);
            self::$active = false;
            self::$snapshot = '';
        }
    }
}

namespace plugin\SandIam\app\service {
    final class SyncSecretCipher
    {
        public function decryptArray(string $value): array { return ['safe' => 'config']; }
        public function decrypt(string $value): string { return $value; }
        public function encrypt(string $value): string { return $value; }
        public function encryptArray(array $value): string { return 'encrypted'; }
    }

    final class AuditWriter
    {
        public function write(mixed ...$values): void
        {
            \SyncRunCompletionTest\State::$audits[] = $values;
            $outcome = $values[7] ?? null;
            if (\SyncRunCompletionTest\State::$auditFailure === 'all'
                || (\SyncRunCompletionTest\State::$auditFailure === 'success' && $outcome === 'succeeded')) {
                throw new \RuntimeException($outcome === 'succeeded'
                    ? 'success audit unavailable with sensitive detail'
                    : 'failed audit unavailable with sensitive detail');
            }
        }
    }

    final class SyncRunOwnership
    {
        private function __construct(private readonly int $connectorId, private readonly int $applicationId) {}

        public static function acquire(int $connectorId, int $applicationId): self
        {
            return new self($connectorId, $applicationId);
        }

        public function check(): void {}
        public function release(): void {}

        public function transaction(?int $runId, callable $operation, array $states = ['running']): mixed
        {
            \think\facade\Db::startTrans();
            try {
                $storedConnector = \plugin\SandIam\app\model\SyncConnector::$rows[$this->connectorId] ?? null;
                if ($storedConnector === null || (int) $storedConnector->application_id !== $this->applicationId) {
                    throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_SYNC_RUN_OWNERSHIP_LOST', 409);
                }
                $connector = new \plugin\SandIam\app\model\SyncConnector(get_object_vars($storedConnector));
                $run = null;
                if ($runId !== null) {
                    $storedRun = \plugin\SandIam\app\model\SyncRun::$rows[$runId] ?? null;
                    if ($storedRun === null || (int) $storedRun->sync_connector_id !== $this->connectorId
                        || (int) $storedRun->application_id !== $this->applicationId
                        || !in_array((string) $storedRun->state, $states, true)) {
                        throw new \plugin\sandadmin\exception\ApiException('SAND_IAM_SYNC_RUN_OWNERSHIP_LOST', 409);
                    }
                    $run = new \plugin\SandIam\app\model\SyncRun(get_object_vars($storedRun));
                }
                $result = $operation($connector, $run);
                \think\facade\Db::commit();
                return $result;
            } catch (\Throwable $exception) {
                \think\facade\Db::rollback();
                throw $exception;
            }
        }
    }
}

namespace {
    function config(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'plugin.sand-iam.app.identity_lifecycle_enabled' => 1,
            'plugin.sand-iam.app.sync_drivers' => ['fixture' => \SyncRunCompletionTest\Driver::class],
            'plugin.sand-iam.app.sync_reference_pepper' => str_repeat('p', 32),
            default => $default,
        };
    }
}

namespace SyncRunCompletionTest {
    require dirname(__DIR__) . '/app/sync/SyncDriverInterface.php';

    final class Driver implements \plugin\SandIam\app\sync\SyncDriverInterface
    {
        public static function capabilities(): array { return ['inbound' => true, 'outbound' => true]; }
        public static function validateConfig(array $config): void {}
        public static function test(array $config): void {}

        public static function pullPage(array $config, ?string $cursor, int $limit): array
        {
            if (State::$driverFailure) {
                throw new \RuntimeException('SAND_IAM_SYNC_DRIVER_DOWN: upstream sensitive detail');
            }
            return ['records' => [], 'next_cursor' => null, 'has_more' => false, 'full_snapshot' => false];
        }

        public static function pushBatch(array $config, array $events): array { return []; }
    }
}

namespace {
    use SyncRunCompletionTest\State;
    use function SyncRunCompletionTest\check;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\SyncConnector;
    use plugin\SandIam\app\model\SyncOutbox;
    use plugin\SandIam\app\model\SyncResource;
    use plugin\SandIam\app\model\SyncRun;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\SyncConnectorService;
    use plugin\SandIam\app\service\SyncSecretCipher;
    use plugin\sandadmin\exception\ApiException;
    use think\facade\Db;

    require dirname(__DIR__) . '/app/service/SyncConnectorService.php';

    Application::$rows = [
        10 => new Application(['id' => 10, 'organization_id' => 100, 'status' => 1]),
    ];
    $service = new SyncConnectorService(new SyncSecretCipher(), new AuditWriter());

    $fixture = static function (): void {
        SyncConnector::$rows = [
            1 => new SyncConnector([
                'id' => 1,
                'organization_id' => 100,
                'application_id' => 10,
                'driver_code' => 'fixture',
                'direction' => 'inbound',
                'encrypted_config' => 'encrypted-secret-config',
                'encrypted_cursor' => null,
                'authority_map' => [],
                'missing_protection_hours' => 24,
                'disable_threshold_percent' => 20,
                'config_version' => 3,
                'status' => 1,
            ]),
        ];
        SyncRun::$rows = [];
        SyncOutbox::$rows = [];
        SyncResource::$rows = [];
        State::$auditFailure = 'none';
        State::$failedSaveFailure = 'none';
        State::$driverFailure = false;
        State::$logFailure = false;
        State::$audits = [];
        State::$logs = [];
        Db::$active = false;
    };
    $run = static fn (): array => $service->run(1, 10, 'admin:7', 'sync-run-request');
    $expectRuntime = static function (callable $operation, string $message): void {
        try {
            $operation();
            throw new \RuntimeException('expected runtime failure was hidden');
        } catch (\RuntimeException $exception) {
            check($exception->getMessage() === $message, "wrong exception: {$exception->getMessage()}");
        }
        check(!Db::$active, 'failure left a transaction open');
    };
    $outcomes = static fn (): array => array_map(static fn (array $audit): mixed => $audit[7] ?? null, State::$audits);
    $states = static fn (): array => array_values(array_map(static fn (SyncRun $item): string => (string) $item->state, SyncRun::$rows));

    $fixture();
    $result = $run();
    check($result['state'] === 'succeeded', 'normal run did not return succeeded');
    check($states() === ['succeeded'], 'normal run did not persist one succeeded run');
    check($outcomes() === ['succeeded'], 'normal run did not persist exactly one succeeded audit');

    $fixture();
    State::$auditFailure = 'success';
    $expectRuntime($run, 'success audit unavailable with sensitive detail');
    check($states() === ['failed'], 'success audit failure did not leave a failed run');
    check($outcomes() === ['failed'], 'success audit failure left a succeeded audit or lost failed audit');

    $fixture();
    State::$auditFailure = 'all';
    $expectRuntime($run, 'success audit unavailable with sensitive detail');
    check($states() === ['failed'], 'all-audit failure did not persist failed state');
    check(State::$audits === [], 'all-audit failure left an audit appended outside its transaction');
    check(count(State::$logs) === 1, 'failed audit failure did not emit one safe diagnostic');
    $log = State::$logs[0];
    $serializedLog = serialize($log);
    check(str_contains($serializedLog, 'RuntimeException'), 'safe diagnostic omitted exception type');
    check(str_contains($serializedLog, '1'), 'safe diagnostic omitted run id');
    check(!str_contains($serializedLog, 'sensitive detail'), 'safe diagnostic leaked exception detail');
    check(!str_contains($serializedLog, 'encrypted-secret-config'), 'safe diagnostic leaked connector config');

    $fixture();
    State::$auditFailure = 'all';
    State::$logFailure = true;
    $expectRuntime($run, 'success audit unavailable with sensitive detail');
    check($states() === ['failed'], 'logger failure changed the persisted failed state');
    check(State::$audits === [], 'logger failure changed failed-audit rollback');

    $fixture();
    State::$driverFailure = true;
    $expectRuntime($run, 'SAND_IAM_SYNC_DRIVER_DOWN: upstream sensitive detail');
    check($states() === ['failed'], 'driver failure did not persist failed state');
    check($outcomes() === ['failed'], 'driver failure did not persist one failed audit');
    State::$driverFailure = false;
    $result = $run();
    check($result['state'] === 'succeeded', 'recovery run did not succeed');
    check($states() === ['failed', 'succeeded'], 'recovery left a running run or wrong terminal states');
    check($outcomes() === ['failed', 'succeeded'], 'recovery persisted wrong audit outcomes');

    foreach (['throw', 'false'] as $failure) {
        $fixture();
        State::$driverFailure = true;
        State::$failedSaveFailure = $failure;
        if ($failure === 'throw') {
            $expectRuntime($run, 'failed run persistence unavailable');
        } else {
            try {
                $run();
                throw new \RuntimeException('false failed-state save was accepted');
            } catch (ApiException $exception) {
                check($exception->getMessage() === 'SAND_IAM_SYNC_RUN_FAILED', 'false save returned wrong error');
                check($exception->getCode() === 503, 'false save returned wrong status');
            }
            check(!Db::$active, 'false failed-state save left a transaction open');
        }
        check($states() === ['running'], "{$failure} failed-state save falsely claimed terminal state");
        check(State::$audits === [], "{$failure} failed-state save wrote a failed audit");
    }

    echo "Sync run completion and failure persistence behavior PASS (non-PG)\n";
}
