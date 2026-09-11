-- Align every AbstractSandIamModel table with the host BaseModel soft-delete contract.
-- Append-only audit_log deliberately uses support\think\Model and is excluded.
BEGIN;

ALTER TABLE sand_iam_application_experience ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_application_network_policy ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_audit_archive ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_audit_retention_policy ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_cas_login_request ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_cas_service ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_cas_ticket ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_directory_sync_run ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_federation_handoff ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_federation_transaction ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_identity_group ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_identity_group_member ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_identity_import_job ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_identity_import_row ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_identity_invitation ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_identity_provider_application ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_initialization_binding ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_initialization_run ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_message_provider ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_message_provider_application ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_oauth_registration_token ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_oidc_logout_delivery ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_oidc_signing_key ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_provisioning_event ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_radius_accounting_event ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_radius_accounting_session ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_radius_nas ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_radius_replay ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_scim_group ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_scim_group_member ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_scim_resource ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_security_alert ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_sync_connector ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_sync_outbox ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_sync_resource ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_sync_run ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;

COMMIT;
