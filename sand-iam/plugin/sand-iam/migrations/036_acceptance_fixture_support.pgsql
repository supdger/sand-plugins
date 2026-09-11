-- SandIAM 0.7.0: controlled acceptance-fixture cleanup support.
-- This migration does not grant either permission to an existing role. It
-- also keeps audit rows while allowing their fixture parents to be purged.
BEGIN;

DO $$
DECLARE
    nullable_columns integer;
    organization_fk text;
    application_fk text;
    ledger_rows integer;
    migration_row record;
BEGIN
    SELECT count(*) INTO nullable_columns
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'sand_iam_audit_log'
      AND column_name IN ('organization_id', 'application_id')
      AND data_type = 'bigint'
      AND is_nullable = 'YES';
    IF nullable_columns <> 2 THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 cannot enable acceptance cleanup: nullable audit ownership columns are incompatible';
    END IF;

    SELECT pg_get_constraintdef(constraint_row.oid, true) INTO organization_fk
    FROM pg_constraint constraint_row
    JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = current_schema()
      AND table_row.relname = 'sand_iam_audit_log'
      AND constraint_row.conname = 'sand_iam_audit_log_organization_id_fkey'
      AND constraint_row.contype = 'f';
    SELECT pg_get_constraintdef(constraint_row.oid, true) INTO application_fk
    FROM pg_constraint constraint_row
    JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = current_schema()
      AND table_row.relname = 'sand_iam_audit_log'
      AND constraint_row.conname = 'sand_iam_audit_log_application_id_fkey'
      AND constraint_row.contype = 'f';
    IF organization_fk NOT IN (
        'FOREIGN KEY (organization_id) REFERENCES sand_iam_organization(id) ON DELETE RESTRICT',
        'FOREIGN KEY (organization_id) REFERENCES sand_iam_organization(id) ON DELETE SET NULL'
    ) OR application_fk NOT IN (
        'FOREIGN KEY (application_id) REFERENCES sand_iam_application(id) ON DELETE RESTRICT',
        'FOREIGN KEY (application_id) REFERENCES sand_iam_application(id) ON DELETE SET NULL'
    ) THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 cannot enable acceptance cleanup: audit ownership foreign-key fingerprint is incompatible';
    END IF;

    SELECT count(*) INTO ledger_rows FROM sand_iam_schema_migration;
    IF ledger_rows NOT IN (36, 37) THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 acceptance cleanup requires the exact migration 001-035 ledger prefix';
    END IF;
    SELECT migration_file, revision, package_version INTO migration_row
    FROM sand_iam_schema_migration
    WHERE migration_file = '035_schema_migration_ledger.pgsql';
    IF migration_row.migration_file IS NULL OR migration_row.revision <> 35 OR migration_row.package_version <> '0.7.0' THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 acceptance cleanup requires the verified migration 035 ledger';
    END IF;
END $$;

DO $$
DECLARE
    organization_fk text;
    application_fk text;
BEGIN
    SELECT pg_get_constraintdef(constraint_row.oid, true) INTO organization_fk
    FROM pg_constraint constraint_row
    JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = current_schema()
      AND table_row.relname = 'sand_iam_audit_log'
      AND constraint_row.conname = 'sand_iam_audit_log_organization_id_fkey';
    IF organization_fk = 'FOREIGN KEY (organization_id) REFERENCES sand_iam_organization(id) ON DELETE RESTRICT' THEN
        ALTER TABLE sand_iam_audit_log DROP CONSTRAINT sand_iam_audit_log_organization_id_fkey;
        ALTER TABLE sand_iam_audit_log ADD CONSTRAINT sand_iam_audit_log_organization_id_fkey
            FOREIGN KEY (organization_id) REFERENCES sand_iam_organization(id) ON DELETE SET NULL;
    END IF;

    SELECT pg_get_constraintdef(constraint_row.oid, true) INTO application_fk
    FROM pg_constraint constraint_row
    JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = current_schema()
      AND table_row.relname = 'sand_iam_audit_log'
      AND constraint_row.conname = 'sand_iam_audit_log_application_id_fkey';
    IF application_fk = 'FOREIGN KEY (application_id) REFERENCES sand_iam_application(id) ON DELETE RESTRICT' THEN
        ALTER TABLE sand_iam_audit_log DROP CONSTRAINT sand_iam_audit_log_application_id_fkey;
        ALTER TABLE sand_iam_audit_log ADD CONSTRAINT sand_iam_audit_log_application_id_fkey
            FOREIGN KEY (application_id) REFERENCES sand_iam_application(id) ON DELETE SET NULL;
    END IF;
END $$;

DO $$
DECLARE
    parent_menu_id bigint;
BEGIN
    SELECT id INTO parent_menu_id
    FROM sand_system_menu
    WHERE code = 'SandIAMDeveloperDocs'
    ORDER BY id
    LIMIT 1;
    IF parent_menu_id IS NULL THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 cannot add acceptance-fixture permissions: parent menu SandIAMDeveloperDocs is missing';
    END IF;
