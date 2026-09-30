<?php

declare(strict_types=1);

// CI-only real PostgreSQL regression. The caller provisions a disposable database.
// This script neither creates a database nor starts or reloads a service.
$options = getopt('', ['executor:', 'host-schema:', 'baseline:', 'candidate:', 'database:', 'host:', 'port:', 'user:', 'output:']);
foreach (['executor', 'host-schema', 'baseline', 'candidate', 'database', 'host', 'port', 'user', 'output'] as $name) {
    if (!isset($options[$name]) || !is_string($options[$name]) || $options[$name] === '') {
        throw new RuntimeException('Missing --' . $name);
    }
}
if ($options['database'] !== 'iam076_cross_platform' || $options['host'] !== '127.0.0.1'
    || !ctype_digit($options['port']) || !in_array((int) $options['port'], [55476, 25477], true)) {
    throw new RuntimeException('Only the explicitly isolated CI database and loopback test ports are allowed');
}
$inputs = [
    'executor' => [$options['executor'], 'eaf465917f2613029f4239a78a25951187f05396cd2a73e9d730de3f0de041c4'],
    'host_schema' => [$options['host-schema'], '428eed64a47cadff565e19bef5f3c41f7f924d0d1a9ccabbba2bf1817aa4fc57'],
    'baseline_install' => [rtrim($options['baseline'], '/\\') . '/install.sql', 'b256b214f74b4da966a2916fd2a853e4e002956e466928e2d148aa9ba14ff115'],
    'candidate_update' => [rtrim($options['candidate'], '/\\') . '/update.sql', 'c930ea5e9abeb8317e2aed653d81a8fd6852c38fd3c9ee95c2cd460c6251f6bf'],
];
foreach ($inputs as $name => [$path, $sha256]) {
    if (!is_file($path) || hash_file('sha256', $path) !== $sha256) {
        throw new RuntimeException('Fixed input digest mismatch: ' . $name);
    }
}
require $options['executor'];
use plugin\sandpackage\app\service\PostgresLifecycleSqlExecutor;
$pdo = new PDO('pgsql:host=127.0.0.1;port=' . $options['port'] . ';dbname=' . $options['database'],
    $options['user'], getenv('PGPASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'iam076_cross_platform') {
    throw new RuntimeException('Database identity mismatch');
}
$pdo->exec("SET lock_timeout='3s'; SET statement_timeout='90s'");
if ((int) $pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname='public'")->fetchColumn() !== 0) {
    throw new RuntimeException('The disposable database must be empty; no existing database is modified');
}
function pass(string $message): void { echo '[PASS] ' . $message . PHP_EOL; }
function assertTest(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    pass($message);
}
function snapshot(PDO $pdo): array {
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    $data = []; $counts = [];
    foreach ($tables as $name) {
        $quoted = '"' . str_replace('"', '""', $name) . '"';
        $rows = $pdo->query("SELECT to_jsonb(t)::text FROM $quoted t ORDER BY to_jsonb(t)::text")->fetchAll(PDO::FETCH_COLUMN);
        $data[$name] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $counts[$name] = count($rows);
    }
    $structure = [];
    foreach ([
        "SELECT table_name,column_name,data_type,udt_name,is_nullable,column_default,character_maximum_length,numeric_precision,numeric_scale,datetime_precision,is_identity,identity_generation FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position",
        "SELECT t.relname,c.conname,c.contype,pg_get_constraintdef(c.oid,true) FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname='public' ORDER BY t.relname,c.conname",
        "SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname",
        "SELECT sequencename,data_type,start_value,min_value,max_value,increment_by,cycle,cache_size,last_value FROM pg_sequences WHERE schemaname='public' ORDER BY sequencename",
    ] as $sql) $structure[] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return ['table_count' => count($tables), 'ledger_rows' => $counts['sand_iam_schema_migration'] ?? null,
        'row_counts' => $counts, 'table_data_sha256' => $data,
        'data_sha256' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)),
        'schema_and_sequences_sha256' => hash('sha256', json_encode($structure, JSON_THROW_ON_ERROR))];
}
final class ObservedConnection {
    public array $readonly = [];
    public function __construct(private PDO $pdo) {}
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }
    public function exec(string $sql): int|false {
        if (preg_match('/\ADO\s+\$/i', ltrim($sql))) {
            $this->readonly[] = $this->pdo->query('SHOW transaction_read_only')->fetchColumn();
        }
        return $this->pdo->exec($sql);
    }
}
function rejection(callable $call, string $expectedState): string {
    try { $call(); } catch (Throwable $error) {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException && (string) $cause->getCode() === $expectedState) {
                echo '[EXPECTED REJECTION] SQLSTATE ' . $expectedState . PHP_EOL;
                return $expectedState;
            }
        }
        throw new RuntimeException('Unexpected rejection cause', 0, $error);
    }
    throw new RuntimeException('Expected PostgreSQL rejection was not observed');
}
$executor = new PostgresLifecycleSqlExecutor();
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iam076-sql-' . bin2hex(random_bytes(6));
if (!mkdir($work, 0700)) throw new RuntimeException('Cannot create temporary SQL fixtures');
$cases = [];
try {
    echo '[STAGE] initialize exact published073 schema using public019 executor' . PHP_EOL;
    $executor->executeFile($inputs['host_schema'][0], $pdo);
    $executor->executeFile($inputs['baseline_install'][0], $pdo);
    $baseline = snapshot($pdo);
    assertTest($baseline['ledger_rows'] === 43, 'published073 fixture has all43 ledger rows');
    $source = file_get_contents($inputs['candidate_update'][0]);
    if (!is_string($source)) throw new RuntimeException('Unreadable update SQL');
    assertTest(count(PostgresLifecycleSqlExecutor::split($source)) === 3, 'BEGIN / complete dollar-quoted DO / COMMIT split as three statements');
    foreach (['original' => $source, 'crlf' => str_replace("\n", "\r\n", str_replace("\r\n", "\n", $source))] as $format => $sql) {
        $file = $work . DIRECTORY_SEPARATOR . $format . '.sql'; file_put_contents($file, $sql);
        $connection = new ObservedConnection($pdo);
        echo '[STAGE] actual019 executor with ' . $format . ' candidate SQL' . PHP_EOL;
        $executor->executeFile($file, $connection);
        $after = snapshot($pdo);
        assertTest($connection->readonly === ['on'], $format . ' DO runs in PostgreSQL READ ONLY transaction');
        assertTest($baseline === $after, $format . ' preserves every table/schema/data/sequence digest');
        $cases[$format] = ['status' => 'pass', 'sql_sha256' => hash('sha256', $sql), 'readonly' => $connection->readonly, 'before' => $baseline, 'after' => $after];
    }
    $bomFile = $work . DIRECTORY_SEPARATOR . 'bom.sql'; file_put_contents($bomFile, "\xEF\xBB\xBF" . $source);
    $state = rejection(fn() => $executor->executeFile($bomFile, $pdo), '42601');
    assertTest(snapshot($pdo) === $baseline && !$pdo->inTransaction(), 'unsupported UTF8 BOM rejects syntax without mutation or an open transaction');
    $cases['bom'] = ['status' => 'expected_rejection', 'sqlstate' => $state, 'before' => $baseline, 'after' => snapshot($pdo)];
    $row = $pdo->query("SELECT * FROM sand_iam_schema_migration WHERE migration_file='042_permission_menu_hierarchy.pgsql'")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('Missing fixture042 row');
    foreach (['missing', 'conflict'] as $mode) {
        echo '[STAGE] ' . $mode . ' ledger rejection' . PHP_EOL;
        try {
            if ($mode === 'missing') $pdo->exec("DELETE FROM sand_iam_schema_migration WHERE migration_file='042_permission_menu_hierarchy.pgsql'");
            else $pdo->exec("UPDATE sand_iam_schema_migration SET checksum=repeat('0',64) WHERE migration_file='042_permission_menu_hierarchy.pgsql'");
            $before = snapshot($pdo);
            $state = rejection(fn() => $executor->executeFile($inputs['candidate_update'][0], $pdo), 'P0001');
            $after = snapshot($pdo);
            assertTest($before === $after && !$pdo->inTransaction(), $mode . ' ledger rejects without DB changes or an open transaction');
            $cases[$mode] = ['status' => 'expected_rejection', 'sqlstate' => $state, 'before' => $before, 'after' => $after];
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($mode === 'missing') {
                $columns = array_keys($row);
                $restore = $pdo->prepare('INSERT INTO sand_iam_schema_migration (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
                $restore->execute(array_values($row));
            } else {
                $restore = $pdo->prepare('UPDATE sand_iam_schema_migration SET checksum=? WHERE migration_file=?');
                $restore->execute([$row['checksum'], $row['migration_file']]);
            }
        }
        assertTest(snapshot($pdo) === $baseline, $mode . ' fixture restored exactly');
    }
    $readonlyFile = $work . DIRECTORY_SEPARATOR . 'readonly-enforcement.sql';
    file_put_contents($readonlyFile, 'BEGIN TRANSACTION READ ONLY; CREATE TABLE iam076_forbidden_write(id integer); COMMIT;');
    $state = rejection(fn() => $executor->executeFile($readonlyFile, $pdo), '25006');
    assertTest(snapshot($pdo) === $baseline && !$pdo->inTransaction(), 'PostgreSQL enforces READ ONLY and rolls back forbidden write');
    $cases['readonly_enforcement'] = ['status' => 'expected_rejection', 'sqlstate' => $state];
    $literalFile = $work . DIRECTORY_SEPARATOR . 'literal-separators.sql';
    file_put_contents($literalFile, "BEGIN READ ONLY; DO \$probe\$ BEGIN IF '; : / \\' <> '; : / \\' THEN RAISE EXCEPTION 'literal mismatch'; END IF; END \$probe\$; COMMIT;");
    $executor->executeFile($literalFile, $pdo);
    assertTest(snapshot($pdo) === $baseline, 'semicolon/colon/slash/backslash inside dollar-quoted SQL preserve bytes and do not split the body');
    $cases['literal_separators'] = ['status' => 'pass'];
    $report = ['status' => 'pass', 'platform' => PHP_OS_FAMILY, 'php_version' => PHP_VERSION, 'postgresql_version' => $pdo->query('SHOW server_version')->fetchColumn(),
        'source' => ['public073_zip_sha256' => 'a3648de10a47c1de263a24c5db5885c0199ab76efd28a1f41fcb7a99e92954ed',
            'public076_zip_sha256' => '7a04aff8a3fa6c9cd3ea537f459f40fc739cb7266c204f9aa46e37a6026814c5',
            'public019_reference' => '3d5a25363ff75298b7e2fa4689984cb8ac89a287', 'file_sha256' => array_map(fn($input) => $input[1], $inputs)],
        'database' => $options['database'], 'baseline' => $baseline, 'cases' => $cases,
        'scope' => 'native executor/SQL PostgreSQL regression; no HTTP manager, user Windows machine, frontend activation or production claim'];
    if (file_put_contents($options['output'], json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL) === false) throw new RuntimeException('Cannot write evidence');
    echo '[COMPLETE] all actualSQL cases pass; evidence=' . $options['output'] . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach (glob($work . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $file) unlink($file);
    rmdir($work);
}
