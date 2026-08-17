<?php

namespace plugin\sandworkflow\api;

use support\Db;
use plugin\sandworkflow\app\support\RuntimeSchemaGuard;
use plugin\sandworkflow\app\support\LegacyRuntimeIdMigrator;

class Install
{
    protected static string $connection = 'pgsql';

    public static function install($version): void
    {
        static::importSql(__DIR__ . '/../install.sql');
    }

    public static function uninstall($version): void
    {
        static::importSql(__DIR__ . '/../uninstall.sql');
    }

    public static function update($fromVersion, $toVersion, $context = null): void
    {
        static::importSql(__DIR__ . '/../update.sql');
        RuntimeSchemaGuard::ensureFormChangeAuditColumn(static::$connection);
        LegacyRuntimeIdMigrator::migrate(static::$connection, date('YmdHis'));
        RuntimeSchemaGuard::assertNanoIdSchema(static::$connection);
    }

    public static function importSql(string $sqlFile): void
    {
        if (!is_file($sqlFile)) {
            throw new \RuntimeException("PostgreSQL schema file does not exist: {$sqlFile}");
        }
        foreach (explode(';', (string) file_get_contents($sqlFile)) as $sql) {
            $sql = trim($sql);
            if ($sql === '') {
                continue;
            }
            Db::connection(static::$connection)->statement($sql);
        }
    }
}
