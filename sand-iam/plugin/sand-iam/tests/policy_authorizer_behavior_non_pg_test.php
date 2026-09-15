<?php
declare(strict_types=1);

namespace PolicyAuthorizerTest {
    final class Query {
        private array $filters = [];
        private ?string $order = null;
        public function __construct(private string $model) {}
        public function where(string $key, mixed $operator, mixed $value = null): self {
            $this->filters[] = func_num_args() === 2 ? [$key, '=', $operator] : [$key, $operator, $value];
            return $this;
        }
        public function order(string $key, string $direction): self {
            if ($direction !== 'asc') throw new \RuntimeException('Unsupported ordering');
            $this->order = $key;
            return $this;
        }
        public function select(): array {
            $rows = array_filter($this->model::$rows, function (array $row): bool {
                foreach ($this->filters as [$key, $operator, $value]) {
                    if (!match ($operator) { '=' => ($row[$key] ?? null) === $value, '>' => ($row[$key] ?? 0) > $value, default => throw new \RuntimeException('Unsupported predicate') }) return false;
                }
                return true;
            });
            if ($this->order !== null) usort($rows, fn (array $a, array $b): int => $a[$this->order] <=> $b[$this->order]);
            return array_map(fn (array $row): object => new ($this->model)($row), array_values($rows));
        }
        public function find(): ?object { return $this->select()[0] ?? null; }
    }
    // Evaluate the actual query's inner joins and predicates over in-memory rows.
    // This does not emulate PostgreSQL locking, planning or transaction isolation.
    final class Relations {
        public static array $tables = [];
        private array $rows;
        private string $fields = '';
        public function __construct(string $table, string $alias) {
            $this->rows = array_map(fn (array $row): array => $this->qualify($row, $alias), self::$tables[$table] ?? []);
        }
        private function qualify(array $row, string $alias): array {
            $result = [];
            foreach ($row as $key => $value) $result[$alias . '.' . $key] = $value;
            return $result;
        }
        public function join(string $table, string $condition): self {
            check(preg_match('/^(\w+) (\w+)$/', $table, $tableParts) === 1, 'Unsupported fixture join table');
            check(preg_match('/^(\w+\.\w+) = (\w+\.\w+)$/', $condition, $keys) === 1, 'Unsupported fixture join condition');
            $joined = [];
            foreach ($this->rows as $left) {
                foreach (self::$tables[$tableParts[1]] ?? [] as $right) {
                    $row = $left + $this->qualify($right, $tableParts[2]);
                    if (isset($row[$keys[1]], $row[$keys[2]]) && $row[$keys[1]] === $row[$keys[2]]) $joined[] = $row;
                }
            }
            $this->rows = $joined;
            return $this;
        }
        public function where(string $key, mixed $value): self {
            $this->rows = array_values(array_filter($this->rows, static fn (array $row): bool => isset($row[$key]) && $row[$key] === $value));
            return $this;
        }
        public function field(string $fields): self { $this->fields = $fields; return $this; }
        public function column(string $field): array { return array_column($this->rows, $field); }
        public function select(): array {
            $result = [];
            foreach ($this->rows as $row) {
                $projected = [];
                foreach (explode(',', $this->fields) as $field) {
                    check(preg_match('/^(\w+\.\w+) AS (\w+)$/', $field, $parts) === 1, 'Unsupported fixture projection');
                    $projected[$parts[2]] = $row[$parts[1]] ?? null;
                }
                $result[] = (object) $projected;
            }
            return $result;
        }
    }
    abstract class Model {
        public function __construct(private array $data) {}
        public function __get(string $key): mixed { return $this->data[$key] ?? null; }
        public static function where(string $key, mixed $operator, mixed $value = null): Query {
            $query = new Query(static::class);
            return func_num_args() === 2 ? $query->where($key, $operator) : $query->where($key, $operator, $value);
        }
    }
    function check(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
}
namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace plugin\SandIam\app\model {
    class Application extends \PolicyAuthorizerTest\Model { public static array $rows = []; }
    class Identity extends \PolicyAuthorizerTest\Model { public static array $rows = []; }
    class Resource extends \PolicyAuthorizerTest\Model { public static array $rows = []; }
    class Policy extends \PolicyAuthorizerTest\Model { public static array $rows = []; }
    class PolicyVersion extends \PolicyAuthorizerTest\Model { public static array $rows = []; }
    class IdentityRole { public static function alias(string $alias): \PolicyAuthorizerTest\Relations { return new \PolicyAuthorizerTest\Relations('sand_iam_identity_role', $alias); } }
    class IdentityGroupMember { public static function alias(string $alias): \PolicyAuthorizerTest\Relations { return new \PolicyAuthorizerTest\Relations('sand_iam_identity_group_member', $alias); } }
}
namespace plugin\SandIam\app\service {
    class AuditWriter {
        public static array $rows = [];
        public function write(string $actor, string $ref, ?int $organization, ?int $application, string $action, string $resource, ?int $id, string $outcome, string $request, array $context = []): void {
            self::$rows[] = compact('application', 'action', 'outcome', 'context');
        }
    }
}
namespace {
    use plugin\SandIam\app\model\{Application, Identity, Resource, Policy, PolicyVersion};
    use plugin\SandIam\app\service\AuditWriter;
    use plugin\SandIam\app\runtime\PolicyAuthorizer;
    use PolicyAuthorizerTest\Relations;
    use function PolicyAuthorizerTest\check;
    require dirname(__DIR__) . '/app/service/RequestId.php';
    require dirname(__DIR__) . '/app/runtime/ScopeMatcher.php';
    require dirname(__DIR__) . '/app/runtime/PolicyAuthorizer.php';

