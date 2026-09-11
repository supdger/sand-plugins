-- SandIAM SCIM group member lifecycle contract.
-- Membership removal is reversible: status records the business state while
-- delete_time is managed by the model's soft-delete concern.

ALTER TABLE sand_iam_scim_group_member
    ADD COLUMN IF NOT EXISTS status smallint NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP;

DO $$ BEGIN
    ALTER TABLE sand_iam_scim_group_member
        ADD CONSTRAINT ck_sand_iam_scim_group_member_status
        CHECK (status IN (1, 2));
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

CREATE INDEX IF NOT EXISTS idx_sand_iam_scim_group_member_group_status
    ON sand_iam_scim_group_member (group_id, status);
