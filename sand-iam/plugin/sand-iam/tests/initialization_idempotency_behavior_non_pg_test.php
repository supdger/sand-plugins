<?php

declare(strict_types=1);

/*
 * This isolated harness deliberately runs InitializationService::apply() with
 * a fake ThinkORM/transaction facade. It proves the real idempotency callback
 * is invoked once, rather than only checking that its source text exists.
 */
namespace plugin\sandadmin\exception {
    final class ApiException extends \RuntimeException {}
}

namespace think\facade {
    final class Db
    {
        public static int $starts = 0;
        public static int $commits = 0;
        public static int $rollbacks = 0;
        /** @var list<array{rows:array<class-string,array<int,object>>,next_ids:array<class-string,int>}> */
        private static array $snapshots = [];
        /** @var list<string> */
        public static array $deletedTables = [];

        public static function startTrans(): void { self::$starts++; self::$snapshots[] = \plugin\SandIam\app\model\InitializationFakeModel::transactionSnapshot(); }
        public static function commit(): void { self::$commits++; array_pop(self::$snapshots); }
        public static function rollback(): void { self::$rollbacks++; $snapshot = array_pop(self::$snapshots); if ($snapshot !== null) \plugin\SandIam\app\model\InitializationFakeModel::restoreTransactionSnapshot($snapshot); }
        /** Simulates a concurrent transaction which commits after this transaction began. */
        public static function recordConcurrentCommit(): void
        {
            $index = array_key_last(self::$snapshots);
            if ($index !== null) self::$snapshots[$index] = \plugin\SandIam\app\model\InitializationFakeModel::transactionSnapshot();
        }
        public static function table(string $table): InitializationTableQuery
        {
            $models = [
                'sand_iam_application' => \plugin\SandIam\app\model\Application::class,
                'sand_iam_application_business_action' => \plugin\SandIam\app\model\ApplicationBusinessAction::class,
                'sand_iam_role' => \plugin\SandIam\app\model\Role::class,
                'sand_iam_user_type' => \plugin\SandIam\app\model\UserType::class,
                'sand_iam_resource' => \plugin\SandIam\app\model\Resource::class,
                'sand_iam_identity_provider' => \plugin\SandIam\app\model\IdentityProvider::class,
                'sand_iam_policy' => \plugin\SandIam\app\model\Policy::class,
            ];
            if (!isset($models[$table])) throw new \LogicException("unexpected table mutation: {$table}");
            self::$deletedTables[] = $table;
            return new InitializationTableQuery($models[$table]);
        }
    }

    final class InitializationTableQuery
    {
        /** @var list<array{0:string,1:mixed}> */ private array $conditions = [];
        /** @param class-string<\plugin\SandIam\app\model\InitializationFakeModel> $model */
        public function __construct(private readonly string $model) {}
        public function where(string $field, mixed $value): self { $this->conditions[] = [$field, $value]; return $this; }
        public function delete(): void { ($this->model)::deleteWhere($this->conditions); }
    }
}

namespace plugin\SandIam\app\model {
    #[\AllowDynamicProperties]
    abstract class InitializationFakeModel
    {
        /** @var array<class-string, array<int, object>> */
        private static array $rows = [];
        /** @var array<class-string, int> */
        private static array $nextIds = [];
        /** @var array<class-string,\Closure(class-string,array<string,mixed>):void> */
        private static array $beforeCreate = [];

        /** @return array{rows:array<class-string,array<int,object>>,next_ids:array<class-string,int>} */
        public static function transactionSnapshot(): array
        {
            return unserialize(serialize(['rows' => self::$rows, 'next_ids' => self::$nextIds]));
        }

        /** @param array{rows:array<class-string,array<int,object>>,next_ids:array<class-string,int>} $snapshot */
        public static function restoreTransactionSnapshot(array $snapshot): void
        {
            self::$rows = $snapshot['rows']; self::$nextIds = $snapshot['next_ids'];
        }

        /** @param class-string $class */
        public static function beforeNextCreate(string $class, \Closure $callback): void { self::$beforeCreate[$class] = $callback; }

        public static function where(string $field, mixed $value): InitializationFakeQuery
        {
            return (new InitializationFakeQuery(static::class))->where($field, $value);
        }

