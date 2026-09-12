<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\AuthRateLimit;
use plugin\SandIam\app\service\AuthRateLimitRetentionService;
use think\facade\Db;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function authRateRetentionPgAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$packageRoot = dirname(__DIR__);
$applicationId = (int) (getenv('SAND_IAM_RETENTION_TEST_APPLICATION_ID') ?: 0);
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1' || $applicationId < 1 || !is_file($hostRoot . '/vendor/autoload.php')) {
    echo "SKIP auth rate-limit retention PostgreSQL integration; set SAND_IAM_RUN_PG_TESTS=1 and SAND_IAM_RETENTION_TEST_APPLICATION_ID to a controlled existing application\n";
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
$hashes = [
    hash('sha256', 'retention-pg:old-a:' . $suffix),
    hash('sha256', 'retention-pg:old-b:' . $suffix),
    hash('sha256', 'retention-pg:recent:' . $suffix),
];
$old = date('Y-m-d H:i:s', time() - 25 * 3600);
$recent = date('Y-m-d H:i:s', time() - 23 * 3600);

try {
    foreach ([[$hashes[0], $old], [$hashes[1], $old], [$hashes[2], $recent]] as [$subjectHash, $windowStart]) {
        AuthRateLimit::create([
            'application_id' => $applicationId,
            'action' => 'retention_fixture',
            'subject_hash' => $subjectHash,
            'window_start' => $windowStart,
            'attempt_count' => 1,
        ]);
    }

    $service = new AuthRateLimitRetentionService();
    $first = $service->prune(24, 1);
    authRateRetentionPgAssert($first['deleted'] === 1, 'first batch did not honor limit one');
    authRateRetentionPgAssert(AuthRateLimit::whereIn('subject_hash', $hashes)->where('window_start', '<=', $first['cutoff'])->count() === 1, 'first batch removed the wrong expired-row count');
    $second = $service->prune(24, 1);
    authRateRetentionPgAssert($second['deleted'] === 1, 'second batch did not remove the remaining expired row');
    authRateRetentionPgAssert(AuthRateLimit::whereIn('subject_hash', $hashes)->count() === 1, 'recent rate-limit window was deleted');
    $third = $service->prune(24, 1);
    authRateRetentionPgAssert($third['deleted'] === 0, 'ineligible rate-limit state was deleted after the expired rows drained');
    echo "auth rate-limit retention PostgreSQL integration passed\n";
} finally {
    Db::table('sand_iam_auth_rate_limit')->where('application_id', $applicationId)->where('action', 'retention_fixture')->whereIn('subject_hash', $hashes)->delete();
}