    Application::$rows = [['id' => 10, 'organization_id' => 1, 'status' => 1]];
    Identity::$rows = [['id' => 20, 'application_id' => 10, 'status' => 1]];
    Resource::$rows = [['id' => 30, 'application_id' => 10, 'code' => 'invoice', 'status' => 1]];
    $snapshot = ['application_id' => 10, 'resource_id' => 30, 'identity_id' => 20, 'role_id' => 0, 'action' => 'invoice.read', 'effect' => 'allow', 'condition' => ['equals' => ['region' => 'cn']], 'scope' => ['equals' => ['owner_id' => 20]], 'priority' => 10];
    $policy = ['id' => 1, 'application_id' => 10, 'status' => 1, 'published_version_id' => 101, 'state' => 'published'];
    Policy::$rows = [$policy];
    PolicyVersion::$rows = [['id' => 101, 'policy_id' => 1, 'snapshot' => $snapshot]];
    $authorizer = new PolicyAuthorizer();
    $decide = static fn (array $attributes = ['region' => 'cn']): array => $authorizer->authorize(10, 20, 'invoice', 'invoice.read', 'read', $attributes, 'authorizer-behavior');
    $result = $decide();
    check($result['allowed'] && $result['scope'] === $snapshot['scope'], 'Published direct identity allow failed');
    check(end(AuditWriter::$rows)['context']['published_version_ids'] === [101], 'Allow audit lacks version');
    Policy::$rows[0] += ['effect' => 'deny', 'priority' => -1, 'scope' => [], 'resource_id' => 999];
    check($decide() === $result, 'Draft fields changed runtime snapshot');
    check(!$decide([])['allowed'] && !$decide(['region' => 'us'])['allowed'], 'Missing or mismatched condition allowed');
    Policy::$rows[0]['status'] = 2;
    check(!$decide()['allowed'], 'Disabled policy still allowed on same authorizer');
    Policy::$rows[0]['state'] = 'revoked';
    check(!$decide()['allowed'], 'Revoked policy still allowed');
    Policy::$rows[0] = $policy;
    check($decide()['allowed'], 'Restored policy remained stale');

