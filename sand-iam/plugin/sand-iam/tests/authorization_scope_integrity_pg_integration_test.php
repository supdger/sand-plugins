<?php

declare(strict_types=1);

// Requires an already installed disposable SandIAM 0.7.3 schema and two
// applications with fixture objects. It never migrates or commits.
if (getenv('SAND_IAM_RUN_PG_TESTS') !== '1') {
    echo "SKIP: set SAND_IAM_RUN_PG_TESTS=1 with a disposable installed SandIAM 0.7.3 database\n";
    exit(0);
}

$dsn = (string) getenv('SAND_IAM_SCOPE_PG_DSN');
$ids = [];
foreach ([
    'application_a', 'application_b',
    'identity_a', 'identity_b',
    'role_a', 'role_b',
    'user_type_a', 'user_type_b',
    'resource_a', 'resource_b',
] as $name) {
    $ids[$name] = (int) getenv('SAND_IAM_SCOPE_' . strtoupper($name));
}
$fixturePrefix = (string) getenv('SAND_IAM_SCOPE_FIXTURE_PREFIX');
$providedFixtures = min($ids) > 0;
if ($dsn === '' || (!$providedFixtures && preg_match('/^sand_iam_acceptance_[a-z0-9_]{1,32}$/D', $fixturePrefix) !== 1)) {
    echo "SKIP: set scope DSN plus fixture IDs, or a fixed sand_iam_acceptance_ fixture prefix\n";
    exit(0);
}

