<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

$directory = dirname(__DIR__) . '/app/middleware';
$checked = 0;
foreach (glob($directory . '/*.php') ?: [] as $file) {
    $source = file_get_contents($file);
    if (!is_string($source) || !str_contains($source, 'json(')) continue;
    $checked++;
    if (str_contains($source, 'use support\\Response;')) {
        fwrite(STDERR, basename($file) . ' narrows json() to support\\Response' . PHP_EOL);
        exit(1);
    }
    if (!str_contains($source, 'use Webman\\Http\\Response;')) {
        fwrite(STDERR, basename($file) . ' does not use the json() response base type' . PHP_EOL);
        exit(1);
    }
}

if ($checked < 12) {
    fwrite(STDERR, 'sensitive middleware response coverage is incomplete' . PHP_EOL);
    exit(1);
}

echo "middleware response type contract checks passed ({$checked})" . PHP_EOL;
