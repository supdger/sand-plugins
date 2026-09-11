<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

function managementSdkAssert(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

$plugin = dirname(__DIR__);
$root = dirname(dirname($plugin));
$catalog = (string) file_get_contents($plugin . '/app/developer/ManagementApiCatalog.php');
$routes = (string) file_get_contents($plugin . '/config/route.php');
$php = (string) file_get_contents($root . '/sdk/php/src/SandIamManagementClient.php');
$phpDto = (string) file_get_contents($root . '/sdk/php/src/ManagementDto.php');
$typescript = (string) file_get_contents($root . '/sdk/typescript/src/management.ts');
$dart = (string) file_get_contents($root . '/sdk/dart/lib/src/management_client.dart');
$dartConstants = (string) file_get_contents($root . '/sdk/dart/lib/src/api_constants.dart');
$dartConsumer = (string) file_get_contents($root . '/sdk/dart/example/management_consumer/bin/main.dart');
$dartConsumerPubspec = (string) file_get_contents($root . '/sdk/dart/example/management_consumer/pubspec.yaml');

foreach ([$catalog, $routes, $php, $phpDto, $typescript, $dart, $dartConstants, $dartConsumer, $dartConsumerPubspec] as $source) managementSdkAssert($source !== '', 'management SDK source is unreadable');

$paths = [
    '/developer/onboarding/preview', '/developer/onboarding/apply',
    '/policy/simulate', '/policy/rollback',
    '/credential/issue', '/credential/rotate', '/credential/revoke',
    '/identity-provider-preset/index', '/identity-provider-preset/draft',
];
foreach ($paths as $path) {
    managementSdkAssert(str_contains($catalog, $path), "ManagementApiCatalog lacks {$path}");
    managementSdkAssert(str_contains($routes, $path), "route registry lacks {$path}");
    managementSdkAssert(str_contains($php, $path), "PHP management SDK drifts from {$path}");
    managementSdkAssert(str_contains($typescript, $path), "TypeScript management SDK drifts from {$path}");
    managementSdkAssert(str_contains($dartConstants, $path), "Dart management SDK drifts from {$path}");
}

foreach ([$php, $typescript, $dart] as $source) {
    managementSdkAssert(!str_contains($source, '/route-sync/'), 'SDK must not invent a standalone route-sync endpoint');
    managementSdkAssert(str_contains($source, 'Cache-Control') && str_contains($source, 'no-store'), 'management SDK lacks no-store protection');
    managementSdkAssert(str_contains($source, 'SAND_IAM_SDK_REQUEST_ID_REQUIRED'), 'management SDK lacks explicit request-id rejection');
}
foreach ([$phpDto, $typescript, $dart] as $source) {
    managementSdkAssert(str_contains($source, 'secretAvailable') || str_contains($source, 'secret_available'), 'one-time secret result does not expose availability metadata');
    managementSdkAssert(str_contains($source, '禁止转换为字符串或写入日志'), 'one-time secret result may be stringified');
}
managementSdkAssert(str_contains($dartConsumer, 'SandIamManagementClient') && str_contains($dartConsumerPubspec, 'path: ../..'), 'Dart path consumer must consume the exported management client');

echo "Management SDK catalog drift non-PG checks passed\n";
