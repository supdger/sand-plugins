<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

use plugin\SandIam\app\model\Service;
use plugin\SandIam\app\model\ServiceAction;
use plugin\SandIam\app\runtime\ServiceCatalog;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;
use Webman\ThinkOrm\ThinkOrm;

function catalogPgFail(string $message): never
{
    fwrite(STDERR, "IAM-06 service catalog PostgreSQL integration failed: {$message}\n");
    exit(1);
}

function catalogPgAssert(bool $condition, string $message): void
{
    if (!$condition) {
        catalogPgFail($message);
    }
}

function catalogPgExpectConflict(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        catalogPgAssert($exception->getCode() === 409 && str_contains($exception->getMessage(), 'SAND_IAM_SERVICE_CATALOG_DISABLED'), $message);
        return;
    }
    catalogPgFail($message);
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$sandIamRoot = dirname(__DIR__, 3);
$repositoryRoot = dirname(__DIR__, 4);
$sandAiConfig = $repositoryRoot . '/sand-ai/config.json';
if (!is_file($hostRoot . '/vendor/autoload.php') || !is_file($sandAiConfig)) {
    catalogPgFail('SandAdmin dependencies or the SandAI package manifest are unavailable');
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $sandIamRoot . '/plugin/sand-iam/app/functions.php';

Config::clear();
support\App::loadAllConfig(['route']);
Config::load($sandIamRoot . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');
ThinkOrm::start(null);

$manifest = json_decode((string) file_get_contents($sandAiConfig), true, flags: JSON_THROW_ON_ERROR);
$declaration = $manifest['sand_platform']['service_catalog'] ?? null;
catalogPgAssert(is_array($declaration), 'SandAI service catalog declaration is missing');
catalogPgAssert(($declaration['dependency'] ?? null) === 'sand-iam', 'SandAI catalog does not declare the SandIAM dependency');
catalogPgAssert(($declaration['class'] ?? null) === ServiceCatalog::class, 'SandAI catalog class does not target SandIAM ServiceCatalog');
catalogPgAssert(($declaration['method'] ?? null) === 'registerServiceActions', 'SandAI catalog method is not registerServiceActions');

$serviceInput = $declaration['service'] ?? null;
$actionInput = $declaration['actions'] ?? null;
catalogPgAssert(is_array($serviceInput) && is_array($actionInput) && $actionInput !== [], 'SandAI service/action declaration is invalid');

$catalog = new ServiceCatalog();
$catalog->registerServiceActions($serviceInput, $actionInput);
$service = Service::where('code', (string) $serviceInput['code'])->find();
catalogPgAssert($service !== null && (int) $service->status === 1, 'SandAI service was not registered');
catalogPgAssert(ServiceAction::where('service_id', (int) $service->id)->count() === count($actionInput), 'SandAI action count does not match its manifest');

$firstActionCode = (string) array_key_first($actionInput);
$firstAction = ServiceAction::where('service_id', (int) $service->id)->where('code', $firstActionCode)->find();
catalogPgAssert($firstAction !== null, 'SandAI first action was not registered');
$originalServiceName = (string) $service->name;
$originalActionName = (string) $firstAction->name;

$catalog->registerServiceActions(
    ['code' => (string) $serviceInput['code'], 'name' => '安装器不得覆盖的服务名称'],
    [$firstActionCode => '安装器不得覆盖的动作名称'] + $actionInput,
);
$service = Service::find((int) $service->id);
$firstAction = ServiceAction::find((int) $firstAction->id);
catalogPgAssert($service !== null && $firstAction !== null, 'repeated registration lost catalog records');
catalogPgAssert((string) $service->name === $originalServiceName && (string) $firstAction->name === $originalActionName, 'repeated registration overwrote administrator-owned names');
catalogPgAssert(Service::where('code', (string) $serviceInput['code'])->count() === 1, 'repeated registration duplicated the service');
catalogPgAssert(ServiceAction::where('service_id', (int) $service->id)->count() === count($actionInput), 'repeated registration duplicated actions');

$firstAction->save(['status' => 2]);
catalogPgExpectConflict(
    static fn () => $catalog->registerServiceActions($serviceInput, $actionInput),
    'a disabled action was restored or did not produce a stable conflict',
);
$firstAction = ServiceAction::find((int) $firstAction->id);
catalogPgAssert($firstAction !== null && (int) $firstAction->status === 2, 'disabled action changed after rejected registration');
$firstAction->save(['status' => 1]);

$service->save(['status' => 2]);
catalogPgExpectConflict(
    static fn () => $catalog->registerServiceActions($serviceInput, $actionInput),
    'a disabled service was restored or did not produce a stable conflict',
);
$service = Service::find((int) $service->id);
catalogPgAssert($service !== null && (int) $service->status === 2, 'disabled service changed after rejected registration');

fwrite(STDOUT, "IAM-06 SandAI service catalog PostgreSQL integration passed\n");
