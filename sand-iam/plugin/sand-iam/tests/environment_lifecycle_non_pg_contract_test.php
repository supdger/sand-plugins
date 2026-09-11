<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * Environment administration must retain the application scope on every write:
 * an application delegate may manage its own environment, must be rejected for
 * another application's environment, and must be able to find its writes in the
 * scoped audit stream. This source contract deliberately leaves host fixtures
 * untouched; PostgreSQL/browser acceptance covers the live request path.
 */
$package = dirname(__DIR__);
$checks = [
    $package . '/app/admin/controller/EnvironmentController.php' => [
        'extends ApplicationResourceController',
        'parent::save($request)',
        'parent::update($request)',
        'throwWriteFailure',
        'SAND_IAM_ENVIRONMENT_CONFLICT',
        '所选接入应用中已存在相同的环境代码',
        "str_contains(\$message, 'uk_sand_iam_environment_application_code')",
    ],
    $package . '/app/admin/support/ApplicationResourceController.php' => [
        'assertApplication($applicationId)',
        'Application::find($model->application_id)',
    ],
    $package . '/app/admin/support/AdminResourceController.php' => [
        '$modelClass::find($id)',
        'auditScopeForModel($model)',
        'isset($model->application_id)',
        '$this->organizationIdForModel($model)',
        '$organizationId, $applicationId',
    ],
    $package . '/app/admin/support/AdminOrganizationAccess.php' => [
        'public function assertApplication(int $applicationId): void',
        'SAND_IAM_APPLICATION_ACCESS_DENIED',
        "'application.access'",
        "'denied'",
    ],
    $package . '/app/admin/controller/AuditLogController.php' => [
        "whereOr('application_id', 'in', \$applicationIds)",
        "whereIn('application_id', \$applicationIds)",
        'in_array((int) $audit->application_id, $access->applicationIds(), true)',
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

echo "environment allow, deny, conflict and scoped-audit non-PG contracts passed\n";