        /** @param array<string,mixed> $payload */
        public static function create(array $payload): static
        {
            $class = static::class;
            $beforeCreate = self::$beforeCreate[$class] ?? null;
            unset(self::$beforeCreate[$class]);
            if ($beforeCreate !== null) $beforeCreate($class, $payload);
            $record = new static();
            $record->id = self::$nextIds[$class] ?? 1;
            self::$nextIds[$class] = $record->id + 1;
            foreach ($payload as $field => $value) $record->{$field} = $value;
            self::$rows[$class][$record->id] = $record;
            return $record;
        }

        /** @param array<string,mixed> $payload */
        public function save(array $payload): void
        {
            foreach ($payload as $field => $value) $this->{$field} = $value;
        }

        /** @return list<object> */
        public static function rows(): array { return array_values(self::$rows[static::class] ?? []); }
        public static function count(): int { return count(self::$rows[static::class] ?? []); }
        public static function reset(): void { self::$rows = []; self::$nextIds = []; self::$beforeCreate = []; }
        /** @param list<array{0:string,1:mixed}> $conditions */
        public static function deleteWhere(array $conditions): void
        {
            foreach (self::$rows[static::class] ?? [] as $id => $record) {
                foreach ($conditions as [$field, $value]) if (($record->{$field} ?? null) !== $value) continue 2;
                unset(self::$rows[static::class][$id]);
            }
        }
    }

    final class InitializationFakeQuery
    {
        /** @var list<array{0:string,1:mixed}> */
        private array $conditions = [];

        /** @param class-string<InitializationFakeModel> $model */
        public function __construct(private readonly string $model) {}
        public function where(string $field, mixed $value): self { $this->conditions[] = [$field, $value]; return $this; }
        public function whereNull(string $field): self { $this->conditions[] = [$field, null]; return $this; }
        public function lock(bool $locked): self { return $this; }
        public function limit(int $limit): self { return $this; }
        public function find(): ?object
        {
            foreach (($this->model)::rows() as $record) {
                foreach ($this->conditions as [$field, $value]) {
                    if (($record->{$field} ?? null) !== $value) continue 2;
                }
                return $record;
            }
            return null;
        }
        /** @return list<object> */
        public function get(): array { return array_values(array_filter(($this->model)::rows(), fn (object $record): bool => $this->matches($record))); }
        public function select(): InitializationFakeCollection { return new InitializationFakeCollection($this->get()); }
        public function delete(): void { ($this->model)::deleteWhere($this->conditions); }
        private function matches(object $record): bool
        {
            foreach ($this->conditions as [$field, $value]) if (($record->{$field} ?? null) !== $value) return false;
            return true;
        }
    }

    final class InitializationFakeCollection
    {
        /** @param list<object> $rows */
        public function __construct(private readonly array $rows) {}
        /** @return list<object> */
        public function all(): array { return $this->rows; }
    }

    final class Application extends InitializationFakeModel {}
    final class ApplicationBusinessAction extends InitializationFakeModel {}
    final class IdentityProvider extends InitializationFakeModel {}
    final class IdentityProviderApplication extends InitializationFakeModel {}
    final class InitializationBinding extends InitializationFakeModel {}
    final class InitializationDraft extends InitializationFakeModel {}
    final class InitializationDraftRevision extends InitializationFakeModel {}
    final class InitializationRun extends InitializationFakeModel {}
    final class Organization extends InitializationFakeModel {}
    final class Policy extends InitializationFakeModel {}
    final class Resource extends InitializationFakeModel {}
    final class Role extends InitializationFakeModel {}
    final class SecurityOperation extends InitializationFakeModel {}
    final class UserType extends InitializationFakeModel {}
}

