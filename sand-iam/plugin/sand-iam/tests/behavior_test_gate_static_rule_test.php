<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/**
 * This heuristic only identifies test files that read source/artifact text. It
 * proves annotation hygiene, not business behavior or runtime acceptance.
 */
$tests = glob(__DIR__ . '/*_test.php') ?: [];
$missing = [];
foreach ($tests as $test) {
    $source = file_get_contents($test);
    if (!is_string($source)) {
        fwrite(STDERR, "unreadable test {$test}\n");
        exit(1);
    }
    if (!str_contains($source, 'file_get_contents(')) {
        continue;
    }
    if (!str_contains($source, 'behavior-test-gate: static-rule')) {
        $missing[] = basename($test);
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'source/artifact text tests require static-rule annotation: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}
echo 'behavior test gate static-rule annotation audit passed; annotated=' . count(array_filter($tests, static function (string $test): bool {
    $source = file_get_contents($test);
    return is_string($source) && str_contains($source, 'file_get_contents(');
})) . PHP_EOL;
