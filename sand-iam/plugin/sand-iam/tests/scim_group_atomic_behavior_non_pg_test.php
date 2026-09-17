<?php
declare(strict_types=1);
namespace ScimGroupTest {
    final class State {
        public static array $rows = [];
        public static array $audits = [];
        public static ?array $transaction = null;
        public static bool $fail = false;
    }
    class Model {
        public function __construct(private array $values) {}
        public function __get(string $key): mixed { return $this->values[$key] ?? null; }
        public static function where(string $key, mixed ...$values): Query { return (new Query(static::class))->where($key, ...$values); }
        public static function whereIn(string $key, array $values): Query { return (new Query(static::class))->whereIn($key, $values); }
        public static function find(int $id): ?static { return isset(State::$rows[static::class][$id]) ? new static(State::$rows[static::class][$id]) : null; }
        public static function withTrashed(): Query { return new Query(static::class); }
        public static function create(array $values): static {
            $values['id'] = max([0, ...array_keys(State::$rows[static::class] ?? [])]) + 1;
            State::$rows[static::class][$values['id']] = $values;
            return new static($values);
        }
        public function save(array $values): void {
            $this->values = array_replace($this->values, $values);
            State::$rows[static::class][$this->id] = $this->values;
        }
        public function delete(): void { $this->save(['delete_time' => '2026-09-14 00:00:00']); }
        public function refresh(): static { return new static(State::$rows[static::class][$this->id]); }
    }
    final class Query {
        private array $filters = [];
        private array $sets = [];
        private ?string $caseInsensitiveUserName = null;
        public function __construct(private string $model) {}
        public function where(string $key, mixed ...$values): self { $this->filters[] = [$key, ...$values]; return $this; }
        public function whereIn(string $key, array $values): self { $this->sets[$key] = $values; return $this; }
        public function whereRaw(string $sql, array $bindings): self {
            check($sql === "lower(source_attributes->>'userName') = lower(?)" && count($bindings) === 1 && is_string($bindings[0]), 'Unexpected raw SCIM query');
            $this->caseInsensitiveUserName = strtolower($bindings[0]);
            return $this;
        }
        public function column(string $key): array { return array_map(static fn (object $row): mixed => $row->{$key}, $this->all()); }
        public function lock(bool $lock): self { check(State::$transaction !== null, 'Lock outside transaction'); return $this; }
        public function select(): self { return $this; }
        public function all(): array {
            $result = [];
            foreach (State::$rows[$this->model] ?? [] as $row) {
                if ($this->caseInsensitiveUserName !== null) {
                    $attributes = $row['source_attributes'] ?? [];
                    if (is_string($attributes)) $attributes = json_decode($attributes, true);
                    if (!is_array($attributes) || strtolower((string) ($attributes['userName'] ?? '')) !== $this->caseInsensitiveUserName) continue;
                }
                foreach ($this->sets as $key => $values) if (!in_array($row[$key] ?? null, $values, true)) continue 2;
                foreach ($this->filters as $filter) {
                    [$key, $value] = $filter;
                    if (count($filter) === 3) {
                        check($value === '<>', 'Unexpected operator');
                        if (($row[$key] ?? null) === $filter[2]) continue 2;
                    } elseif (($row[$key] ?? null) !== $value) continue 2;
                }
                $result[] = new ($this->model)($row);
            }
            return $result;
        }
        public function find(): ?object { return $this->all()[0] ?? null; }
        public function update(array $values): void { foreach ($this->all() as $row) $row->save($values); }
    }
    function check(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Organization extends \ScimGroupTest\Model {}
    class Application extends \ScimGroupTest\Model {}
    class IdentityProvider extends \ScimGroupTest\Model {}
    class IdentityProviderApplication extends \ScimGroupTest\Model {}
    class ScimGroup extends \ScimGroupTest\Model {}
    class ScimGroupMember extends \ScimGroupTest\Model {}
    class ScimResource extends \ScimGroupTest\Model {}
    class Identity extends \ScimGroupTest\Model {}
    class IdentityBinding extends \ScimGroupTest\Model {}
    class AuthSession extends \ScimGroupTest\Model {}
    class AuthRefreshToken extends \ScimGroupTest\Model {}
}
namespace think\facade {
    class Db {
        public static function startTrans(): void {
            \ScimGroupTest\check(\ScimGroupTest\State::$transaction === null, 'Leaked transaction');
            \ScimGroupTest\State::$transaction = [\ScimGroupTest\State::$rows, \ScimGroupTest\State::$audits];
        }
        public static function commit(): void { \ScimGroupTest\State::$transaction = null; }
        public static function rollback(): void {
            if (\ScimGroupTest\State::$transaction !== null) [\ScimGroupTest\State::$rows, \ScimGroupTest\State::$audits] = \ScimGroupTest\State::$transaction;
            \ScimGroupTest\State::$transaction = null;
        }
    }
}
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public function write(...$arguments): void {
            \ScimGroupTest\State::$audits[] = $arguments;
            if (\ScimGroupTest\State::$fail) throw new \RuntimeException('audit failure');
        }
    }
}
namespace {
    use ScimGroupTest\State;
    use function ScimGroupTest\check;
    use plugin\SandIam\app\model\{Organization, Application, IdentityProvider, IdentityProviderApplication, ScimGroup, ScimGroupMember, ScimResource};
    use plugin\SandIam\app\model\{Identity, IdentityBinding, AuthSession, AuthRefreshToken};
    use plugin\SandIam\app\service\ScimService;
    use plugin\sandadmin\exception\ApiException;
    require dirname(__DIR__) . '/app/service/ScimService.php';
    State::$rows = [
        Organization::class => [1 => ['id' => 1, 'status' => 1]],
        Application::class => [2 => ['id' => 2, 'organization_id' => 1, 'status' => 1]],
        IdentityProvider::class => [3 => ['id' => 3, 'organization_id' => 1, 'application_id' => 2, 'scope_type' => 'application', 'provider_type' => 'scim', 'status' => 1]],
        IdentityProviderApplication::class => [4 => ['id' => 4, 'identity_provider_id' => 3, 'organization_id' => 1, 'application_id' => 2, 'status' => 1]],
        ScimResource::class => [5 => ['id' => 5, 'identity_provider_id' => 3, 'application_id' => 2, 'identity_id' => 6, 'scim_id' => 'user-one', 'source_state' => 'active']],
    ];
    $provider = new IdentityProvider(State::$rows[IdentityProvider::class][3]);
    $service = new ScimService();
    $resource = ['urn:sand:params:scim:schemas:extension:source:1.0' => ['sourceKey' => 'group-one'], 'externalId' => 'external-one', 'displayName' => 'One', 'members' => [['value' => 'user-one']]];
    $create = static fn (): array => $service->createGroup($provider, 2, $resource, 'scim-create');
    $before = [State::$rows, State::$audits];
    State::$fail = true;
    try { $create(); throw new \RuntimeException('Create audit failure ignored'); }
    catch (\RuntimeException $exception) { check($exception->getMessage() === 'audit failure', $exception->getMessage()); }
    check([State::$rows, State::$audits] === $before && State::$transaction === null, 'Failed create retained group/member/audit');
    State::$fail = false;
    $created = $create();
    check($created['members'] === [['value' => 'user-one']] && $created['meta']['version'] === 'W/"1"', 'Created group contract incorrect');
    $baseline = [State::$rows, State::$audits];
    foreach (['replace', 'patch', 'delete'] as $method) {
        [State::$rows, State::$audits] = $baseline;
        $operation = static fn () => match ($method) {
            'replace' => $service->replaceGroup($provider, 2, $created['id'], ['displayName' => 'Two', 'members' => []], 'W/"1"', 'scim-replace'),
            'patch' => $service->patchGroup($provider, 2, $created['id'], ['Operations' => [['op' => 'remove', 'path' => 'members']]], 'W/"1"', 'scim-patch'),
            'delete' => $service->deleteGroup($provider, 2, $created['id'], 'W/"1"', 'scim-delete'),
        };
        State::$fail = true;
        try { $operation(); throw new \RuntimeException('Mutation audit failure ignored'); }
        catch (\RuntimeException $exception) { check($exception->getMessage() === 'audit failure', $exception->getMessage()); }
        check([State::$rows, State::$audits] === $baseline && State::$transaction === null, 'Failed mutation retained group/member/version/audit');
        State::$fail = false;
        $operation();
        check(State::$rows[ScimGroup::class][1]['version'] === 2 && State::$rows[ScimGroupMember::class][1]['status'] === 2, 'Mutation lost version/member removal');
        check(count(State::$audits) === 2 && State::$audits[1][2] === 1 && State::$audits[1][3] === 2, 'Mutation audit scope lost');
        $before = [State::$rows, State::$audits];
        try { $operation(); throw new \RuntimeException('Stale mutation accepted'); }
        catch (ApiException $exception) { check($exception->getCode() === ($method === 'delete' ? 404 : 412), 'Wrong stale mutation error'); }
        check([State::$rows, State::$audits] === $before && State::$transaction === null, 'Stale mutation changed data');
    }
    echo "SCIM group lifecycle audit rollback and version recovery non-PG behavior PASS\n";
    foreach ([
        ['op' => 'remove', 'path' => 'externalId'],
        ['op' => 'replace', 'path' => 'externalId', 'value' => null],
        ['op' => 'replace', 'value' => ['externalId' => null]],
    ] as $clearOperation) {
        [State::$rows, State::$audits] = $baseline;
        $cleared = $service->patchGroup($provider, 2, $created['id'], ['Operations' => [$clearOperation]], 'W/"1"', 'external-clear');
        check(!array_key_exists('externalId', $cleared), 'Explicit externalId clear retained old value');
        check($cleared['id'] === $created['id'] && $cleared['members'] === $created['members'] && $cleared['meta']['version'] === 'W/"2"', 'ExternalId clear changed group identity or members');
        check($service->getGroup($provider, 2, $created['id']) === $cleared, 'Cleared externalId returned on reread');
    }
    echo "SCIM group explicit externalId clearing non-PG behavior PASS\n";
    $removeExternal = ['op' => 'remove', 'path' => 'externalId'];
    $setExternal = ['op' => 'replace', 'path' => 'externalId', 'value' => 'external-two'];
    foreach ([[$removeExternal, $setExternal], [$setExternal, $removeExternal]] as $operations) {
        [State::$rows, State::$audits] = $baseline;
        $result = $service->patchGroup($provider, 2, $created['id'], ['Operations' => $operations], 'W/"1"', 'ordered-patch');
        check(($result['externalId'] ?? null) === ($operations[1]['value'] ?? null), 'PATCH operation order lost');
        check($result['meta']['version'] === 'W/"2"' && count(State::$audits) === count($baseline[1]) + 1, 'One PATCH must produce one version and audit');
    }
    foreach ([
        [[$removeExternal, ['op' => 'replace', 'path' => 'unsupported', 'value' => 'x']], 'W/"1"', 400],
        [[$removeExternal, ['op' => 'replace', 'path' => 'externalId', 'value' => ['invalid']]], 'W/"1"', 400],
        [[$removeExternal], null, 428],
        [[$removeExternal], 'W/"0"', 412],
    ] as [$operations, $version, $error]) {
        [State::$rows, State::$audits] = $baseline;
        try {
            $service->patchGroup($provider, 2, $created['id'], ['Operations' => $operations], $version, 'rejected-patch');
            throw new \RuntimeException('Invalid PATCH accepted');
        } catch (ApiException $exception) {
            check($exception->getCode() === $error, 'Wrong PATCH rejection status');
        }
        check([State::$rows, State::$audits] === $baseline && State::$transaction === null, 'Rejected PATCH retained partial changes');
    }
    echo "SCIM group ordered PATCH and rejection atomicity non-PG behavior PASS\n";
    $userInput = ['urn:sand:params:scim:schemas:extension:source:1.0' => ['sourceKey' => 'user-key'], 'userName' => 'user.one', 'displayName' => 'First'];
    $createUser = static fn (): array => $service->createUser($provider, 2, $userInput, 'user-create');
    $before = [State::$rows, State::$audits];
    State::$fail = true;
    try { $createUser(); throw new \RuntimeException('User create audit failure ignored'); }
    catch (\RuntimeException $exception) { check($exception->getMessage() === 'audit failure', $exception->getMessage()); }
    check([State::$rows, State::$audits] === $before && State::$transaction === null, 'Failed user create retained identity/binding/resource/audit');
    State::$fail = false;
    $user = $createUser();
    check($user['userName'] === 'user.one' && $user['active'] === true && $user['meta']['version'] === 'W/"1"', 'User creation contract incorrect');
    $beforeDuplicate = [State::$rows, State::$audits];
    try {
        $service->createUser($provider, 2, [
            'urn:sand:params:scim:schemas:extension:source:1.0' => ['sourceKey' => 'user-key-duplicate'],
            'userName' => 'USER.ONE',
            'displayName' => 'Duplicate',
        ], 'duplicate-create');
        throw new \RuntimeException('Case-insensitive duplicate userName accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 409, 'Duplicate userName returned wrong status');
    }
    check([State::$rows, State::$audits] === $beforeDuplicate && State::$transaction === null, 'Duplicate userName create retained partial state');
    $otherUser = $service->createUser($provider, 2, [
        'urn:sand:params:scim:schemas:extension:source:1.0' => ['sourceKey' => 'user-key-other'],
        'userName' => 'user.other',
        'displayName' => 'Other',
    ], 'other-create');
    $beforeDuplicatePatch = [State::$rows, State::$audits];
    try {
        $service->patchUser($provider, 2, $otherUser['id'], [
            'Operations' => [['op' => 'replace', 'path' => 'userName', 'value' => 'USER.ONE']],
        ], 'W/"1"', 'duplicate-patch');
        throw new \RuntimeException('Case-insensitive duplicate userName PATCH accepted');
    } catch (ApiException $exception) {
        check($exception->getCode() === 409, 'Duplicate PATCH userName returned wrong status');
    }
    check([State::$rows, State::$audits] === $beforeDuplicatePatch && State::$transaction === null, 'Duplicate userName PATCH retained partial state');
    State::$rows[AuthSession::class] = [20 => ['id' => 20, 'identity_binding_id' => 1, 'status' => 1], 21 => ['id' => 21, 'identity_binding_id' => 99, 'status' => 1]];
    State::$rows[AuthRefreshToken::class] = [30 => ['id' => 30, 'session_id' => 20, 'status' => 1], 31 => ['id' => 31, 'session_id' => 21, 'status' => 1]];
    $legacyDuplicateBaseline = [State::$rows, State::$audits];
    foreach (State::$rows[ScimResource::class] as $resourceId => $resourceRow) {
        if (($resourceRow['scim_id'] ?? null) !== $otherUser['id']) continue;
        State::$rows[ScimResource::class][$resourceId]['source_attributes']['userName'] = 'USER.ONE';
    }
    $disabledLegacyDuplicate = $service->patchUser($provider, 2, $user['id'], [
        'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]],
    ], 'W/"1"', 'legacy-duplicate-disable');
    check($disabledLegacyDuplicate['active'] === false, 'Historical duplicate userName blocked deactivation');
    check(State::$rows[AuthSession::class][20]['status'] === 2 && State::$rows[AuthRefreshToken::class][30]['status'] === 2, 'Historical duplicate userName deactivation did not revoke sessions');
    [State::$rows, State::$audits] = $legacyDuplicateBaseline;
    $baseline = [State::$rows, State::$audits];
    foreach (['replace', 'patch', 'delete'] as $method) {
        [State::$rows, State::$audits] = $baseline;
        $operation = static fn () => match ($method) {
            'replace' => $service->replaceUser($provider, 2, $user['id'], ['userName' => 'user.two', 'displayName' => 'Second', 'active' => false], 'W/"1"', 'user-replace'),
            'patch' => $service->patchUser($provider, 2, $user['id'], ['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]], 'W/"1"', 'user-patch'),
            'delete' => $service->deleteUser($provider, 2, $user['id'], 'W/"1"', 'user-delete'),
        };
        State::$fail = true;
        try { $operation(); throw new \RuntimeException('User mutation audit failure ignored'); }
        catch (\RuntimeException $exception) { check($exception->getMessage() === 'audit failure', $exception->getMessage()); }
        check([State::$rows, State::$audits] === $baseline && State::$transaction === null, 'Failed user mutation retained partial identity/binding/session/version/audit');
        State::$fail = false;
        $result = $operation();
        check(State::$rows[IdentityBinding::class][1]['source_state'] === ($method === 'delete' ? 'deleted' : 'disabled'), 'Binding not disabled');
        check(State::$rows[Identity::class][1]['status'] === 1, 'Shared identity disabled by source removal');
        check(State::$rows[AuthSession::class][20]['status'] === 2 && State::$rows[AuthRefreshToken::class][30]['status'] === 2, 'Source session or refresh survived');
        check(State::$rows[AuthSession::class][21] === $baseline[0][AuthSession::class][21] && State::$rows[AuthRefreshToken::class][31] === $baseline[0][AuthRefreshToken::class][31], 'Other source session changed');
        if ($method !== 'delete') check($result['active'] === false && $result['meta']['version'] === 'W/"2"', 'User version or active state incorrect');
        if ($method === 'replace') check($result['userName'] === 'user.two' && State::$rows[Identity::class][1]['display_name'] === 'Second', 'User replacement attributes lost');
        $before = [State::$rows, State::$audits];
        try { $operation(); throw new \RuntimeException('Stale user mutation accepted'); }
        catch (ApiException $exception) { check($exception->getCode() === ($method === 'delete' ? 404 : 412), 'Wrong stale user error'); }
        check([State::$rows, State::$audits] === $before && State::$transaction === null, 'Stale user mutation changed data');
    }
    echo "SCIM user lifecycle audit rollback and source revocation non-PG behavior PASS\n";
}
