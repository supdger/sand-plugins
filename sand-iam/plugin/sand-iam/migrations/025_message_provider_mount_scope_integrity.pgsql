-- SandIAM message provider mount scope integrity.
-- A mount may only connect an application and provider owned by the same
-- organization. Controller validation is not a substitute for database
-- isolation because imports and future workers can also write this table.

DO $$ BEGIN
    ALTER TABLE sand_iam_message_provider
        ADD CONSTRAINT uk_sand_iam_message_provider_id_organization
        UNIQUE (id, organization_id);
EXCEPTION
    WHEN duplicate_object OR duplicate_table THEN NULL;
END $$;

DO $$ BEGIN
    ALTER TABLE sand_iam_message_provider_application
        ADD CONSTRAINT fk_sand_iam_message_mount_provider_organization
        FOREIGN KEY (message_provider_id, organization_id)
        REFERENCES sand_iam_message_provider(id, organization_id)
        ON DELETE RESTRICT;
EXCEPTION
    WHEN duplicate_object OR duplicate_table THEN NULL;
END $$;

DO $$ BEGIN
    ALTER TABLE sand_iam_message_provider_application
        ADD CONSTRAINT fk_sand_iam_message_mount_application_organization
        FOREIGN KEY (application_id, organization_id)
        REFERENCES sand_iam_application(id, organization_id)
        ON DELETE RESTRICT;
EXCEPTION
    WHEN duplicate_object OR duplicate_table THEN NULL;
END $$;
