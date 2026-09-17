-- SandIAM 0.7.3: close database-level application ownership gaps in direct
-- identity grants and policy publication, and align policy action width with
-- the 96-character application action contract.
BEGIN;

LOCK TABLE
    sand_iam_identity,
    sand_iam_role,
    sand_iam_user_type,
    sand_iam_resource,
    sand_iam_identity_role,
    sand_iam_identity_user_type,
    sand_iam_policy,
    sand_iam_policy_version
IN SHARE ROW EXCLUSIVE MODE;

DO $$
DECLARE
    conflict_count bigint;
BEGIN
    SELECT count(*) INTO conflict_count
    FROM sand_iam_identity_role relation
    JOIN sand_iam_identity identity_row ON identity_row.id = relation.identity_id
    JOIN sand_iam_role role_row ON role_row.id = relation.role_id
    WHERE identity_row.application_id IS DISTINCT FROM role_row.application_id;
    IF conflict_count <> 0 THEN
        RAISE EXCEPTION 'SandIAM 0.7.3 refuses % cross-application identity-role rows', conflict_count;
    END IF;

    SELECT count(*) INTO conflict_count
    FROM sand_iam_identity_user_type relation
    JOIN sand_iam_identity identity_row ON identity_row.id = relation.identity_id
    JOIN sand_iam_user_type type_row ON type_row.id = relation.user_type_id
    WHERE identity_row.application_id IS DISTINCT FROM type_row.application_id;
    IF conflict_count <> 0 THEN
        RAISE EXCEPTION 'SandIAM 0.7.3 refuses % cross-application identity-user-type rows', conflict_count;
    END IF;

    SELECT count(*) INTO conflict_count
    FROM sand_iam_policy policy_row
    JOIN sand_iam_resource resource_row ON resource_row.id = policy_row.resource_id
    LEFT JOIN sand_iam_role role_row ON role_row.id = policy_row.role_id
    LEFT JOIN sand_iam_identity identity_row ON identity_row.id = policy_row.identity_id
    WHERE resource_row.application_id IS DISTINCT FROM policy_row.application_id
       OR (policy_row.role_id IS NOT NULL AND role_row.application_id IS DISTINCT FROM policy_row.application_id)
       OR (policy_row.identity_id IS NOT NULL AND identity_row.application_id IS DISTINCT FROM policy_row.application_id);
    IF conflict_count <> 0 THEN
        RAISE EXCEPTION 'SandIAM 0.7.3 refuses % policy rows with cross-application targets', conflict_count;
    END IF;

    SELECT count(*) INTO conflict_count
    FROM sand_iam_policy_version version_row
    JOIN sand_iam_policy policy_row ON policy_row.id = version_row.policy_id
    WHERE version_row.application_id IS DISTINCT FROM policy_row.application_id;
    IF conflict_count <> 0 THEN
        RAISE EXCEPTION 'SandIAM 0.7.3 refuses % policy versions with a foreign application', conflict_count;
    END IF;

    SELECT count(*) INTO conflict_count
    FROM sand_iam_policy_version version_row
    JOIN sand_iam_policy_version rollback_row ON rollback_row.id = version_row.rollback_of_version_id
    WHERE rollback_row.policy_id IS DISTINCT FROM version_row.policy_id
       OR rollback_row.application_id IS DISTINCT FROM version_row.application_id;
    IF conflict_count <> 0 THEN
        RAISE EXCEPTION 'SandIAM 0.7.3 refuses % rollback pointers outside their policy', conflict_count;
    END IF;

    SELECT count(*) INTO conflict_count
    FROM sand_iam_policy policy_row
    JOIN sand_iam_policy_version version_row ON version_row.id = policy_row.published_version_id
    WHERE version_row.policy_id IS DISTINCT FROM policy_row.id
       OR version_row.application_id IS DISTINCT FROM policy_row.application_id;
    IF conflict_count <> 0 THEN
        RAISE EXCEPTION 'SandIAM 0.7.3 refuses % published pointers outside their policy', conflict_count;
    END IF;

    SELECT count(*) INTO conflict_count
    FROM sand_iam_policy_version
    WHERE version_no <= 0
       OR jsonb_typeof(snapshot) IS DISTINCT FROM 'object'
       OR snapshot_hash !~ '^[0-9a-f]{64}$';
    IF conflict_count <> 0 THEN
        RAISE EXCEPTION 'SandIAM 0.7.3 refuses % malformed policy-version rows', conflict_count;
    END IF;
END $$;

ALTER TABLE sand_iam_identity_role ADD COLUMN application_id bigint NULL;
ALTER TABLE sand_iam_identity_user_type ADD COLUMN application_id bigint NULL;

UPDATE sand_iam_identity_role relation
SET application_id = identity_row.application_id
FROM sand_iam_identity identity_row
WHERE identity_row.id = relation.identity_id;

UPDATE sand_iam_identity_user_type relation
SET application_id = identity_row.application_id
FROM sand_iam_identity identity_row
WHERE identity_row.id = relation.identity_id;

ALTER TABLE sand_iam_identity_role ALTER COLUMN application_id SET NOT NULL;
ALTER TABLE sand_iam_identity_user_type ALTER COLUMN application_id SET NOT NULL;
ALTER TABLE sand_iam_policy ALTER COLUMN action TYPE varchar(96);

