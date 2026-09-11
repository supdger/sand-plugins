-- SandIAM T10 phase 1: identity lifecycle and application-local groups.
-- PostgreSQL only; integrated into the generated root lifecycle.

ALTER TABLE sand_iam_identity
    ADD COLUMN IF NOT EXISTS lifecycle_state VARCHAR(16) NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS state_before_delete VARCHAR(16),
    ADD COLUMN IF NOT EXISTS deleted_time TIMESTAMP WITHOUT TIME ZONE,
    ADD COLUMN IF NOT EXISTS purge_after TIMESTAMP WITHOUT TIME ZONE,
    ADD COLUMN IF NOT EXISTS external_guest_ref_hash CHAR(64);

UPDATE sand_iam_identity
SET lifecycle_state = CASE WHEN status = 1 THEN 'active' ELSE 'disabled' END
WHERE lifecycle_state = 'active' AND status <> 1;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sand_iam_identity_lifecycle_state') THEN
        ALTER TABLE sand_iam_identity ADD CONSTRAINT ck_sand_iam_identity_lifecycle_state
            CHECK (lifecycle_state IN ('pending', 'active', 'disabled', 'guest', 'deleted'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sand_iam_identity_state_before_delete') THEN
        ALTER TABLE sand_iam_identity ADD CONSTRAINT ck_sand_iam_identity_state_before_delete
            CHECK (state_before_delete IS NULL OR state_before_delete IN ('pending', 'active', 'disabled', 'guest'));
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uk_sand_iam_identity_id_application') THEN
        ALTER TABLE sand_iam_identity ADD CONSTRAINT uk_sand_iam_identity_id_application UNIQUE (id, application_id);
    END IF;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_identity_guest_application
    ON sand_iam_identity(application_id, external_guest_ref_hash)
    WHERE external_guest_ref_hash IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_application_lifecycle
    ON sand_iam_identity(application_id, lifecycle_state, status);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_purge_after
    ON sand_iam_identity(purge_after)
    WHERE lifecycle_state = 'deleted';

CREATE TABLE IF NOT EXISTS sand_iam_identity_group (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    parent_id BIGINT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    description VARCHAR(500),
    depth SMALLINT NOT NULL DEFAULT 1 CHECK (depth BETWEEN 1 AND 8),
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_identity_group_app_code UNIQUE (application_id, code),
    CONSTRAINT uk_sand_iam_identity_group_id_app UNIQUE (id, application_id),
    CONSTRAINT fk_sand_iam_identity_group_parent_app FOREIGN KEY (parent_id, application_id)
        REFERENCES sand_iam_identity_group(id, application_id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_group_parent
    ON sand_iam_identity_group(application_id, parent_id, status);

CREATE TABLE IF NOT EXISTS sand_iam_identity_group_member (
    id BIGSERIAL PRIMARY KEY,
    identity_group_id BIGINT NOT NULL,
    application_id BIGINT NOT NULL,
    identity_id BIGINT NOT NULL,
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_identity_group_member UNIQUE (identity_group_id, identity_id),
    CONSTRAINT fk_sand_iam_group_member_group_app FOREIGN KEY (identity_group_id, application_id)
        REFERENCES sand_iam_identity_group(id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_group_member_identity_app FOREIGN KEY (identity_id, application_id)
        REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_group_member_identity
    ON sand_iam_identity_group_member(application_id, identity_id, status);

INSERT INTO sand_iam_service (code, name, status)
VALUES ('sand-iam', 'SandIAM 身份服务', 1)
ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name;

INSERT INTO sand_iam_service_action (service_id, code, name, status)
SELECT id, 'identity.guest.upsert', '创建或更新应用访客', 1
FROM sand_iam_service WHERE code = 'sand-iam'
ON CONFLICT (service_id, code) DO UPDATE SET name = EXCLUDED.name;
