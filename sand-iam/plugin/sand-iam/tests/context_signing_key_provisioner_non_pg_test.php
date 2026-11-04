<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

namespace Webman {
    // Mirrors the real framework shape on hosts where Webman\Config has no
    // setter: refresh() must tolerate it, and the provisioned key must stay
    // usable inside the same process despite the stale cached config.
    if (!class_exists(Config::class)) {
        class Config
        {
            public static function get(?string $key = null, mixed $default = null): mixed
            {
                return $default;
            }
        }
    }
}

namespace {

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return $GLOBALS['__test_config'][$key] ?? $default;
    }
}
if (!function_exists('run_path')) {
    function run_path(string $path = ''): string
    {
        return ($GLOBALS['__test_run_path'] ?? sys_get_temp_dir()) . ($path === '' ? '' : '/' . $path);
    }
}

require_once dirname(__DIR__) . '/app/runtime/ContextSigningKeyProvisioner.php';
require_once dirname(__DIR__) . '/app/admin/support/AccessMatrixInput.php';

use plugin\SandIam\app\admin\support\AccessMatrixInput;
use plugin\SandIam\app\runtime\ContextSigningKeyProvisioner;

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('context signing key provisioner check failed: ' . $label);
    }
    ++$checks;
};

// Contract surface: binds the documented env/config key pair.
$contract = ContextSigningKeyProvisioner::contract();
$assert($contract['name'] === 'SAND_IAM_CONTEXT_SIGNING_KEY', 'environment name');
$assert($contract['config_key'] === 'plugin.sand-iam.app.context_signing_key', 'config key');

// Operator-declared value is honoured verbatim; provisioning never runs.
$GLOBALS['__test_config']['plugin.sand-iam.app.context_signing_key'] = 'operator-declared-key';
$assert(ContextSigningKeyProvisioner::ensure() === 'operator-declared-key', 'operator value honoured');
unset($GLOBALS['__test_config']['plugin.sand-iam.app.context_signing_key']);

// First-use provisioning: empty config + writable private .env → generated
// 64-hex key persisted exactly once; second call adopts the declared value.
$home = sys_get_temp_dir() . '/siam-sign-' . bin2hex(random_bytes(6));
mkdir($home, 0700);
file_put_contents($home . '/.env', "DB_NAME=demo\n");
chmod($home . '/.env', 0600);
$GLOBALS['__test_run_path'] = $home;
$first = ContextSigningKeyProvisioner::ensure();
$assert(preg_match('/^[0-9a-f]{64}$/', $first) === 1, 'generated 32-byte hex key');
$envAfter = (string) file_get_contents($home . '/.env');
$assert(substr_count($envAfter, 'SAND_IAM_CONTEXT_SIGNING_KEY=') === 1, 'single env entry appended');
$assert(str_contains($envAfter, 'DB_NAME=demo'), 'existing entries preserved');
$assert(is_file($home . '/.env.sand-iam-context-original'), 'one-time backup created');

// ux_p2b regression: refresh() ran while the stubbed config cache still
// reports the key as empty (Webman\Config above has no set()); the same
// process must keep returning the provisioned key instead of latching ''.
$assert(ContextSigningKeyProvisioner::ensure() === $first, 'repeat ensure returns provisioned key with stale config');
$assert(($_ENV['SAND_IAM_CONTEXT_SIGNING_KEY'] ?? '') === $first, '$_ENV refreshed');
$assert(getenv('SAND_IAM_CONTEXT_SIGNING_KEY') === $first, 'putenv refreshed');

// Route + catalog registrations stay in sync with the controller.
$plugin = dirname(__DIR__);
$service = (string) file_get_contents($plugin . '/app/service/AccessMatrixService.php');
$controller = (string) file_get_contents($plugin . '/app/admin/controller/AccessMatrixController.php');
$routes = (string) file_get_contents($plugin . '/config/route.php');
$catalog = (string) file_get_contents($plugin . '/app/developer/ManagementApiCatalog.php');
$assert(str_contains($service, 'function ensureDefaults'), 'service method');
$assert(str_contains($service, 'access_matrix.ensure_defaults'), 'idempotent operation');
$assert(str_contains($service, AccessMatrixInput::DEFAULT_APPLICATION_CODE), 'default app code used');
$assert(str_contains($controller, 'function ensureDefaults'), 'controller method');
$assert(str_contains($routes, 'ensure-defaults'), 'route registered');
$assert(str_contains($catalog, "ensure-defaults' => 'sand_iam:grant:save'"), 'catalog permission');
$assert(str_contains($catalog, 'access-matrix/ensure-defaults'), 'catalog summary');
$assert(AccessMatrixInput::DEFAULT_APPLICATION_CODE === 'lvxu' && AccessMatrixInput::DEFAULT_APPLICATION_NAME === '律序', 'default application identity');

echo 'context_signing_key_provisioner_non_pg_test OK (' . $checks . " checks)\n";

}
