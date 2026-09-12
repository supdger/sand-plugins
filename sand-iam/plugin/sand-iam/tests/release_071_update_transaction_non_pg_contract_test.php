<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$project = dirname($root);
$executor = $project . '/sandadmin-demo-host/server/plugin/sandpackage/app/service/PostgresLifecycleSqlExecutor.php';

if (!is_file($executor)) {
    throw new RuntimeException('SandPackage PostgreSQL lifecycle executor is unavailable for this contract');
}
require_once $executor;

use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;

/** @param bool $condition */
function release071Assert(string $label, bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException("release 0.7.1 transaction contract failed: {$label}");
    }
    echo "[PASS] {$label}\n";
}

final class Release071RecordingPdo
{
    /** @var list<string> */
    public array $executed = [];

    /** @param null|callable(string):bool $fails */
    public function __construct(private $fails = null)
    {
    }

    public function exec(string $sql): int|false
    {
        $statement = trim($sql);
        $this->executed[] = $statement;
        return is_callable($this->fails) && ($this->fails)($statement) ? false : 1;
    }
}

/** @param list<string> $statements */
function release071TransactionCommands(array $statements): array
{
    $commands = [];
    foreach ($statements as $statement) {
        $sql = ltrim($statement);
        while (str_starts_with($sql, '--')) {
            $newline = strcspn($sql, "\r\n");
            $sql = ltrim(substr($sql, $newline));
        }
        if (in_array($sql, ['BEGIN', 'COMMIT', 'ROLLBACK'], true)) {
            $commands[] = $sql;
        }
    }

    return $commands;
}

/** @param list<string> $statements */
function release071Contains(array $statements, callable $predicate): bool
{
    foreach ($statements as $statement) {
        if ($predicate($statement)) {
            return true;
        }
    }

    return false;
}

$migration = $root . '/migrations/038_auth_rate_limit_retention.pgsql';
$packageMigration = $root . '/plugin/sand-iam/migrations/038_auth_rate_limit_retention.pgsql';
$update = $root . '/update.sql';
$packageUpdate = $root . '/plugin/sand-iam/update.sql';
$published038Hash = 'ff8af3eec4ac77567d671ad2183dd8f88e830e0f1ca4af6157c7a01681f2b571';

release071Assert(
    '038 source remains byte-identical in the root and package mirrors',
    hash_file('sha256', $migration) === $published038Hash
    && hash_file('sha256', $packageMigration) === $published038Hash
);
release071Assert('generated update payload remains root/package identical', hash_file('sha256', $update) === hash_file('sha256', $packageUpdate));

$executorInstance = new PostgresLifecycleSqlExecutor();
$success = new Release071RecordingPdo();
$executorInstance->executeFile($update, $success);
release071Assert(
    '0.7.1 update executes through the real executor with one explicit transaction and no nesting',
    release071TransactionCommands($success->executed) === ['BEGIN', 'COMMIT']
);

$preflightFailure = new Release071RecordingPdo(
    static fn (string $statement): bool => str_contains($statement, 'requires exact 001-037 ledger identities before executing 038')
);
try {
    $executorInstance->executeFile($update, $preflightFailure);
    throw new RuntimeException('preflight failure was accepted');
} catch (RuntimeException) {
    release071Assert(
        'preflight failure rolls back before any 038 body statement executes',
        release071TransactionCommands($preflightFailure->executed) === ['BEGIN', 'ROLLBACK']
        && !release071Contains($preflightFailure->executed, static fn (string $statement): bool => str_contains($statement, 'idx_sand_iam_auth_rate_limit_retention'))
    );
}

$migrationFailure = new Release071RecordingPdo(
    static fn (string $statement): bool => str_contains($statement, 'CREATE INDEX IF NOT EXISTS idx_sand_iam_auth_rate_limit_retention')
);
try {
    $executorInstance->executeFile($update, $migrationFailure);
    throw new RuntimeException('038 failure was accepted');
} catch (RuntimeException) {
    release071Assert(
        '038 body failure rolls back the shared preflight and migration transaction',
        release071TransactionCommands($migrationFailure->executed) === ['BEGIN', 'ROLLBACK']
        && release071Contains($migrationFailure->executed, static fn (string $statement): bool => str_contains($statement, 'requires exact 001-037 ledger identities before executing 038'))
    );
}

echo "SandIAM 0.7.1 update transaction non-PG contract passed\n";
