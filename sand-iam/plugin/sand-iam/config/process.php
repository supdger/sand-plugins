<?php

use plugin\SandIam\app\process\WebhookWorker;
use plugin\SandIam\app\process\OidcLogoutWorker;
use plugin\SandIam\app\process\RadiusServerWorker;
use plugin\SandIam\app\process\RadiusAccountingWorker;
use plugin\SandIam\app\process\AuditOperationsWorker;
use plugin\SandIam\app\process\DirectorySyncWorker;

// Workers are opt-in. An enabled deployment must finish the matching lifecycle
// migration and operational acceptance before it starts a worker.
$processes = [];
if ((string) env('SAND_IAM_WEBHOOK_WORKER_ENABLED', '0') === '1') {
    $processes['sand_iam_webhook_worker'] = [
        'handler' => WebhookWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ];
}
if ((string) env('SAND_IAM_OIDC_LOGOUT_WORKER_ENABLED', '0') === '1') {
    $processes['sand_iam_oidc_logout_worker'] = [
        'handler' => OidcLogoutWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ];
}
if ((string) env('SAND_IAM_RADIUS_WORKER_ENABLED', '0') === '1') {
    $processes['sand_iam_radius_server'] = [
        'handler' => RadiusServerWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ];
    $processes['sand_iam_radius_accounting'] = [
        'handler' => RadiusAccountingWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ];
}
if ((string) env('SAND_IAM_AUDIT_ARCHIVE_WORKER_ENABLED', '0') === '1') {
    $processes['sand_iam_audit_archive_worker'] = [
        'handler' => AuditOperationsWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ];
}
// Directory synchronization can change application-user lifecycle state. Keep
// it off until the identity lifecycle switch, migrations and operational
// acceptance are all explicitly enabled for this deployment.
if ((string) env('SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED', '0') === '1'
    && (string) env('SAND_IAM_IDENTITY_LIFECYCLE_ENABLED', '0') === '1') {
    $processes['sand_iam_directory_sync_worker'] = [
        'handler' => DirectorySyncWorker::class,
        'count' => 1,
        'user' => '',
        'group' => '',
        'reloadable' => false,
        'constructor' => [],
    ];
}
return $processes;
