<?php
declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\webhook {
    interface WebhookHttpAdapter {}
    class NativeWebhookHttpAdapter implements WebhookHttpAdapter {}
}
namespace plugin\SandIam\app\service {
    class WebhookSecretCipher {}
    class AuditWriter { public function write(mixed ...$args): void {} }
    class RequestId { public static function normalize(string $value): string { return $value; } }
}
namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    class Record {
        public function __construct(array $values) { foreach ($values as $key => $value) $this->$key = $value; }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
    }
    class Application extends Record {}
    class Organization extends Record {}
    class WebhookEndpoint extends Record { public static array $rows = []; }
    class WebhookDelivery extends Record {
        public static array $rows = [];
        public static ?int $failEndpoint = null;
        public static string $failure = 'storage unavailable';
        public static function create(array $values): self {
            \think\facade\Db::usable();
            if (!in_array(Application::class, Query::$locks, true)) throw new \RuntimeException('application not locked before enqueue');
            foreach (self::$rows as $row) {
                if ($row->webhook_endpoint_id === $values['webhook_endpoint_id'] && $row->event_id === $values['event_id']) {
                    \think\facade\Db::$aborted = true;
                    throw new \RuntimeException('unique violation');
                }
            }
            if (self::$failEndpoint === $values['webhook_endpoint_id']) {
                \think\facade\Db::$aborted = true;
                throw new \RuntimeException(self::$failure);
            }
            return self::$rows[] = new self(['id' => count(self::$rows) + 1] + $values);
        }
    }
    class Query {
        public static array $locks = [];
        private array $conditions = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value): self { $this->conditions[$key] = $value; return $this; }
        public function lock(bool $value): self { self::$locks[] = $this->model; return $this; }
        public function find(): ?Record { return $this->all()[0] ?? null; }
        public function select(): self { return $this; }
        public function all(): array {
            \think\facade\Db::usable();
            $rows = match ($this->model) {
                Application::class => [new Application(['id' => 10, 'organization_id' => 5, 'status' => 1]), new Application(['id' => 11, 'organization_id' => 5, 'status' => 1])],
                Organization::class => [new Organization(['id' => 5, 'status' => 1])],
                WebhookEndpoint::class => WebhookEndpoint::$rows,
                default => WebhookDelivery::$rows,
            };
            return array_values(array_filter($rows, function (Record $row): bool {
                foreach ($this->conditions as $key => $value) if ($row->$key !== $value) return false;
                return true;
            }));
        }
    }
}
namespace think\facade {
    class Db {
        public static bool $active = false;
        public static bool $aborted = false;
        private static string $snapshot;
        public static function usable(): void { if (self::$aborted) throw new \RuntimeException('transaction aborted'); }
        public static function startTrans(): void {
            self::$snapshot = serialize(\plugin\SandIam\app\model\WebhookDelivery::$rows);
            self::$active = true; self::$aborted = false; \plugin\SandIam\app\model\Query::$locks = [];
        }
        public static function commit(): void { self::usable(); self::$active = false; }
        public static function rollback(): void {
            \plugin\SandIam\app\model\WebhookDelivery::$rows = unserialize(self::$snapshot);
            self::$active = false; self::$aborted = false;
        }
    }
}
namespace {
    use plugin\SandIam\app\model\WebhookDelivery;
    use plugin\SandIam\app\model\WebhookEndpoint;
    use plugin\SandIam\app\service\WebhookService;
    require dirname(__DIR__) . '/app/webhook/EventCatalog.php';
    require dirname(__DIR__) . '/app/service/WebhookService.php';
    $service = new WebhookService();
    foreach ([1, 2] as $id) WebhookEndpoint::$rows[] = new WebhookEndpoint(['id' => $id, 'application_id' => 10, 'status' => 1, 'event_types' => ['identity.created']]);
    $service->enqueue(10, 'identity.created', ['identity_id' => 7], 'event-001');
    if (count(WebhookDelivery::$rows) !== 2) throw new \RuntimeException('initial fanout incomplete');
    WebhookDelivery::$rows[0]->status = 3;
    WebhookDelivery::$rows[0]->attempt_count = 2;
    WebhookDelivery::$rows[1]->status = 4;
    $before = serialize(WebhookDelivery::$rows);
    $service->enqueue(10, 'identity.created', ['identity_id' => 7], 'event-001');
    if (serialize(WebhookDelivery::$rows) !== $before) throw new \RuntimeException('replay changed deliveries');
    WebhookEndpoint::$rows[] = new WebhookEndpoint(['id' => 3, 'application_id' => 10, 'status' => 1, 'event_types' => ['identity.created']]);
    $service->enqueue(10, 'identity.created', ['identity_id' => 7], 'event-001');
    if (count(WebhookDelivery::$rows) !== 3 || WebhookDelivery::$rows[2]->webhook_endpoint_id !== 3) throw new \RuntimeException('replay skipped new endpoint');
    $before = serialize(WebhookDelivery::$rows);
    WebhookDelivery::$failEndpoint = 2;
    try { $service->enqueue(10, 'identity.created', ['identity_id' => 8], 'event-002'); throw new \RuntimeException('storage failure hidden'); }
    catch (\RuntimeException $error) { if ($error->getMessage() !== 'storage unavailable') throw $error; }
    if (serialize(WebhookDelivery::$rows) !== $before || \think\facade\Db::$active) throw new \RuntimeException('failed fanout left partial delivery');
    WebhookDelivery::$failEndpoint = null;
    $service->enqueue(10, 'identity.created', ['identity_id' => 8], 'event-002');
    if (count(WebhookDelivery::$rows) !== 6) throw new \RuntimeException('fanout retry incomplete');
    $before = serialize(WebhookDelivery::$rows);
    WebhookDelivery::$failEndpoint = 2; WebhookDelivery::$failure = 'unique violation';
    try { $service->enqueue(10, 'identity.created', ['identity_id' => 9], 'event-003'); throw new \RuntimeException('unique failure hidden'); }
    catch (\RuntimeException $error) { if ($error->getMessage() !== 'unique violation') throw $error; }
    if (serialize(WebhookDelivery::$rows) !== $before) throw new \RuntimeException('unique failure left partial delivery');
    WebhookDelivery::$failEndpoint = null;
    WebhookEndpoint::$rows[] = new WebhookEndpoint(['id' => 4, 'application_id' => 11, 'status' => 1, 'event_types' => ['identity.created']]);
    $service->enqueue(11, 'identity.created', ['identity_id' => 20], 'event-001');
    if (count(WebhookDelivery::$rows) !== 7 || WebhookDelivery::$rows[6]->application_id !== 11) throw new \RuntimeException('other application incorrectly deduplicated');
    $service->enqueueForEndpoint(1, 10, 'identity.created', ['identity_id' => 30], 'event-004', 'request-004');
    $service->enqueue(10, 'identity.created', ['identity_id' => 30], 'event-004');
    $again = $service->enqueueForEndpoint(1, 10, 'identity.created', ['identity_id' => 30], 'event-004', 'request-004');
    if (!$again['replayed'] || count(WebhookDelivery::$rows) !== 10) throw new \RuntimeException('enqueue entrypoints duplicated event');
    echo "Webhook enqueue replay and rollback PASS (offline)\n";
    foreach (["event-005\n", "event-005\r\n", '1234567', str_repeat('a', 97)] as $eventId) {
        $before = serialize(WebhookDelivery::$rows);
        try { $service->enqueue(10, 'identity.created', ['identity_id' => 7], $eventId); throw new \RuntimeException('Invalid event ID accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $error) { if ($error->getCode() !== 400 || $error->getMessage() !== 'SAND_IAM_WEBHOOK_EVENT_ID_INVALID') throw $error; }
        if (serialize(WebhookDelivery::$rows) !== $before) throw new \RuntimeException('Invalid event ID queued delivery');
    }
    foreach (['abc-_.:9', str_repeat('a', 96)] as $eventId) {
        if ($service->enqueue(10, 'identity.created', ['identity_id' => 7], $eventId) !== $eventId) throw new \RuntimeException('Valid event ID changed');
    }
    echo "Webhook event ID exact boundary behavior PASS (offline)\n";
}
