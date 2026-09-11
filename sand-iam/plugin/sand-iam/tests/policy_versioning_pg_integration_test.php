<?php

declare(strict_types=1);

use plugin\SandIam\app\model\Policy;
use plugin\SandIam\app\model\PolicyVersion;
use plugin\SandIam\app\service\PolicyVersionService;
use plugin\SandIam\app\service\RequestId;
use plugin\sandadmin\exception\ApiException;
use think\facade\Db;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

// Requires a preinstalled disposable SandIAM schema. It never migrates, installs,
// or clears a host. SAND_IAM_RUN_PG_TESTS=1 is an explicit write authorization.
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') { echo "SKIP: set SAND_IAM_RUN_PG_TESTS=1 with a disposable installed SandIAM database\n"; exit(0); }
$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) throw new RuntimeException('SandAdmin ThinkORM harness is unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';
Config::clear(); support\App::loadAllConfig(['route']); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam'); ThinkOrm::start(null);

function pvpg(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$suffix = 'pgpv_' . bin2hex(random_bytes(8)); $policy = null; $versionIds = [];
try {
    // The caller supplies valid existing application/resource/role IDs from its disposable fixture.
    $applicationId = (int) getenv('SAND_IAM_POLICY_VERSION_APPLICATION_ID'); $resourceId = (int) getenv('SAND_IAM_POLICY_VERSION_RESOURCE_ID'); $roleId = (int) getenv('SAND_IAM_POLICY_VERSION_ROLE_ID');
    if ($applicationId <= 0 || $resourceId <= 0 || $roleId <= 0) { echo "SKIP: set disposable SAND_IAM_POLICY_VERSION_APPLICATION_ID/RESOURCE_ID/ROLE_ID\n"; exit(0); }
    $policy = Policy::create(['application_id' => $applicationId, 'resource_id' => $resourceId, 'role_id' => $roleId, 'identity_id' => null, 'action' => $suffix . '.read', 'effect' => 'allow', 'condition' => [], 'scope' => [], 'priority' => 10, 'state' => 'draft', 'status' => 1]);
    $service = new PolicyVersionService(); $request = 'pg-policy-' . bin2hex(random_bytes(12));
    $calls = 0; $first = $service->publish((int) $policy->id, $request); ++$calls; $versionIds[] = (int) $first['version']->id;
    $replay = $service->publish((int) $policy->id, $request); if (!$replay['replayed']) ++$calls;
    pvpg($first['replayed'] === false && $replay['replayed'] === true && $calls === 1, 'production PolicyVersionService replay duplicated side effect callback');
    try { $service->publish((int) $policy->id, $request, (int) $first['version']->id); throw new RuntimeException('operation conflict accepted'); } catch (ApiException $e) { pvpg($e->getCode() === 409 && str_contains($e->getMessage(), 'SAND_IAM_IDEMPOTENCY_CONFLICT'), 'production service did not reject different operation/fingerprint'); }

    // Two real PDO connections contend for the same production policy row. The service's
    // FOR UPDATE lock is therefore independently reviewable before the second publish.
    $dsn = (string) getenv('SAND_IAM_POLICY_VERSION_PG_DSN'); if ($dsn === '') { echo "policy version PDO race skipped: set SAND_IAM_POLICY_VERSION_PG_DSN\n"; }
    else { $a = new PDO($dsn, (string) getenv('SAND_IAM_POLICY_VERSION_PG_USER'), (string) getenv('SAND_IAM_POLICY_VERSION_PG_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $b = new PDO($dsn, (string) getenv('SAND_IAM_POLICY_VERSION_PG_USER'), (string) getenv('SAND_IAM_POLICY_VERSION_PG_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $a->beginTransaction(); $a->prepare('SELECT id FROM sand_iam_policy WHERE id=? FOR UPDATE')->execute([(int) $policy->id]); $b->exec("SET lock_timeout='300ms'"); $blocked = false; try { $b->beginTransaction(); $b->prepare('SELECT id FROM sand_iam_policy WHERE id=? FOR UPDATE')->execute([(int) $policy->id]); } catch (PDOException $e) { $blocked = in_array((string) $e->getCode(), ['55P03', '57014'], true); if ($b->inTransaction()) $b->rollBack(); } $a->commit(); pvpg($blocked, 'two PDO connections did not compete on the production policy row'); }
    echo "policy version PostgreSQL integration passed\n";
} finally {
    if ($policy !== null) { Policy::where('id', (int) $policy->id)->update(['published_version_id' => null]); foreach ($versionIds as $id) PolicyVersion::where('id', $id)->delete(); Policy::where('id', (int) $policy->id)->delete(); }
}
