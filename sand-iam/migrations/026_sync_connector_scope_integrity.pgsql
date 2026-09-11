-- SandIAM sync connector scope integrity.
-- The connector's organization is denormalized for administration and audit,
-- but it must always match the owning application's organization.

DO $$ BEGIN
    ALTER TABLE sand_iam_sync_connector
        ADD CONSTRAINT fk_sand_iam_sync_connector_application_organization
        FOREIGN KEY (application_id, organization_id)
        REFERENCES sand_iam_application(id, organization_id)
        ON DELETE RESTRICT;
EXCEPTION
    WHEN duplicate_object OR duplicate_table THEN NULL;
END $$;