namespace plugin\SandIam\app\service {
    final class AuditWriter
    {
        public static int $writes = 0;
        /** @param array<string,mixed> $context */
        public function write(string $actorType, string $actorRef, ?int $organizationId, ?int $applicationId, string $action, string $resourceType, ?int $resourceId, string $outcome, string $requestId, array $context = []): void
        {
            self::$writes++;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/runtime/ScopeMatcher.php';
    require_once dirname(__DIR__) . '/app/developer/ApplicationBusinessActionCatalog.php';
    require_once dirname(__DIR__) . '/app/initialization/InitializationPackage.php';
    require_once dirname(__DIR__) . '/app/service/RequestId.php';
    require_once dirname(__DIR__) . '/app/service/IdempotencyService.php';
    require_once dirname(__DIR__) . '/app/service/InitializationService.php';

    use plugin\SandIam\app\model\Application;
    use plugin\SandIam\app\model\ApplicationBusinessAction;
    use plugin\SandIam\app\model\InitializationBinding;
    use plugin\SandIam\app\model\InitializationDraft;
    use plugin\SandIam\app\model\InitializationDraftRevision;
    use plugin\SandIam\app\model\InitializationFakeModel;
    use plugin\SandIam\app\model\InitializationRun;
    use plugin\SandIam\app\model\Organization;
    use plugin\SandIam\app\model\Policy;
    use plugin\SandIam\app\model\Resource;
    use plugin\SandIam\app\model\Role;
    use plugin\SandIam\app\model\SecurityOperation;
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\service\InitializationService;
    use plugin\sandadmin\exception\ApiException;
    use think\facade\Db;

    function initializationBehaviorAssert(bool $condition, string $message): void
    {
        if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
    }

    InitializationFakeModel::reset();
    Organization::create(['id' => 1, 'code' => 'acme', 'status' => 1]);
    $manifest = [
        'format' => 'sand-iam.initialization/v1',
        'package_code' => 'acme-base',
        'organization_code' => 'acme',
        'application' => ['code' => 'portal', 'name' => 'Acme Portal', 'status' => 1],
        'roles' => [['code' => 'staff', 'name' => 'Staff', 'status' => 1]],
        'user_types' => [],
        'resources' => [['code' => 'case', 'name' => 'Case', 'owner_field' => 'owner_id', 'organization_field' => 'organization_id', 'status' => 1]],
        'business_actions' => [['code' => 'case.read', 'name' => 'Read case', 'description' => '', 'state' => 'published', 'status' => 1]],
        'identity_providers' => [],
        'policies' => [['key' => 'case-read', 'resource_code' => 'case', 'role_code' => 'staff', 'action' => 'case.read', 'effect' => 'allow', 'condition' => [], 'scope' => [], 'priority' => 0, 'state' => 'published', 'status' => 1]],
    ];
    $service = new InitializationService();
    $previewHash = (string) $service->preview($manifest)['preview_hash'];

    $first = $service->apply($manifest, $previewHash, 7, 'initialization-apply-20260822');
    initializationBehaviorAssert(($first['run_id'] ?? 0) === 1, 'matching preview did not apply the initialization package');
    initializationBehaviorAssert(Application::count() === 1 && ApplicationBusinessAction::count() === 1 && Role::count() === 1 && Resource::count() === 1 && Policy::count() === 1 && InitializationBinding::count() === 5 && SecurityOperation::count() === 1, 'apply callback did not persist its initialized objects, bindings, and security operation');
    initializationBehaviorAssert(AuditWriter::$writes === 1 && Db::$starts === 2 && Db::$commits === 2, 'apply callback or transaction boundary did not execute exactly once');

    $replay = $service->apply($manifest, $previewHash, 7, 'initialization-apply-20260822');
    initializationBehaviorAssert(($replay['run_id'] ?? 0) === 1, 'matching idempotency request did not return the original result');
    initializationBehaviorAssert(Application::count() === 1 && ApplicationBusinessAction::count() === 1 && Role::count() === 1 && Resource::count() === 1 && Policy::count() === 1 && InitializationBinding::count() === 5 && SecurityOperation::count() === 1 && AuditWriter::$writes === 1, 'idempotency replay re-executed the apply callback or audit');

    try {
        $service->apply($manifest, $previewHash . 'stale', 7, 'initialization-stale-20260822');
        initializationBehaviorAssert(false, 'stale preview hash was accepted');
    } catch (ApiException $exception) {
        initializationBehaviorAssert($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_INITIALIZATION_PREVIEW_STALE'), 'stale preview hash returned the wrong failure');
    }
    initializationBehaviorAssert(Application::count() === 1 && AuditWriter::$writes === 1, 'stale preview hash mutated state or emitted audit');

    $draft = $service->saveDraft($manifest, 7, 'initialization-draft-save-20260908');
    initializationBehaviorAssert(($draft['draft_id'] ?? 0) === 1 && ($draft['revision'] ?? 0) === 1, 'draft save did not create revision one');
    initializationBehaviorAssert(InitializationDraft::count() === 1 && InitializationDraftRevision::count() === 1 && AuditWriter::$writes === 2, 'draft save did not persist one draft, revision, and audit');
    $draftReplay = $service->saveDraft($manifest, 7, 'initialization-draft-save-20260908');
    initializationBehaviorAssert(($draftReplay['draft_id'] ?? 0) === ($draft['draft_id'] ?? 0) && InitializationDraftRevision::count() === 1 && AuditWriter::$writes === 2, 'draft save replay created another revision or audit record');

    $secretManifest = $manifest;
    $secretManifest['application']['secret'] = 'never-persisted';
    try {
        $service->saveDraft($secretManifest, 7, 'initialization-draft-secret-20260908');
        initializationBehaviorAssert(false, 'draft save accepted a sensitive field');
    } catch (ApiException $exception) {
        initializationBehaviorAssert($exception->getCode() === 400 && str_contains($exception->getMessage(), 'SAND_IAM_INITIALIZATION_SENSITIVE_FIELD'), 'sensitive draft rejection was not stable');
    }
    initializationBehaviorAssert(InitializationDraft::count() === 1 && InitializationDraftRevision::count() === 1, 'sensitive draft input persisted state');

    $raceManifest = $manifest;
    $raceManifest['package_code'] = 'acme-race';
    InitializationFakeModel::beforeNextCreate(InitializationDraft::class, static function (string $class, array $payload): void {
        if ($class !== InitializationDraft::class) return;
        SecurityOperation::deleteWhere([
            ['operation', 'initialization_draft.save'],
            ['request_id', 'initialization-draft-race-20260908'],
        ]);
        InitializationDraft::create($payload);
        Db::recordConcurrentCommit();
        throw new \RuntimeException('SQLSTATE[23505]: unique constraint violation');
    });
    try {
        $service->saveDraft($raceManifest, 7, 'initialization-draft-race-20260908');
        initializationBehaviorAssert(false, 'concurrent same organization/package draft save was accepted');
    } catch (ApiException $exception) {
        initializationBehaviorAssert($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_INITIALIZATION_DRAFT_CONFLICT'), 'concurrent draft save did not return the stable conflict');
    }
    initializationBehaviorAssert(InitializationDraft::count() === 2 && InitializationDraftRevision::count() === 1 && AuditWriter::$writes === 2, 'concurrent draft conflict created a second revision or audit');

    $updatedManifest = $manifest;
    $updatedManifest['application']['name'] = 'Acme Portal Updated';
    $updated = $service->updateDraft((int) $draft['draft_id'], 1, $updatedManifest, 7, 'initialization-draft-update-20260908');
    initializationBehaviorAssert(($updated['revision'] ?? 0) === 2 && InitializationDraftRevision::count() === 2, 'draft update did not append revision two');
    $firstRevision = InitializationDraftRevision::rows()[0] ?? null;
    initializationBehaviorAssert(($firstRevision?->manifest['application']['name'] ?? '') === 'Acme Portal', 'draft update rewrote immutable revision one');
    try {
        $service->updateDraft((int) $draft['draft_id'], 1, $updatedManifest, 7, 'initialization-draft-stale-20260908');
        initializationBehaviorAssert(false, 'stale draft revision was accepted');
    } catch (ApiException $exception) {
        initializationBehaviorAssert($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_INITIALIZATION_DRAFT_REVISION_STALE'), 'stale draft revision did not return the stable conflict');
    }
    initializationBehaviorAssert(InitializationDraftRevision::count() === 2 && AuditWriter::$writes === 3, 'stale draft revision mutated history or audit');
    $disabled = $service->disableDraft((int) $draft['draft_id'], 2, 7, 'initialization-draft-disable-20260908');
    initializationBehaviorAssert(($disabled['revision'] ?? 0) === 3 && ($disabled['status'] ?? 0) === 2 && InitializationDraftRevision::count() === 3, 'draft disable did not append disabled revision');
    initializationBehaviorAssert((InitializationRun::rows()[0]?->state ?? '') === 'applied', 'draft disable changed an applied initialization run');

    $firstRun = InitializationRun::rows()[0] ?? null;
    $confirmation = hash('sha256', "sand-iam-init-rollback\0{$firstRun->id}\0{$firstRun->package_hash}");
    $beforeInvalidRollback = [
        'run_state' => $firstRun->state,
        'application' => Application::count(),
        'business_actions' => ApplicationBusinessAction::count(),
        'roles' => Role::count(),
        'resources' => Resource::count(),
        'policies' => Policy::count(),
        'bindings' => InitializationBinding::count(),
        'operations' => SecurityOperation::count(),
        'audit' => AuditWriter::$writes,
        'starts' => Db::$starts,
        'commits' => Db::$commits,
        'rollbacks' => Db::$rollbacks,
        'deletes' => Db::$deletedTables,
    ];
    try {
        $service->rollback((int) $firstRun->id, 'wrong-confirmation', 7, 'initialization-rollback-invalid-20260908');
        initializationBehaviorAssert(false, 'rollback accepted an invalid confirmation');
    } catch (ApiException $exception) {
        initializationBehaviorAssert($exception->getCode() === 403 && str_contains($exception->getMessage(), 'SAND_IAM_INITIALIZATION_ROLLBACK_CONFIRMATION_INVALID'), 'invalid rollback confirmation did not return the stable denial');
    }
    initializationBehaviorAssert(
        ($firstRun->state ?? '') === $beforeInvalidRollback['run_state']
        && Application::count() === $beforeInvalidRollback['application']
        && ApplicationBusinessAction::count() === $beforeInvalidRollback['business_actions']
        && Role::count() === $beforeInvalidRollback['roles']
        && Resource::count() === $beforeInvalidRollback['resources']
        && Policy::count() === $beforeInvalidRollback['policies']
        && InitializationBinding::count() === $beforeInvalidRollback['bindings']
        && SecurityOperation::count() === $beforeInvalidRollback['operations']
        && AuditWriter::$writes === $beforeInvalidRollback['audit']
        && Db::$starts === $beforeInvalidRollback['starts'] + 2
        && Db::$commits === $beforeInvalidRollback['commits'] + 1
        && Db::$rollbacks === $beforeInvalidRollback['rollbacks'] + 1
        && Db::$deletedTables === $beforeInvalidRollback['deletes'],
        'invalid rollback confirmation changed a business record, audit, delete sequence, or transaction outcome'
    );
    $firstRun = InitializationRun::rows()[0] ?? null;
    $rolledBack = $service->rollback((int) $firstRun->id, $confirmation, 7, 'initialization-rollback-20260908');
    initializationBehaviorAssert(($rolledBack['run_id'] ?? 0) === (int) $firstRun->id && ($firstRun->state ?? '') === 'rolled_back', 'valid rollback did not close the applied run');
    initializationBehaviorAssert(Application::count() === 0 && ApplicationBusinessAction::count() === 0 && Role::count() === 0 && Resource::count() === 0 && Policy::count() === 0 && InitializationBinding::count() === 0, 'valid rollback did not reverse created initialized objects and bindings');
    initializationBehaviorAssert(Db::$deletedTables === [
        'sand_iam_policy',
        'sand_iam_resource',
        'sand_iam_role',
        'sand_iam_application_business_action',
        'sand_iam_application',
    ], 'rollback did not delete recorded dependent objects before the application');

    $secondPreview = (string) $service->preview($manifest)['preview_hash'];
    $second = $service->apply($manifest, $secondPreview, 7, 'initialization-apply-drift-20260908');
    $application = Application::rows()[0] ?? null;
    $application?->save(['name' => 'changed outside initialization']);
    $secondRun = InitializationRun::rows()[1] ?? null;
    $driftConfirmation = hash('sha256', "sand-iam-init-rollback\0{$secondRun->id}\0{$secondRun->package_hash}");
    try {
        $service->rollback((int) $secondRun->id, $driftConfirmation, 7, 'initialization-rollback-drift-20260908');
        initializationBehaviorAssert(false, 'rollback accepted a drifted target');
    } catch (ApiException $exception) {
        initializationBehaviorAssert($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT'), 'drift rollback did not return the stable conflict');
    }
    initializationBehaviorAssert(($secondRun->state ?? '') === 'applied' && InitializationBinding::count() > 0, 'drift rollback changed run state or bindings');

    echo "initialization idempotency behavior non-PG checks passed\n";
}