ALTER TABLE sand_iam_user_type
    ADD CONSTRAINT uk_sand_iam_user_type_id_application UNIQUE (id, application_id);
ALTER TABLE sand_iam_policy
    ADD CONSTRAINT uk_sand_iam_policy_id_application UNIQUE (id, application_id);
ALTER TABLE sand_iam_policy_version
    ADD CONSTRAINT uk_sand_iam_policy_version_owner UNIQUE (id, policy_id, application_id);

ALTER TABLE sand_iam_identity_role
    ADD CONSTRAINT fk_sand_iam_identity_role_identity_application
        FOREIGN KEY (identity_id, application_id)
        REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_sand_iam_identity_role_role_application
        FOREIGN KEY (role_id, application_id)
        REFERENCES sand_iam_role(id, application_id) ON DELETE RESTRICT;

ALTER TABLE sand_iam_identity_user_type
    ADD CONSTRAINT fk_sand_iam_identity_user_type_identity_application
        FOREIGN KEY (identity_id, application_id)
        REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_sand_iam_identity_user_type_type_application
        FOREIGN KEY (user_type_id, application_id)
        REFERENCES sand_iam_user_type(id, application_id) ON DELETE RESTRICT;

ALTER TABLE sand_iam_policy
    ADD CONSTRAINT fk_sand_iam_policy_resource_application
        FOREIGN KEY (resource_id, application_id)
        REFERENCES sand_iam_resource(id, application_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_sand_iam_policy_role_application
        FOREIGN KEY (role_id, application_id)
        REFERENCES sand_iam_role(id, application_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_sand_iam_policy_identity_application
        FOREIGN KEY (identity_id, application_id)
        REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT;

ALTER TABLE sand_iam_policy_version
    ADD CONSTRAINT fk_sand_iam_policy_version_policy_application
        FOREIGN KEY (policy_id, application_id)
        REFERENCES sand_iam_policy(id, application_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_sand_iam_policy_version_rollback_owner
        FOREIGN KEY (rollback_of_version_id, policy_id, application_id)
        REFERENCES sand_iam_policy_version(id, policy_id, application_id) ON DELETE RESTRICT,
    ADD CONSTRAINT ck_sand_iam_policy_version_number CHECK (version_no > 0),
    ADD CONSTRAINT ck_sand_iam_policy_version_snapshot_object CHECK (jsonb_typeof(snapshot) = 'object'),
    ADD CONSTRAINT ck_sand_iam_policy_version_snapshot_hash CHECK (snapshot_hash ~ '^[0-9a-f]{64}$');

ALTER TABLE sand_iam_policy
    ADD CONSTRAINT fk_sand_iam_policy_published_version_owner
        FOREIGN KEY (published_version_id, id, application_id)
        REFERENCES sand_iam_policy_version(id, policy_id, application_id) ON DELETE RESTRICT;

ALTER TABLE sand_iam_identity_role
    DROP CONSTRAINT sand_iam_identity_role_identity_id_fkey,
    DROP CONSTRAINT sand_iam_identity_role_role_id_fkey;
ALTER TABLE sand_iam_identity_user_type
    DROP CONSTRAINT sand_iam_identity_user_type_identity_id_fkey,
    DROP CONSTRAINT sand_iam_identity_user_type_user_type_id_fkey;
ALTER TABLE sand_iam_policy
    DROP CONSTRAINT sand_iam_policy_resource_id_fkey,
    DROP CONSTRAINT sand_iam_policy_role_id_fkey,
    DROP CONSTRAINT sand_iam_policy_identity_id_fkey,
    DROP CONSTRAINT fk_sand_iam_policy_published_version;
ALTER TABLE sand_iam_policy_version
    DROP CONSTRAINT sand_iam_policy_version_policy_id_fkey,
    DROP CONSTRAINT sand_iam_policy_version_application_id_fkey,
    DROP CONSTRAINT sand_iam_policy_version_rollback_of_version_id_fkey;

CREATE INDEX idx_sand_iam_identity_role_application_role
    ON sand_iam_identity_role (application_id, role_id, status);
CREATE INDEX idx_sand_iam_identity_user_type_application_type
    ON sand_iam_identity_user_type (application_id, user_type_id, status);

DO $$
DECLARE recorded record;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '041_authorization_scope_integrity.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 41
        OR recorded.checksum <> 'a06c3c7879e7760fb54dae955c36e6a6aacfa9a6ad36c6e35b5981d00eb43a50'
        OR recorded.package_version <> '0.7.3'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 041 ledger identity conflicts with authorization scope integrity';
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('a06c3c7879e7760fb54dae955c36e6a6aacfa9a6ad36c6e35b5981d00eb43a50'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '041_authorization_scope_integrity.pgsql', 41, self_checksum.checksum, '0.7.3', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '041_authorization_scope_integrity.pgsql'
      AND revision = 41
      AND checksum = 'a06c3c7879e7760fb54dae955c36e6a6aacfa9a6ad36c6e35b5981d00eb43a50'
      AND package_version = '0.7.3';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 42 THEN
        RAISE EXCEPTION 'SandIAM migration 041 did not close the exact 001-041 ledger';
    END IF;
END $$;

COMMIT;
