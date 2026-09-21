-- SandWorkflow PostgreSQL uninstall script.
BEGIN;

DELETE FROM "sand_system_role_menu" WHERE "menu_id" IN (
  SELECT "id" FROM "sand_system_menu"
  WHERE "slug" LIKE 'sandworkflow:%'
     OR "component" LIKE '/plugin/sandworkflow/%'
     OR "code" = 'SandWorkflow'
     OR "code" = 'SandWorkflowCenter'
     OR "code" = 'SandWorkflowManage'
     OR "code" LIKE 'sandworkflow/%'
     OR "path" LIKE '/sandworkflow%'
);

DELETE FROM "sand_system_menu"
WHERE "slug" LIKE 'sandworkflow:%'
   OR "component" LIKE '/plugin/sandworkflow/%'
   OR "code" = 'SandWorkflow'
   OR "code" = 'SandWorkflowCenter'
   OR "code" = 'SandWorkflowManage'
   OR "code" LIKE 'sandworkflow/%'
   OR "path" LIKE '/sandworkflow%';

DROP TABLE IF EXISTS "sand_workflow_log";
DROP TABLE IF EXISTS "sand_workflow_task_assignee";
DROP TABLE IF EXISTS "sand_workflow_task";
DROP TABLE IF EXISTS "sand_workflow_instance";
DROP TABLE IF EXISTS "sand_workflow_definition_version";
DROP TABLE IF EXISTS "sand_workflow_definition";
DROP TABLE IF EXISTS "sand_workflow_group";

COMMIT;
