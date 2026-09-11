<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * Application delegates must be scoped to their granted application across the
 * application -> environment -> workload-client hierarchy. This is source-only
 * because it must not mutate a host or acceptance database.
 */
$package = dirname(__DIR__);
$checks = [
    $package . '/app/admin/controller/ApplicationController.php' => [
        "leftJoin('sand_iam_organization organization', 'organization.id = application.organization_id')",
        "field('application.*, organization.name AS organization_name')",
        "whereIn('application.id', \$applicationIds)",
        "where('application.id', (int) \$model->id)",
        'organization_name',
        'organization_editable',
        'organizationIds()',
        'assertApplication((int) $model->id)',
        'if ($existing === null)',
        'assertOrganization((int) ($payload[\'organization_id\'] ?? 0))',
        'assertOrganization((int) $existing->organization_id)',
    ],
    $package . '/app/admin/controller/EnvironmentController.php' => [
        'extends ApplicationResourceController',
    ],
    $package . '/app/admin/controller/WorkloadClientController.php' => [
        'extends EnvironmentResourceController',
    ],
    $package . '/app/admin/support/ApplicationResourceController.php' => [
        '$this->access()->applicationIds()',
        "whereIn('application_id', \$applicationIds)",
        'assertApplication($applicationId)',
    ],
    $package . '/app/admin/support/EnvironmentResourceController.php' => [
        '$this->access()->applicationIds()',
        "whereIn('environment_id', Environment::whereIn('application_id', \$applicationIds)->column('id'))",
        'assertApplication((int) ($environment?->application_id ?? 0))',
    ],
    $package . '/app/admin/support/AdminOrganizationAccess.php' => [
        'SAND_IAM_APPLICATION_ACCESS_DENIED',
        "'application.access'",
        "'denied'",
    ],
];

foreach ($checks as $file => $fragments) {
    $content = file_get_contents($file);
    if (!is_string($content)) {
        fwrite(STDERR, "unreadable {$file}\n");
        exit(1);
    }
    foreach ($fragments as $fragment) {
        if (!str_contains($content, $fragment)) {
            fwrite(STDERR, "missing {$fragment} in {$file}\n");
            exit(1);
        }
    }
}

foreach (['EnvironmentController.php', 'WorkloadClientController.php'] as $controllerName) {
    $content = file_get_contents($package . '/app/admin/controller/' . $controllerName);
    if (!is_string($content) || str_contains($content, 'assertOrganization(')) {
        fwrite(STDERR, "{$controllerName} retains organization-only application scope\n");
        exit(1);
    }
}

echo "application scope controller non-PG contract checks passed\n";
