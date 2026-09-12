-- SandIAM 0.7.0: bounded cleanup access path for expired authentication
-- throttling state. The cleanup worker remains deployment opt-in.
BEGIN;

DO $$
DECLARE
    matched_columns integer;
BEGIN
    WITH required_column(column_name, data_type, character_maximum_length, is_nullable) AS (
        VALUES
            ('id', 'bigint', NULL::integer, 'NO'),
            ('window_start', 'timestamp without time zone', NULL, 'NO')
    )
    SELECT count(*) INTO matched_columns
    FROM required_column expected
    JOIN information_schema.columns actual
      ON actual.table_schema = current_schema()
     AND actual.table_name = 'sand_iam_auth_rate_limit'
     AND actual.column_name = expected.column_name
     AND actual.data_type = expected.data_type
     AND actual.character_maximum_length IS NOT DISTINCT FROM expected.character_maximum_length
     AND actual.is_nullable = expected.is_nullable;

    IF matched_columns <> 2 THEN
        RAISE EXCEPTION 'SandIAM auth-rate-limit retention requires the exact id and window_start columns';
    END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_sand_iam_auth_rate_limit_retention
    ON sand_iam_auth_rate_limit(window_start, id);

DO $$
DECLARE
    matched_indexes integer;
    index_evidence text;
BEGIN
    SELECT count(*) INTO matched_indexes
    FROM pg_index actual_index
    JOIN pg_class actual_table ON actual_table.oid = actual_index.indrelid
    JOIN pg_namespace actual_schema ON actual_schema.oid = actual_table.relnamespace
    JOIN pg_class index_relation ON index_relation.oid = actual_index.indexrelid
    WHERE actual_schema.nspname = current_schema()
      AND actual_table.relname = 'sand_iam_auth_rate_limit'
      AND index_relation.relname = 'idx_sand_iam_auth_rate_limit_retention'
      AND NOT actual_index.indisunique
      AND actual_index.indisvalid
      AND actual_index.indisready
      AND actual_index.indoption::text = '0 0'
      AND pg_get_indexdef(actual_index.indexrelid, 1, true) = 'window_start'
      AND pg_get_indexdef(actual_index.indexrelid, 2, true) = 'id'
      AND pg_get_indexdef(actual_index.indexrelid, 3, true) IS NULL;

    IF matched_indexes <> 1 THEN
        SELECT pg_get_indexdef(actual_index.indexrelid)
        INTO index_evidence
        FROM pg_index actual_index
        JOIN pg_class index_relation ON index_relation.oid = actual_index.indexrelid
        JOIN pg_namespace actual_schema ON actual_schema.oid = index_relation.relnamespace
        WHERE actual_schema.nspname = current_schema()
          AND index_relation.relname = 'idx_sand_iam_auth_rate_limit_retention';
        RAISE EXCEPTION 'SandIAM auth-rate-limit retention index fingerprint is incompatible (actual %)', index_evidence;
    END IF;
END $$;

DO $$
DECLARE recorded record;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration WHERE migration_file = '038_auth_rate_limit_retention.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 38
        OR recorded.checksum <> '2765bed31eb3a8c5e894c0758e80120f6926f90b4f5a1a9ea39609dd608fe2c3'
        OR recorded.package_version <> '0.7.0'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 038 ledger identity conflicts with auth-rate-limit retention';
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('2765bed31eb3a8c5e894c0758e80120f6926f90b4f5a1a9ea39609dd608fe2c3'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '038_auth_rate_limit_retention.pgsql', 38, self_checksum.checksum, '0.7.0', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '038_auth_rate_limit_retention.pgsql'
      AND revision = 38
      AND checksum = '2765bed31eb3a8c5e894c0758e80120f6926f90b4f5a1a9ea39609dd608fe2c3'
      AND package_version = '0.7.0';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 39 THEN
        RAISE EXCEPTION 'SandIAM migration 038 did not close the exact 001-038 ledger';
    END IF;
END $$;

COMMIT;