    Policy::$rows[] = array_replace($policy, ['id' => 2, 'published_version_id' => 102]);
    PolicyVersion::$rows[] = ['id' => 102, 'policy_id' => 2, 'snapshot' => array_replace($snapshot, ['effect' => 'deny'])];
    check(!$decide()['allowed'], 'Equal priority deny did not win');
    PolicyVersion::$rows[1]['snapshot']['priority'] = 20;
    check($decide()['allowed'], 'Lower precedence deny overrode priority 10');
    PolicyVersion::$rows[1]['snapshot']['priority'] = 5;
    check(!$decide()['allowed'] && end(AuditWriter::$rows)['context']['policy_ids'] === [2], 'Priority 5 deny was not selected');
    Policy::$rows = [$policy];
    PolicyVersion::$rows = [PolicyVersion::$rows[0]];
    foreach (['application_id' => 11, 'resource_id' => 31, 'identity_id' => 21, 'action' => 'invoice.write'] as $key => $value) {
        PolicyVersion::$rows[0]['snapshot'] = array_replace($snapshot, [$key => $value]);
        check(!$decide()['allowed'], 'Wrong snapshot target accepted: ' . $key);
    }
    PolicyVersion::$rows[0]['snapshot'] = $snapshot;
    PolicyVersion::$rows[0]['policy_id'] = 999;
    check(!$decide()['allowed'], 'Foreign version pointer accepted');
    PolicyVersion::$rows[0]['policy_id'] = 1;
    foreach ([Application::class, Identity::class, Resource::class] as $model) {
        $model::$rows[0]['status'] = 2;
        check(!$decide()['allowed'], 'Disabled dependency accepted: ' . $model);
        $model::$rows[0]['status'] = 1;
    }
    $authorizer->assertScope(10, 20, 'invoice', 'read', $snapshot['scope'], ['owner_id' => 20], 'scope-behavior');
    try {
        $authorizer->assertScope(10, 20, 'invoice', 'read', $snapshot['scope'], ['owner_id' => 21], 'scope-behavior');
        throw new \RuntimeException('Foreign owner passed data scope');
    } catch (\plugin\sandadmin\exception\ApiException $error) { check($error->getCode() === 403, 'Wrong scope error'); }
    check(end(AuditWriter::$rows)['outcome'] === 'denied', 'Scope denial not audited');
    echo "policy authorizer direct identity non-PG behavior PASS\n";

    PolicyVersion::$rows[0]['snapshot'] = array_replace($snapshot, ['identity_id' => 0, 'role_id' => 50]);
    Relations::$tables = [
        'sand_iam_identity_role' => [],
        'sand_iam_role' => [['id' => 50, 'application_id' => 10, 'status' => 1]],
        'sand_iam_identity_group' => [['id' => 60, 'application_id' => 10, 'status' => 1]],
        'sand_iam_identity_group_role' => [['id' => 70, 'identity_group_id' => 60, 'role_id' => 50, 'application_id' => 10, 'status' => 1]],
        'sand_iam_identity_group_member' => [['id' => 80, 'identity_group_id' => 60, 'identity_id' => 20, 'application_id' => 10, 'status' => 1]],
    ];
    check($decide()['allowed'] && end(AuditWriter::$rows)['context']['subject_source'] === ['identity_group:60'], 'Group role did not authorize or lost audit source');
    $relations = Relations::$tables;
    foreach ([
        ['sand_iam_identity_group_member', 'status', 2],
        ['sand_iam_identity_group_member', 'identity_id', 21],
        ['sand_iam_identity_group_member', 'identity_group_id', 61],
        ['sand_iam_identity_group_member', 'application_id', 11],
        ['sand_iam_identity_group', 'status', 2],
        ['sand_iam_identity_group', 'application_id', 11],
        ['sand_iam_identity_group_role', 'status', 2],
        ['sand_iam_identity_group_role', 'application_id', 11],
        ['sand_iam_identity_group_role', 'identity_group_id', 61],
        ['sand_iam_identity_group_role', 'role_id', 51],
        ['sand_iam_role', 'status', 2],
        ['sand_iam_role', 'application_id', 11],
    ] as [$table, $field, $value]) {
        Relations::$tables[$table][0][$field] = $value;
        check(!$decide()['allowed'], 'Changed relationship still authorized on same instance: ' . $table . '.' . $field);
        check(end(AuditWriter::$rows)['context']['effective_role_sources'] === [], 'Denied audit retained revoked role source');
        Relations::$tables = $relations;
        check($decide()['allowed'], 'Restored group relationship remained stale');
    }
    Relations::$tables['sand_iam_identity_role'] = [['identity_id' => 20, 'role_id' => 50, 'status' => 1]];
    check($decide()['allowed'] && end(AuditWriter::$rows)['context']['subject_source'] === ['direct_identity_role', 'identity_group:60'], 'Multiple role sources not retained');
    Relations::$tables['sand_iam_identity_group_member'][0]['status'] = 2;
    check($decide()['allowed'] && end(AuditWriter::$rows)['context']['subject_source'] === ['direct_identity_role'], 'Removing group membership incorrectly revoked independent direct grant');
    Relations::$tables['sand_iam_identity_role'][0]['status'] = 2;
    check(!$decide()['allowed'], 'Removing all role sources still authorized');
    Relations::$tables['sand_iam_identity_group_member'][0]['status'] = 1;
    check($decide()['allowed'] && end(AuditWriter::$rows)['context']['subject_source'] === ['identity_group:60'], 'Group restore did not regain authorization');
    echo "policy authorizer group revocation and multiple role sources non-PG behavior PASS\n";
}
