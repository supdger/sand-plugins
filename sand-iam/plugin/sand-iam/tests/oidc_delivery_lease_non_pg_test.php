<?php
declare(strict_types=1);
namespace plugin\SandIam\app\oidc {
    interface OidcBackchannelHttpAdapter {}
    class NativeOidcBackchannelHttpAdapter implements OidcBackchannelHttpAdapter {
        public static mixed $send;
        public function postLogoutToken(string $uri, string $body, int $timeout): array { return (self::$send)($uri, $body, $timeout); }
    }
}
namespace plugin\SandIam\app\service {
    class Clock { public static int $now = 1800000000; }
    function time(): int { return Clock::$now; }
    function date(string $format, ?int $timestamp = null): string { return \date($format, $timestamp ?? Clock::$now); }
    class OidcLogoutTokenCipher { public function decrypt(string $value): string { return '{"target_uri":"https://example.invalid/logout","logout_token":"offline-fixture"}'; } }
    class AuditWriter { public static array $events = []; public function write(mixed ...$args): void { self::$events[] = $args; } }
}
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class Record {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public static function where(string $key, mixed ...$args): Query { return (new Query(static::class))->where($key, ...$args); }
        public static function find(int $id): ?Record { return static::where('id', $id)->find(); }
        public function save(array $values): void { foreach ($values as $key => $value) { $this->$key = $value; static::$rows[$this->id]->$key = $value; } }
    }
    class Application extends Record { public static array $rows = []; public static bool $fail = false; }
    class OAuthClient extends Record { public static array $rows = []; }
    class OidcLogoutDelivery extends Record { public static array $rows = []; }
    class Query {
        private array $conditions = []; private int $limit = 1000;
        public function __construct(private string $model) {}
        public function where(string $key, mixed ...$args): self { $this->conditions[] = [$key, count($args) === 1 ? '=' : $args[0], $args[count($args) - 1]]; return $this; }
        public function order(string $key): self { return $this; }
        public function limit(int $limit): self { $this->limit = $limit; return $this; }
        public function lock(mixed $lock): self { return $this; }
        public function select(): self { return $this; }
        public function find(): ?Record { return $this->all()[0] ?? null; }
        public function all(): array {
            if ($this->model === Application::class && Application::$fail) throw new \RuntimeException('audit lookup failed');
            $rows = [];
            foreach ($this->model::$rows as $row) {
                foreach ($this->conditions as [$key, $operator, $value]) {
                    $actual = $row->$key ?? null;
                    if (!match ($operator) { '=' => $actual === $value, '<' => $actual !== null && $actual < $value, '<=' => $actual !== null && $actual <= $value, '>' => $actual !== null && $actual > $value, default => false }) continue 2;
                }
                $rows[] = clone $row; if (count($rows) >= $this->limit) break;
            }
            return $rows;
        }
        public function update(array $values): int { $rows = $this->all(); foreach ($rows as $row) $row->save($values); return count($rows); }
    }
}
namespace think\facade {
    class Db {
        public static bool $active = false; public static mixed $afterCommit = null; private static string $snapshot;
        public static function startTrans(): void { self::$active = true; self::$snapshot = serialize(\plugin\SandIam\app\model\OidcLogoutDelivery::$rows); }
        public static function commit(): void { self::$active = false; $fn = self::$afterCommit; self::$afterCommit = null; if ($fn !== null) $fn(); }
        public static function rollback(): void { self::$active = false; \plugin\SandIam\app\model\OidcLogoutDelivery::$rows = unserialize(self::$snapshot); }
    }
}
namespace {
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\OAuthClient;
    use plugin\SandIam\app\model\OidcLogoutDelivery;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\Clock;
    use plugin\SandIam\app\service\OidcBackchannelLogoutService;
    use plugin\SandIam\app\oidc\NativeOidcBackchannelHttpAdapter;
    require dirname(__DIR__) . '/app/service/OidcBackchannelLogoutService.php';
    function seed(int $count): void {
        Clock::$now = 1800000000; Application::$fail = false; AuditWriter::$events = []; OidcLogoutDelivery::$rows = [];
        Application::$rows[10] = new Application(['id' => 10, 'organization_id' => 5]);
        OAuthClient::$rows[7] = new OAuthClient(['id' => 7, 'application_id' => 10, 'status' => 1]);
        for ($id = 1; $id <= $count; $id++) OidcLogoutDelivery::$rows[$id] = new OidcLogoutDelivery(['id' => $id, 'application_id' => 10, 'oauth_client_id' => 7, 'state' => 'pending', 'status' => 1, 'attempt_count' => 0, 'locked_until' => null, 'next_attempt_time' => date('Y-m-d H:i:s', Clock::$now - 1), 'encrypted_logout_token' => 'cipher-fixture', 'event_id' => 'event-' . $id]);
    }
    $service = new OidcBackchannelLogoutService();
    seed(3); $calls = 0;
    NativeOidcBackchannelHttpAdapter::$send = static function (string $uri, string $body, int $timeout) use (&$calls): array {
        $calls++;
        if ($uri !== 'https://example.invalid/logout' || $body !== 'logout_token=offline-fixture' || $timeout !== 10 || \think\facade\Db::$active) throw new \RuntimeException('delivery contract changed');
        if (count(array_filter(OidcLogoutDelivery::$rows, static fn ($row) => $row->state === 'sending')) !== 1) throw new \RuntimeException('waiting rows preclaimed');
        Clock::$now += 90; return ['status' => 200, 'body' => 'ok'];
    };
    $result = $service->deliverBatch(2);
    if ($calls !== 2 || $result['delivered'] !== 2 || OidcLogoutDelivery::$rows[3]->state !== 'pending') throw new \RuntimeException('OIDC immediate bounded claim failed');
    seed(1); $calls = 0;
    \think\facade\Db::$afterCommit = static function (): void { Clock::$now += 121; };
    NativeOidcBackchannelHttpAdapter::$send = static function () use (&$calls): array { $calls++; return ['status' => 200, 'body' => 'ok']; };
    if (($service->deliverBatch(1)['lease_lost'] ?? 0) !== 1 || $calls !== 0) throw new \RuntimeException('expired lease sent logout');
    foreach ([200, 503, 0] as $status) {
        seed(1);
        NativeOidcBackchannelHttpAdapter::$send = static function () use ($status): array {
            Clock::$now += 130; OidcLogoutDelivery::$rows[1]->save(['locked_until' => date('Y-m-d H:i:s', Clock::$now + 120), 'attempt_count' => 3]);
            if ($status === 0) throw new \RuntimeException('transport failed');
            return ['status' => $status, 'body' => 'response'];
        };
        $result = $service->deliverBatch(1);
        if (($result['lease_lost'] ?? 0) !== 1 || OidcLogoutDelivery::$rows[1]->state !== 'sending' || OidcLogoutDelivery::$rows[1]->attempt_count !== 3 || AuditWriter::$events !== []) throw new \RuntimeException('old logout worker overwrote new lease');
    }
    foreach ([[200, 0, 'delivered'], [503, 0, 'retried'], [503, 4, 'dead']] as [$status, $attempt, $outcome]) {
        seed(1); Application::$fail = true; OidcLogoutDelivery::$rows[1]->attempt_count = $attempt;
        NativeOidcBackchannelHttpAdapter::$send = static fn (): array => ['status' => $status, 'body' => 'response'];
        if ($service->deliverBatch(1)[$outcome] !== 1) throw new \RuntimeException('audit failure changed logout outcome');
        if ($outcome === 'retried' && OidcLogoutDelivery::$rows[1]->next_attempt_time !== date('Y-m-d H:i:s', Clock::$now + 60)) throw new \RuntimeException('retry delay changed');
    }
    seed(1); OidcLogoutDelivery::$rows[1]->save(['state' => 'sending', 'locked_until' => date('Y-m-d H:i:s', Clock::$now - 1)]);
    NativeOidcBackchannelHttpAdapter::$send = static fn (): array => ['status' => 200, 'body' => 'ok'];
    if ($service->deliverBatch(1)['delivered'] !== 1 || $service->deliverBatch(1)['claimed'] !== 0) throw new \RuntimeException('logout recovery or empty queue failed');
    echo "OIDC delivery lease behavior PASS (offline)\n";
}
