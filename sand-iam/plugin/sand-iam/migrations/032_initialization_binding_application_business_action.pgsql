-- Existing installations already have 020's original checks. Replacing those
-- checks here keeps upgrades compatible with application-owned action bindings.
BEGIN;

ALTER TABLE sand_iam_initialization_binding
    DROP CONSTRAINT IF EXISTS ck_sand_iam_initialization_binding_type;
ALTER TABLE sand_iam_initialization_binding
    DROP CONSTRAINT IF EXISTS ck_sand_iam_initialization_binding_table;

ALTER TABLE sand_iam_initialization_binding
    ADD CONSTRAINT ck_sand_iam_initialization_binding_type
    CHECK (object_type IN ('application', 'role', 'user_type', 'resource', 'identity_provider', 'policy', 'application_business_action'));
ALTER TABLE sand_iam_initialization_binding
    ADD CONSTRAINT ck_sand_iam_initialization_binding_table
    CHECK (table_name IN ('sand_iam_application', 'sand_iam_role', 'sand_iam_user_type', 'sand_iam_resource', 'sand_iam_identity_provider', 'sand_iam_policy', 'sand_iam_application_business_action'));

COMMIT;
