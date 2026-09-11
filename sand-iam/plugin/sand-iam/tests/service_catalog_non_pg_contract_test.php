<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$source = file_get_contents(dirname(__DIR__) . '/app/runtime/ServiceCatalog.php');
if (!is_string($source)) {
    fwrite(STDERR, "cannot read ServiceCatalog.php\n");
    exit(1);
}

foreach ([
    'registerServiceActions',
    'private const MAX_ACTIONS = 256',
    "preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', \$serviceCode)",
    "preg_match('/^[a-z0-9][a-z0-9._-]{1,95}$/', \$actionCode)",
    'mb_strlen($serviceName) > 128',
    'mb_strlen($actionName) > 128',
    'isset($normalized[$actionCode])',
    "Service::where('code', \$serviceCode)->lock(true)->find()",
    "->where('code', \$actionCode)",
    '->lock(true)',
    'SAND_IAM_SERVICE_CATALOG_DISABLED',
    'SAND_IAM_SERVICE_CATALOG_INVALID',
    "str_contains(\$message, '23505')",
    '$this->persist($serviceCode, $serviceName, $actions, 1)',
    'Db::startTrans()',
    'Db::commit()',
    'Db::rollback()',
] as $fragment) {
    if (!str_contains($source, $fragment)) {
        fwrite(STDERR, "service catalog contract missing {$fragment}\n");
        exit(1);
    }
}

foreach ([
    'Organization',
    'Application',
    'Environment',
    'WorkloadClient',
    'Credential',
    'ServiceGrant',
    'Identity',
    'Role',
    'Policy',
] as $forbiddenModel) {
    if (preg_match('/\\b' . preg_quote($forbiddenModel, '/') . '::(?:create|where|find)/', $source)) {
        fwrite(STDERR, "service catalog crosses ownership boundary through {$forbiddenModel}\n");
        exit(1);
    }
}

if (substr_count($source, '->save([') !== 2
    || !str_contains($source, 'if ($serviceRecord === null)')
    || !str_contains($source, 'if ($action === null)')) {
    fwrite(STDERR, "service catalog must only save newly created service/action rows\n");
    exit(1);
}

echo 'service catalog non-PG contract checks passed' . PHP_EOL;
