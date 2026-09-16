<?php
declare(strict_types=1);
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\webhook {
    interface WebhookHttpAdapter {}
    class NativeWebhookHttpAdapter implements WebhookHttpAdapter {
        public static mixed $send = null;
        public function post(mixed ...$args): array { return (self::$send)(...$args); }
    }
}
namespace plugin\SandIam\app\service {
    class Clock { public static int $now = 1800000000; }
    function time(): int { return Clock::$now; }
    function date(string $format, ?int $timestamp = null): string { return \date($format, $timestamp ?? Clock::$now); }
    class WebhookSecretCipher { public function decrypt(string $value): string { return 'offline-signing-fixture'; } }
    class AuditWriter { public static array $events = []; public function write(mixed ...$args): void { self::$events[] = $args; } }
}
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class Record {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public static function where(string $key, mixed ...$args): Query { return (new Query(static::class))->where($key, ...$args); }
        public static function find(int $id): ?Record { return static::where('id', $id)->find(); }
        public function save(array $values): void {
            foreach ($values as $key => $value) { $this->$key = $value; static::$rows[$this->id]->$key = $value; }
        }
    }
    class Application extends Record { public static array $rows = []; public static bool $fail = false; }
    class WebhookEndpoint extends Record { public static array $rows = []; }
    class WebhookDelivery extends Record { public static array $rows = []; }
    class Query {
        private array $conditions = [];
        private int $limit = 1000;
        public function __construct(private string $model) {}
        public function where(string $key, mixed ...$args): self { $this->conditions[] = [$key, count($args) === 1 ? '=' : $args[0], $args[count($args) - 1]]; return $this; }
        public function lock(mixed $value): self { return $this; }
        public function order(string $key): self { return $this; }
        public function limit(int $limit): self { $this->limit = $limit; return $this; }
        public function select(): self { return $this; }
        public function find(): ?Record { return $this->all()[0] ?? null; }
        public function all(): array {
            if ($this->model === Application::class && Application::$fail) throw new \RuntimeException('audit application lookup unavailable');
            $found = [];
            foreach ($this->model::$rows as $row) {
                foreach ($this->conditions as [$key, $operator, $value]) {
                    $actual = $row->$key ?? null;
                    $match = match ($operator) {
                        '=' => $actual === $value, '<' => $actual !== null && $actual < $value,
                        '<=' => $actual !== null && $actual <= $value, '>' => $actual !== null && $actual > $value,
                        default => throw new \RuntimeException('unsupported query'),
                    };
                    if (!$match) continue 2;
                }
                $found[] = clone $row;
                if (count($found) >= $this->limit) break;
            }
            return $found;
        }
        public function update(array $values): int { $rows = $this->all(); foreach ($rows as $row) $row->save($values); return count($rows); }
    }
}
namespace think\facade {
    class Db {
        public static bool $active = false;
        public static mixed $afterCommit = null;
        private static string $snapshot;
        public static function startTrans(): void { self::$active = true; self::$snapshot = serialize(\plugin\SandIam\app\model\WebhookDelivery::$rows); }
        public static function commit(): void { self::$active = false; $callback = self::$afterCommit; self::$afterCommit = null; if ($callback !== null) $callback(); }
        public static function rollback(): void { self::$active = false; \plugin\SandIam\app\model\WebhookDelivery::$rows = unserialize(self::$snapshot); }
    }
}
namespace {
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\WebhookDelivery;
    use plugin\SandIam\app\model\WebhookEndpoint;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\Clock;
    use plugin\SandIam\app\service\WebhookService;
    use plugin\SandIam\app\webhook\NativeWebhookHttpAdapter;
    require dirname(__DIR__) . '/app/webhook/EventCatalog.php';
    require dirname(__DIR__) . '/app/service/WebhookService.php';
    function seed(int $count): void {
        Clock::$now = 1800000000; WebhookDelivery::$rows = []; AuditWriter::$events = []; Application::$fail = false;
        Application::$rows[10] = new Application(['id' => 10, 'organization_id' => 5]);
        WebhookEndpoint::$rows[7] = new WebhookEndpoint(['id' => 7, 'application_id' => 10, 'status' => 1, 'max_attempts' => 3, 'encrypted_secret' => 'fixture', 'secret_version' => 1, 'url' => 'https://example.invalid', 'timeout_seconds' => 30]);
        for ($id = 1; $id <= $count; $id++) WebhookDelivery::$rows[$id] = new WebhookDelivery([
            'id' => $id, 'application_id' => 10, 'webhook_endpoint_id' => 7, 'status' => 1,
            'locked_until' => null, 'next_attempt_time' => date('Y-m-d H:i:s', Clock::$now - 1),
            'attempt_count' => 0, 'event_id' => 'event-' . $id, 'event_type' => 'identity.created',
            'payload' => ['type' => 'identity.created', 'data' => ['identity_id' => $id]],
        ]);
    }
    $service = new WebhookService();
    seed(3); $calls = 0;
    NativeWebhookHttpAdapter::$send = static function () use (&$calls): array {
        $calls++;
        if (\think\facade\Db::$active) throw new \RuntimeException('HTTP inside transaction');
        $claimed = array_filter(WebhookDelivery::$rows, static fn ($row) => $row->status === 2);
        if (count($claimed) !== 1) throw new \RuntimeException('batch preclaimed waiting deliveries');
        Clock::$now += 90;
        return ['status' => 200, 'body' => 'ok'];
    };
    $result = $service->deliverBatch(2);
    if ($calls !== 2 || $result['delivered'] !== 2 || WebhookDelivery::$rows[3]->status !== 1) throw new \RuntimeException('bounded immediate claim failed');
    seed(1); $calls = 0;
    \think\facade\Db::$afterCommit = static function (): void { Clock::$now += 121; };
    NativeWebhookHttpAdapter::$send = static function () use (&$calls): array { $calls++; return ['status' => 200, 'body' => 'ok']; };
    $result = $service->deliverBatch(1);
    if ($calls !== 0 || $result['lease_lost'] !== 1 || AuditWriter::$events !== []) throw new \RuntimeException('expired claim sent HTTP');
    foreach (['success', 'http_error', 'exception'] as $outcome) {
        seed(1);
        NativeWebhookHttpAdapter::$send = static function () use ($outcome): array {
            Clock::$now += 130;
            WebhookDelivery::$rows[1]->save(['locked_until' => date('Y-m-d H:i:s', Clock::$now + 120), 'attempt_count' => 7]);
            if ($outcome === 'exception') throw new \RuntimeException('network failed');
            return ['status' => $outcome === 'success' ? 200 : 503, 'body' => 'response'];
        };
        $result = $service->deliverBatch(1);
        if (($result['lease_lost'] ?? 0) !== 1 || WebhookDelivery::$rows[1]->status !== 2 || WebhookDelivery::$rows[1]->attempt_count !== 7 || AuditWriter::$events !== []) throw new \RuntimeException('old worker changed new lease');
    }
    foreach ([0, 2] as $attempts) {
        seed(1); WebhookDelivery::$rows[1]->attempt_count = $attempts;
        NativeWebhookHttpAdapter::$send = static fn (): array => ['status' => 503, 'body' => 'retry'];
        $result = $service->deliverBatch(1);
        if ($result[$attempts === 0 ? 'retried' : 'dead'] !== 1) throw new \RuntimeException('retry outcome changed');
    }
    $webhookServiceSource = (string) file_get_contents(dirname(__DIR__) . '/app/service/WebhookService.php');
    if (preg_match('/function retry\\b[\\s\\S]*?attempt_count\\s*[\'"]?\\s*=>\\s*0[\\s\\S]*?function deliver\\b/', $webhookServiceSource) === 1) {
        throw new \RuntimeException('manual retry resets the cumulative delivery attempt number');
    }
    seed(1);
    WebhookDelivery::$rows[1]->save(['status' => 2, 'locked_until' => date('Y-m-d H:i:s', Clock::$now - 1)]);
    NativeWebhookHttpAdapter::$send = static fn (): array => ['status' => 200, 'body' => 'ok'];
    if ($service->deliverBatch(1)['delivered'] !== 1 || $service->deliverBatch(1)['claimed'] !== 0) throw new \RuntimeException('expired recovery or empty queue failed');
    foreach ([[200, 0, 'delivered', 3], [503, 0, 'retried', 1], [503, 2, 'dead', 4]] as [$httpStatus, $attempts, $outcome, $rowStatus]) {
        seed(1); Application::$fail = true; WebhookDelivery::$rows[1]->attempt_count = $attempts;
        NativeWebhookHttpAdapter::$send = static fn (): array => ['status' => $httpStatus, 'body' => 'response'];
        $result = $service->deliverBatch(1);
        if ($result[$outcome] !== 1 || $result['lease_lost'] !== 0 || WebhookDelivery::$rows[1]->status !== $rowStatus) throw new \RuntimeException('audit lookup changed delivery outcome');
    }
    echo "Webhook lease behavior PASS (offline)\n";
}
