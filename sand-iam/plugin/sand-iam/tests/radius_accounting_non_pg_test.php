<?php

declare(strict_types=1);

use plugin\SandIam\app\model\RadiusNas;
use plugin\SandIam\app\radius\RadiusPacketCodec;
use plugin\SandIam\app\service\RadiusAccountingService;
use plugin\sandadmin\exception\ApiException;
use Webman\Config;

function t11AccountingFail(string $message): never { fwrite(STDERR, "IAM-T11 RADIUS accounting test failed: {$message}\n"); exit(1); }
function t11AccountingExpect(callable $callback, string $code): void { try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; } t11AccountingFail("expected {$code}"); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t11AccountingFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';
putenv('SAND_IAM_RADIUS_REPLAY_KEY=' . str_repeat('r', 48));
Config::clear(); Config::load($root . '/plugin/sand-iam/config', ['route'], 'plugin.sand-iam');

$secret = 'radius-shared-secret-v1';
$attributes = chr(40) . chr(6) . pack('N', 1)
    . chr(44) . chr(13) . 'session-001'
    . chr(1) . chr(8) . 'lawyer'
    . chr(55) . chr(6) . pack('N', time())
    . chr(46) . chr(6) . pack('N', 120)
    . chr(42) . chr(6) . pack('N', 1000)
    . chr(43) . chr(6) . pack('N', 2000);
$prefix = pack('CCn', 4, 9, 20 + strlen($attributes));
$authenticator = hash('md5', $prefix . str_repeat("\0", 16) . $attributes . $secret, true);
$raw = $prefix . $authenticator . $attributes;
$codec = new RadiusPacketCodec();
$packet = $codec->decode($raw);
$codec->verifyAccountingAuthenticator($packet, $secret);
$tampered = $raw; $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
t11AccountingExpect(static fn () => $codec->verifyAccountingAuthenticator($codec->decode($tampered), $secret), 'SAND_IAM_RADIUS_ACCOUNTING_AUTHENTICATOR_INVALID');

$nas = new RadiusNas(); $nas->id = 8; $nas->application_id = 12;
$method = new ReflectionMethod(RadiusAccountingService::class, 'event'); $method->setAccessible(true);
$event = $method->invoke(new RadiusAccountingService(), $packet, $nas, str_repeat('a', 64));
if (($event['type'] ?? '') !== 'start' || ($event['session_seconds'] ?? -1) !== 120 || ($event['input_octets'] ?? -1) !== 1000 || ($event['output_octets'] ?? -1) !== 2000 || strlen((string) ($event['session_reference'] ?? '')) !== 64) t11AccountingFail('accounting event normalization failed');

$response = $codec->response(RadiusPacketCodec::ACCOUNTING_RESPONSE, 9, $authenticator, [], $secret, false);
$decoded = $codec->decode($response);
$expected = hash('md5', substr($response, 0, 4) . $authenticator . $secret, true);
if ($decoded['code'] !== 5 || !hash_equals($expected, $decoded['authenticator'])) t11AccountingFail('Accounting-Response authenticator invalid');

echo "RADIUS accounting non-PG tests passed\n";
