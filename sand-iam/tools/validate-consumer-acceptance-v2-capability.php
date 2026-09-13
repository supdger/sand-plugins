#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/consumer-acceptance-v2/LiveContract.php';

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new InvalidArgumentException('arguments must use --name=value');
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if ($value === '' || !in_array($key, ['observed-capability-file', 'trusted-key-file', 'host-revision-sha256', 'scope-sha256', 'now'], true) || isset($options[$key])) throw new InvalidArgumentException('unknown, duplicate, or empty argument');
        $options[$key] = $value;
    }
    foreach (['observed-capability-file', 'trusted-key-file', 'host-revision-sha256', 'scope-sha256', 'now'] as $key) if (!isset($options[$key])) throw new InvalidArgumentException('missing required argument');
    $now = new DateTimeImmutable($options['now']);
    $result = ConsumerAcceptanceV2LiveContract::validateObservedCapabilityFile($options['observed-capability-file'], $options['trusted-key-file'], $options['host-revision-sha256'], $options['scope-sha256'], $now);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['status'] === 'observed_validated_offline' ? 0 : 2);
} catch (Throwable $error) {
    echo json_encode(['status' => 'preflight_blocked', 'before_first_write' => true, 'database_factory_initialized' => false], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}
