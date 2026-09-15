<?php

declare(strict_types=1);

// behavior-test-gate: static-rule

/** Execute the documented restore guard against fake PostgreSQL CLIs only. */

$root = dirname(__DIR__, 3);
$guide = $root . '/docs/user-guide/backup-and-restore.md';
$guideBytes = file_get_contents($guide);
if (!is_string($guideBytes) || preg_match('/```sh restore-guard-contract\s*(.*?)\s*```/s', $guideBytes, $match) !== 1) {
    throw new RuntimeException('cannot find the documented restore guard contract');
}
$seed = tempnam('/private/tmp', 'sand-iam-restore-guard-');
if ($seed === false || !unlink($seed) || !mkdir($seed, 0700) || !mkdir($seed . '/bin', 0700)) throw new RuntimeException('cannot create restore guard fixture');

/** @return array{0:int,1:string} */
$run = static function (array $environment, string $guard) use ($seed): array {
    $arguments = ['env', 'PATH=' . $seed . '/bin:' . getenv('PATH'), 'BACKUP_FILE=' . $seed . '/backup.dump', 'DATABASE_URL=source', 'RESTORE_DATABASE_URL=restore', 'RESTORE_TARGET_ENVIRONMENT=test'];
    foreach ($environment as $key => $value) $arguments[] = $key . '=' . $value;
    $arguments[] = 'sh';
    $arguments[] = $guard;
    $output = [];
    exec(implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $status);
    return [$status, implode("\n", $output)];
};

try {
    $guard = $seed . '/restore-guard.sh';
    file_put_contents($guard, $match[1] . "\n");
    chmod($guard, 0700);
    file_put_contents($seed . '/bin/psql', <<<'SH'
#!/bin/sh
set -eu
case "$*" in
  *pg_control_system*)
    if printf '%s' "$1" | grep -q 'source'; then
      printf '%s\n' "${SAND_TEST_SOURCE_IDENTITY:?}"
    else
      [ -n "${SAND_TEST_RESTORE_IDENTITY:-}" ] || exit 71
      printf '%s\n' "$SAND_TEST_RESTORE_IDENTITY"
    fi
    ;;
  *pg_catalog.pg_class*)
    printf '%s' "$1" | grep -q 'restore' || exit 72
    printf '%s\n' "${SAND_TEST_RESTORE_RELATIONS:?}"
    ;;
  *) exit 73 ;;
