<?php

declare(strict_types=1);

use plugin\SandIam\app\service\OAuthDynamicRegistrationService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t11DcrFail(string $message): never { fwrite(STDERR, "IAM-T11 DCR validation test failed: {$message}\n"); exit(1); }
function t11DcrExpect(callable $callback, string $code, string $case = ''): void { try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; } t11DcrFail("expected {$code}" . ($case === '' ? '' : " for {$case}")); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t11DcrFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';
putenv('SAND_IAM_AUTH_PEPPER=' . str_repeat('p', 48));
putenv('SAND_IAM_OAUTH_DYNAMIC_REGISTRATION_ENABLED=0');
Config::clear(); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$service = new OAuthDynamicRegistrationService();
t11DcrExpect(static fn () => $service->register('siam_dcr_' . str_repeat('a', 48), [], '127.0.0.1', 't11-dcr-disabled'), 'SAND_IAM_DCR_DISABLED');

$redirectMethod = new ReflectionMethod(OAuthDynamicRegistrationService::class, 'redirectUris'); $redirectMethod->setAccessible(true);
$native = $redirectMethod->invoke($service, ['http://127.0.0.1:47832/callback'], 'native');
if ($native !== ['http://127.0.0.1:47832/callback']) t11DcrFail('native loopback URI was not accepted');
t11DcrExpect(static fn () => $redirectMethod->invoke($service, ['http://example.test/callback'], 'native'), 'SAND_IAM_DCR_INVALID_REDIRECT_URI', 'non-loopback HTTP');
t11DcrExpect(static fn () => $redirectMethod->invoke($service, ['https://example.test/callback#fragment'], 'web'), 'SAND_IAM_DCR_INVALID_REDIRECT_URI', 'URI fragment');
t11DcrExpect(static fn () => $redirectMethod->invoke($service, ['https://*.example.test/callback'], 'web'), 'SAND_IAM_DCR_INVALID_REDIRECT_URI', 'wildcard host');

$grantMethod = new ReflectionMethod(OAuthDynamicRegistrationService::class, 'grantTypes'); $grantMethod->setAccessible(true);
if ($grantMethod->invoke($service, ['authorization_code', 'refresh_token']) !== ['authorization_code', 'refresh_token']) t11DcrFail('supported grants changed');
t11DcrExpect(static fn () => $grantMethod->invoke($service, ['client_credentials']), 'SAND_IAM_DCR_INVALID_CLIENT_METADATA');
t11DcrExpect(static fn () => $grantMethod->invoke($service, ['refresh_token']), 'SAND_IAM_DCR_INVALID_CLIENT_METADATA');

$responseMethod = new ReflectionMethod(OAuthDynamicRegistrationService::class, 'responseTypes'); $responseMethod->setAccessible(true);
t11DcrExpect(static fn () => $responseMethod->invoke($service, ['token'], ['authorization_code']), 'SAND_IAM_DCR_INVALID_CLIENT_METADATA');

$hostsMethod = new ReflectionMethod(OAuthDynamicRegistrationService::class, 'hosts'); $hostsMethod->setAccessible(true);
if ($hostsMethod->invoke($service, ['APP.EXAMPLE.TEST', '127.0.0.1']) !== ['app.example.test', '127.0.0.1']) t11DcrFail('allowed hosts normalization failed');
t11DcrExpect(static fn () => $hostsMethod->invoke($service, ['https://example.test']), 'SAND_IAM_DCR_TOKEN_POLICY_INVALID');

$tokenHashMethod = new ReflectionMethod(OAuthDynamicRegistrationService::class, 'tokenHash'); $tokenHashMethod->setAccessible(true);
$hash = $tokenHashMethod->invoke($service, 'siam_dcr_' . str_repeat('b', 48));
if (!is_string($hash) || strlen($hash) !== 64 || str_contains($hash, 'siam_dcr_')) t11DcrFail('token HMAC failed');

echo "OAuth dynamic registration validation non-PG tests passed\n";
