<?php
declare(strict_types=1);

namespace AccountingTest {
    final class State {
        public static array $rows = [];
        public static ?array $snapshot = null;
        public static bool $failEvent = false;
    }
    class Model {
        public function __construct(private array $data) {}
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
        public static function create(array $values): static {
            $values['id'] = max([0, ...array_keys(State::$rows[static::class] ?? [])]) + 1;
            State::$rows[static::class][$values['id']] = $values;
            if (static::class === \plugin\SandIam\app\model\RadiusAccountingEvent::class && State::$failEvent) throw new \RuntimeException('event storage failed');
            return new static($values);
        }
        public function save(array $values): void { State::$rows[static::class][$this->id] = array_replace($this->data, $values); }
    }
    final class Query {
        private array $filters = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value): self { $this->filters[$key] = $value; return $this; }
        public function lock(bool $lock): self {
            if (!$lock || State::$snapshot === null) throw new \RuntimeException('Lock outside transaction');
            return $this;
        }
        public function select(): self { return $this; }
        public function all(): array {
            $rows = array_filter(State::$rows[$this->model] ?? [], function (array $row): bool {
                foreach ($this->filters as $key => $value) if (($row[$key] ?? null) !== $value) return false;
                return true;
            });
            return array_map(fn (array $row): object => new ($this->model)($row), array_values($rows));
        }
        public function find(): ?object { return $this->all()[0] ?? null; }
    }
    function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class RadiusNas extends \AccountingTest\Model {}
    class Application extends \AccountingTest\Model {}
    class Organization extends \AccountingTest\Model {}
    class RadiusAccountingEvent extends \AccountingTest\Model {}
    class RadiusAccountingSession extends \AccountingTest\Model {}
}
namespace plugin\SandIam\app\service {
    class RadiusSecretCipher { public function decrypt(string $value): string { return 'test-radius-secret'; } }
}
namespace think\facade {
    class Db {
        public static function startTrans(): void {
            if (\AccountingTest\State::$snapshot !== null) throw new \RuntimeException('Nested transaction');
            \AccountingTest\State::$snapshot = \AccountingTest\State::$rows;
        }
        public static function commit(): void { \AccountingTest\State::$snapshot = null; }
        public static function rollback(): void {
            if (\AccountingTest\State::$snapshot !== null) \AccountingTest\State::$rows = \AccountingTest\State::$snapshot;
            \AccountingTest\State::$snapshot = null;
        }
    }
}
namespace {
    use AccountingTest\State;
    use function AccountingTest\check;
    use plugin\SandIam\app\model\{RadiusNas, Application, Organization, RadiusAccountingEvent, RadiusAccountingSession};
    use plugin\SandIam\app\radius\RadiusPacketCodec;
    use plugin\SandIam\app\service\RadiusAccountingService;
    function config(string $key, mixed $default = null): mixed {
        return match ($key) { 'plugin.sand-iam.app.radius_server_enabled' => 1, 'plugin.sand-iam.app.radius_replay_key' => str_repeat('r', 48), default => $default };
    }
    require dirname(__DIR__) . '/app/radius/RadiusPacketCodec.php';
    require dirname(__DIR__) . '/app/radius/RadiusNetwork.php';
    require dirname(__DIR__) . '/app/service/RadiusAccountingService.php';
    State::$rows = [
        RadiusNas::class => [1 => ['id' => 1, 'status' => 1, 'accounting_enabled' => true, 'source_cidr' => '192.0.2.0/24', 'application_id' => 10, 'encrypted_shared_secret' => 'test-only']],
        Application::class => [10 => ['id' => 10, 'organization_id' => 2, 'status' => 1]],
        Organization::class => [2 => ['id' => 2, 'status' => 1]],
        RadiusAccountingEvent::class => [], RadiusAccountingSession::class => [],
    ];
    $timestamp = time();
    $packet = static function (int $type, int $identifier, int $seconds = 0, int $bytes = 0, string $session = 'session') use ($timestamp): string {
        $attrs = chr(40) . chr(6) . pack('N', $type) . chr(44) . chr(strlen($session) + 2) . $session
            . chr(55) . chr(6) . pack('N', $timestamp) . chr(46) . chr(6) . pack('N', $seconds)
            . chr(42) . chr(6) . pack('N', $bytes) . chr(43) . chr(6) . pack('N', $bytes);
        $head = pack('CCn', 4, $identifier, 20 + strlen($attrs));
        return $head . hash('md5', $head . str_repeat("\0", 16) . $attrs . 'test-radius-secret', true) . $attrs;
    };
    $service = new RadiusAccountingService();
    $ack = static function (string $raw) use ($service): void {
        $response = $service->handle('192.0.2.10', $raw);
        check(is_string($response), 'Accounting response missing');
        $decoded = (new RadiusPacketCodec())->decode($response);
        check($decoded['code'] === 5 && $decoded['identifier'] === ord($raw[1]), 'Wrong response header');
        check(hash_equals(hash('md5', substr($response, 0, 4) . substr($raw, 4, 16) . substr($response, 20) . 'test-radius-secret', true), $decoded['authenticator']), 'Wrong response authenticator');
        check(State::$snapshot === null, 'Transaction leaked');
    };
    $start = $packet(1, 1);
    $ack($start);
    check(count(State::$rows[RadiusAccountingSession::class]) === 1 && State::$rows[RadiusAccountingSession::class][1]['state'] === 'active', 'Start not applied');
    $before = State::$rows;
    $ack($start);
    check(State::$rows === $before, 'Exact replay duplicated state');
    $ack($packet(3, 2, 20, 100));
    check(State::$rows[RadiusAccountingSession::class][1]['input_octets'] === 100, 'Interim not applied');
    $active = State::$rows[RadiusAccountingSession::class];
    $ack($packet(3, 3, 10, 50));
    check(State::$rows[RadiusAccountingSession::class] === $active && end(State::$rows[RadiusAccountingEvent::class])['outcome'] === 'conflict', 'Regressing event changed session');
    $ack($packet(1, 4));
    check(State::$rows[RadiusAccountingSession::class] === $active && end(State::$rows[RadiusAccountingEvent::class])['outcome'] === 'conflict', 'Duplicate Start changed session');
    $ack($packet(2, 5, 30, 200));
    check(State::$rows[RadiusAccountingSession::class][1]['state'] === 'stopped' && State::$rows[RadiusAccountingSession::class][1]['input_octets'] === 200, 'Stop not applied');
    $ack($packet(3, 6, 40, 300, 'missing-start'));
    check(end(State::$rows[RadiusAccountingEvent::class])['outcome'] === 'orphaned' && count(State::$rows[RadiusAccountingSession::class]) === 1, 'Orphan created session');
    $before = State::$rows;
    State::$failEvent = true;
    $retry = $packet(1, 7, 0, 0, 'retry-session');
    check($service->handle('192.0.2.10', $retry) === null && State::$rows === $before && State::$snapshot === null, 'Storage failure acknowledged or left partial session');
    State::$failEvent = false;
    $ack($retry);
    check(count(State::$rows[RadiusAccountingSession::class]) === 2, 'Failed packet could not be retried');
    $before = State::$rows;
    $tampered = $packet(3, 8); $tampered[4] = chr(ord($tampered[4]) ^ 1);
    check($service->handle('192.0.2.10', $tampered) === null && State::$rows === $before, 'Invalid authenticator changed state');
    check($service->handle('198.51.100.1', $start) === null && State::$rows === $before, 'Unregistered NAS accepted');
    echo "RADIUS accounting handle non-PG behavior PASS\n";
}
