<?php

declare(strict_types=1);

namespace SyncConfigurationAtomicTest {
    final class State
    {
        public static bool $failAudit = false;
        public static array $audits = [];
        public static array $validatedConfigs = [];
        public static array $encryptedValues = [];
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

        public function save(array $values): void
        {
            foreach ($values as $key => $value) {
                $this->$key = $value;
            }
            static::$rows[$this->id] = $this;
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
    class Application extends \SyncConfigurationAtomicTest\Record
    {
        public static array $rows = [];
    }

    class SyncConnector extends \SyncConfigurationAtomicTest\Record
    {
        public static array $rows = [];
    }

    class Identity extends \SyncConfigurationAtomicTest\Record { public static array $rows = []; }
    class IdentityGroup extends \SyncConfigurationAtomicTest\Record { public static array $rows = []; }
    class IdentityGroupMember extends \SyncConfigurationAtomicTest\Record { public static array $rows = []; }
    class SyncOutbox extends \SyncConfigurationAtomicTest\Record { public static array $rows = []; }
    class SyncResource extends \SyncConfigurationAtomicTest\Record { public static array $rows = []; }
    class SyncRun extends \SyncConfigurationAtomicTest\Record { public static array $rows = []; }
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
                \SyncConfigurationAtomicTest\State::$audits,
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
                \SyncConfigurationAtomicTest\State::$audits,
            ] = unserialize(self::$snapshot);
            self::$active = false;
            self::$snapshot = '';
        }
    }
}

namespace plugin\SandIam\app\service {
    final class SyncSecretCipher
    {
        public function encryptArray(array $value): string
        {
            \SyncConfigurationAtomicTest\State::$encryptedValues[] = $value;
            return 'sealed:' . base64_encode(json_encode($value, JSON_THROW_ON_ERROR));
        }

        public function encrypt(string $value): string { return 'sealed:' . $value; }
        public function decrypt(string $value): string { return $value; }
        public function decryptArray(string $value): array { return []; }
    }

    final class AuditWriter
    {
        public function write(mixed ...$values): void
        {
            \SyncConfigurationAtomicTest\State::$audits[] = $values;
            if (\SyncConfigurationAtomicTest\State::$failAudit) {
                throw new \RuntimeException('configuration audit unavailable');
            }
        }
    }
}

namespace {
    function config(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'plugin.sand-iam.app.identity_lifecycle_enabled' => 1,
            'plugin.sand-iam.app.sync_drivers' => [
                'fixture' => \SyncConfigurationAtomicTest\Driver::class,
                'inbound_only' => \SyncConfigurationAtomicTest\InboundOnlyDriver::class,
            ],
            default => $default,
        };
    }
}

namespace SyncConfigurationAtomicTest {
    require dirname(__DIR__) . '/app/sync/SyncDriverInterface.php';

    class Driver implements \plugin\SandIam\app\sync\SyncDriverInterface
    {
        public static function capabilities(): array
        {
            return ['inbound' => true, 'outbound' => true];
        }

        public static function validateConfig(array $config): void
        {
            State::$validatedConfigs[] = $config;
        }

        public static function test(array $config): void {}
        public static function pullPage(array $config, ?string $cursor, int $limit): array { return []; }
        public static function pushBatch(array $config, array $events): array { return []; }
    }

    final class InboundOnlyDriver extends Driver
    {
        public static function capabilities(): array
        {
            return ['inbound' => true, 'outbound' => false];
        }
    }
}

namespace {
    use SyncConfigurationAtomicTest\State;
    use function SyncConfigurationAtomicTest\check;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\SyncConnector;
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
    $payload = ['tenant' => 'directory.example.test', 'client_id' => 'client-1'];
    $authority = ['display_name' => 'source', 'email' => 'local'];

    $fixture = static function (string $direction = 'bidirectional', string $driver = 'fixture'): SyncConnector {
        SyncConnector::$rows = [
            1 => new SyncConnector([
                'id' => 1,
                'organization_id' => 100,
                'application_id' => 10,
                'driver_code' => $driver,
                'direction' => $direction,
                'encrypted_config' => 'sealed:old',
                'authority_map' => ['display_name' => 'local'],
                'config_version' => 7,
                'status' => 1,
            ]),
        ];
        State::$failAudit = false;
        State::$audits = [];
        State::$validatedConfigs = [];
        State::$encryptedValues = [];
        Db::$active = false;
        return SyncConnector::$rows[1];
    };
    $stored = static fn (): string => serialize([SyncConnector::$rows, State::$audits]);
    $rejectsWithoutChange = static function (callable $operation, string $error, int $status) use ($stored): void {
        $before = $stored();
        try {
            $operation();
            throw new \RuntimeException("accepted {$error}");
        } catch (ApiException $exception) {
            $message = $exception->getMessage();
            check($message === $error || str_starts_with($message, $error . ':'), "wrong error for {$error}");
            check($exception->getCode() === $status, "wrong status for {$error}");
        }
        check($stored() === $before, "{$error} changed connector or audit");
        check(!Db::$active, "{$error} left transaction open");
    };

