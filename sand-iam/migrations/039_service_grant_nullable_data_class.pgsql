-- SandIAM 0.7.2: align the persisted service-grant data class with the
-- public contract, where a null value means that the grant does not impose
-- an additional data classification constraint.
BEGIN;

DO $$
DECLARE
    matched_columns integer;
BEGIN
    SELECT count(*) INTO matched_columns
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'sand_iam_service_grant'
      AND column_name = 'data_class'
      AND data_type = 'character varying'
      AND character_maximum_length = 32
      AND is_nullable IN ('YES', 'NO')
      AND column_default = '''internal''::character varying';

    IF matched_columns <> 1 THEN
        RAISE EXCEPTION 'SandIAM service-grant data-class migration requires the exact varchar(32) column with the internal default';
    END IF;
END $$;

ALTER TABLE sand_iam_service_grant
    ALTER COLUMN data_class DROP NOT NULL;

DO $$
DECLARE
    matched_columns integer;
BEGIN
    SELECT count(*) INTO matched_columns
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'sand_iam_service_grant'
      AND column_name = 'data_class'
      AND data_type = 'character varying'
      AND character_maximum_length = 32
      AND is_nullable = 'YES'
      AND column_default = '''internal''::character varying';

    IF matched_columns <> 1 THEN
        RAISE EXCEPTION 'SandIAM service-grant data-class migration did not preserve the nullable varchar(32) column and internal default';
    END IF;
END $$;

DO $$
DECLARE recorded record;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '039_service_grant_nullable_data_class.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 39
        OR recorded.checksum <> 'c6b0c315da73bb6e5e6596c00f09e8c4494db07c790a40f70c5882c0fff3ad53'
        OR recorded.package_version <> '0.7.2'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 039 ledger identity conflicts with nullable service-grant data class';
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('c6b0c315da73bb6e5e6596c00f09e8c4494db07c790a40f70c5882c0fff3ad53'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '039_service_grant_nullable_data_class.pgsql', 39, self_checksum.checksum, '0.7.2', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '039_service_grant_nullable_data_class.pgsql'
      AND revision = 39
      AND checksum = 'c6b0c315da73bb6e5e6596c00f09e8c4494db07c790a40f70c5882c0fff3ad53'
      AND package_version = '0.7.2';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 40 THEN
        RAISE EXCEPTION 'SandIAM migration 039 did not close the exact 001-039 ledger';
    END IF;
END $$;

COMMIT;
