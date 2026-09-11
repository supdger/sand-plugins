-- SandIAM v0.6.0 federation integrity additions.
-- Kept separate so existing 006 deployments receive the same idempotent
-- security constraints without renumbering later planned migrations.

BEGIN;

CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_iam_identity_binding_id_app_identity
    ON sand_iam_identity_binding(id, application_id, identity_id);

ALTER TABLE sand_iam_identity_binding ADD COLUMN IF NOT EXISTS source_state varchar(16) NOT NULL DEFAULT 'active';
ALTER TABLE sand_iam_identity_binding ADD COLUMN IF NOT EXISTS source_updated_time timestamp NULL;
ALTER TABLE sand_iam_identity_binding ADD COLUMN IF NOT EXISTS missing_since timestamp NULL;
ALTER TABLE sand_iam_identity_binding DROP CONSTRAINT IF EXISTS ck_sand_iam_identity_binding_source_state;
ALTER TABLE sand_iam_identity_binding ADD CONSTRAINT ck_sand_iam_identity_binding_source_state CHECK (source_state IN ('active', 'disabled', 'deleted'));

ALTER TABLE sand_iam_identity_provider ADD COLUMN IF NOT EXISTS config_version bigint NOT NULL DEFAULT 1;
ALTER TABLE sand_iam_federation_transaction ADD COLUMN IF NOT EXISTS provider_config_version bigint NOT NULL DEFAULT 1;
ALTER TABLE sand_iam_directory_sync_run ADD COLUMN IF NOT EXISTS provider_config_version bigint NOT NULL DEFAULT 1;
ALTER TABLE sand_iam_directory_sync_run ADD COLUMN IF NOT EXISTS observed_count integer NULL;

ALTER TABLE sand_iam_auth_session
    DROP CONSTRAINT IF EXISTS ck_sand_iam_auth_session_federation_method;
ALTER TABLE sand_iam_auth_session
    ADD CONSTRAINT ck_sand_iam_auth_session_federation_method CHECK (
        (auth_method = 'local_password' AND identity_binding_id IS NULL)
        OR (auth_method = 'federation' AND identity_binding_id IS NOT NULL)
    );
ALTER TABLE sand_iam_auth_session
    DROP CONSTRAINT IF EXISTS fk_sand_iam_auth_session_federation_binding;
ALTER TABLE sand_iam_auth_session
    ADD CONSTRAINT fk_sand_iam_auth_session_federation_binding
    FOREIGN KEY (identity_binding_id, application_id, identity_id)
    REFERENCES sand_iam_identity_binding(id, application_id, identity_id);

CREATE UNIQUE INDEX IF NOT EXISTS uq_sand_iam_federation_assertion_replay
    ON sand_iam_federation_transaction(identity_provider_id, assertion_hash)
    WHERE assertion_hash IS NOT NULL;

DO $$
DECLARE row record;
BEGIN
    FOR row IN SELECT conname FROM pg_constraint
        WHERE conrelid = 'sand_iam_identity_provider'::regclass
          AND contype = 'c'
          AND pg_get_constraintdef(oid) LIKE '%provider_type%'
    LOOP
        EXECUTE format('ALTER TABLE sand_iam_identity_provider DROP CONSTRAINT %I', row.conname);
    END LOOP;
    ALTER TABLE sand_iam_identity_provider
        ADD CONSTRAINT ck_sand_iam_identity_provider_type
        CHECK (provider_type IN ('local', 'oidc', 'oauth2', 'saml', 'ldap', 'scim', 'kerberos'));
EXCEPTION WHEN duplicate_object THEN NULL;
END $$;

COMMIT;
