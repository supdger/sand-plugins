<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Saithink\Saipackage\service\Server;

function failLifecycleRunner(string $message): never
{
    fwrite(STDERR, "SandIAM lifecycle runner refused: {$message}\n");
    exit(1);
}

if (PHP_SAPI !== 'cli') {
    failLifecycleRunner('CLI execution is required');
}

if ($argc !== 4) {
    failLifecycleRunner('usage: php run-sandpackage-lifecycle.php <host-root> <acceptance-database> <install|update|uninstall.sql>');
}

$hostRoot = realpath((string) $argv[1]);
$databaseName = (string) $argv[2];
$sqlFile = realpath((string) $argv[3]);
$sandIamRoot = dirname(__DIR__);

if ($hostRoot === false || !is_file($hostRoot . '/vendor/autoload.php') || !is_file($hostRoot . '/support/bootstrap.php')) {
    failLifecycleRunner('the SandAdmin host root is invalid');
}

if (preg_match('/^sand_iam_acceptance_[a-z0-9_]{1,48}$/D', $databaseName) !== 1) {
    failLifecycleRunner('database name must use the sand_iam_acceptance_ prefix');
}

$allowedSqlFiles = [];
foreach (['install.sql', 'update.sql', 'uninstall.sql'] as $name) {
    $allowedPath = realpath($sandIamRoot . '/' . $name);
    if ($allowedPath !== false) {
        $allowedSqlFiles[] = $allowedPath;
    }
}
if ($sqlFile === false || !in_array($sqlFile, $allowedSqlFiles, true)) {
    failLifecycleRunner('only SandIAM root lifecycle SQL is allowed');
}

chdir($hostRoot);
require $hostRoot . '/vendor/autoload.php';
require $hostRoot . '/support/bootstrap.php';

$connectionConfig = config('database.connections.pgsql', []);
if (!is_array($connectionConfig) || ($connectionConfig['driver'] ?? null) !== 'pgsql') {
    failLifecycleRunner('the host pgsql connection is unavailable');
}
$connectionConfig['database'] = $databaseName;

// Do not trust DB_NAME here. The host bootstrap loads its own .env and may
// replace process-level values. Install a dedicated global connection first,
// then verify PostgreSQL's actual target before the importer sees any SQL.
$capsule = new Capsule();
$capsule->addConnection($connectionConfig, 'pgsql');
$capsule->getDatabaseManager()->setDefaultConnection('pgsql');
$capsule->setAsGlobal();
$capsule->bootEloquent();

$target = $capsule->getConnection('pgsql')->selectOne('SELECT current_database() AS database_name');
$actualDatabase = is_object($target) ? (string) ($target->database_name ?? '') : '';
if ($actualDatabase !== $databaseName) {
    failLifecycleRunner("connected database {$actualDatabase} does not match requested acceptance database");
}

Server::importSql($sqlFile);
fwrite(STDOUT, "SandIAM lifecycle imported into {$actualDatabase}: " . basename($sqlFile) . "\n");
