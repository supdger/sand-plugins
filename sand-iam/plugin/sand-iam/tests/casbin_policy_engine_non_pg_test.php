<?php

declare(strict_types=1);

namespace plugin\sandadmin\exception { class ApiException extends \RuntimeException {} }
namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    spl_autoload_register(static function (string $class): void { $prefix = 'plugin\\SandIam\\'; if (str_starts_with($class, $prefix)) { $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; if (is_file($file)) require_once $file; } });
    use plugin\SandIam\app\runtime\{PublishedPolicyAdapter, CasbinPolicyEngine};
    function cbcheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo '[PASS] ' . $message . "\n"; }
    $s = ['application_id' => 10, 'resource_id' => 30, 'identity_id' => 20, 'role_id' => 0, 'action' => 'read_record', 'effect' => 'allow', 'condition' => [], 'scope' => ['equals' => ['owner_id' => 20]], 'priority' => 10];
    $facts = ['application_id' => 10, 'identity_id' => 20, 'policies' => [], 'references' => ['identity' => [20,21], 'role' => [20,50], 'resource' => [30]], 'direct_roles' => [], 'group_roles' => []];
    $row = static fn (int $id, array $changes = []): array => ['id' => $id, 'published_version_id' => 100 + $id, 'version_id' => 100 + $id, 'version_policy_id' => $id, 'version_application_id' => 10, 'snapshot' => array_replace($s, $changes)];
    $engine = new CasbinPolicyEngine();
    $run = static function (array $rows, array $attributes = [], array $relations = [], int $app = 10, string $action = 'read_record') use ($facts, $engine): array { $adapter = new PublishedPolicyAdapter(array_replace($facts, ['policies' => $rows], $relations)); return $engine->decide($adapter, $app, 20, 30, $action, $attributes, true); };
    $r = $run([$row(10), $row(20, ['priority' => 20, 'effect' => 'deny'])]);
    cbcheck($r['allowed'] && $r['policy_ids'] === [10] && $r['scope'] === $s['scope'], 'P1 min matched tier, single scope');
    $r = $run([$row(10), $row(20, ['effect' => 'deny'])]);
    cbcheck(!$r['allowed'] && $r['policy_ids'] === [20] && $r['scope'] === [], 'P2 same tier deny overrides earlier allow');
    $r = $run([$row(30, ['effect' => 'deny']), $row(20, ['effect' => 'deny']), $row(10, ['priority' => 20])]);
    cbcheck(!$r['allowed'] && $r['policy_ids'] === [20], 'P3 stable smallest deny id');
    for ($i = 0; $i < 12; ++$i) { $rows = [$row(20, ['scope' => ['equals' => ['department_id' => 7]]]), $row(10)]; shuffle($rows); $r = $run($rows); cbcheck($r['allowed'] && $r['policy_ids'] === [10] && $r['scope'] === $s['scope'], 'P4 shuffled single scope ' . $i); }
    $r = $run([$row(10, ['priority' => 5, 'effect' => 'deny', 'condition' => ['equals' => ['n' => 1]]]), $row(20)]);
    cbcheck($r['allowed'] && $r['policy_ids'] === [20], 'P5 unmatched high precedence deny');
    $r = $run([$row(10, ['scope' => ['invalid' => []]]), $row(20)]);
    cbcheck(!$r['allowed'] && $r['policy_ids'] === [10] && $r['scope'] === [], 'P6 invalid decisive scope cannot fall through');
    cbcheck(!$run([])['allowed'] && $run([])['policy_ids'] === [], 'P7 empty set denies without explanation');
    cbcheck(!$run([$row(10)], [], [], 11)['allowed'] && !$run([$row(10)], [], [], 10, 'read_record_extra')['allowed'], 'P8 exact domain and action');
    foreach ([[['equals' => ['n' => 1]], ['n' => 1], true], [['equals' => ['n' => 1]], ['n' => '1'], false], [['equals' => ['n' => null]], ['n' => null], true], [['equals' => ['n' => null]], [], false], [['in' => ['n' => [1, null]]], ['n' => 1], true], [['in' => ['n' => [1, null]]], ['n' => '1'], false], [['in' => ['n' => [1, null]]], ['n' => null], true], [[], [], true]] as $i => [$condition, $attrs, $expected]) cbcheck($run([$row(10, ['condition' => $condition])], $attrs)['allowed'] === $expected, 'strict condition ' . $i);
    foreach ([['equals' => ['n' => 1.5]], ['eval' => ['n' => 1]], ['in' => ['n' => []]]] as $condition) { try { $run([$row(10, ['condition' => $condition])]); throw new RuntimeException('Invalid condition mapped'); } catch (RuntimeException $e) { cbcheck(str_starts_with($e->getMessage(), 'SAND_IAM_POLICY_MAPPING_INVALID'), 'invalid condition blocks mapping'); } }
    foreach ([['identity_id' => 0, 'role_id' => 0], ['identity_id' => 20, 'role_id' => 50], ['identity_id' => -1], ['identity_id' => '20'], ['application_id' => 11], ['identity_id' => 999]] as $change) { try { $run([$row(10, $change)]); throw new LogicException('Malformed subject accepted'); } catch (RuntimeException) { cbcheck(true, 'malformed/cross-domain subject blocks mapping'); } }
    $r = $run([$row(10, ['identity_id' => 0, 'role_id' => 20])], [], ['group_roles' => [['role_id' => 20, 'group_id' => 20]]]);
    cbcheck($r['allowed'] && $r['subject_source'] === ['identity_group:20'], 'S4 same numeric identity/role/group typed graph');
    cbcheck(!$run([$row(10, ['identity_id' => 0, 'role_id' => 20])])['allowed'], 'role membership never public');
    foreach (['direct_roles' => [['role_id' => 20]], 'group_roles' => [['role_id' => 20, 'group_id' => 20]]] as $relation => $edges) {
        foreach (['allow', 'deny'] as $effect) {
            $diagnostic = $run([$row(10, ['identity_id' => 0, 'role_id' => 20, 'effect' => $effect])], [], [$relation => $edges]);
            cbcheck($diagnostic['allowed'] === ($effect === 'allow') && $diagnostic['matched_rules'][0]['condition_matched'] === true && $diagnostic['policy_ids'] === [10], 'official role links in single-policy diagnostic ' . $relation . ' ' . $effect);
        }
    }
    foreach (['id', 'published_version_id', 'policy_id', 'version_id', 'version_policy_id', 'version_application_id'] as $field) {
        try { $run([$row(10, [$field => 99999])]); throw new LogicException('Reserved snapshot metadata accepted'); }
        catch (RuntimeException $e) { cbcheck(str_starts_with($e->getMessage(), 'SAND_IAM_POLICY_MAPPING_INVALID'), 'reserved snapshot metadata rejected ' . $field); }
    }
    $r = $run([$row(10, ['effect' => 'deny']), $row(20, ['priority' => 20]), $row(30, ['identity_id' => 21, 'priority' => 30]), $row(40, ['priority' => 40, 'condition' => ['equals' => ['secret' => 'sensitive', 'owner_id' => null]]])]);
    cbcheck(!$r['allowed'] && $r['policy_ids'] === [10] && array_column($r['matched_rules'], 'condition_matched') === [true,true,false,false], 'G5 deny matched versus no-match, all rows remain');
    cbcheck($r['matched_rules'][3]['condition']['equals']['secret'] === '[已脱敏]' && $r['missing_context'] === ['secret','owner_id'], 'G5 redaction and missing/null diagnostics');
    $before = $run([$row(10)]); $run([$row(10, ['effect' => 'deny'])]); cbcheck($run([$row(10)]) === $before, 'no model/role/result contamination across requests');
    $adapter = new PublishedPolicyAdapter($facts);
    foreach ([fn () => $adapter->savePolicy(new \Casbin\Model\Model()), fn () => $adapter->addPolicy('p', 'p', []), fn () => $adapter->removePolicy('p', 'p', []), fn () => $adapter->removeFilteredPolicy('p', 'p', 0)] as $write) { try { $write(); throw new RuntimeException('write accepted'); } catch (LogicException $e) { cbcheck($e->getMessage() === 'SAND_IAM_POLICY_ADAPTER_READ_ONLY', 'G6 adapter write rejected'); } }
    echo json_encode(['casbin_version' => Composer\InstalledVersions::getPrettyVersion('casbin/casbin'), 'casbin_reference' => Composer\InstalledVersions::getReference('casbin/casbin'), 'enforcer_path' => (new ReflectionClass(Casbin\Enforcer::class))->getFileName(), 'model_hash' => $before['model_hash']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
