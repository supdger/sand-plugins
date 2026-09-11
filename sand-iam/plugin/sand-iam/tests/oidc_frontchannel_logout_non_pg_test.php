<?php

declare(strict_types=1);

use plugin\SandIam\app\api\controller\OAuthOidcController;
use plugin\SandIam\app\model\OAuthClient;
use plugin\SandIam\app\service\OAuthOidcService;
use Webman\Config;

function t11FrontFail(string $message): never { fwrite(STDERR, "IAM-T11 front-channel logout test failed: {$message}\n"); exit(1); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t11FrontFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';
putenv('SAND_IAM_OIDC_ISSUER=https://iam.example.test/api/sand-iam/v1');
putenv('SAND_IAM_OIDC_FRONTCHANNEL_LOGOUT_ENABLED=1');
Config::clear(); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$method = new ReflectionMethod(OAuthOidcService::class, 'frontchannelLogoutUri'); $method->setAccessible(true);
$client = new OAuthClient();
$client->frontchannel_logout_uri = 'https://rp.example.test/logout?source=iam';
$client->frontchannel_logout_session_required = true;
$uri = $method->invoke(new OAuthOidcService(), $client, 'session-100');
if (!is_string($uri)) t11FrontFail('registered URI was not accepted');
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
if (($query['source'] ?? '') !== 'iam' || ($query['iss'] ?? '') !== 'https://iam.example.test/api/sand-iam/v1' || ($query['sid'] ?? '') !== 'session-100') t11FrontFail('iss/sid query was not appended safely');

$client->frontchannel_logout_session_required = false;
$withoutSid = $method->invoke(new OAuthOidcService(), $client, 'session-100');
parse_str((string) parse_url((string) $withoutSid, PHP_URL_QUERY), $queryWithoutSid);
if (isset($queryWithoutSid['sid']) || ($queryWithoutSid['iss'] ?? '') === '') t11FrontFail('session_required=false behavior failed');
$client->frontchannel_logout_uri = 'http://rp.example.test/logout';
if ($method->invoke(new OAuthOidcService(), $client, 'session-100') !== null) t11FrontFail('insecure URI was accepted');

$pageMethod = new ReflectionMethod(OAuthOidcController::class, 'frontchannelLogoutPage'); $pageMethod->setAccessible(true);
$response = $pageMethod->invoke(new OAuthOidcController(), ['https://rp.example.test/logout?a=1&b=2'], 'https://app.example.test/signed-out?x=1&y=2');
$body = $response->rawBody();
if ($response->getStatusCode() !== 200 || !str_contains($body, 'a=1&amp;b=2') || !str_contains($body, 'x=1&amp;y=2') || str_contains($body, 'a=1&b=2')) t11FrontFail('logout page escaping failed');
if (!str_contains((string) $response->getHeader('Content-Security-Policy'), 'frame-src https:') || $response->getHeader('Cache-Control') !== 'no-store') t11FrontFail('logout page security headers missing');

echo "OIDC front-channel logout non-PG tests passed\n";
