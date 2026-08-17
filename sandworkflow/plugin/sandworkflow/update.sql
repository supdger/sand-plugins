-- SandWorkflow PostgreSQL upgrade script (idempotent).
ALTER TABLE "sand_workflow_log" ADD COLUMN IF NOT EXISTS "form_change_snapshot" jsonb NULL;

-- 宿主更名后把存量账号空间 saiadmin 改写为 sandadmin，避免升级后待办消失。
UPDATE "sand_workflow_instance"
SET "account_space" = 'sandadmin',
    "tenant_id" = CASE WHEN "tenant_id" = 'saiadmin' THEN 'sandadmin' ELSE "tenant_id" END,
    "owner_scope" = CASE WHEN "owner_scope" = 'saiadmin' THEN 'sandadmin' ELSE "owner_scope" END
WHERE "account_space" = 'saiadmin';

UPDATE "sand_workflow_task_assignee"
SET "account_space" = 'sandadmin'
WHERE "account_space" = 'saiadmin';

UPDATE "sand_workflow_log"
SET "account_space" = 'sandadmin'
WHERE "account_space" = 'saiadmin';

UPDATE "sand_workflow_task"
SET "assignee_refs" = replace(replace("assignee_refs"::text, '"account_space": "saiadmin"', '"account_space": "sandadmin"'), '"account_space":"saiadmin"', '"account_space":"sandadmin"')::jsonb
WHERE "assignee_refs" IS NOT NULL AND "assignee_refs"::text LIKE '%saiadmin%';

UPDATE "sand_workflow_instance"
SET "participants" = replace(replace("participants"::text, '"account_space": "saiadmin"', '"account_space": "sandadmin"'), '"account_space":"saiadmin"', '"account_space":"sandadmin"')::jsonb
WHERE "participants" IS NOT NULL AND "participants"::text LIKE '%saiadmin%';

-- The PostgreSQL package starts at NanoID runtime IDs. RuntimeSchemaGuard
-- performs the structural check after the plugin update hook runs.
SELECT 1;
