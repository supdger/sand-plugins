#!/usr/bin/env php
<?php

declare(strict_types=1);

use plugin\SandIam\bin\RuntimeConfigurationPreflight;

require_once __DIR__ . '/RuntimeConfigurationPreflight.php';

$profile = 'release';
$json = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--profile=')) {
        $profile = substr($argument, strlen('--profile='));
    } elseif ($argument === '--json') {
        $json = true;
    } else {
        fwrite(STDERR, 'Unsupported argument. Use --profile=release|acceptance and optional --json.' . PHP_EOL);
        exit(64);
    }
}

try {
    $environment = getenv();
    $result = (new RuntimeConfigurationPreflight())->inspect(is_array($environment) ? $environment : [], $profile);
} catch (\InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(64);
}

if ($json) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} else {
    echo ($result['passed'] ? '[PASS]' : '[FAIL]') . ' SandIAM runtime configuration preflight' . PHP_EOL;
    echo 'Profile: ' . $result['profile'] . '; checked keys: ' . $result['checked_keys'] . '; scope: ' . $result['scope'] . PHP_EOL;
    foreach ($result['errors'] as $issue) {
        echo '[ERROR] ' . $issue['code'] . ' ' . $issue['key'] . ': ' . $issue['message'] . PHP_EOL;
    }
    foreach ($result['warnings'] as $issue) {
        echo '[WARN] ' . $issue['code'] . ' ' . $issue['key'] . ': ' . $issue['message'] . PHP_EOL;
    }
    echo 'This check does not connect to PostgreSQL, start services, or prove runtime acceptance.' . PHP_EOL;
}

exit($result['passed'] ? 0 : 1);
