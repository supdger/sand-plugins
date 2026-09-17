<?php

declare(strict_types=1);

// Requires an already installed disposable SandIAM 0.7.2 schema. Every write
// and every DDL statement is enclosed in a transaction that this test rolls
// back. It never installs, commits, or rewrites the migration ledger.
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') {
    echo "SKIP: set SAND_IAM_RUN_PG_TESTS=1 with a disposable installed SandIAM 0.7.2 database\n";
    exit(0);
}

$dsn = (string) getenv('SAND_IAM_SCOPE_MIGRATION_PG_DSN');
$identityA = (int) getenv('SAND_IAM_SCOPE_MIGRATION_IDENTITY_A');
$roleA = (int) getenv('SAND_IAM_SCOPE_MIGRATION_ROLE_A');
$roleB = (int) getenv('SAND_IAM_SCOPE_MIGRATION_ROLE_B');
$fixturePrefix = (string) getenv('SAND_IAM_SCOPE_MIGRATION_FIXTURE_PREFIX');
$providedFixtures = min($identityA, $roleA, $roleB) > 0;
if ($dsn === '' || (!$providedFixtures && preg_match('/^sand_iam_acceptance_[a-z0-9_]{1,32}$/D', $fixturePrefix) !== 1)) {
    echo "SKIP: set migration DSN plus fixture IDs, or a fixed sand_iam_acceptance_ fixture prefix\n";
    exit(0);
}

$pdo = new PDO(
    $dsn,
    (string) getenv('SAND_IAM_SCOPE_MIGRATION_PG_USER'),
    (string) getenv('SAND_IAM_SCOPE_MIGRATION_PG_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$expectedDatabase = (string) getenv('SAND_IAM_SCOPE_MIGRATION_EXPECTED_DATABASE');
$actualDatabase = (string) $pdo->query('SELECT current_database()')->fetchColumn();
if ($expectedDatabase === '' || !hash_equals($expectedDatabase, $actualDatabase)) {
    throw new RuntimeException('migration rollback test connected to an unexpected database');
}
$root = dirname(__DIR__, 3);
$update = (string) file_get_contents($root . '/update.sql');
$begin = strpos($update, "\nBEGIN;");
$commit = strrpos($update, "\nCOMMIT;");
if ($begin === false || $commit === false || $begin >= $commit) {
    throw new RuntimeException('generated update has no composable outer transaction');
}
$body = substr($update, $begin + strlen("\nBEGIN;"), $commit - ($begin + strlen("\nBEGIN;")));

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $statement = $pdo->prepare(
        'SELECT count(*) FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
    );
    $statement->execute([$table, $column]);
    return (int) $statement->fetchColumn() === 1;
};
$ledger041 = static fn (PDO $pdo): int => (int) $pdo
    ->query("SELECT count(*) FROM sand_iam_schema_migration WHERE migration_file = '041_authorization_scope_integrity.pgsql'")
    ->fetchColumn();
$insertId = static function (PDO $pdo, string $sql, array $parameters): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
};
$createFixtures = static function (PDO $pdo, string $prefix) use ($insertId): array {
    $organization = $insertId(
        $pdo,
        'INSERT INTO sand_iam_organization (code, name, status) VALUES (?, ?, 1) RETURNING id',
        [$prefix . 'org', '0.7.3 migration rollback fixture']
    );
    $applicationA = $insertId(
        $pdo,
        'INSERT INTO sand_iam_application (organization_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
        [$organization, $prefix . 'app_a', '0.7.3 fixture A']
    );
    $applicationB = $insertId(
        $pdo,
        'INSERT INTO sand_iam_application (organization_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
        [$organization, $prefix . 'app_b', '0.7.3 fixture B']
    );
    $identity = $insertId(
        $pdo,
        'INSERT INTO sand_iam_identity (application_id, code, display_name, status) VALUES (?, ?, ?, 1) RETURNING id',
        [$applicationA, $prefix . 'identity_a', '0.7.3 fixture identity']
    );
    $sameRole = $insertId(
        $pdo,
        'INSERT INTO sand_iam_role (application_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
        [$applicationA, $prefix . 'role_a', '0.7.3 fixture role A']
    );
    $foreignRole = $insertId(
        $pdo,
        'INSERT INTO sand_iam_role (application_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
        [$applicationB, $prefix . 'role_b', '0.7.3 fixture role B']
    );
    return [$identity, $sameRole, $foreignRole];
};

