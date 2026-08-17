-- SandIAM P0 PostgreSQL uninstall (v0.1.0).
-- Drops only objects owned by this package, from dependent to principal tables.
BEGIN;

DROP TABLE IF EXISTS sand_iam_admin_organization_grant;

DROP TABLE IF EXISTS sand_iam_audit_log;
DROP TABLE IF EXISTS sand_iam_policy;
DROP TABLE IF EXISTS sand_iam_resource;
DROP TABLE IF EXISTS sand_iam_identity_role;
DROP TABLE IF EXISTS sand_iam_role;
DROP TABLE IF EXISTS sand_iam_identity_user_type;
DROP TABLE IF EXISTS sand_iam_user_type;
DROP TABLE IF EXISTS sand_iam_identity_binding;
DROP TABLE IF EXISTS sand_iam_identity;
DROP TABLE IF EXISTS sand_iam_service_grant;
DROP TABLE IF EXISTS sand_iam_service_action;
DROP TABLE IF EXISTS sand_iam_service;
DROP TABLE IF EXISTS sand_iam_credential;
DROP TABLE IF EXISTS sand_iam_workload_client;
DROP TABLE IF EXISTS sand_iam_environment;
DROP TABLE IF EXISTS sand_iam_application;
DROP TABLE IF EXISTS sand_iam_organization;

COMMIT;
