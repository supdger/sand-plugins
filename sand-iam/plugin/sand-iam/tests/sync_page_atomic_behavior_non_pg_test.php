<?php

declare(strict_types=1);

namespace SyncPageAtomicTest {
    final class State
    {
        public static ?int $failConnectorSaveAt = null;
        public static ?int $failRunSaveAt = null;
        public static ?int $falseConnectorSaveAt = null;
        public static ?int $falseRunSaveAt = null;
        public static int $connectorSaves = 0;
        public static int $runSaves = 0;
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

        public static function find(int $id): ?static
        {
            return static::$rows[$id] ?? null;
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

        public static function where(string $field, mixed $value): Query
        {
            return (new Query(static::class))->where($field, $value);
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

        public function lock(bool $lock): self
        {
            return $this;
        }

        public function find(): ?Record
        {
            foreach ($this->model::$rows as $row) {
                foreach ($this->filters as [$field, $value]) {
                    if (($row->$field ?? null) !== $value) {
                        continue 2;
                    }
                }
                return $row;
            }
            return null;
        }
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

namespace plugin\SandIam\app\model {
    class Identity extends \SyncPageAtomicTest\Record
    {
        public static array $rows = [];
    }

    class SyncResource extends \SyncPageAtomicTest\Record
    {
        public static array $rows = [];
    }

    class SyncConnector extends \SyncPageAtomicTest\Record
    {
        public static array $rows = [];

        public function save(array $values): bool
        {
            parent::save($values);
            $save = ++\SyncPageAtomicTest\State::$connectorSaves;
            if (\SyncPageAtomicTest\State::$failConnectorSaveAt === $save) {
                throw new \RuntimeException('connector save failure');
            }
            if (\SyncPageAtomicTest\State::$falseConnectorSaveAt === $save) {
                return false;
            }
            return true;
        }
    }

    class SyncRun extends \SyncPageAtomicTest\Record
    {
        public static array $rows = [];

        public function save(array $values): bool
        {
            parent::save($values);
            $save = ++\SyncPageAtomicTest\State::$runSaves;
            if (\SyncPageAtomicTest\State::$failRunSaveAt === $save) {
                throw new \RuntimeException('run save failure');
            }
            if (\SyncPageAtomicTest\State::$falseRunSaveAt === $save) {
                return false;
            }
            return true;
        }
    }

    class Application extends \SyncPageAtomicTest\Record { public static array $rows = []; }
    class IdentityGroup extends \SyncPageAtomicTest\Record { public static array $rows = []; }
    class IdentityGroupMember extends \SyncPageAtomicTest\Record { public static array $rows = []; }
    class SyncOutbox extends \SyncPageAtomicTest\Record { public static array $rows = []; }
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
                \plugin\SandIam\app\model\Identity::$rows,
                \plugin\SandIam\app\model\SyncResource::$rows,
                \plugin\SandIam\app\model\SyncConnector::$rows,
                \plugin\SandIam\app\model\SyncRun::$rows,
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
                \plugin\SandIam\app\model\Identity::$rows,
                \plugin\SandIam\app\model\SyncResource::$rows,
                \plugin\SandIam\app\model\SyncConnector::$rows,
                \plugin\SandIam\app\model\SyncRun::$rows,
            ] = unserialize(self::$snapshot);
            self::$active = false;
            self::$snapshot = '';
        }
    }
}

namespace plugin\SandIam\app\service {
    final class SyncSecretCipher
    {
        public function encrypt(string $value): string
        {
            return 'encrypted:' . $value;
        }

        public function decrypt(string $value): string
        {
            return str_starts_with($value, 'encrypted:') ? substr($value, 10) : $value;
        }

        public function encryptArray(array $value): string
        {
            return base64_encode(json_encode($value, JSON_THROW_ON_ERROR));
        }

