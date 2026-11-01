-- SandIAM 0.8.0: retain distinct scope checks within one request as append-only
-- events. Existing audit facts are not rewritten; their event_key stays empty.
BEGIN;

LOCK TABLE sand_iam_audit_log, sand_iam_schema_migration IN ACCESS EXCLUSIVE MODE;

DO $$
DECLARE
    recorded record;
    old_unique text;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '043_scope_audit_event_key.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 43
        OR recorded.checksum <> '937a58b5cd1ceb3ada3ae0e0cd2a34166d53db87b60a1c096a5a55dd5c549994'
        OR recorded.package_version <> '0.8.0'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 043 ledger identity conflicts with scope audit events';
    END IF;

    IF recorded.revision IS NULL THEN
        IF (SELECT count(*) FROM sand_iam_schema_migration) <> 43
           OR NOT EXISTS (
               SELECT 1 FROM sand_iam_schema_migration
               WHERE migration_file = '042_permission_menu_hierarchy.pgsql'
                 AND revision = 42
                 AND checksum = '62d79e86170788d40d39b528b604269e439e0d3588c011154c8e12080564fbeb'
                 AND package_version = '0.7.3'
           ) THEN
            RAISE EXCEPTION 'SandIAM migration 043 requires the complete published 001-042 ledger';
        END IF;
        IF EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_audit_log'
              AND column_name = 'event_key'
        ) THEN
            RAISE EXCEPTION 'SandIAM migration 043 refuses an unrecorded audit event_key column';
        END IF;
        SELECT pg_get_constraintdef(oid, true) INTO old_unique
        FROM pg_constraint
        WHERE conrelid = 'sand_iam_audit_log'::regclass
          AND conname = 'uk_sand_iam_audit_log_request_action' AND contype = 'u';
        IF old_unique IS DISTINCT FROM 'UNIQUE (request_id, action)' THEN
            RAISE EXCEPTION 'SandIAM migration 043 requires the exact prior audit uniqueness constraint';
        END IF;
        ALTER TABLE sand_iam_audit_log
            ADD COLUMN event_key varchar(64) NOT NULL DEFAULT '',
            ADD CONSTRAINT ck_sand_iam_audit_log_event_key
                CHECK (event_key = '' OR event_key ~ '^[0-9a-f]{64}$'),
            ADD CONSTRAINT uk_sand_iam_audit_log_request_action_event
                UNIQUE (request_id, action, event_key),
            DROP CONSTRAINT uk_sand_iam_audit_log_request_action;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'sand_iam_audit_log'
          AND column_name = 'event_key' AND data_type = 'character varying'
          AND character_maximum_length = 64 AND is_nullable = 'NO'
          AND column_default = '''''::character varying'
    ) OR NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'sand_iam_audit_log'::regclass
          AND conname = 'ck_sand_iam_audit_log_event_key' AND contype = 'c' AND convalidated
          AND regexp_replace(pg_get_constraintdef(oid, true), '[()[:space:]]', '', 'g')
              = 'CHECKevent_key::text=''''::textORevent_key::text~''^[0-9a-f]{64}$''::text'
    ) OR NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'sand_iam_audit_log'::regclass
          AND conname = 'uk_sand_iam_audit_log_request_action_event' AND contype = 'u'
          AND pg_get_constraintdef(oid, true) = 'UNIQUE (request_id, action, event_key)'
    ) OR EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conrelid = 'sand_iam_audit_log'::regclass
          AND conname = 'uk_sand_iam_audit_log_request_action'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 043 audit event schema fingerprint is incompatible';
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('937a58b5cd1ceb3ada3ae0e0cd2a34166d53db87b60a1c096a5a55dd5c549994'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '043_scope_audit_event_key.pgsql', 43, self_checksum.checksum, '0.8.0', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
BEGIN
    IF (SELECT count(*) FROM sand_iam_schema_migration) <> 44
       OR NOT EXISTS (
           SELECT 1 FROM sand_iam_schema_migration
           WHERE migration_file = '043_scope_audit_event_key.pgsql'
             AND revision = 43 AND checksum = '937a58b5cd1ceb3ada3ae0e0cd2a34166d53db87b60a1c096a5a55dd5c549994' AND package_version = '0.8.0'
       ) THEN
        RAISE EXCEPTION 'SandIAM migration 043 did not close the exact 001-043 ledger';
    END IF;
END $$;

COMMIT;
