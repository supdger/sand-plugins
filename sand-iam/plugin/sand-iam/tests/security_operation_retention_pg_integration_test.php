<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\SecurityOperation;
use plugin\SandIam\app\service\SecurityOperationRetentionService;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function retentionPgAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$packageRoot = dirname(__DIR__);
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1' || !is_file($hostRoot . '/vendor/autoload.php')) {
    echo "SKIP security operation retention PostgreSQL integration; set SAND_IAM_RUN_PG_TESTS=1 and provide SandAdmin host dependencies\n";
    exit(0);
}
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $packageRoot . '/app/functions.php';
Config::clear();
support\App::loadAllConfig(['route']);
Config::load($packageRoot . '/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$suffix = substr(bin2hex(random_bytes(8)), 0, 12);
$actorRef = 'retention-pg:' . $suffix;
$old = date('Y-m-d H:i:s', time() - 31 * 86400);
$recent = date('Y-m-d H:i:s', time() - 29 * 86400);
$base = [
    'actor_type' => 'system',
    'actor_ref' => $actorRef,
    'operation' => 'retention.fixture',
    'request_fingerprint' => hash('sha256', $suffix),
    'resource_type' => 'retention_fixture',
    'result' => ['fixture' => true],
];

try {
    foreach ([
        ['request_id' => 'old-a-' . $suffix, 'state' => 'succeeded', 'create_time' => $old, 'update_time' => $old],
        ['request_id' => 'old-b-' . $suffix, 'state' => 'succeeded', 'create_time' => $old, 'update_time' => $old],
        ['request_id' => 'recent-' . $suffix, 'state' => 'succeeded', 'create_time' => $recent, 'update_time' => $recent],
        ['request_id' => 'pending-' . $suffix, 'state' => 'pending', 'create_time' => $old, 'update_time' => $old],
    ] as $row) {
        SecurityOperation::create($base + $row);
    }

    $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('now');
    $service = new SecurityOperationRetentionService(clock: $clock);
    $first = $service->prune(30, 1);
    retentionPgAssert($first['deleted'] === 1, 'first batch did not honor limit one');
    retentionPgAssert(SecurityOperation::where('actor_ref', $actorRef)->where('state', 'succeeded')->where('update_time', '<=', $first['cutoff'])->count() === 1, 'first batch removed the wrong old-operation count');
    $second = $service->prune(30, 1);
    retentionPgAssert($second['deleted'] === 1, 'second batch did not remove the remaining eligible operation');
    retentionPgAssert(SecurityOperation::where('actor_ref', $actorRef)->where('state', 'succeeded')->count() === 1, 'recent succeeded operation was deleted');
    retentionPgAssert(SecurityOperation::where('actor_ref', $actorRef)->where('state', 'pending')->count() === 1, 'old pending operation was deleted');
    $third = $service->prune(30, 1);
    retentionPgAssert($third['deleted'] === 0, 'ineligible records were deleted after eligible rows drained');
    echo "security operation retention PostgreSQL integration passed\n";
} finally {
    SecurityOperation::where('actor_type', 'system')->where('actor_ref', $actorRef)->where('operation', 'retention.fixture')->delete();
}
