-- IAM-T12-031 BEGIN
-- OIDC has one public issuer and therefore one signing owner. OAuth clients
-- are application scoped but do not carry an environment selector; per-app or
-- per-environment signing keys would be unverifiable at this issuer-wide JWKS.
BEGIN;

ALTER TABLE sand_iam_oidc_signing_key
    ADD COLUMN IF NOT EXISTS encrypted_private_key text NULL,
    ADD COLUMN IF NOT EXISTS encryption_version varchar(32) NULL,
    ADD COLUMN IF NOT EXISTS algorithm varchar(16) NOT NULL DEFAULT 'RS256';

COMMENT ON COLUMN sand_iam_oidc_signing_key.encrypted_private_key IS 'Write-only versioned private-key envelope; NULL only when no private signing material is stored.';
COMMENT ON COLUMN sand_iam_oidc_signing_key.encryption_version IS 'Key-encryption version for encrypted_private_key; NULL when no private signing material is stored.';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sand_iam_oidc_signing_key_algorithm') THEN
        ALTER TABLE sand_iam_oidc_signing_key
            ADD CONSTRAINT ck_sand_iam_oidc_signing_key_algorithm
            CHECK (algorithm = 'RS256') NOT VALID;
    END IF;
END $$;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sand_iam_oidc_signing_key_algorithm' AND NOT convalidated) THEN
        ALTER TABLE sand_iam_oidc_signing_key VALIDATE CONSTRAINT ck_sand_iam_oidc_signing_key_algorithm;
    END IF;
END $$;

-- One active database signer prevents concurrent rotations from producing two
-- valid signing authorities. Existing verify_only rows remain verification
-- history and are intentionally not affected by this invariant.
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_oidc_signing_key_one_active
    ON sand_iam_oidc_signing_key ((1))
    WHERE state = 'active';

COMMIT;
-- IAM-T12-031 END
