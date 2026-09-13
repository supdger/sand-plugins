#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/consumer-acceptance/Runtime.php';

try {
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) throw new InvalidArgumentException('arguments must use --name=value');
        [$key, $value] = explode('=', substr($argument, 2), 2);
        if ($value === '' || !in_array($key, ['mode', 'plan', 'report', 'secrets-file'], true) || isset($options[$key])) throw new InvalidArgumentException('unknown, duplicate, or empty argument');
        $options[$key] = $value;
    }
    if (!isset($options['mode'], $options['plan'], $options['report']) || !in_array($options['mode'], ['validate', 'live'], true)) throw new InvalidArgumentException('requires --mode=validate|live, --plan, and --report');
    if (($options['mode'] === 'validate' && isset($options['secrets-file'])) || ($options['mode'] === 'live' && !isset($options['secrets-file']))) throw new InvalidArgumentException('secrets-file is required only in live mode');
    $planInput = ConsumerAcceptanceRuntime::readPlanFile($options['plan']);
    $plan = json_decode($planInput['bytes'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($plan) || array_is_list($plan)) throw new RuntimeException('plan must be a JSON object');
    $runtime = new ConsumerAcceptanceRuntime();
    $report = $options['mode'] === 'validate'
        ? $runtime->validate($plan, $planInput['sha256'])
        : $runtime->live($plan, $planInput['sha256'], $options['secrets-file'], dirname(__DIR__));
    ConsumerAcceptanceRuntime::writeReport($options['report'], $report);
    echo json_encode(['status' => $report['status'], 'real_l04' => false], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report['status'] === 'unsupported' ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'consumer acceptance rejected: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
