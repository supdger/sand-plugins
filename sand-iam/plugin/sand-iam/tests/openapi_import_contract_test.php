<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

function openApiImportContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$plugin = dirname(__DIR__);
$root = dirname($plugin, 2);
$controller = (string) file_get_contents($plugin . '/app/admin/controller/DeveloperController.php');
$routes = (string) file_get_contents($plugin . '/config/route.php');
$service = (string) file_get_contents($plugin . '/app/service/OpenApiImportService.php');
$catalog = (string) file_get_contents($plugin . '/app/developer/ManagementApiCatalog.php');
$frontend = (string) file_get_contents($root . '/sandadmin-artd/src/views/plugin/sand-iam/route-manifest/index.vue');
$phpSdk = (string) file_get_contents($root . '/sdk/php/src/SandIamManagementClient.php');
$typescriptSdk = (string) file_get_contents($root . '/sdk/typescript/src/management.ts');
$dartSdk = (string) file_get_contents($root . '/sdk/dart/lib/src/management_client.dart');
$docs = (string) file_get_contents($root . '/docs/development/sand-iam-api-governance-v0.1.md');

foreach ([$controller, $routes, $service, $catalog, $frontend, $phpSdk, $typescriptSdk, $dartSdk, $docs] as $source) {
    openApiImportContractAssert($source !== '', 'OpenAPI import source is unreadable');
}
foreach (['/developer/openapi-import/preview', '/developer/openapi-import/apply'] as $path) {
    foreach ([$routes, $catalog, $phpSdk, $typescriptSdk] as $source) {
        openApiImportContractAssert(str_contains($source, $path), "OpenAPI import contract lacks {$path}");
    }
}
foreach (['openApiImportPreview', 'openApiImportApply'] as $method) {
    openApiImportContractAssert(str_contains($controller, "function {$method}("), "controller lacks {$method}");
    openApiImportContractAssert(str_contains($frontend, "developer/openapi-import/" . ($method === 'openApiImportPreview' ? 'preview' : 'apply')), "frontend lacks {$method}");
    openApiImportContractAssert(str_contains($dartSdk, $method), "Dart SDK lacks {$method}");
}
foreach ([
    "->where('source', 'openapi')",
    "SAND_IAM_OPENAPI_IMPORT_PREVIEW_STALE",
    "hash_equals(",
    "Db::startTrans()",
    "Db::rollback()",
] as $needle) {
    openApiImportContractAssert(str_contains($service . $controller . $frontend, $needle), "OpenAPI import safety contract lacks {$needle}");
}
openApiImportContractAssert(
    str_contains($controller, "\$request->post('apply', false) !== true")
    && str_contains($frontend, 'apply: true'),
    'OpenAPI import apply confirmation is not enforced end to end',
);
openApiImportContractAssert(!str_contains($service, "'document' =>"), 'OpenAPI import persists or audits the raw document');
openApiImportContractAssert(str_contains($docs, '原始 OpenAPI 文档只在当前请求中解析，不写入数据库、审计或响应'), 'raw-document retention boundary is undocumented');

echo "OpenAPI import contract checks passed\n";