    $connector = $fixture();
    $before = $stored();
    State::$failAudit = true;
    try {
        $service->configure($connector, $payload, $authority, 'admin:7', 'configure-request');
        throw new \RuntimeException('configuration audit failure was hidden');
    } catch (\RuntimeException $exception) {
        check($exception->getMessage() === 'configuration audit unavailable', 'unexpected audit failure');
    } finally {
        State::$failAudit = false;
    }
    check($stored() === $before, 'audit failure did not roll back configuration and audit');
    check(get_object_vars($connector) === get_object_vars(SyncConnector::$rows[1]), 'audit rollback did not refresh the connector');
    check(!Db::$active, 'audit failure left transaction open');

    $service->configure($connector, $payload, $authority, 'admin:7', 'configure-request');
    check((int) $connector->config_version === 8, 'retry did not increment exactly one version');
    check($connector->authority_map === $authority, 'retry stored wrong authority map');
    check((string) $connector->encrypted_config === 'sealed:' . base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), 'retry stored wrong ciphertext');
    check(State::$validatedConfigs === [$payload, $payload], 'driver did not validate the same payload on failure and retry');
    check(State::$encryptedValues === [$payload, $payload], 'cipher did not receive the same payload on failure and retry');
    check(count(State::$audits) === 1, 'retry did not commit exactly one audit');
    check(State::$audits[0][4] === 'sync_connector.configure' && State::$audits[0][8] === 'configure-request', 'retry audit used wrong action or request');
    check((State::$audits[0][9]['config_version'] ?? null) === 8, 'retry audit recorded wrong config version');

    $storedConnector = $fixture();
    $staleConnector = new SyncConnector(get_object_vars($storedConnector));
    $staleConnector->config_version = 5;
    $service->configure($staleConnector, $payload, $authority, 'admin:7', 'stale-version');
    check((int) $staleConnector->config_version === 8, 'stale connector did not increment the locked version');
    check((int) SyncConnector::$rows[1]->config_version === 8, 'stale connector overwrote the current version');
    check(count(State::$audits) === 1 && (State::$audits[0][9]['config_version'] ?? null) === 8, 'stale connector audit recorded wrong version');

    foreach ([
        'application' => ['application_id' => 11],
        'organization' => ['organization_id' => 101],
    ] as $scope => $changes) {
        $storedConnector = $fixture();
        $staleScope = new SyncConnector(get_object_vars($storedConnector));
        foreach ($changes as $field => $value) {
            $staleScope->$field = $value;
        }
        $rejectsWithoutChange(
            static fn () => $service->configure($staleScope, $payload, $authority, 'admin:7', "stale-{$scope}"),
            'SAND_IAM_SYNC_CONNECTOR_SCOPE_CHANGED',
            409,
        );
        check(get_object_vars($staleScope) === get_object_vars(SyncConnector::$rows[1]), "stale {$scope} rejection did not refresh the supplied connector");
    }

    $connector = $fixture();
    $rejectsWithoutChange(
        static fn () => $service->configure($connector, $payload, ['department' => 'source'], 'admin:7', 'invalid-field'),
        'SAND_IAM_SYNC_AUTHORITY_MAP_INVALID',
        400,
    );
    $rejectsWithoutChange(
        static fn () => $service->configure($connector, $payload, ['display_name' => 'upstream'], 'admin:7', 'invalid-authority'),
        'SAND_IAM_SYNC_AUTHORITY_MAP_INVALID',
        400,
    );

    $connector = $fixture();
    $rejectsWithoutChange(
        static fn () => $service->configure($connector, $payload, ['email' => 'source'], 'admin:7', 'missing-display-name'),
        'SAND_IAM_SYNC_AUTHORITY_MAP_REQUIRED',
        400,
    );

    $connector = $fixture('outbound', 'inbound_only');
    $rejectsWithoutChange(
        static fn () => $service->configure($connector, $payload, ['display_name' => 'source'], 'admin:7', 'unsupported-direction'),
        'SAND_IAM_SYNC_DIRECTION_UNSUPPORTED',
        409,
    );

    echo "Sync configuration atomicity behavior PASS (non-PG)\n";
}
