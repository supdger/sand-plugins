-- SandIAM 0.7.1 normal-update admission gate.
--
-- This is lifecycle input, not a migration revision. It admits only a
-- completed 0.7.0 ledger through 037, then lets the immutable 038 migration
-- perform the sole state change in this update payload.
-- The installed 0.7.0 v28 ledger manager predates the 038 catalog entry.
-- Its business schema fingerprint is identical, so its recorded 035 identity
-- is accepted without rewriting the historical ledger.
DO $$
DECLARE
    expected_rows integer;
    recorded_rows integer;
    matched_columns integer;
    matched_constraints integer;
    ledger_pk text;
BEGIN
    IF to_regclass(current_schema() || '.sand_iam_schema_migration') IS NULL THEN
        RAISE EXCEPTION 'SandIAM 0.7.1 update requires the completed 0.7.0 migration ledger through 037';
    END IF;

    IF (SELECT count(*) FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'sand_iam_schema_migration') <> 5
       OR NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
              AND column_name = 'migration_file' AND data_type = 'character varying'
              AND character_maximum_length = 160 AND is_nullable = 'NO'
       )
       OR NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
              AND column_name = 'revision' AND data_type = 'smallint' AND is_nullable = 'NO'
       )
       OR NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
              AND column_name = 'checksum' AND data_type = 'character' AND character_maximum_length = 64 AND is_nullable = 'NO'
       )
       OR NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
              AND column_name = 'package_version' AND data_type = 'character varying'
              AND character_maximum_length = 32 AND is_nullable = 'NO'
       )
       OR NOT EXISTS (
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
              AND column_name = 'executed_time' AND data_type = 'timestamp without time zone' AND is_nullable = 'NO'
       ) THEN
        RAISE EXCEPTION 'SandIAM 0.7.1 update requires the exact 0.7.0 migration ledger structure';
    END IF;

    SELECT pg_get_constraintdef(constraint_row.oid, true) INTO ledger_pk
    FROM pg_constraint constraint_row
    JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
    WHERE schema_row.nspname = current_schema()
      AND table_row.relname = 'sand_iam_schema_migration'
      AND constraint_row.contype = 'p';
    IF ledger_pk IS DISTINCT FROM 'PRIMARY KEY (migration_file)' THEN
        RAISE EXCEPTION 'SandIAM 0.7.1 update requires the exact 0.7.0 migration ledger primary key';
    END IF;

    CREATE TEMPORARY TABLE sand_iam_071_expected_table (table_name varchar(160) PRIMARY KEY) ON COMMIT DROP;
    INSERT INTO sand_iam_071_expected_table (table_name) VALUES
        ('sand_iam_admin_application_grant'), ('sand_iam_admin_organization_grant'), ('sand_iam_api_resource'), ('sand_iam_api_route_binding'), ('sand_iam_application'), ('sand_iam_application_business_action'), ('sand_iam_application_experience'), ('sand_iam_application_network_policy'), ('sand_iam_audit_archive'), ('sand_iam_audit_log'), ('sand_iam_audit_retention_policy'), ('sand_iam_auth_challenge'), ('sand_iam_auth_policy'), ('sand_iam_auth_rate_limit'), ('sand_iam_auth_refresh_token'), ('sand_iam_auth_session'), ('sand_iam_auth_verification'), ('sand_iam_authorization_code'), ('sand_iam_cas_login_request'), ('sand_iam_cas_service'), ('sand_iam_cas_ticket'), ('sand_iam_credential'), ('sand_iam_directory_sync_run'), ('sand_iam_environment'), ('sand_iam_federation_handoff'), ('sand_iam_federation_transaction'), ('sand_iam_identity'), ('sand_iam_identity_auth'), ('sand_iam_identity_binding'), ('sand_iam_identity_group'), ('sand_iam_identity_group_member'), ('sand_iam_identity_group_role'), ('sand_iam_identity_import_job'), ('sand_iam_identity_import_row'), ('sand_iam_identity_invitation'), ('sand_iam_identity_provider'), ('sand_iam_identity_provider_application'), ('sand_iam_identity_role'), ('sand_iam_identity_user_type'), ('sand_iam_initialization_binding'), ('sand_iam_initialization_draft'), ('sand_iam_initialization_draft_revision'), ('sand_iam_initialization_run'), ('sand_iam_message_provider'), ('sand_iam_message_provider_application'), ('sand_iam_mfa_factor'), ('sand_iam_mfa_recovery_code'), ('sand_iam_oauth_authorization_request'), ('sand_iam_oauth_client'), ('sand_iam_oauth_consent'), ('sand_iam_oauth_grant'), ('sand_iam_oauth_registration_token'), ('sand_iam_oauth_token'), ('sand_iam_oidc_logout_delivery'), ('sand_iam_oidc_signing_key'), ('sand_iam_organization'), ('sand_iam_policy'), ('sand_iam_policy_version'), ('sand_iam_provisioning_event'), ('sand_iam_radius_accounting_event'), ('sand_iam_radius_accounting_session'), ('sand_iam_radius_nas'), ('sand_iam_radius_replay'), ('sand_iam_resource'), ('sand_iam_role'), ('sand_iam_schema_migration'), ('sand_iam_scim_group'), ('sand_iam_scim_group_member'), ('sand_iam_scim_resource'), ('sand_iam_scim_token'), ('sand_iam_security_alert'), ('sand_iam_security_operation'), ('sand_iam_service'), ('sand_iam_service_action'), ('sand_iam_service_grant'), ('sand_iam_service_invocation_operation'), ('sand_iam_service_quota_bucket'), ('sand_iam_sync_connector'), ('sand_iam_sync_outbox'), ('sand_iam_sync_resource'), ('sand_iam_sync_run'), ('sand_iam_user_type'), ('sand_iam_webauthn_credential'), ('sand_iam_webhook_delivery'), ('sand_iam_webhook_endpoint'), ('sand_iam_workload_client');

    IF (SELECT count(*) FROM sand_iam_071_expected_table) <> 86
       OR EXISTS (
            SELECT table_name FROM sand_iam_071_expected_table
            EXCEPT
            SELECT table_row.relname FROM pg_class table_row
            JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
            WHERE schema_row.nspname = current_schema() AND table_row.relkind IN ('r', 'p') AND table_row.relname LIKE 'sand_iam_%'
       )
       OR EXISTS (
            SELECT table_row.relname FROM pg_class table_row
            JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace
            WHERE schema_row.nspname = current_schema() AND table_row.relkind IN ('r', 'p') AND table_row.relname LIKE 'sand_iam_%'
            EXCEPT SELECT table_name FROM sand_iam_071_expected_table
       ) THEN
        RAISE EXCEPTION 'SandIAM 0.7.1 update requires the exact completed 86-table 0.7.0 schema';
    END IF;

    WITH required_column(table_name, column_name, data_type, character_maximum_length, is_nullable, datetime_precision, is_identity, identity_generation, current_timestamp_default) AS (
        VALUES
            ('sand_iam_schema_migration', 'migration_file', 'character varying', 160, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_schema_migration', 'revision', 'smallint', NULL::integer, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_schema_migration', 'checksum', 'character', 64, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_schema_migration', 'package_version', 'character varying', 32, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_schema_migration', 'executed_time', 'timestamp without time zone', NULL::integer, 'NO', 0, 'NO', NULL::text, true),
            ('sand_iam_auth_rate_limit', 'id', 'bigint', NULL::integer, 'NO', NULL::integer, 'YES', 'BY DEFAULT', false),
            ('sand_iam_auth_rate_limit', 'window_start', 'timestamp without time zone', NULL::integer, 'NO', 0, 'NO', NULL::text, false),
            ('sand_iam_identity_group_role', 'create_time', 'timestamp without time zone', NULL::integer, 'NO', 0, 'NO', NULL::text, true),
            ('sand_iam_identity_group_role', 'update_time', 'timestamp without time zone', NULL::integer, 'NO', 0, 'NO', NULL::text, true),
            ('sand_iam_initialization_draft', 'manifest_hash', 'character', 64, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_initialization_draft', 'revision', 'integer', NULL::integer, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_initialization_draft', 'update_time', 'timestamp without time zone', NULL::integer, 'NO', 0, 'NO', NULL::text, true),
            ('sand_iam_initialization_draft_revision', 'draft_id', 'bigint', NULL::integer, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_initialization_draft_revision', 'request_id', 'character varying', 96, 'NO', NULL::integer, 'NO', NULL::text, false),
            ('sand_iam_initialization_draft_revision', 'create_time', 'timestamp without time zone', NULL::integer, 'NO', 0, 'NO', NULL::text, true)
    )
    SELECT count(*) INTO matched_columns
    FROM required_column expected
    JOIN information_schema.columns actual
      ON actual.table_schema = current_schema() AND actual.table_name = expected.table_name AND actual.column_name = expected.column_name
     AND actual.data_type = expected.data_type
     AND actual.character_maximum_length IS NOT DISTINCT FROM expected.character_maximum_length
     AND actual.is_nullable = expected.is_nullable
     AND actual.datetime_precision IS NOT DISTINCT FROM expected.datetime_precision
     AND actual.is_identity = expected.is_identity
     AND actual.identity_generation IS NOT DISTINCT FROM expected.identity_generation
     AND (NOT expected.current_timestamp_default OR actual.column_default = 'CURRENT_TIMESTAMP');
    IF matched_columns <> 15 THEN
        RAISE EXCEPTION 'SandIAM 0.7.1 update requires exact 0.7.0 ledger, retention, and new-table column fingerprints';
    END IF;

    WITH required_constraint(table_name, constraint_name, constraint_type, normalized_definition) AS (
        VALUES
            ('sand_iam_schema_migration', 'ck_sand_iam_schema_migration_revision', 'c', 'checkrevision>=1andrevision<=999'),
            ('sand_iam_schema_migration', 'ck_sand_iam_schema_migration_checksum', 'c', 'checkchecksum~''^[0-9a-f]{64}$'''),
            ('sand_iam_identity_group_role', 'uk_sand_iam_identity_group_role', 'u', 'uniqueidentity_group_id,role_id'),
            ('sand_iam_initialization_draft', 'uk_sand_iam_initialization_draft_organization_package', 'u', 'uniqueorganization_id,package_code'),
            ('sand_iam_initialization_draft_revision', 'uk_sand_iam_initialization_draft_revision', 'u', 'uniquedraft_id,revision'),
            ('sand_iam_auth_rate_limit', 'uk_sand_iam_auth_rate_limit_application_action_subject', 'u', 'uniqueapplication_id,action,subject_hash')
    )
    SELECT count(*) INTO matched_constraints
    FROM required_constraint expected
    JOIN pg_constraint actual ON actual.conname = expected.constraint_name
    JOIN pg_class table_row ON table_row.oid = actual.conrelid AND table_row.relname = expected.table_name
    JOIN pg_namespace schema_row ON schema_row.oid = table_row.relnamespace AND schema_row.nspname = current_schema()
    WHERE actual.contype = expected.constraint_type
      AND actual.convalidated
      AND regexp_replace(
            regexp_replace(
                regexp_replace(lower(pg_get_constraintdef(actual.oid, true)), E'::[a-z_][a-z0-9_]*(\\s+varying)?(\\[\\])?', '', 'g'),
                E'\\s+', '', 'g'
            ),
            '[()]', '', 'g'
          ) = expected.normalized_definition;
    IF matched_constraints <> 6 THEN
        RAISE EXCEPTION 'SandIAM 0.7.1 update requires exact 0.7.0 ledger and critical constraint fingerprints';
    END IF;

    CREATE TEMPORARY TABLE sand_iam_071_expected_ledger (
        migration_file varchar(160) PRIMARY KEY,
        revision smallint NOT NULL,
        checksum char(64) NOT NULL,
        package_version varchar(32) NOT NULL
    ) ON COMMIT DROP;

    INSERT INTO sand_iam_071_expected_ledger (migration_file, revision, checksum, package_version) VALUES
        ('001_iam04_admin_organization_grant.pgsql', 1, 'd214397c680631150f4cc1199d0af27063e968211b13b24a1e857ec9f0ceb5f6', '0.6.0'),
        ('002_identity_provider_scope.pgsql', 2, '92e2623876d251b8720303f573a88931765bc14db6c2f12a983fbb0d0084150a', '0.6.0'),
        ('003_human_auth_core.pgsql', 3, 'c75be4675a56dfe628a69ba7b2188450c1db88d0770672df0e6ddc1645e8ea50', '0.6.0'),
        ('004_mfa_passkey.pgsql', 4, 'e4c2a2b10650cc02d8b654746e4520912326609fad9675d93c6222de09accce3', '0.6.0'),
        ('005_oauth_oidc.pgsql', 5, 'de3e4f34a6e78b73179d62036788c1022c5557b08ca9671b2389a3ea1755ea62', '0.6.0'),
        ('006_federation_directory_scim.pgsql', 6, '94fea592ac863ff133d61da2cb9b015b00410a8487053858cc8481d2bec6e709', '0.6.0'),
        ('006_federation_integrity.pgsql', 6, '14095610942f64ee2aa463d1673550596d2df489ec3f0dd0d48ace6041396081', '0.6.0'),
        ('007_federation_handoff.pgsql', 7, 'f729fcae6da3effc2ebf41d8ddccc7b71251730be770d2f879d101d23f44a481', '0.6.0'),
        ('008_api_governance.pgsql', 8, 'cc16eee10ad09b1049f4d2e2e128665295fd2f64b26c8a727d4e082bb641f008', '0.6.0'),
        ('009_admin_application_grant.pgsql', 9, '6dda3f9466f51008e405877b2ad47b646891d312808dbfe9c9ab496299e8e34f', '0.6.0'),
        ('010_webhook_delivery.pgsql', 10, 'fce2c626b7ca1f69e250a0fc3c6cc8450d18a183f837f14d21adab695791f4b1', '0.6.0'),
        ('011_application_experience_message_provider.pgsql', 11, '46a087d79e14965a16421990cf888d80e097f1eecbc090f3aea658ae418a203a', '0.6.0'),
        ('012_identity_lifecycle_group.pgsql', 12, '7b44c4d2d9005c72af2922330d74ae8b50bd17a9392fc229063e427f8abf8299', '0.6.0'),
        ('013_identity_invitation.pgsql', 13, 'd9fd0740ace8a2be088f200942db8a1c49a1a114013c786415d8bdd7a40b8643', '0.6.0'),
        ('014_identity_import_export.pgsql', 14, '038b35c57d375a1d083b733915da68386b2b4f97d83224382b203b80f2ae587b', '0.6.0'),
        ('015_identity_sync_connector.pgsql', 15, '3219bd7db6b6f596d0b0c1169350f26d21f82f16f58960527e4eb382c16f6bfd', '0.6.0'),
        ('016_oauth_dynamic_registration_logout.pgsql', 16, '6d510349490eb8135c8fb02b30df8c0abcfc98b57ba4545ce115d0752c4d05bd', '0.6.0'),
        ('017_cas_protocol.pgsql', 17, '6ded442269d9d22721d78e5ca340cc9b08efddcdadfbd2efd01b76a0319df2f6', '0.6.0'),
        ('018_radius_server.pgsql', 18, '5517787fe08af96a90b125ec72a77179a7f0b6fa575b6fb59009a1ce6f22532c', '0.6.0'),
        ('019_security_operations.pgsql', 19, 'c800d667cc2efc733822970e176b3727d7be2600207137fc080112c161f035a3', '0.6.0'),
        ('020_initialization_package.pgsql', 20, '54abcaebca1ef4c7dc8747bf0f0bf84ca9bda5fc3227eb2d5415767277a0f3f2', '0.6.0'),
        ('021_admin_permission_catalog.pgsql', 21, 'f263ec1450bbd15d883c1d5b0f3a0fcfd15db602df9d120b1049a0c4cc428db9', '0.6.0'),
        ('022_model_soft_delete_contract.pgsql', 22, 'cf9e02c62b19d7115d2b1c7418f7555abfc9f5d8438be485431f6b9b1ac90d0e', '0.6.0'),
        ('023_federation_protocol_constraint_alignment.pgsql', 23, 'c88792bb4732c9c9c7aae641619416db765239122d5237ca97b991b722df78fa', '0.6.0'),
        ('024_scim_group_member_lifecycle.pgsql', 24, 'b178d81ff2b8820b3255a4e74127dbca3dbd59ae38573eeeefd8e0d688df1342', '0.6.0'),
        ('025_message_provider_mount_scope_integrity.pgsql', 25, 'dcd3c5e360566bae1cbdce7a2f5b0307ac05a36b41ae136e1fb8123bbb166f03', '0.6.0'),
        ('026_sync_connector_scope_integrity.pgsql', 26, 'b052b80e781b439781251bee7064f7dde7cada40dd67169ed19c93b42a3e70ce', '0.6.0'),
        ('027_security_operation_idempotency.pgsql', 27, 'c0c9b57cef2ed4c4d0d61be923124363f728beda27b3b2863ffae11a8f47d128', '0.6.0'),
        ('028_application_business_action.pgsql', 28, 'cb14e237f3052bb8ab200bf7e59f3d9a3b186ca2d79cdfa686af5c3b5e81329a', '0.6.0'),
        ('029_policy_versioning.pgsql', 29, '53b27e150c504600a53357c020de602819fcc8d6d9f2ff1e67820ef6b36e3c12', '0.6.0'),
        ('030_service_grant_invocation_control.pgsql', 30, '9fa587065ae878777d191db10f62d2e6e0dd33a97d90edcee883b709081602bc', '0.6.0'),
        ('031_oidc_signing_key_rotation.pgsql', 31, '7ce71dca33ee220faf2ca12812c0d132dd4190ed4c3094eb69e10bf8fdb31f14', '0.6.0'),
        ('032_initialization_binding_application_business_action.pgsql', 32, '5d3f6f7893356167f51eac62fa667990dcae2aebc4f12c11c3ac8e88f87353cb', '0.6.0'),
        ('033_identity_group_role.pgsql', 33, '161352617d1e46205a2abbf3b074ab7276e9dd691d151b61a2d8d146de26fd68', '0.7.0'),
        ('034_identity_group_role_permission_catalog.pgsql', 34, '97ab44356d905eb0e1c13ca3101a3dd0b8a4af3264cecb81310e1f522dfc2485', '0.7.0'),
        ('035_schema_migration_ledger.pgsql', 35, '7365abe0bbaa0134c018a291a65a4a420b2b145e0c7c3d184ac0123405ae0e12', '0.7.0'),
        ('036_acceptance_fixture_support.pgsql', 36, '403f0fbadaf54436b81d8ff9d9eb2c14cbe83b5a26c3d13594241f2b40e17a3b', '0.7.0'),
        ('037_initialization_draft.pgsql', 37, 'cf7013ee42f9bd6319e8c23524f274e382901af7fbb32fa947732cd8beeb640c', '0.7.0');

    SELECT count(*) INTO expected_rows FROM sand_iam_071_expected_ledger;
    SELECT count(*) INTO recorded_rows FROM sand_iam_schema_migration;
    IF expected_rows <> 38
       OR recorded_rows <> expected_rows
       OR (SELECT max(revision) FROM sand_iam_schema_migration) <> 37
       OR EXISTS (
            SELECT 1 FROM sand_iam_schema_migration recorded
            FULL OUTER JOIN sand_iam_071_expected_ledger expected USING (migration_file)
            WHERE recorded.migration_file IS NULL
               OR expected.migration_file IS NULL
               OR recorded.revision IS DISTINCT FROM expected.revision
               OR (
                    recorded.checksum IS DISTINCT FROM expected.checksum
                    AND NOT (
                        recorded.migration_file = '035_schema_migration_ledger.pgsql'
                        AND recorded.revision = 35
                        AND recorded.package_version = '0.7.0'
                        AND recorded.checksum = '4372ad7731e2dae60e51b7b2448a95971c5b22db06fc9678fb1d076d6e28ea25'
                    )
               )
               OR recorded.package_version IS DISTINCT FROM expected.package_version
       ) THEN
        RAISE EXCEPTION 'SandIAM 0.7.1 update requires exact 001-037 ledger identities before executing 038';
    END IF;
END $$;