$pdo = new PDO(
    $dsn,
    (string) getenv('SAND_IAM_SCOPE_PG_USER'),
    (string) getenv('SAND_IAM_SCOPE_PG_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$expectedDatabase = (string) getenv('SAND_IAM_SCOPE_EXPECTED_DATABASE');
$actualDatabase = (string) $pdo->query('SELECT current_database()')->fetchColumn();
if ($expectedDatabase === '' || !hash_equals($expectedDatabase, $actualDatabase)) {
    throw new RuntimeException('authorization scope test connected to an unexpected database');
}

/** @param list<mixed> $parameters */
$insertId = static function (PDO $pdo, string $sql, array $parameters): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
};
$createFixtures = static function (PDO $pdo, string $prefix) use ($insertId): array {
    $organization = $insertId(
        $pdo,
        'INSERT INTO sand_iam_organization (code, name, status) VALUES (?, ?, 1) RETURNING id',
        [$prefix . 'org', '0.7.3 authorization fixture']
    );
    $ids = [];
    foreach (['a', 'b'] as $suffix) {
        $application = $insertId(
            $pdo,
            'INSERT INTO sand_iam_application (organization_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
            [$organization, $prefix . 'app_' . $suffix, '0.7.3 fixture application ' . strtoupper($suffix)]
        );
        $ids['application_' . $suffix] = $application;
        $ids['identity_' . $suffix] = $insertId(
            $pdo,
            'INSERT INTO sand_iam_identity (application_id, code, display_name, status) VALUES (?, ?, ?, 1) RETURNING id',
            [$application, $prefix . 'identity_' . $suffix, '0.7.3 fixture identity ' . strtoupper($suffix)]
        );
        $ids['role_' . $suffix] = $insertId(
            $pdo,
            'INSERT INTO sand_iam_role (application_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
            [$application, $prefix . 'role_' . $suffix, '0.7.3 fixture role ' . strtoupper($suffix)]
        );
        $ids['user_type_' . $suffix] = $insertId(
            $pdo,
            'INSERT INTO sand_iam_user_type (application_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
            [$application, $prefix . 'type_' . $suffix, '0.7.3 fixture type ' . strtoupper($suffix)]
        );
        $ids['resource_' . $suffix] = $insertId(
            $pdo,
            'INSERT INTO sand_iam_resource (application_id, code, name, status) VALUES (?, ?, ?, 1) RETURNING id',
            [$application, $prefix . 'resource_' . $suffix, '0.7.3 fixture resource ' . strtoupper($suffix)]
        );
    }
    return $ids;
};

$expectConstraint = static function (PDO $pdo, callable $operation, string $label): void {
    $pdo->exec('SAVEPOINT sand_iam_scope_negative');
    try {
        $operation();
        throw new RuntimeException("{$label} was accepted");
    } catch (PDOException $exception) {
        if (!in_array((string) $exception->getCode(), ['23503', '23514', '22001'], true)) {
            throw $exception;
        }
    } finally {
        $pdo->exec('ROLLBACK TO SAVEPOINT sand_iam_scope_negative');
        $pdo->exec('RELEASE SAVEPOINT sand_iam_scope_negative');
    }
};

$pdo->beginTransaction();
try {
    if (!$providedFixtures) {
        $ids = $createFixtures($pdo, $fixturePrefix);
    }
    $column = $pdo->query(
        "SELECT character_maximum_length
         FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = 'sand_iam_policy'
           AND column_name = 'action'"
    )->fetchColumn();
    if ((int) $column !== 96) throw new RuntimeException('policy action is not varchar(96)');

    $pdo->prepare(
        'INSERT INTO sand_iam_identity_role (identity_id, role_id, application_id, status)
         VALUES (?, ?, ?, 1)'
    )->execute([$ids['identity_a'], $ids['role_a'], $ids['application_a']]);
    $expectConstraint($pdo, static function () use ($pdo, $ids): void {
        $pdo->prepare(
            'INSERT INTO sand_iam_identity_role (identity_id, role_id, application_id, status)
             VALUES (?, ?, ?, 1)'
        )->execute([$ids['identity_a'], $ids['role_b'], $ids['application_a']]);
    }, 'cross-application identity-role grant');

    $pdo->prepare(
        'INSERT INTO sand_iam_identity_user_type (identity_id, user_type_id, application_id, status)
         VALUES (?, ?, ?, 1)'
    )->execute([$ids['identity_a'], $ids['user_type_a'], $ids['application_a']]);
    $expectConstraint($pdo, static function () use ($pdo, $ids): void {
        $pdo->prepare(
            'INSERT INTO sand_iam_identity_user_type (identity_id, user_type_id, application_id, status)
             VALUES (?, ?, ?, 1)'
        )->execute([$ids['identity_a'], $ids['user_type_b'], $ids['application_a']]);
    }, 'cross-application identity-user-type grant');

    $policySql = <<<'SQL'
INSERT INTO sand_iam_policy
    (application_id, resource_id, role_id, identity_id, action, effect, condition, scope, priority, state, status)
VALUES (?, ?, ?, ?, ?, 'allow', '{}'::jsonb, '{}'::jsonb, 10, 'draft', 1)
RETURNING id
SQL;
    $action96 = str_repeat('a', 96);
    $policyA = $insertId($pdo, $policySql, [
        $ids['application_a'], $ids['resource_a'], $ids['role_a'], null, $action96,
    ]);
    $policyB = $insertId($pdo, $policySql, [
        $ids['application_b'], $ids['resource_b'], $ids['role_b'], null, 'scope.test.b',
    ]);
    $policyAOther = $insertId($pdo, $policySql, [
        $ids['application_a'], $ids['resource_a'], null, $ids['identity_a'], 'scope.test.identity',
    ]);
    $expectConstraint($pdo, static function () use ($pdo, $insertId, $policySql, $ids): void {
        $insertId($pdo, $policySql, [
            $ids['application_a'], $ids['resource_b'], $ids['role_a'], null, 'scope.cross.resource',
        ]);
    }, 'cross-application policy resource');
    $expectConstraint($pdo, static function () use ($pdo, $insertId, $policySql, $ids): void {
        $insertId($pdo, $policySql, [
            $ids['application_a'], $ids['resource_a'], $ids['role_b'], null, 'scope.cross.role',
        ]);
    }, 'cross-application policy role');
    $expectConstraint($pdo, static function () use ($pdo, $insertId, $policySql, $ids): void {
        $insertId($pdo, $policySql, [
            $ids['application_a'], $ids['resource_a'], null, $ids['identity_b'], 'scope.cross.identity',
        ]);
    }, 'cross-application policy identity');

    $versionSql = <<<'SQL'
INSERT INTO sand_iam_policy_version
    (policy_id, application_id, version_no, snapshot, snapshot_hash, rollback_of_version_id, operation, request_id, request_fingerprint)
VALUES (?, ?, ?, '{}'::jsonb, ?, ?, ?, ?, ?)
RETURNING id
SQL;
    $hash = str_repeat('a', 64);
    $versionA = $insertId($pdo, $versionSql, [$policyA, $ids['application_a'], 1, $hash, null, 'publish', 'scope-version-a', $hash]);
    $versionB = $insertId($pdo, $versionSql, [$policyB, $ids['application_b'], 1, $hash, null, 'publish', 'scope-version-b', $hash]);
    $versionAOther = $insertId($pdo, $versionSql, [$policyAOther, $ids['application_a'], 1, $hash, null, 'publish', 'scope-version-a-other', $hash]);
    $insertId($pdo, $versionSql, [$policyA, $ids['application_a'], 2, $hash, $versionA, 'rollback', 'scope-version-a2', $hash]);
    $expectConstraint($pdo, static function () use ($pdo, $insertId, $versionSql, $ids, $policyA, $versionAOther, $hash): void {
        $insertId($pdo, $versionSql, [$policyA, $ids['application_a'], 3, $hash, $versionAOther, 'rollback', 'scope-version-other-policy', $hash]);
    }, 'rollback pointer to another policy in the same application');
    $expectConstraint($pdo, static function () use ($pdo, $insertId, $versionSql, $ids, $policyA, $versionB, $hash): void {
        $insertId($pdo, $versionSql, [$policyA, $ids['application_a'], 3, $hash, $versionB, 'rollback', 'scope-version-cross-app', $hash]);
    }, 'rollback pointer to another application');
    $expectConstraint($pdo, static function () use ($pdo, $insertId, $versionSql, $ids, $policyA, $hash): void {
        $insertId($pdo, $versionSql, [$policyA, $ids['application_b'], 4, $hash, null, 'publish', 'scope-version-app', $hash]);
    }, 'policy version outside its application');

    $pdo->prepare('UPDATE sand_iam_policy SET published_version_id = ? WHERE id = ?')->execute([$versionA, $policyA]);
    $expectConstraint($pdo, static function () use ($pdo, $versionAOther, $policyA): void {
        $pdo->prepare('UPDATE sand_iam_policy SET published_version_id = ? WHERE id = ?')->execute([$versionAOther, $policyA]);
    }, 'published pointer to another policy in the same application');
    $expectConstraint($pdo, static function () use ($pdo, $versionB, $policyA): void {
        $pdo->prepare('UPDATE sand_iam_policy SET published_version_id = ? WHERE id = ?')->execute([$versionB, $policyA]);
    }, 'published pointer to another application');

    echo "authorization scope integrity PostgreSQL integration passed\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
