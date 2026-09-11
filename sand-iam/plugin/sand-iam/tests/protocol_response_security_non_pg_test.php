<?php

declare(strict_types=1);

use plugin\SandIam\app\api\controller\OAuthOidcController;
use plugin\SandIam\app\federation\NativeFederationHttpAdapter;
use plugin\SandIam\app\middleware\ScimProtocolMiddleware;
use plugin\SandIam\app\oidc\NativeOidcBackchannelHttpAdapter;
use plugin\SandIam\app\security\PublicDnsResolver;
use plugin\sandadmin\exception\ApiException;

function protocolSecurityFail(string $message): never
{
    fwrite(STDERR, "protocol response security non-PG test failed: {$message}\n");
    exit(1);
}

function protocolSecurityAssert(bool $condition, string $message): void
{
    if (!$condition) protocolSecurityFail($message);
}

function protocolSecurityExpect(callable $callback, string $code): void
{
    try {
        $callback();
    } catch (ApiException $exception) {
        if (str_contains($exception->getMessage(), $code)) return;
    }
    protocolSecurityFail("expected {$code}");
}

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) protocolSecurityFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $root . '/plugin/sand-iam/app/functions.php';

$middleware = new ScimProtocolMiddleware();
$decorate = new ReflectionMethod($middleware, 'decorate');
$decorate->setAccessible(true);
$success = $decorate->invoke($middleware, response('{"ok":true}', 200, ['Content-Type' => 'application/json']));
protocolSecurityAssert($success->getHeader('Content-Type') === 'application/scim+json', 'SCIM success content type is not protocol-specific');
protocolSecurityAssert($success->getHeader('Cache-Control') === 'no-store' && $success->getHeader('Pragma') === 'no-cache', 'SCIM success cache headers are incomplete');

$scimError = new ReflectionMethod($middleware, 'error');
$scimError->setAccessible(true);
$unauthorized = $scimError->invoke($middleware, 'SAND_IAM_SCIM_ACCESS_DENIED', 401);
protocolSecurityAssert($unauthorized->getStatusCode() === 401 && $unauthorized->getHeader('Content-Type') === 'application/scim+json', 'SCIM error status or content type drifted');
protocolSecurityAssert($unauthorized->getHeader('Cache-Control') === 'no-store' && $unauthorized->getHeader('Pragma') === 'no-cache', 'SCIM error cache headers are incomplete');
protocolSecurityAssert($unauthorized->getHeader('WWW-Authenticate') === 'Bearer', 'SCIM 401 bearer challenge is missing');

$oauth = new OAuthOidcController();
$dcrError = new ReflectionMethod($oauth, 'dynamicRegistrationError');
$dcrError->setAccessible(true);
foreach (['SAND_IAM_DCR_ACCESS_DENIED', 'SAND_IAM_AUTHENTICATION_FAILED'] as $code) {
    $response = $dcrError->invoke($oauth, new ApiException($code, 401));
    $body = json_decode($response->rawBody(), true);
    protocolSecurityAssert($response->getStatusCode() === 401 && ($body['error'] ?? null) === 'invalid_token', "DCR {$code} did not return stable invalid_token");
    protocolSecurityAssert(str_contains((string) $response->getHeader('WWW-Authenticate'), 'Bearer') && str_contains((string) $response->getHeader('WWW-Authenticate'), 'invalid_token'), "DCR {$code} bearer challenge is incomplete");
    protocolSecurityAssert($response->getHeader('Cache-Control') === 'no-store' && $response->getHeader('Pragma') === 'no-cache', "DCR {$code} cache headers are incomplete");
}