        public function decryptArray(string $value): array
        {
            return json_decode(base64_decode($value, true), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    final class AuditWriter
    {
        public function write(mixed ...$values): void {}
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
                    if ($storedRun === null || (int) $storedRun->application_id !== $this->applicationId
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
        return $key === 'plugin.sand-iam.app.sync_reference_pepper'
            ? str_repeat('p', 32)
            : $default;
    }
}

namespace SyncPageAtomicTest {
    require dirname(__DIR__) . '/app/sync/SyncDriverInterface.php';

    final class Driver implements \plugin\SandIam\app\sync\SyncDriverInterface
    {
        public static bool $twoPages = false;
        public static array $cursors = [];

        public static function capabilities(): array
        {
            return ['inbound' => true, 'outbound' => false];
        }

        public static function validateConfig(array $config): void {}
        public static function test(array $config): void {}

        public static function pullPage(array $config, ?string $cursor, int $limit): array
        {
            self::$cursors[] = $cursor;
            if (!self::$twoPages || $cursor === 'page-1') {
                $number = $cursor === 'page-1' ? 2 : 1;
                return [
                    'records' => [[
                        'source_id' => 'source-' . $number,
                        'version' => 'v1',
                        'display_name' => 'Person ' . $number,
                        'deleted' => false,
                        'attributes' => [],
                    ]],
                    'next_cursor' => null,
                    'has_more' => false,
                    'full_snapshot' => false,
                ];
            }
            return [
                'records' => [[
                    'source_id' => 'source-1',
                    'version' => 'v1',
                    'display_name' => 'Person 1',
                    'deleted' => false,
                    'attributes' => [],
                ]],
                'next_cursor' => 'page-1',
                'has_more' => true,
                'full_snapshot' => false,
            ];
        }

        public static function pushBatch(array $config, array $events): array
        {
            return [];
        }
    }
}

namespace {
    use SyncPageAtomicTest\Driver;
    use SyncPageAtomicTest\State;
    use function SyncPageAtomicTest\check;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Identity;
    use plugin\SandIam\app\model\SyncConnector;
    use plugin\SandIam\app\model\SyncResource;
    use plugin\SandIam\app\model\SyncRun;
    use plugin\SandIam\app\service\SyncConnectorService;
    use plugin\SandIam\app\service\SyncRunOwnership;
    use think\facade\Db;

    require dirname(__DIR__) . '/app/service/SyncConnectorService.php';

    $pull = new ReflectionMethod(SyncConnectorService::class, 'pull');
    $pull->setAccessible(true);
    $zeroCounts = ['pulled' => 0, 'pushed' => 0, 'created' => 0, 'updated' => 0, 'missing' => 0, 'disabled' => 0, 'conflict' => 0];

    $fixture = static function (bool $twoPages = false): array {
        Application::$rows = [
            10 => new Application(['id' => 10, 'organization_id' => 100, 'status' => 1]),
        ];
        Identity::$rows = [];
        SyncResource::$rows = [];
        SyncConnector::$rows = [
            1 => new SyncConnector([
                'id' => 1,
                'organization_id' => 100,
                'application_id' => 10,
                'encrypted_cursor' => null,
                'authority_map' => [],
                'conflict_policy' => 'source_wins',
                'config_version' => 3,
                'status' => 1,
            ]),
        ];
        SyncRun::$rows = [
            5 => new SyncRun([
                'id' => 5,
                'sync_connector_id' => 1,
                'application_id' => 10,
                'connector_config_version' => 3,
                'state' => 'running',
                'pulled' => 0,
                'created' => 0,
                'updated' => 0,
                'missing' => 0,
                'conflict' => 0,
            ]),
        ];
        State::$failConnectorSaveAt = null;
        State::$failRunSaveAt = null;
        State::$falseConnectorSaveAt = null;
        State::$falseRunSaveAt = null;
        State::$connectorSaves = 0;
        State::$runSaves = 0;
        Driver::$twoPages = $twoPages;
        Driver::$cursors = [];
        Db::$active = false;
        return [SyncConnector::$rows[1], SyncRun::$rows[5]];
    };
    $stored = static fn (): string => serialize([
        Identity::$rows,
        SyncResource::$rows,
        SyncConnector::$rows,
        SyncRun::$rows,
    ]);
    $invoke = static function (SyncConnector $connector, SyncRun $run, array &$counts) use ($pull): void {
        $arguments = [$connector, $run, Driver::class, [], &$counts, SyncRunOwnership::acquire(1, 10)];
        $pull->invokeArgs(new SyncConnectorService(), $arguments);
    };
    $expectFailure = static function (callable $operation, string $message): void {
        try {
            $operation();
            throw new \RuntimeException("{$message} was hidden");
        } catch (\RuntimeException $exception) {
            check($exception->getMessage() === $message, "unexpected failure: {$exception->getMessage()}");
        }
        check(!Db::$active, "{$message} left a transaction open");
    };
    $expectSaveRejected = static function (callable $operation): void {
        try {
            $operation();
            throw new \RuntimeException('false page save was accepted');
        } catch (\plugin\sandadmin\exception\ApiException $exception) {
            check($exception->getMessage() === 'SAND_IAM_SYNC_RUN_FAILED', 'false page save returned wrong error');
            check($exception->getCode() === 503, 'false page save returned wrong status');
        }
        check(!Db::$active, 'false page save left a transaction open');
    };

    [$connector, $run] = $fixture();
    $before = $stored();
    $counts = $zeroCounts;
    State::$failConnectorSaveAt = 1;
    $expectFailure(static function () use ($invoke, $connector, $run, &$counts): void {
        $invoke($connector, $run, $counts);
    }, 'connector save failure');
    check($stored() === $before, 'connector cursor failure did not roll back the whole page');
    check(get_object_vars($connector) === get_object_vars(SyncConnector::find(1)), 'connector transaction dirtied the separately held connector');
    check(get_object_vars($run) === get_object_vars(SyncRun::find(5)), 'connector transaction dirtied the separately held run');
    check($counts === $zeroCounts, 'connector cursor failure increased counts before commit');
    State::$failConnectorSaveAt = null;
    $invoke(SyncConnector::find(1), SyncRun::find(5), $counts);
    check(count(Identity::$rows) === 1 && count(SyncResource::$rows) === 1, 'connector retry did not create one identity and resource');
    check($counts['pulled'] === 1 && $counts['created'] === 1, 'connector retry returned wrong committed counts');

    [$connector, $run] = $fixture();
    $before = $stored();
    $counts = $zeroCounts;
    State::$falseConnectorSaveAt = 1;
    $expectSaveRejected(static function () use ($invoke, $connector, $run, &$counts): void {
        $invoke($connector, $run, $counts);
    });
    check($stored() === $before, 'false connector save did not roll back the whole page');
    check($counts === $zeroCounts, 'false connector save increased counts before commit');

    [$connector, $run] = $fixture();
    $before = $stored();
    $counts = $zeroCounts;
    State::$failRunSaveAt = 1;
    $expectFailure(static function () use ($invoke, $connector, $run, &$counts): void {
        $invoke($connector, $run, $counts);
    }, 'run save failure');
    check($stored() === $before, 'run progress failure did not roll back data and cursor');
    check(get_object_vars($connector) === get_object_vars(SyncConnector::find(1)), 'run transaction dirtied the separately held connector');
    check(get_object_vars($run) === get_object_vars(SyncRun::find(5)), 'run transaction dirtied the separately held run');
    check($counts === $zeroCounts, 'run progress failure increased counts before commit');
    State::$failRunSaveAt = null;
    $invoke(SyncConnector::find(1), SyncRun::find(5), $counts);
    check(count(Identity::$rows) === 1 && count(SyncResource::$rows) === 1, 'run retry duplicated identity or resource');
    check($counts['pulled'] === 1 && $counts['created'] === 1, 'run retry returned wrong committed counts');

    [$connector, $run] = $fixture();
    $before = $stored();
    $counts = $zeroCounts;
    State::$falseRunSaveAt = 1;
    $expectSaveRejected(static function () use ($invoke, $connector, $run, &$counts): void {
        $invoke($connector, $run, $counts);
    });
    check($stored() === $before, 'false run save did not roll back data and cursor');
    check($counts === $zeroCounts, 'false run save increased counts before commit');

    [$connector, $run] = $fixture(true);
    $counts = $zeroCounts;
    State::$failRunSaveAt = 2;
    $expectFailure(static function () use ($invoke, $connector, $run, &$counts): void {
        $invoke($connector, $run, $counts);
    }, 'run save failure');
    check(count(Identity::$rows) === 1 && count(SyncResource::$rows) === 1, 'second-page failure did not preserve exactly page one');
    check((string) SyncConnector::find(1)->encrypted_cursor === 'encrypted:page-1', 'second-page failure lost page-one cursor');
    check($counts['pulled'] === 1 && $counts['created'] === 1, 'second-page failure exposed uncommitted page-two counts');
    check(Driver::$cursors === [null, 'page-1'], 'driver did not receive the persisted page cursor');
    State::$failRunSaveAt = null;
    $invoke(SyncConnector::find(1), SyncRun::find(5), $counts);
    check(count(Identity::$rows) === 2 && count(SyncResource::$rows) === 2, 'second-page retry did not finish without duplicates');
    check($counts['pulled'] === 2 && $counts['created'] === 2, 'second-page retry returned wrong committed totals');
    check(Driver::$cursors === [null, 'page-1', 'page-1'], 'retry did not resume from the committed cursor');

    echo "Sync pull page atomicity behavior PASS (non-PG)\n";
}
