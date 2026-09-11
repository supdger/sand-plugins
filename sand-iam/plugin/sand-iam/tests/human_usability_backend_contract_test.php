<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$sandIamRoot = dirname(__DIR__, 3);
$resourceController = file_get_contents($sandIamRoot . '/plugin/sand-iam/app/admin/support/AdminResourceController.php');
$serviceActionController = file_get_contents($sandIamRoot . '/plugin/sand-iam/app/admin/controller/ServiceActionController.php');

if ($resourceController === false || $serviceActionController === false) {
    throw new RuntimeException('Cannot read SandIAM code validation sources');
}

foreach ([
    'preg_match(\'/^[a-z0-9][a-z0-9_-]{1,63}$/\', $code)',
    '系统代码须为 2–64 位小写字母、数字、短横线或下划线',
] as $required) {
    if (!str_contains($resourceController, $required)) {
        throw new RuntimeException('Generic human-readable system code contract is missing: ' . $required);
    }
}

foreach ([
    'preg_match(\'/^[a-z0-9][a-z0-9._-]{1,95}$/\', $code)',
    'sand_ai.document_parse',
    '服务动作代码须为 2–96 位',
] as $required) {
    if (!str_contains($serviceActionController, $required)) {
        throw new RuntimeException('Service action semantic code contract is missing: ' . $required);
    }
}

echo "SandIAM human usability backend contracts passed\n";
