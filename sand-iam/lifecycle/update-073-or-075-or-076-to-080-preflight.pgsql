-- SandIAM 0.7.3/0.7.5/0.7.6 -> 0.8.0: standalone read-only admission.
-- The lifecycle builder combines this gate with migration 043 in one
-- read-write transaction; this source alone does not upgrade the database.
-- The 43 expected rows are copied from the published 0.7.3 lifecycle ledger.
-- In particular, revision 042 is 042_permission_menu_hierarchy.pgsql, never
-- the conflicting 042_schema_semantics.pgsql from an unpublished branch.
-- BEGIN READ ONLY enforces the no-write boundary in PostgreSQL itself.
BEGIN TRANSACTION READ ONLY;

DO $$
DECLARE
    ledger_pk text;
    mismatch_count integer;
BEGIN
    IF to_regclass(current_schema() || '.sand_iam_schema_migration') IS NULL THEN
        RAISE EXCEPTION 'SandIAM 0.8.0 requires the published 0.7.3 migration ledger';
    END IF;
    IF (SELECT count(*) FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration') <> 5
       OR NOT EXISTS (SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
             AND column_name = 'migration_file' AND data_type = 'character varying'
             AND character_maximum_length = 160 AND is_nullable = 'NO')
       OR NOT EXISTS (SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
             AND column_name = 'revision' AND data_type = 'smallint' AND is_nullable = 'NO')
       OR NOT EXISTS (SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
             AND column_name = 'checksum' AND data_type = 'character'
             AND character_maximum_length = 64 AND is_nullable = 'NO')
       OR NOT EXISTS (SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
             AND column_name = 'package_version' AND data_type = 'character varying'
             AND character_maximum_length = 32 AND is_nullable = 'NO')
       OR NOT EXISTS (SELECT 1 FROM information_schema.columns
           WHERE table_schema = current_schema() AND table_name = 'sand_iam_schema_migration'
             AND column_name = 'executed_time' AND data_type = 'timestamp without time zone'
             AND is_nullable = 'NO') THEN
        RAISE EXCEPTION 'SandIAM 0.8.0 requires the exact 0.7.3 ledger structure';
    END IF;

    SELECT pg_get_constraintdef(c.oid, true) INTO ledger_pk
    FROM pg_constraint c
    JOIN pg_class t ON t.oid = c.conrelid
    JOIN pg_namespace n ON n.oid = t.relnamespace
    WHERE n.nspname = current_schema() AND t.relname = 'sand_iam_schema_migration'
      AND c.contype = 'p';
    IF ledger_pk IS DISTINCT FROM 'PRIMARY KEY (migration_file)' THEN
        RAISE EXCEPTION 'SandIAM 0.8.0 requires the 0.7.3 ledger primary key';
    END IF;

    WITH expected(migration_file, revision, checksum, package_version) AS (
        VALUES
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
            ('037_initialization_draft.pgsql', 37, 'cf7013ee42f9bd6319e8c23524f274e382901af7fbb32fa947732cd8beeb640c', '0.7.0'),
            ('038_auth_rate_limit_retention.pgsql', 38, '2765bed31eb3a8c5e894c0758e80120f6926f90b4f5a1a9ea39609dd608fe2c3', '0.7.0'),
            ('039_service_grant_nullable_data_class.pgsql', 39, 'c6b0c315da73bb6e5e6596c00f09e8c4494db07c790a40f70c5882c0fff3ad53', '0.7.2'),
            ('040_passkey_auth_challenge_identity.pgsql', 40, 'b83ec813f6405914338b7573fe6d56fe0d7d749516eda1d439bb72beb0212327', '0.7.2'),
            ('041_authorization_scope_integrity.pgsql', 41, 'a06c3c7879e7760fb54dae955c36e6a6aacfa9a6ad36c6e35b5981d00eb43a50', '0.7.3'),
            ('042_permission_menu_hierarchy.pgsql', 42, '62d79e86170788d40d39b528b604269e439e0d3588c011154c8e12080564fbeb', '0.7.3')
    ), difference AS (
        SELECT e.migration_file AS expected_file, a.migration_file AS actual_file
        FROM expected e
        FULL OUTER JOIN sand_iam_schema_migration a
          ON a.migration_file = e.migration_file
        WHERE e.migration_file IS NULL OR a.migration_file IS NULL
           OR a.revision IS DISTINCT FROM e.revision
           OR a.checksum::text IS DISTINCT FROM e.checksum
           OR a.package_version IS DISTINCT FROM e.package_version
    )
    SELECT count(*) INTO mismatch_count FROM difference;
    IF mismatch_count <> 0 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 43 THEN
        RAISE EXCEPTION 'SandIAM 0.8.0 requires the exact published 0.7.3 ledger (001-042, 43 files)';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'sand_iam_policy'
          AND column_name = 'action' AND data_type = 'character varying'
          AND character_maximum_length = 96) THEN
        RAISE EXCEPTION 'SandIAM 0.8.0 requires the 0.7.3 policy action schema';
    END IF;
    IF (SELECT count(*) FROM pg_constraint c
        JOIN pg_class t ON t.oid = c.conrelid
        JOIN pg_namespace n ON n.oid = t.relnamespace
        WHERE n.nspname = current_schema() AND c.contype = 'f' AND c.convalidated
          AND (t.relname, c.conname) IN (
            ('sand_iam_identity_role', 'fk_sand_iam_identity_role_identity_application'),
            ('sand_iam_identity_role', 'fk_sand_iam_identity_role_role_application'),
            ('sand_iam_identity_user_type', 'fk_sand_iam_identity_user_type_identity_application'),
            ('sand_iam_identity_user_type', 'fk_sand_iam_identity_user_type_type_application'),
            ('sand_iam_policy', 'fk_sand_iam_policy_resource_application'),
            ('sand_iam_policy', 'fk_sand_iam_policy_role_application'),
            ('sand_iam_policy', 'fk_sand_iam_policy_identity_application'),
            ('sand_iam_policy_version', 'fk_sand_iam_policy_version_policy_application'),
            ('sand_iam_policy_version', 'fk_sand_iam_policy_version_rollback_owner'),
            ('sand_iam_policy', 'fk_sand_iam_policy_published_version_owner')
          )) <> 10 THEN
        RAISE EXCEPTION 'SandIAM 0.8.0 requires the 0.7.3 ownership constraints';
    END IF;
    IF (SELECT count(*) FROM sand_system_menu
        WHERE code = 'SandIAM' AND delete_time IS NULL) <> 1 THEN
        RAISE EXCEPTION 'SandIAM 0.8.0 requires the 0.7.3 menu root';
    END IF;
END $$;

COMMIT;
