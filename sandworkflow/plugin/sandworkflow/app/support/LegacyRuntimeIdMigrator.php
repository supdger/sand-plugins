<?php

declare(strict_types=1);

namespace plugin\sandworkflow\app\support;

use RuntimeException;
use support\Db;

/**
 * The PostgreSQL package begins with NanoID runtime primary keys.  A legacy
 * integer schema can only have come from an unsupported pre-adaptation build,
 * so stop before applying the old MySQL shadow-table migration to PostgreSQL.
 */
final class LegacyRuntimeIdMigrator
{
    public static function migrate(string $connection, string $suffix): void
    {
        unset($suffix);
        foreach (['sand_workflow_definition_version', 'sand_workflow_instance', 'sand_workflow_task', 'sand_workflow_task_assignee', 'sand_workflow_log'] as $table) {
            $columns = Db::connection($connection)->select(
                "SELECT data_type, character_maximum_length FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '{$table}' AND column_name = 'id'"
            );
            if ($columns === []) {
                continue;
            }
            $type = strtolower((string) ($columns[0]->data_type ?? ''));
            $length = (int) ($columns[0]->character_maximum_length ?? 0);
            if ($type !== 'character' || $length !== 21) {
                throw new RuntimeException("{$table} uses a legacy integer primary key. Install the PostgreSQL package into a new schema; cross-engine data migration must be performed separately.");
            }
        }
    }
}
