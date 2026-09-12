#!/usr/bin/env php
<?php

declare(strict_types=1);

use plugin\SandIam\bin\RuntimeRequirementsPreflight;

require_once __DIR__ . '/RuntimeRequirementsPreflight.php';

$json = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--json') {
        $json = true;
    } else {
        fwrite(STDERR, 'Unsupported argument. Use optional --json.' . PHP_EOL);
        exit(64);
    }
}

$result = (new RuntimeRequirementsPreflight())->inspect(PHP_VERSION, RuntimeRequirementsPreflight::detect());
if ($json) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} else {
    echo ($result['passed'] ? '[PASS]' : '[FAIL]') . ' SandIAM complete runtime requirements' . PHP_EOL;
    echo 'PHP: ' . $result['php_version'] . '; minimum: ' . $result['minimum_php'] . '; checked: ' . $result['checked'] . PHP_EOL;
    foreach ($result['errors'] as $issue) {
        echo '[ERROR] ' . $issue['code'] . ' ' . $issue['requirement'] . ': required for ' . $issue['purpose'] . PHP_EOL;
    }
    echo 'This check does not connect to PostgreSQL, inspect a schema, start services, or prove runtime acceptance.' . PHP_EOL;
}
exit($result['passed'] ? 0 : 1);
