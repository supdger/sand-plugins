<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception { final class ApiException extends \RuntimeException {} }
namespace support { final class Log { public static function error(string $message, array $context): void {} } }
namespace plugin\SandIam\app\model {
    final class ScopeAuditQuery
    {
        public function __construct(private readonly string $model) {}
        public function where(string $field, mixed $value): self { return $this; }
        public function find(): ?object
        {
            return $this->model === Application::class
                ? (Application::$enabled ? new Application() : null)
                : (Resource::$enabled ? new Resource() : null);
        }
    }
    final class Application
    {
        public static bool $enabled = true;
        public int $organization_id = 19;
        public static function where(string $field, mixed $value): ScopeAuditQuery { return new ScopeAuditQuery(self::class); }
    }
    final class Resource
    {
        public static bool $enabled = true;
        public int $id = 8;
        public static function where(string $field, mixed $value): ScopeAuditQuery { return new ScopeAuditQuery(self::class); }
    }
    final class AuditLog
    {
        public static array $legacy = [];
        public array $values = [];
        public function data(array $values): self { $this->values = $values; return $this; }
        public static function create(array $values): self
        {
            $key = $values['request_id'] . '|' . $values['action'];
            if (isset(self::$legacy[$key])) throw new \RuntimeException('SQLSTATE[23505] legacy duplicate');
            self::$legacy[$key] = $values;
            return (new self())->data($values);
        }
    }
}
namespace think\facade {
    final class ScopeAuditConnection
    {
        public function query(string $sql, array $values, bool $writer): array { return Db::query($sql, $values, $writer); }
    }
    final class Db
    {
        public static array $events = [];
        public static ?string $failure = null;
        public static int $depth = 0;
        public static bool $competingWriterWins = false;
        public static function transaction(callable $operation): mixed
        {
            $before = [self::$events, \plugin\SandIam\app\service\AuditEventPublisher::$calls, \plugin\SandIam\app\service\SecurityOperationsService::$calls];
            ++self::$depth;
            try { return $operation(new ScopeAuditConnection()); }
            catch (\Throwable $exception) {
                [self::$events, \plugin\SandIam\app\service\AuditEventPublisher::$calls, \plugin\SandIam\app\service\SecurityOperationsService::$calls] = $before;
                throw $exception;
            } finally { --self::$depth; }
        }
        public static function query(string $sql, array $values, bool $writer = false): array
        {
            if (self::$failure !== null) throw new \RuntimeException(self::$failure);
            if (!$writer || self::$depth === 0 || !str_contains($sql, 'ON CONFLICT (request_id, action, event_key) DO NOTHING RETURNING id')) {
                throw new \RuntimeException('Scope events must use one writer statement with the precise conflict target');
            }
            $key = $values[8] . '|' . $values[4] . '|' . $values[11];
            if (isset(self::$events[$key])) return [];
            self::$events[$key] = [
                'actor_ref' => $values[1], 'application_id' => $values[3], 'action' => $values[4],
                'outcome' => $values[7], 'request_id' => $values[8],
                'context' => json_decode($values[9], true, 512, JSON_THROW_ON_ERROR), 'event_key' => $values[11],
            ];
            if (self::$competingWriterWins) {
                // Deterministic boundary double: the competing transaction
                // committed this key and its projections before our INSERT.
                self::$competingWriterWins = false;
                ++\plugin\SandIam\app\service\AuditEventPublisher::$calls;
                ++\plugin\SandIam\app\service\SecurityOperationsService::$calls;
                return [];
            }
            return [['id' => count(self::$events)]];
        }
    }
}
namespace plugin\SandIam\app\service {
    final class AuditEventPublisher
    {
        public static int $calls = 0;
        public static bool $fail = false;
        public function publish(?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId): void
        {
            ++self::$calls;
            if (self::$fail) throw new \RuntimeException('fixture outbox failure');
        }
    }
    final class SecurityOperationsService
    {
        public static int $calls = 0;
        public static bool $fail = false;
        public function observeAudit(\plugin\SandIam\app\model\AuditLog $audit): void
        {
            ++self::$calls;
            if (self::$fail) throw new \RuntimeException('fixture alert SQL failure');
        }
    }
}
namespace {
    $package = dirname(__DIR__);
    foreach (['service/RequestId', 'service/AuditWriter', 'runtime/ScopeMatcher', 'runtime/PolicySnapshotLoader', 'runtime/PostgresPolicySnapshotLoader', 'runtime/PolicyAuthorizer', 'runtime/EntityScopeGuard', 'runtime/ApiGovernanceService', 'runtime/ApplicationAuthorizationService'] as $file) {
        require_once $package . '/app/' . $file . '.php';
    }
    use plugin\SandIam\app\runtime\ApplicationAuthorizationService;
    use plugin\SandIam\app\runtime\PolicyAuthorizer;
    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\Resource;
    use plugin\SandIam\app\model\AuditLog;
    use plugin\SandIam\app\service\AuditEventPublisher;
    use plugin\SandIam\app\service\SecurityOperationsService;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\sandadmin\exception\ApiException;
    use think\facade\Db;
    function scopeAuditCheck(bool $ok, string $message): void
    {
        if (!$ok) throw new RuntimeException($message);
        echo '[PASS] ' . $message . "\n";
    }
    function scopeAuditDeny(Closure $check): void
    {
        try { $check(); throw new LogicException('Expected scope denial'); }
        catch (ApiException $exception) {
            scopeAuditCheck($exception->getCode() === 403 && $exception->getMessage() === 'SAND_IAM_RESOURCE_SCOPE_DENIED', 'scope denial remains the exact 403');
        }
    }
    $authorizer = new PolicyAuthorizer();
    $service = new ApplicationAuthorizationService(authorizer: $authorizer);
    $decision = ['application_id' => 19, 'identity_id' => 8, 'resource_code' => 'records', 'operation' => 'read', 'scope' => ['equals' => ['owner_id' => 8]], 'request_id' => 'scope_collection_080'];
    $resolve = static fn (object $entity): array => ['id' => $entity->id, 'owner_id' => $entity->owner_id];
    $first = (object) ['id' => 1, 'owner_id' => 8];
    $second = (object) ['id' => 2, 'owner_id' => 8];
    $guard = $service->entityScopeGuard($decision, 'collection', $resolve);
    $guard->assertCollection([$first, $second]);
    $guard->assertCompleted();
    scopeAuditCheck(count(Db::$events) === 2, 'two legal entities preserve two immutable events under one request/action');
    $guard->assertCollection([$first, $second]);
    scopeAuditCheck(count(Db::$events) === 2 && AuditEventPublisher::$calls === 2 && SecurityOperationsService::$calls === 2, 'identical collection replay inserts and projects nothing twice');
    $bad = (object) ['id' => 3, 'owner_id' => 100008];
    $badGuard = $service->entityScopeGuard($decision, 'collection', $resolve);
    scopeAuditDeny(static fn () => $badGuard->assertCollection([$first, $bad]));
    try { $badGuard->assertCompleted(); throw new LogicException('Denied collection completed'); }
    catch (ApiException $exception) { scopeAuditCheck($exception->getCode() === 500, 'denied collection does not mark the guard completed'); }
    scopeAuditDeny(static fn () => $badGuard->assertCollection([$first, $bad]));
    scopeAuditCheck(count(Db::$events) === 3 && array_column(array_values(Db::$events), 'outcome') === ['allowed', 'allowed', 'denied'], 'denial is retained, repeated denial is idempotent');
    $entityGuard = $service->entityScopeGuard($decision, 'entity', $resolve);
    $entityGuard->assertEntity($first);
    scopeAuditDeny(static fn () => $entityGuard->assertEntity($bad));
    try { $entityGuard->assertCompleted(); throw new LogicException('Previous success hid current denial'); }
    catch (ApiException $exception) { scopeAuditCheck($exception->getCode() === 500, 'reused guard clears previous completion before a denied check'); }
    $entityGuard->assertEntity($first);
    scopeAuditCheck(count(Db::$events) === 3, 'allow then deny then allow cannot overwrite denial');
    $attributes = ['owner_id' => 8, 'id' => 4, 'private_value' => 'not-for-audit'];
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], $attributes, $decision['request_id']);
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], array_reverse($attributes, true), $decision['request_id']);
    scopeAuditCheck(count(Db::$events) === 4, 'associative attribute key order does not change event identity');
    scopeAuditCheck(!str_contains(json_encode(Db::$events), 'not-for-audit') && array_unique(array_column(Db::$events, 'request_id')) === [$decision['request_id']], 'audit keeps original request and excludes trusted attribute plaintext');
    Application::$enabled = false;
    scopeAuditDeny(static fn () => $entityGuard->assertEntity($first));
    Application::$enabled = true;
    Resource::$enabled = false;
    scopeAuditDeny(static fn () => $entityGuard->assertEntity($first));
    Resource::$enabled = true;
    scopeAuditCheck(count(Db::$events) === 6, 'same checked entity revalidates live application/resource state without cached authorization');
    Db::$failure = 'SQLSTATE[42703] event_key missing';
    try { $entityGuard->assertEntity($first); throw new LogicException('Old schema accepted'); }
    catch (RuntimeException $exception) { scopeAuditCheck($exception->getMessage() === Db::$failure, 'missing migrated schema fails without legacy fallback'); }
    Db::$failure = 'SQLSTATE[23503] foreign key failure';
    try { $entityGuard->assertEntity($first); throw new LogicException('Other constraint failure hidden'); }
    catch (RuntimeException $exception) { scopeAuditCheck($exception->getMessage() === Db::$failure, 'non-event storage failures are not swallowed'); }
    Db::$failure = null;
    $before = count(Db::$events);
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'strict_scope_type_080');
    scopeAuditDeny(static fn () => $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => '8'], 'strict_scope_type_080'));
    scopeAuditCheck(count(Db::$events) === $before + 2, 'integer/string values keep separate event identities and strict scope semantics');
    $before = count(Db::$events); $projections = AuditEventPublisher::$calls;
    AuditEventPublisher::$fail = true;
    try { $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'scope_projection_retry_080'); throw new LogicException('Outbox failure hidden'); }
    catch (RuntimeException $exception) { scopeAuditCheck($exception->getMessage() === 'fixture outbox failure' && count(Db::$events) === $before && AuditEventPublisher::$calls === $projections, 'failed scope outbox rolls back the new audit and its projection'); }
    AuditEventPublisher::$fail = false;
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'scope_projection_retry_080');
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'scope_projection_retry_080');
    scopeAuditCheck(count(Db::$events) === $before + 1 && AuditEventPublisher::$calls === $projections + 1, 'outbox failure retry inserts and projects once, following replay is safe');
    Db::transaction(static function () use ($authorizer, $decision): void {
        $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'scope_outer_transaction_080');
        scopeAuditCheck(Db::$depth === 1, 'scope transaction leaves the existing outer transaction active');
        $before = count(Db::$events); AuditEventPublisher::$fail = true;
        try { $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 9], 'scope_nested_failure_080'); throw new LogicException('Nested projection failure hidden'); }
        catch (RuntimeException $exception) { scopeAuditCheck(count(Db::$events) === $before && Db::$depth === 1, 'scope savepoint failure preserves earlier outer transaction work'); }
        AuditEventPublisher::$fail = false;
    });
    $before = count(Db::$events); SecurityOperationsService::$fail = true;
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'scope_alert_failure_080');
    SecurityOperationsService::$fail = false;
    scopeAuditCheck(count(Db::$events) === $before + 1, 'best-effort alert failure rolls back only its savepoint and preserves scope audit/outbox');
    $before = count(Db::$events); $projections = AuditEventPublisher::$calls; Db::$competingWriterWins = true;
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'scope_competing_writer_080');
    $authorizer->assertScope(19, 8, 'records', 'read', $decision['scope'], ['owner_id' => 8], 'scope_competing_writer_080');
    scopeAuditCheck(count(Db::$events) === $before + 1 && AuditEventPublisher::$calls === $projections + 1, 'competing-writer conflict return never duplicates the winning event projection (boundary double)');
    $writer = new AuditWriter();
    $writer->write('identity', '8', 19, 19, 'authorize.read', 'records', 8, 'allowed', 'legacy_authorize_080');
    try { $writer->write('identity', '8', 19, 19, 'authorize.read', 'records', 8, 'allowed', 'legacy_authorize_080'); throw new LogicException('Legacy audit changed'); }
    catch (RuntimeException $exception) { scopeAuditCheck(str_contains($exception->getMessage(), '23505'), 'non-scope empty event key preserves the existing audit contract'); }
    scopeAuditCheck(count(AuditLog::$legacy) === 1, 'scope checks never use the legacy insertion path');
    echo "scope audit event non-PG checks passed\n";
}