esac
SH
    );
    file_put_contents($seed . '/bin/pg_restore', <<<'SH'
#!/bin/sh
set -eu
: "${SAND_TEST_RESTORE_MARKER:?}"
printf 'called\n' >"$SAND_TEST_RESTORE_MARKER"
SH
    );
    chmod($seed . '/bin/psql', 0700);
    chmod($seed . '/bin/pg_restore', 0700);

    $marker = $seed . '/pg-restore-called';
    [$sameStatus] = $run([
        'DATABASE_URL' => 'postgresql://source-v4@127.0.0.1:5432/source',
        'RESTORE_DATABASE_URL' => 'postgresql://restore-v6@[::1]:5432/restore',
        'SAND_TEST_SOURCE_IDENTITY' => '9001:42:736f75726365',
        'SAND_TEST_RESTORE_IDENTITY' => '9001:42:726573746f7265',
        'SAND_TEST_RESTORE_RELATIONS' => '0',
        'RESTORE_TARGET_IDENTITY' => '9001:42:726573746f7265',
        'SAND_TEST_RESTORE_MARKER' => $marker,
    ], $guard);
    $sameBlocked = $sameStatus !== 0 && !file_exists($marker);

    [$unknownStatus] = $run([
        'SAND_TEST_SOURCE_IDENTITY' => '9001:41:736f75726365',
        'SAND_TEST_RESTORE_IDENTITY' => '',
        'SAND_TEST_RESTORE_RELATIONS' => '0',
        'RESTORE_TARGET_IDENTITY' => '9001:42:726573746f7265',
        'SAND_TEST_RESTORE_MARKER' => $marker,
    ], $guard);
    $unknownBlocked = $unknownStatus !== 0 && !file_exists($marker);

    [$nonEmptyStatus] = $run([
        'SAND_TEST_SOURCE_IDENTITY' => '9001:41:736f75726365',
        'SAND_TEST_RESTORE_IDENTITY' => '9001:42:726573746f7265',
        'SAND_TEST_RESTORE_RELATIONS' => '1',
        'RESTORE_TARGET_IDENTITY' => '9001:42:726573746f7265',
        'SAND_TEST_RESTORE_MARKER' => $marker,
    ], $guard);
    $nonEmptyBlocked = $nonEmptyStatus !== 0 && !file_exists($marker);

    [$warningStatus] = $run([
        'SAND_TEST_SOURCE_IDENTITY' => "NOTICE: read-only identity\n9001:41:736f75726365",
        'SAND_TEST_RESTORE_IDENTITY' => '9002:42:726573746f7265',
        'SAND_TEST_RESTORE_RELATIONS' => '0',
        'RESTORE_TARGET_IDENTITY' => '9002:42:726573746f7265',
        'SAND_TEST_RESTORE_MARKER' => $marker,
    ], $guard);
    $warningBlocked = $warningStatus !== 0 && !file_exists($marker);

    [$invalidApprovalStatus] = $run([
        'SAND_TEST_SOURCE_IDENTITY' => '9001:41:736f75726365',
        'SAND_TEST_RESTORE_IDENTITY' => '9002:42:726573746f7265',
        'SAND_TEST_RESTORE_RELATIONS' => '0',
        'RESTORE_TARGET_IDENTITY' => '9002:42:726573746f7265:extra',
        'SAND_TEST_RESTORE_MARKER' => $marker,
    ], $guard);
    $invalidApprovalBlocked = $invalidApprovalStatus !== 0 && !file_exists($marker);

    $grammarBlocked = true;
    foreach (['0:42:726573746f7265', '01:42:726573746f7265', '18446744073709551616:42:726573746f7265', '9002:4294967296:726573746f7265', '9002:42:f'] as $invalidIdentity) {
        [$grammarStatus] = $run([
            'SAND_TEST_SOURCE_IDENTITY' => '9001:41:736f75726365',
            'SAND_TEST_RESTORE_IDENTITY' => '9002:42:726573746f7265',
            'SAND_TEST_RESTORE_RELATIONS' => '0',
            'RESTORE_TARGET_IDENTITY' => $invalidIdentity,
            'SAND_TEST_RESTORE_MARKER' => $marker,
        ], $guard);
        $grammarBlocked = $grammarBlocked && $grammarStatus !== 0 && !file_exists($marker);
    }

    [$allowedStatus] = $run([
        'SAND_TEST_SOURCE_IDENTITY' => '9001:41:736f75726365',
        'SAND_TEST_RESTORE_IDENTITY' => '9002:42:726573746f7265',
        'SAND_TEST_RESTORE_RELATIONS' => '0',
        'RESTORE_TARGET_IDENTITY' => '9002:42:726573746f7265',
        'SAND_TEST_RESTORE_MARKER' => $marker,
    ], $guard);
    if (!$sameBlocked || !$unknownBlocked || !$nonEmptyBlocked || !$warningBlocked || !$invalidApprovalBlocked || !$grammarBlocked || $allowedStatus !== 0 || !is_file($marker)) {
        throw new RuntimeException('documented restore guard called pg_restore before identity/proof checks completed');
    }
} finally {
    $removeTree = null;
    $removeTree = static function (string $path) use (&$removeTree): void {
        if (is_link($path) || is_file($path)) { unlink($path); return; }
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
        rmdir($path);
    };
    $removeTree($seed);
}

echo "SandIAM restore command guard checks passed\n";
