<?php

declare(strict_types=1);

// Existing, installed sandadmin database only. No schema/install/migration.
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') { echo "SKIP: existing authorized SandIAM PostgreSQL fixture required\n"; exit(0); }
$root = dirname(__DIR__, 3); $host = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_demo/server';
chdir($host); require $host . '/vendor/autoload.php'; Dotenv\Dotenv::createUnsafeImmutable($host)->safeLoad();
require $root . '/plugin/sand-iam/app/functions.php';
Webman\Config::clear(); support\App::loadAllConfig(['route']); Webman\Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam'); Webman\ThinkOrm\ThinkOrm::start(null);
use plugin\SandIam\app\runtime\{PolicyAuthorizer, PostgresPolicySnapshotLoader, PublishedPolicyAdapter, CasbinPolicyEngine, ApplicationAuthorizationService};
use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\service\PolicyVersionService;
$orm = think\facade\Db::connect(); $pdo = $orm->getPdo() ?: $orm->connect();
if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'sandadmin') throw new RuntimeException('Test target must be authorized existing sandadmin');
$config = $orm->getConfig();
$second = new PDO('pgsql:host=' . ($config['hostname'] ?? $config['host'] ?? '127.0.0.1') . ';port=' . ($config['hostport'] ?? $config['port'] ?? 5432) . ';dbname=' . $config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = 'cbpg_' . bin2hex(random_bytes(8)); $owned = []; $checks = 0;
function cbpg(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); ++$checks; echo '[PASS] ' . $message . "\n"; }
$insert = static function (string $table, array $values) use ($pdo, &$owned): int { $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ') RETURNING id'; $q = $pdo->prepare($sql); $q->execute(array_values($values)); $id = (int) $q->fetchColumn(); $owned[$table][] = $id; return $id; };
$exec = static function (PDO $connection, string $sql, array $parameters = []): void { $q = $connection->prepare($sql); $q->execute($parameters); };
final class CasbinBarrierStatement extends PDOStatement
{
    protected function __construct(private readonly Closure $barrier) {}
    public function execute(?array $params = null): bool { $result = parent::execute($params); ($this->barrier)($this->queryString); return $result; }
}
try {
    echo 'Target: existing sandadmin PostgreSQL ' . $pdo->query('SHOW server_version')->fetchColumn() . '; random-prefix fixture; no DDL' . "\n";
    $org = $insert('sand_iam_organization', ['code' => $prefix, 'name' => $prefix]);
    $app = $insert('sand_iam_application', ['organization_id' => $org, 'code' => $prefix, 'name' => $prefix]);
    $identity = $insert('sand_iam_identity', ['application_id' => $app, 'code' => $prefix, 'display_name' => $prefix]);
    $resource = $insert('sand_iam_resource', ['application_id' => $app, 'code' => $prefix, 'name' => $prefix]);
    $role = $insert('sand_iam_role', ['application_id' => $app, 'code' => $prefix, 'name' => $prefix]);
    $binding = $insert('sand_iam_identity_role', ['application_id' => $app, 'identity_id' => $identity, 'role_id' => $role]);
    $policy = $insert('sand_iam_policy', ['application_id' => $app, 'resource_id' => $resource, 'role_id' => $role, 'action' => $prefix . '.read', 'effect' => 'allow', 'condition' => '{}', 'scope' => json_encode(['equals' => ['owner_id' => $identity]]), 'priority' => 10]);
    $publisher = new PolicyVersionService(); $published = $publisher->publish($policy, $prefix . '-publish'); $vid = (int) $published['version']->id; $owned['sand_iam_policy_version'][] = $vid;
    $authorizer = new PolicyAuthorizer();
    $simulate = static fn (): array => $authorizer->simulate($app, $identity, $prefix, $prefix . '.read', 'read', [], $prefix . '-simulate');
    $result = $simulate(); cbpg($result['allowed'] && $result['policy_ids'] === [$policy] && $result['published_version_ids'] === [$vid], 'real published version and role via Casbin simulate');
    cbpg($result['subject_source'] === ['direct_identity_role'] && $result['engine'] === 'casbin', 'real role provenance and engine');
    cbpg(!$pdo->inTransaction(), 'short snapshot is closed before Enforcer/audit');
    $exec($pdo, "UPDATE sand_iam_policy SET state='draft', action='draft_changed', priority=-10, effect='deny', scope='{}'::jsonb WHERE id=?", [$policy]);
    cbpg($simulate()['allowed'] && $simulate()['scope'] === $result['scope'], 'draft fields and state=draft retain published pointer');
    try { $publisher->publish($policy, $prefix . '-publish'); throw new RuntimeException('changed draft request unexpectedly replayed'); }
    catch (\plugin\sandadmin\exception\ApiException $e) { cbpg(str_contains($e->getMessage(), 'SAND_IAM_IDEMPOTENCY_CONFLICT'), 'publish idempotency rejects changed draft'); }
    $rolled = $publisher->publish($policy, $prefix . '-rollback', $vid); $owned['sand_iam_policy_version'][] = (int) $rolled['version']->id;
    cbpg((int) $rolled['version']->id !== $vid && (int) $rolled['version']->rollback_of_version_id === $vid, 'rollback creates new immutable version with same compiler');
    cbpg($simulate()['allowed'] && $simulate()['scope'] === $result['scope'], 'rollback restores published scope without mutating history');
    $exec($pdo, 'UPDATE sand_iam_policy SET status=2 WHERE id=?', [$policy]); cbpg(!$simulate()['allowed'], 'committed revoke denies next snapshot');
    $exec($pdo, 'UPDATE sand_iam_policy SET status=1 WHERE id=?', [$policy]);
    $events = []; $fired = false;
    $barrier = function (string $sql) use (&$fired, &$events, $pdo, $second, $exec, $policy, $binding): void {
        if ($fired || !str_starts_with($sql, 'SELECT id, organization_id FROM sand_iam_application')) return;
        $fired = true;
        $events[] = ['event' => 'old_snapshot_acquired', 'pid' => $pdo->query('SELECT pg_backend_pid()')->fetchColumn(), 'isolation' => $pdo->query('SHOW transaction_isolation')->fetchColumn(), 'read_only' => $pdo->query('SHOW transaction_read_only')->fetchColumn()];
        $second->beginTransaction(); $exec($second, 'UPDATE sand_iam_policy SET status=2 WHERE id=?', [$policy]); $exec($second, 'UPDATE sand_iam_identity_role SET status=2 WHERE id=?', [$binding]); $second->commit();
        $events[] = ['event' => 'revoke_and_role_commit', 'pid' => $second->query('SELECT pg_backend_pid()')->fetchColumn()];
    };
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CasbinBarrierStatement::class, [$barrier]]);
    $facts = (new PostgresPolicySnapshotLoader($pdo))->load($app, $identity, $prefix, $prefix . '.read');
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    $inflight = (new CasbinPolicyEngine())->decide(new PublishedPolicyAdapter($facts), $app, $identity, $resource, $prefix . '.read', []);
    cbpg($inflight['allowed'] && count($facts['direct_roles']) === 1, 'barrier old policy and role facts remain consistent after concurrent commit');
    cbpg($events[0]['isolation'] === 'repeatable read' && $events[0]['read_only'] === 'on', 'actual PostgreSQL REPEATABLE READ READ ONLY');
    cbpg(!$simulate()['allowed'], 'snapshot after revoke commit denies'); echo json_encode(['snapshot_events' => $events], JSON_UNESCAPED_SLASHES) . "\n";
    $exec($pdo, 'UPDATE sand_iam_policy SET status=1 WHERE id=?', [$policy]); $exec($pdo, 'UPDATE sand_iam_identity_role SET status=1 WHERE id=?', [$binding]);
    $pdo->beginTransaction(); $outerDenied = $simulate(); cbpg(!$outerDenied['allowed'] && $pdo->inTransaction(), 'outer old transaction rejects without modifying caller transaction'); $pdo->rollBack();
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CasbinBarrierStatement::class, [static function (string $sql): void { if (str_contains($sql, 'FROM sand_iam_policy p')) throw new RuntimeException('fixture load failure'); }]]);
    cbpg(!$simulate()['allowed'] && !$pdo->inTransaction(), 'loader failure denies and rolls back short snapshot'); $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]); cbpg($simulate()['allowed'], 'following request recovers without old facts');
    $decision = $authorizer->authorize($app, $identity, $prefix, $prefix . '.read', 'read', [], $prefix . '-authorize'); cbpg($decision['allowed'], 'actual PolicyAuthorizer authorize entrypoint');
    $guard = (new ApplicationAuthorizationService())->entityScopeGuard($decision + ['application_id' => $app, 'identity_id' => $identity, 'resource_code' => $prefix, 'operation' => 'read', 'request_id' => $prefix . '-entity'], 'entity', static fn (object $entity): array => ['owner_id' => $entity->owner_id]);
    $guard->assertEntity((object) ['owner_id' => $identity]); cbpg(true, 'trusted within-scope entity passes');
    $collectionRequest = $prefix . '-collection';
    $collectionDecision = $decision + ['application_id' => $app, 'identity_id' => $identity, 'resource_code' => $prefix, 'operation' => 'read', 'request_id' => $collectionRequest];
    $collectionGuard = (new ApplicationAuthorizationService())->entityScopeGuard($collectionDecision, 'collection', static fn (object $entity): array => ['id' => $entity->id, 'owner_id' => $entity->owner_id]);
    $entities = [(object) ['id' => 1, 'owner_id' => $identity], (object) ['id' => 2, 'owner_id' => $identity]];
    $collectionGuard->assertCollection($entities); $collectionGuard->assertCompleted();
    $collectionGuard->assertCollection($entities); $collectionGuard->assertCompleted();
    $scopeEvents = static function () use ($pdo, $collectionRequest): array {
        $statement = $pdo->prepare('SELECT request_id, action, outcome, event_key, context FROM sand_iam_audit_log WHERE request_id=? AND action=? ORDER BY id');
        $statement->execute([$collectionRequest, 'scope.read']);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    };
    cbpg(count($scopeEvents()) === 2, 'real PostgreSQL two-entity collection and identical replay append only two scope events');
    foreach ([1, 2] as $retry) {
        try { $collectionGuard->assertCollection([$entities[0], (object) ['id' => 3, 'owner_id' => $identity + 1]]); throw new RuntimeException('Partially unauthorized collection accepted'); }
        catch (\plugin\sandadmin\exception\ApiException $e) { cbpg($e->getCode() === 403 && str_starts_with($e->getMessage(), 'SAND_IAM_RESOURCE_SCOPE_DENIED'), 'real PostgreSQL mixed collection denial remains 403 on attempt ' . $retry); }
        try { $collectionGuard->assertCompleted(); throw new RuntimeException('Previous success hid collection denial'); }
        catch (\plugin\sandadmin\exception\ApiException $e) { cbpg($e->getCode() === 500, 'denied collection clears previous completion'); }
    }
    $collectionGuard->assertCollection($entities);
    $scopeRows = $scopeEvents();
    cbpg(count($scopeRows) === 3 && array_column($scopeRows, 'outcome') === ['allowed', 'allowed', 'denied']
        && count(array_unique(array_column($scopeRows, 'event_key'))) === 3
        && array_unique(array_column($scopeRows, 'request_id')) === [$collectionRequest], 'real PostgreSQL scope denial survives subsequent allow under original request/action');
    foreach (['owner', 'application', 'resource'] as $case) {
        $guard = (new ApplicationAuthorizationService())->entityScopeGuard($decision + ['application_id' => $app, 'identity_id' => $identity, 'resource_code' => $prefix, 'operation' => 'read', 'request_id' => $prefix . '-entity-' . $case], 'entity', static fn (object $entity): array => ['owner_id' => $entity->owner_id]);
        if ($case === 'application') $exec($second, 'UPDATE sand_iam_application SET status=2 WHERE id=?', [$app]);
        if ($case === 'resource') $exec($second, 'UPDATE sand_iam_resource SET status=2 WHERE id=?', [$resource]);
        try { $guard->assertEntity((object) ['owner_id' => $case === 'owner' ? $identity + 1 : $identity]); throw new RuntimeException('Scope/state accepted: ' . $case); }
        catch (\plugin\sandadmin\exception\ApiException $e) { cbpg(str_starts_with($e->getMessage(), 'SAND_IAM_RESOURCE_SCOPE_DENIED'), 'execution scope live guard rejects ' . $case); }
        if ($case === 'application') $exec($second, 'UPDATE sand_iam_application SET status=1 WHERE id=?', [$app]);
        if ($case === 'resource') $exec($second, 'UPDATE sand_iam_resource SET status=1 WHERE id=?', [$resource]);
    }
    $group = $insert('sand_iam_identity_group', ['application_id' => $app, 'code' => $prefix, 'name' => $prefix]);
    $member = $insert('sand_iam_identity_group_member', ['application_id' => $app, 'identity_group_id' => $group, 'identity_id' => $identity]);
    $groupRole = $insert('sand_iam_identity_group_role', ['application_id' => $app, 'identity_group_id' => $group, 'role_id' => $role]);
    $exec($pdo, 'UPDATE sand_iam_identity_role SET status=2 WHERE id=?', [$binding]);
    cbpg($simulate()['allowed'] && $simulate()['subject_source'] === ['identity_group:' . $group], 'real group-to-role graph authorizes');
    foreach ([['sand_iam_identity_group_member', $member], ['sand_iam_identity_group', $group], ['sand_iam_identity_group_role', $groupRole], ['sand_iam_role', $role]] as [$table, $id]) {
        $exec($second, 'UPDATE ' . $table . ' SET status=2 WHERE id=?', [$id]); cbpg(!$simulate()['allowed'], 'committed group edge/entity disable denies ' . $table);
        $exec($second, 'UPDATE ' . $table . ' SET status=1 WHERE id=?', [$id]);
    }
    $exec($pdo, 'UPDATE sand_iam_identity_role SET status=1 WHERE id=?', [$binding]);
    cbpg($simulate()['subject_source'] === ['direct_identity_role', 'identity_group:' . $group], 'real multiple role provenance preserved');
    $missingApp = (int) $pdo->query('SELECT max(id) + 1000000 FROM sand_iam_application')->fetchColumn();
    $missingRequest = $prefix . '-missing-app';
    $missing = $authorizer->authorize($missingApp, $identity, $prefix, $prefix . '.read', 'read', [], $missingRequest);
    cbpg(!$missing['allowed'] && $missing['code'] === 'SAND_IAM_POLICY_DENIED', 'nonexistent application returns ordinary deny without audit FK failure');
    $q = $pdo->prepare('SELECT id, application_id FROM sand_iam_audit_log WHERE request_id=?'); $q->execute([$missingRequest]); $missingAudit = $q->fetch(PDO::FETCH_ASSOC);
    cbpg($missingAudit !== false && $missingAudit['application_id'] === null, 'nonexistent application audit has null authority foreign key');
    $owned['sand_iam_audit_log'][] = (int) $missingAudit['id'];
    $start = hrtime(true); for ($i = 0; $i < 20; ++$i) cbpg($simulate()['allowed'], 'request-local role/model rebuild ' . $i); echo 'Twenty simulation requests elapsed_ms=' . round((hrtime(true) - $start) / 1e6, 2) . "; single-process sample, not P95 or multi-Worker evidence\n";
    echo 'Casbin PG checks=' . $checks . " PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    // Exact owned IDs and application-scoped projections only. No global clear.
    if (isset($app)) { $exec($pdo, 'DELETE FROM sand_iam_security_alert WHERE application_id=?', [$app]); $exec($pdo, 'DELETE FROM sand_iam_audit_log WHERE application_id=?', [$app]); }
    if (isset($policy)) $exec($pdo, 'UPDATE sand_iam_policy SET published_version_id=NULL WHERE id=?', [$policy]);
    foreach (array_reverse($owned, true) as $table => $ids) foreach (array_reverse($ids) as $id) $exec($pdo, 'DELETE FROM ' . $table . ' WHERE id=?', [$id]);
    $residual = 0; foreach ($owned as $table => $ids) foreach ($ids as $id) { $q = $pdo->prepare('SELECT count(*) FROM ' . $table . ' WHERE id=?'); $q->execute([$id]); $residual += (int) $q->fetchColumn(); }
    echo 'Owned fixture residual=' . $residual . "\n"; if ($residual !== 0) throw new RuntimeException('Fixture cleanup incomplete');
}
