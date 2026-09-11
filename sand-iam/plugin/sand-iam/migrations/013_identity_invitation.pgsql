-- SandIAM T10 phase 2: application invitation lifecycle.
-- PostgreSQL only; integrated into the generated root lifecycle.

CREATE TABLE IF NOT EXISTS sand_iam_identity_invitation (
    id BIGSERIAL PRIMARY KEY,
    application_id BIGINT NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    target_type VARCHAR(16) NOT NULL CHECK (target_type IN ('email', 'phone')),
    target_hash CHAR(64) NOT NULL,
    target_masked VARCHAR(320) NOT NULL,
    encrypted_target TEXT,
    token_hash CHAR(64) NOT NULL,
    encrypted_delivery_token TEXT,
    initial_group_ids JSONB NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(initial_group_ids) = 'array'),
    invited_by VARCHAR(64) NOT NULL,
    state VARCHAR(24) NOT NULL CHECK (state IN ('sending', 'pending', 'delivery_failed', 'accepted', 'revoked', 'expired')),
    identity_id BIGINT,
    guest_identity_id BIGINT,
    expire_time TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    delivered_time TIMESTAMP WITHOUT TIME ZONE,
    consumed_time TIMESTAMP WITHOUT TIME ZONE,
    delivery_error_code VARCHAR(128),
    status SMALLINT NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_invitation_token_hash UNIQUE (token_hash),
    CONSTRAINT fk_sand_iam_invitation_identity_app FOREIGN KEY (identity_id, application_id)
        REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_invitation_guest_identity_app FOREIGN KEY (guest_identity_id, application_id)
        REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_invitation_active_target
    ON sand_iam_identity_invitation(application_id, target_hash)
    WHERE state IN ('sending', 'pending', 'delivery_failed');
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_invitation_active_guest
    ON sand_iam_identity_invitation(application_id, guest_identity_id)
    WHERE guest_identity_id IS NOT NULL AND state IN ('sending', 'pending', 'delivery_failed');
CREATE INDEX IF NOT EXISTS idx_sand_iam_invitation_application_state
    ON sand_iam_identity_invitation(application_id, state, expire_time);