END $$;

WITH desired(code, name, sort) AS (
    VALUES
        ('sand_iam:acceptance_fixture:cleanup', '清理验收数据', 1881),
        ('sand_iam:acceptance_fixture:read', '查看验收数据状态', 1882)
), parent AS (
    SELECT id
    FROM sand_system_menu
    WHERE code = 'SandIAMDeveloperDocs'
    ORDER BY id
    LIMIT 1
), refresh_existing AS (
    UPDATE sand_system_menu existing
    SET parent_id = parent.id,
        name = desired.name,
        slug = desired.code,
        type = 3,
        path = '',
        component = '',
        icon = '',
        sort = desired.sort,
        is_hidden = 1,
        status = 1,
        update_time = CURRENT_TIMESTAMP
    FROM desired
    CROSS JOIN parent
    WHERE existing.code = desired.code
      AND (
          existing.parent_id IS DISTINCT FROM parent.id
          OR existing.name IS DISTINCT FROM desired.name
          OR existing.slug IS DISTINCT FROM desired.code
          OR existing.type IS DISTINCT FROM 3
          OR existing.path IS DISTINCT FROM ''
          OR existing.component IS DISTINCT FROM ''
          OR existing.icon IS DISTINCT FROM ''
          OR existing.sort IS DISTINCT FROM desired.sort
          OR existing.is_hidden IS DISTINCT FROM 1
          OR existing.status IS DISTINCT FROM 1
      )
    RETURNING existing.id
)
INSERT INTO sand_system_menu (parent_id, name, code, slug, type, path, component, icon, sort, is_hidden, status, create_time, update_time)
SELECT parent.id, desired.name, desired.code, desired.code,
       3, '', '', '', desired.sort, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM desired
CROSS JOIN parent
WHERE NOT EXISTS (
    SELECT 1
    FROM sand_system_menu existing
    WHERE existing.code = desired.code
);

DO $$
DECLARE
    matched_permissions integer;
BEGIN
    WITH desired(code, name, sort) AS (
        VALUES
            ('sand_iam:acceptance_fixture:cleanup', '清理验收数据', 1881),
            ('sand_iam:acceptance_fixture:read', '查看验收数据状态', 1882)
    ), parent AS (
        SELECT id FROM sand_system_menu WHERE code = 'SandIAMDeveloperDocs' ORDER BY id LIMIT 1
    )
    SELECT count(*) INTO matched_permissions
    FROM desired
    CROSS JOIN parent
    JOIN sand_system_menu actual
      ON actual.code = desired.code
     AND actual.parent_id = parent.id
     AND actual.name = desired.name
     AND actual.slug = desired.code
     AND actual.type = 3
     AND actual.path = ''
     AND actual.component = ''
     AND actual.icon = ''
     AND actual.sort = desired.sort
     AND actual.is_hidden = 1
     AND actual.status = 1;
    IF matched_permissions <> 2 OR EXISTS (
        SELECT required.code
        FROM (VALUES
            ('sand_iam:acceptance_fixture:cleanup'),
            ('sand_iam:acceptance_fixture:read')
        ) AS required(code)
        LEFT JOIN sand_system_menu actual ON actual.code = required.code
        GROUP BY required.code
        HAVING count(actual.id) <> 1
    ) THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 acceptance-fixture permission fingerprint is incompatible';
    END IF;
    IF EXISTS (
        SELECT 1 FROM pg_constraint constraint_row
        JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
        JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
        WHERE schema_row.nspname = current_schema()
          AND table_row.relname = 'sand_iam_audit_log'
          AND constraint_row.conname IN (
              'sand_iam_audit_log_organization_id_fkey',
              'sand_iam_audit_log_application_id_fkey'
          )
          AND pg_get_constraintdef(constraint_row.oid, true) NOT LIKE '% ON DELETE SET NULL'
    ) THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 acceptance cleanup did not preserve audit rows with nullable ownership links';
    END IF;
END $$;

DO $$
DECLARE
    recorded record;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '036_acceptance_fixture_support.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 36
        OR recorded.checksum <> '68a9e2a01b0037d4750a2e9861034029dea4620b638c809cb1020ac8608b208a'
        OR recorded.package_version <> '0.7.0'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 036 ledger identity conflicts with the controlled acceptance-fixture support';
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('68a9e2a01b0037d4750a2e9861034029dea4620b638c809cb1020ac8608b208a'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '036_acceptance_fixture_support.pgsql', 36, self_checksum.checksum, '0.7.0', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE
    recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '036_acceptance_fixture_support.pgsql'
      AND revision = 36
      AND checksum = '68a9e2a01b0037d4750a2e9861034029dea4620b638c809cb1020ac8608b208a'
      AND package_version = '0.7.0';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 37 THEN
        RAISE EXCEPTION 'SandIAM migration 036 did not close the exact 001-036 ledger';
    END IF;
END $$;

COMMIT;