$authorizeError = new ReflectionMethod($oauth, 'authorizationEndpointError');
$authorizeError->setAccessible(true);
foreach (['SAND_IAM_OAUTH_INVALID_CLIENT', 'SAND_IAM_OAUTH_REDIRECT_URI_INVALID'] as $code) {
    $response = $authorizeError->invoke($oauth, new ApiException($code, 400));
    $body = json_decode($response->rawBody(), true);
    protocolSecurityAssert($response->getStatusCode() === 400 && ($body['error'] ?? null) === 'invalid_request', "authorize {$code} did not return a safe protocol error");
    protocolSecurityAssert($response->getHeader('Location') === null, "authorize {$code} reflected an unverified redirect");
    protocolSecurityAssert($response->getHeader('Cache-Control') === 'no-store' && $response->getHeader('Pragma') === 'no-cache', "authorize {$code} cache headers are incomplete");
}

$publicRecords = static fn (string $host): array => [
    ['host' => $host, 'type' => 'A', 'ip' => '93.184.216.34'],
    ['host' => $host, 'type' => 'AAAA', 'ipv6' => '2606:2800:220:1:248:1893:25c8:1946'],
];
$resolver = new PublicDnsResolver($publicRecords);
$addresses = $resolver->resolve('idp.example.test', 'UNAVAILABLE', 'REJECTED');
protocolSecurityAssert($addresses === ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], 'A and AAAA records were not both retained');
protocolSecurityAssert(PublicDnsResolver::curlResolveEntry('idp.example.test', 443, $addresses[1]) === 'idp.example.test:443:[2606:2800:220:1:248:1893:25c8:1946]', 'IPv6 cURL pin is malformed');

$mixedResolver = new PublicDnsResolver(static fn (string $host): array => [
    ['host' => $host, 'type' => 'A', 'ip' => '93.184.216.34'],
    ['host' => $host, 'type' => 'AAAA', 'ipv6' => 'fc00::1'],
]);
protocolSecurityExpect(static fn () => $mixedResolver->resolve('idp.example.test', 'UNAVAILABLE', 'REJECTED'), 'REJECTED');
$mixedIpv4Resolver = new PublicDnsResolver(static fn (string $host): array => [
    ['host' => $host, 'type' => 'A', 'ip' => '10.0.0.1'],
    ['host' => $host, 'type' => 'AAAA', 'ipv6' => '2606:2800:220:1:248:1893:25c8:1946'],
]);
protocolSecurityExpect(static fn () => $mixedIpv4Resolver->resolve('idp.example.test', 'UNAVAILABLE', 'REJECTED'), 'REJECTED');

$federationDestination = new ReflectionMethod(NativeFederationHttpAdapter::class, 'destination');
$federationDestination->setAccessible(true);
$federation = new NativeFederationHttpAdapter($resolver);
$destination = $federationDestination->invoke($federation, 'https://idp.example.test/token');
protocolSecurityAssert($destination[2] === $addresses, 'federation adapter did not use the validated A/AAAA set');
$rejectedFederation = new NativeFederationHttpAdapter($mixedResolver);
protocolSecurityExpect(static fn () => $federationDestination->invoke($rejectedFederation, 'https://idp.example.test/token'), 'SAND_IAM_FEDERATION_PROVIDER_ADDRESS_REJECTED');

$backchannelDestination = new ReflectionMethod(NativeOidcBackchannelHttpAdapter::class, 'destination');
$backchannelDestination->setAccessible(true);
$backchannel = new NativeOidcBackchannelHttpAdapter($resolver);
$destination = $backchannelDestination->invoke($backchannel, 'https://rp.example.test/logout');
protocolSecurityAssert($destination[2] === $addresses, 'OIDC back-channel adapter did not use the validated A/AAAA set');
$rejectedBackchannel = new NativeOidcBackchannelHttpAdapter($mixedResolver);
protocolSecurityExpect(static fn () => $backchannelDestination->invoke($rejectedBackchannel, 'https://rp.example.test/logout'), 'SAND_IAM_OIDC_BACKCHANNEL_DESTINATION_REJECTED');

echo "protocol response security non-PG tests passed\n";