if ($columnExists($pdo, 'sand_iam_identity_role', 'application_id') || $ledger041($pdo) !== 0) {
    throw new RuntimeException('migration rollback test requires an exact pre-041 SandIAM 0.7.2 schema');
}

// A cross-application row must make 041 fail before any DDL or ledger change
// becomes durable. The conflicting fixture is rolled back with the migration.
$pdo->beginTransaction();
try {
    [$testIdentityA, $testRoleA, $testRoleB] = $providedFixtures
        ? [$identityA, $roleA, $roleB]
        : $createFixtures($pdo, $fixturePrefix);
    $statement = $pdo->prepare(
        'INSERT INTO sand_iam_identity_role (identity_id, role_id, status, delete_time)
         VALUES (?, ?, 1, CURRENT_TIMESTAMP)'
    );
    $statement->execute([$testIdentityA, $testRoleB]);
    try {
        $pdo->exec($body);
        throw new RuntimeException('041 accepted a cross-application preexisting grant');
    } catch (PDOException $exception) {
        if (!str_contains($exception->getMessage(), 'cross-application identity-role rows')) {
            throw $exception;
        }
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
if ($columnExists($pdo, 'sand_iam_identity_role', 'application_id') || $ledger041($pdo) !== 0) {
    throw new RuntimeException('failed 041 left a column or ledger row behind');
}

// The same generated update must accept clean data, install the target shape,
// allow a same-application grant, reject a foreign role, and still roll back
// all DDL and DML when the test ends.
$pdo->beginTransaction();
try {
    [$testIdentityA, $testRoleA, $testRoleB] = $providedFixtures
        ? [$identityA, $roleA, $roleB]
        : $createFixtures($pdo, $fixturePrefix);
    $pdo->exec($body);
    if (!$columnExists($pdo, 'sand_iam_identity_role', 'application_id')
        || !$columnExists($pdo, 'sand_iam_identity_user_type', 'application_id')
        || $ledger041($pdo) !== 1) {
        throw new RuntimeException('041 did not install its columns and exact ledger row');
    }
    $applicationId = (int) $pdo->query(
        'SELECT application_id FROM sand_iam_identity WHERE id = ' . $testIdentityA
    )->fetchColumn();
    $statement = $pdo->prepare(
        'INSERT INTO sand_iam_identity_role (identity_id, role_id, application_id, status)
         VALUES (?, ?, ?, 1)'
    );
    $statement->execute([$testIdentityA, $testRoleA, $applicationId]);
    $pdo->exec('SAVEPOINT sand_iam_scope_migration_negative');
    try {
        $statement->execute([$testIdentityA, $testRoleB, $applicationId]);
        throw new RuntimeException('post-041 cross-application grant was accepted');
    } catch (PDOException $exception) {
        if ((string) $exception->getCode() !== '23503') throw $exception;
    } finally {
        $pdo->exec('ROLLBACK TO SAVEPOINT sand_iam_scope_migration_negative');
        $pdo->exec('RELEASE SAVEPOINT sand_iam_scope_migration_negative');
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

if ($columnExists($pdo, 'sand_iam_identity_role', 'application_id') || $ledger041($pdo) !== 0) {
    throw new RuntimeException('successful migration rehearsal was not rolled back');
}

echo "authorization scope migration PostgreSQL rollback rehearsal passed\n";
