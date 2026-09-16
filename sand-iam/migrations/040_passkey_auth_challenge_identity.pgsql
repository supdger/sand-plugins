-- SandIAM 0.7.2: an anonymous passkey challenge starts without an identity,
-- then binds the verified identity after a successful assertion. Other
-- challenge purposes remain identity-bound for their entire lifetime.
BEGIN;

DO $$
DECLARE
    matched_constraints integer;
BEGIN
    SELECT count(*) INTO matched_constraints
    FROM pg_constraint actual
    JOIN pg_class table_row ON table_row.oid = actual.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = current_schema()
      AND table_row.relname = 'sand_iam_auth_challenge'
      AND actual.conname = 'ck_sand_iam_auth_challenge_identity'
      AND actual.contype = 'c'
      AND regexp_replace(
            regexp_replace(
                regexp_replace(
                    lower(pg_get_constraintdef(actual.oid, true)),
                    E'::[a-z_][a-z0-9_]*(\\s+varying)?',
                    '',
                    'g'
                ),
                E'\\s+',
                '',
                'g'
            ),
            '[()]',
            '',
            'g'
          ) IN (
              'checkpurpose=''webauthn_auth''andidentity_idisnullorpurpose<>''webauthn_auth''andidentity_idisnotnull',
              'checkpurpose=''webauthn_auth''oridentity_idisnotnull'
          );

    IF matched_constraints <> 1 THEN
        RAISE EXCEPTION 'SandIAM passkey challenge identity migration requires the exact old or target identity constraint';
    END IF;
END $$;

ALTER TABLE sand_iam_auth_challenge
    DROP CONSTRAINT ck_sand_iam_auth_challenge_identity;

ALTER TABLE sand_iam_auth_challenge
    ADD CONSTRAINT ck_sand_iam_auth_challenge_identity
    CHECK (purpose = 'webauthn_auth' OR identity_id IS NOT NULL);

DO $$
DECLARE
    matched_constraints integer;
BEGIN
    SELECT count(*) INTO matched_constraints
    FROM pg_constraint actual
    JOIN pg_class table_row ON table_row.oid = actual.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = current_schema()
      AND table_row.relname = 'sand_iam_auth_challenge'
      AND actual.conname = 'ck_sand_iam_auth_challenge_identity'
      AND actual.contype = 'c'
      AND regexp_replace(
            regexp_replace(
                regexp_replace(
                    lower(pg_get_constraintdef(actual.oid, true)),
                    E'::[a-z_][a-z0-9_]*(\\s+varying)?',
                    '',
                    'g'
                ),
                E'\\s+',
                '',
                'g'
            ),
            '[()]',
            '',
            'g'
          ) = 'checkpurpose=''webauthn_auth''oridentity_idisnotnull';

    IF matched_constraints <> 1 THEN
        RAISE EXCEPTION 'SandIAM passkey challenge identity migration did not install the target constraint';
    END IF;
END $$;

DO $$
DECLARE recorded record;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '040_passkey_auth_challenge_identity.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 40
        OR recorded.checksum <> 'b83ec813f6405914338b7573fe6d56fe0d7d749516eda1d439bb72beb0212327'
        OR recorded.package_version <> '0.7.2'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 040 ledger identity conflicts with passkey challenge identity binding';
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('b83ec813f6405914338b7573fe6d56fe0d7d749516eda1d439bb72beb0212327'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '040_passkey_auth_challenge_identity.pgsql', 40, self_checksum.checksum, '0.7.2', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '040_passkey_auth_challenge_identity.pgsql'
      AND revision = 40
      AND checksum = 'b83ec813f6405914338b7573fe6d56fe0d7d749516eda1d439bb72beb0212327'
      AND package_version = '0.7.2';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 41 THEN
        RAISE EXCEPTION 'SandIAM migration 040 did not close the exact 001-040 ledger';
    END IF;
END $$;

COMMIT;
