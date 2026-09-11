<?php

declare(strict_types=1);

use plugin\SandIam\app\kerberos\SpnegoVerifier;
use plugin\SandIam\app\kerberos\UnavailableSpnegoVerifier;
use plugin\SandIam\app\kerberos\UnavailableSpnegoContextResolver;
use plugin\SandIam\app\service\FederationService;
use plugin\SandIam\app\service\KerberosProtocolService;
use plugin\sandadmin\exception\ApiException;

function t11KerberosFail(string $message): never { fwrite(STDERR, "IAM-T11 Kerberos validation test failed: {$message}\n"); exit(1); }
function t11KerberosExpect(callable $callback, string $code): void { try { $callback(); } catch (ApiException $exception) { if (str_contains($exception->getMessage(), $code)) return; } t11KerberosFail("expected {$code}"); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t11KerberosFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';

$unavailable = new UnavailableSpnegoVerifier();
t11KerberosExpect(static fn () => $unavailable->verify('token', [], ['channel_binding' => 'binding', 'remote_ip' => '127.0.0.1']), 'SAND_IAM_KERBEROS_VERIFIER_UNAVAILABLE');
$request = (new ReflectionClass(\support\Request::class))->newInstanceWithoutConstructor();
t11KerberosExpect(static fn () => (new UnavailableSpnegoContextResolver())->resolve($request), 'SAND_IAM_KERBEROS_TRANSPORT_CONTEXT_UNAVAILABLE');

$verifier = new class implements SpnegoVerifier {
    public function verify(string $token, array $config, array $context): array { return ['principal' => 'lawyer@EXAMPLE.COM', 'service_principal' => 'HTTP/iam.example.test@EXAMPLE.COM', 'mutual_auth' => true, 'channel_binding' => true, 'replay_protected' => true]; }
};
$service = new KerberosProtocolService($verifier);
$principal = new ReflectionMethod(KerberosProtocolService::class, 'principal'); $principal->setAccessible(true);
$config = ['allowed_realms' => ['EXAMPLE.COM']];
if ($principal->invoke($service, 'lawyer@example.com', $config) !== 'lawyer@EXAMPLE.COM') t11KerberosFail('realm normalization failed');
t11KerberosExpect(static fn () => $principal->invoke($service, 'lawyer@OTHER.COM', $config), 'SAND_IAM_KERBEROS_REALM_NOT_ALLOWED');
t11KerberosExpect(static fn () => $principal->invoke($service, "lawyer\n@EXAMPLE.COM", $config), 'SAND_IAM_KERBEROS_PRINCIPAL_INVALID');

$validate = new ReflectionMethod(FederationService::class, 'validateConfig'); $validate->setAccessible(true);
$validConfig = ['service_principal' => 'HTTP/iam.example.test@EXAMPLE.COM', 'keytab_ref' => 'sand-iam-prod-v1', 'allowed_realms' => ['EXAMPLE.COM'], 'require_channel_binding' => true, 'require_replay_cache' => true, 'require_mutual_auth' => true];
$validate->invoke(new FederationService(), 'kerberos', $validConfig);
foreach (['require_channel_binding', 'require_replay_cache', 'require_mutual_auth'] as $field) {
    $invalid = $validConfig; $invalid[$field] = false;
    t11KerberosExpect(static fn () => $validate->invoke(new FederationService(), 'kerberos', $invalid), 'SAND_IAM_KERBEROS_CONFIGURATION_INVALID');
}

echo "Kerberos/SPNEGO validation non-PG tests passed\n";
