<?php
declare(strict_types=1);

namespace plugin\sandworkflow\app\support;

use RuntimeException;
use support\Db;

final class RuntimeSchemaGuard
{
    /** @var list<string> */
    private const RUNTIME_TABLES = [
        'sand_workflow_definition_version',
        'sand_workflow_instance',
        'sand_workflow_task',
        'sand_workflow_task_assignee',
        'sand_workflow_log',
    ];

    public static function ensureFormChangeAuditColumn(string $connection): void
    {
        $columns = Db::connection($connection)
            ->select("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'sand_workflow_log' AND column_name = 'form_change_snapshot'");
        if ($columns !== []) {
            return;
        }
        Db::connection($connection)->statement(
            'ALTER TABLE "sand_workflow_log" '
            . 'ADD COLUMN IF NOT EXISTS "form_change_snapshot" jsonb NULL'
        );
    }

    public static function assertNanoIdSchema(string $connection): void
    {
        foreach (self::RUNTIME_TABLES as $table) {
            $column = Db::connection($connection)->select("SELECT data_type, character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{$table}' AND column_name = 'id'");
            if ($column === []) {
                continue;
            }
            $type = strtolower((string) ($column[0]->data_type ?? ''));
            $length = (int) ($column[0]->character_maximum_length ?? 0);
            if ($type !== 'character' || $length !== 21) {
                throw new RuntimeException(
                    "{$table} 仍为旧整数主键；请先按 migrations/20260723-runtime-id-migration.md 完成保数迁移"
                );
            }
        }
    }
}
