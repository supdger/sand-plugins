<?php

declare(strict_types=1);

use plugin\SandIam\app\service\CasProtocolService;

function t11CasFail(string $message): never { fwrite(STDERR, "IAM-T11 CAS validation test failed: {$message}\n"); exit(1); }

$hostRoot = getenv('SAND_IAM_T01_HOST_ROOT') ?: '/Users/code/project/sand_plugins/sandadmin-demo-host/server';
$root = dirname(__DIR__, 3);
if (!is_file($hostRoot . '/vendor/autoload.php')) t11CasFail('SandAdmin host dependencies are unavailable');
chdir($hostRoot); require $hostRoot . '/vendor/autoload.php'; require $root . '/plugin/sand-iam/app/functions.php';

$service = new CasProtocolService();
$valid = new ReflectionMethod(CasProtocolService::class, 'validServiceUrl'); $valid->setAccessible(true);
$append = new ReflectionMethod(CasProtocolService::class, 'appendTicket'); $append->setAccessible(true);

if (!$valid->invoke($service, 'https://lvxu.example.test/cas/callback?from=iam')) t11CasFail('valid service URL rejected');
foreach (['http://lvxu.example.test/cas', 'https://user:pass@lvxu.example.test/cas', 'https://lvxu.example.test/cas#fragment', 'https://lvxu.example.test/cas?ticket=attacker', 'https://*.example.test/cas'] as $url) {
    if ($valid->invoke($service, $url)) t11CasFail("unsafe service URL accepted: {$url}");
}

$redirect = $append->invoke($service, 'https://lvxu.example.test/cas/callback?from=iam', 'ST-safe_ticket');
if ($redirect !== 'https://lvxu.example.test/cas/callback?from=iam&ticket=ST-safe_ticket') t11CasFail('ticket query append invalid');

$principal = ['username' => "lawyer<&\"", 'attributes' => ['displayName' => '律师<&', 'email' => 'lawyer@example.test']];
$cas1 = $service->cas1Response($principal);
if ($cas1 !== "yes\nlawyer<&\"\n") t11CasFail('CAS 1.0 success response invalid');
if ($service->cas1Response(null) !== "no\n\n") t11CasFail('CAS 1.0 failure response invalid');
$cas2 = $service->xmlResponse($principal, false);
if (!str_contains($cas2, '<cas:user>lawyer&lt;&amp;&quot;</cas:user>') || str_contains($cas2, '<cas:attributes>')) t11CasFail('CAS 2.0 XML response invalid');
$cas3 = $service->xmlResponse($principal, true);
if (!str_contains($cas3, '<cas:attributes>') || !str_contains($cas3, '<cas:displayName>律师&lt;&amp;</cas:displayName>')) t11CasFail('CAS 3.0 attributes invalid');
if (!str_contains($service->xmlResponse(null, true), 'code="INVALID_TICKET"')) t11CasFail('CAS XML failure response invalid');

echo "CAS protocol validation non-PG tests passed\n";
