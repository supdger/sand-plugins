<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$processConfig = dirname(__DIR__) . '/config/process.php';
$content = file_get_contents($processConfig);
if (!is_string($content)) {
    fwrite(STDERR, "directory sync process config unreadable\n");
    exit(1);
}

foreach ([
    'DirectorySyncWorker',
    'SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED',
    'SAND_IAM_IDENTITY_LIFECYCLE_ENABLED',
    "'sand_iam_directory_sync_worker'",
    "'count' => 1",
] as $fragment) {
    if (!str_contains($content, $fragment)) {
        fwrite(STDERR, "directory sync process registration missing {$fragment}\n");
        exit(1);
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        return $value === false ? $default : $value;
    }
}

putenv('SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED=0');
putenv('SAND_IAM_IDENTITY_LIFECYCLE_ENABLED=1');
$off = require $processConfig;
if (isset($off['sand_iam_directory_sync_worker'])) {
    fwrite(STDERR, "directory sync worker started without its opt-in switch\n");
    exit(1);
}

putenv('SAND_IAM_DIRECTORY_SYNC_WORKER_ENABLED=1');
putenv('SAND_IAM_IDENTITY_LIFECYCLE_ENABLED=0');
$lifecycleOff = require $processConfig;
if (isset($lifecycleOff['sand_iam_directory_sync_worker'])) {
    fwrite(STDERR, "directory sync worker started while lifecycle is disabled\n");
    exit(1);
}

putenv('SAND_IAM_IDENTITY_LIFECYCLE_ENABLED=1');
$on = require $processConfig;
$worker = $on['sand_iam_directory_sync_worker'] ?? null;
if (!is_array($worker) || ($worker['handler'] ?? null) !== \plugin\SandIam\app\process\DirectorySyncWorker::class || ($worker['count'] ?? null) !== 1) {
    fwrite(STDERR, "directory sync worker opt-in registration is invalid\n");
    exit(1);
}

echo "directory sync worker process static checks passed\n";
