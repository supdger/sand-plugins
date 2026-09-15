<?php
declare(strict_types=1);
namespace CasScopeTest {
    final class State { public static array $rows = [], $audits = []; public static ?array $snapshot = null; }
    class Record {
        public function __construct(private array $values) {}
        public function __get(string $key): mixed { return $this->values[$key] ?? null; }
        public static function where(string $key, mixed $value): Query { return (new Query(static::class))->where($key, $value); }
        public static function find(int $id): ?static { return isset(State::$rows[static::class][$id]) ? new static(State::$rows[static::class][$id]) : null; }
        public function save(array $values): void { State::$rows[static::class][$this->id] = $this->values = array_replace($this->values, $values); }
    }
    final class Query {
        private array $conditions = [];
        public function __construct(private string $model) {}
        public function where(string $key, mixed $value): self { $this->conditions[$key] = $value; return $this; }
        public function lock(bool $locked): self { check(State::$snapshot !== null, 'Ticket lock outside transaction'); return $this; }
        public function find(): ?Record {
            foreach (State::$rows[$this->model] ?? [] as $row) {
                foreach ($this->conditions as $key => $value) if (($row[$key] ?? null) !== $value) continue 2;
                return new $this->model($row);
            }
            return null;
        }
    }
    function check(bool $result, string $message): void { if (!$result) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \CasScopeTest\Record {}
    class Organization extends \CasScopeTest\Record {}
    class Identity extends \CasScopeTest\Record {}
    class CasService extends \CasScopeTest\Record {}
    class CasTicket extends \CasScopeTest\Record {}
}
namespace plugin\SandIam\app\service {
    class HumanAuthService {}
    class AuditWriter { public function write(mixed ...$values): void { \CasScopeTest\State::$audits[] = $values; } }
}
namespace think\facade {
    use CasScopeTest\State;
    class Db {
        public static function startTrans(): void { State::$snapshot = [State::$rows, State::$audits]; }
        public static function commit(): void { State::$snapshot = null; }
        public static function rollback(): void { [State::$rows, State::$audits] = State::$snapshot; State::$snapshot = null; }
    }
}
namespace {
    use CasScopeTest\State;
    use function CasScopeTest\check;
    use plugin\SandIam\app\model\{Application, Organization, Identity, CasService, CasTicket};
    use plugin\SandIam\app\service\CasProtocolService;
    function config(string $key, mixed $default = null): mixed { return match ($key) { 'plugin.sand-iam.app.cas_enabled' => 1, 'plugin.sand-iam.app.auth_pepper' => str_repeat('fixture', 8), default => $default }; }
    require __DIR__ . '/../app/service/CasProtocolService.php';
    $ticket = 'ST-' . str_repeat('a', 54);
    $url = 'https://consumer.example.invalid/cas';
    $baseline = [
        Organization::class => [1 => ['id' => 1, 'status' => 1]],
        Application::class => [2 => ['id' => 2, 'organization_id' => 1, 'status' => 1]],
        Identity::class => [3 => ['id' => 3, 'application_id' => 2, 'username' => 'person', 'status' => 1]],
        CasService::class => [4 => ['id' => 4, 'application_id' => 2, 'service_url' => $url, 'released_attributes' => [], 'status' => 1]],
        CasTicket::class => [5 => ['id' => 5, 'application_id' => 2, 'cas_service_id' => 4, 'identity_id' => 3, 'status' => 1, 'consumed_time' => null, 'expire_time' => date('Y-m-d H:i:s', time() + 300), 'ticket_hash' => hash_hmac('sha256', 'cas-ticket:' . $ticket, config('plugin.sand-iam.app.auth_pepper'))]],
    ];
    $service = new CasProtocolService();
    foreach (['active', 'disabled', 'missing'] as $state) {
        State::$rows = $baseline; State::$audits = [];
        if ($state === 'disabled') State::$rows[Organization::class][1]['status'] = 2;
        if ($state === 'missing') State::$rows[Organization::class] = [];
        $result = $service->validate($url, $ticket, 'cas-scope-test');
        check($result === ($state === 'active' ? ['username' => 'person', 'attributes' => []] : null), 'Organization state ignored: ' . $state);
        check(State::$rows[CasTicket::class][5]['status'] === 2 && State::$snapshot === null, 'Ticket not consumed or transaction open');
        check(count(State::$audits) === ($state === 'active' ? 1 : 0), 'Rejected ticket emitted success audit');
        State::$rows[Organization::class] = $baseline[Organization::class];
        check($service->validate($url, $ticket, 'cas-replay-test') === null, 'Consumed ticket revived after organization recovery');
    }
    echo "CAS ticket organization scope and consumed replay behavior PASS (non-PG)\n";
    foreach ([Application::class => 2, Identity::class => 3, CasService::class => 4] as $model => $id) {
        foreach (['disabled', 'missing', 'foreign'] as $state) {
            if ($state === 'foreign' && $model === Application::class) continue;
            State::$rows = $baseline; State::$audits = [];
            if ($state === 'disabled') State::$rows[$model][$id]['status'] = 2;
            if ($state === 'missing') State::$rows[$model] = [];
            if ($state === 'foreign') State::$rows[$model][$id]['application_id'] = 99;
            check($service->validate($url, $ticket, 'cas-binding-test') === null, 'Unavailable or foreign binding accepted: ' . $model . '/' . $state);
            check(State::$rows[CasTicket::class][5]['status'] === 2 && State::$snapshot === null && State::$audits === [], 'Binding rejection did not consume ticket cleanly');
            State::$rows[$model] = $baseline[$model];
            check($service->validate($url, $ticket, 'cas-binding-replay') === null, 'Restoring binding revived ticket');
        }
    }
    State::$rows = $baseline; State::$audits = [];
    check($service->validate($url . '/other', $ticket, 'cas-wrong-service') === null, 'Wrong service accepted');
    check(State::$rows[CasTicket::class][5]['status'] === 2 && State::$audits === [], 'Wrong service did not consume ticket');
    check($service->validate($url, $ticket, 'cas-correct-replay') === null, 'Correct service revived rejected ticket');
    foreach (['expired', 'consumed', 'disabled', 'missing'] as $state) {
        State::$rows = $baseline; State::$audits = [];
        if ($state === 'expired') State::$rows[CasTicket::class][5]['expire_time'] = date('Y-m-d H:i:s', time() - 60);
        if ($state === 'consumed') State::$rows[CasTicket::class][5]['consumed_time'] = date('Y-m-d H:i:s');
        if ($state === 'disabled') State::$rows[CasTicket::class][5]['status'] = 2;
        if ($state === 'missing') State::$rows[CasTicket::class] = [];
        $before = State::$rows;
        check($service->validate($url, $ticket, 'cas-invalid-ticket') === null, 'Invalid ticket accepted: ' . $state);
        check(State::$rows === $before && State::$audits === [] && State::$snapshot === null, 'Invalid ticket changed state');
    }
    echo "CAS service binding, disabled principals, expiry and replay behavior PASS (non-PG)\n";
}
