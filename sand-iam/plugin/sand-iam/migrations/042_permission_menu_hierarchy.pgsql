-- SandIAM 0.7.3: repair the 100 legacy permission leaves created before the
-- task-path menu consolidation. Keep every permission slug and row id stable,
-- so existing role grants remain attached to the same logical permission.
BEGIN;

LOCK TABLE sand_system_menu, sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;

DO $$
DECLARE
    desired record;
    target_permission_id bigint;
    old_permission_id bigint;
    old_permission_rows integer;
    old_permission_status smallint;
    new_permission_id bigint;
    new_permission_rows integer;
    new_permission_status smallint;
    active_slug_rows integer;
    target_parent_id bigint;
    parent_rows integer;
    conflicting_code_rows integer;
    matched_permissions integer := 0;
    role_set_before text;
    role_set_after text;
BEGIN
    WITH resource_def(resource_code, resource_name, parent_code, actions) AS (
        VALUES
            ('organization', '客户主体', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
            ('application', '接入应用', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
            ('environment', '应用环境', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
            ('client', '服务调用身份', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
            ('grant', '服务授权', 'SandIAMConnection', ARRAY['index','read','save','update','revoke']::text[]),
            ('credential', '调用凭证', 'SandIAMConnection', ARRAY['index','read','issue','rotate','revoke']::text[]),
            ('service', '平台服务', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
            ('service_action', '服务动作', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
            ('identity_provider', '身份源', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
            ('identity', '应用用户', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
            ('identity_binding', '身份绑定', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
            ('user_type', '用户类型', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
            ('role', '角色', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
            ('identity_user_type', '身份用户类型', 'SandIAMPeopleAccess', ARRAY['index','grant','revoke']::text[]),
            ('identity_role', '身份角色', 'SandIAMPeopleAccess', ARRAY['index','grant','revoke']::text[]),
            ('resource', '业务资源', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
            ('policy', '授权策略', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable','publish','revoke']::text[]),
            ('admin_organization_grant', '客户主体管理委派', 'SandIAMAdminScope', ARRAY['index','read','save','update','disable']::text[]),
            ('audit', '访问审计', 'SandIAMAuditTroubleshooting', ARRAY['index','read']::text[]),
            ('auth_policy', '认证策略', 'SandIAMAuthSession', ARRAY['index','read','save','update','disable']::text[])
    ), generated_permission AS (
        SELECT
            'sand_iam:' || resource_def.resource_code || ':' || action.code AS slug,
            resource_def.parent_code,
            CASE action.code
                WHEN 'index' THEN resource_def.resource_name || '列表'
                WHEN 'read' THEN '查看' || resource_def.resource_name
                WHEN 'save' THEN '新增' || resource_def.resource_name
                WHEN 'update' THEN '编辑' || resource_def.resource_name
                WHEN 'disable' THEN '停用' || resource_def.resource_name
                WHEN 'revoke' THEN '撤销' || resource_def.resource_name
                WHEN 'issue' THEN '签发' || resource_def.resource_name
                WHEN 'rotate' THEN '轮换' || resource_def.resource_name
                WHEN 'grant' THEN '授予' || resource_def.resource_name
                WHEN 'publish' THEN '发布' || resource_def.resource_name
                ELSE NULL
            END AS permission_name
        FROM resource_def
        CROSS JOIN LATERAL unnest(resource_def.actions) AS action(code)
    ), special_permission(slug, parent_code, permission_name) AS (
        VALUES
            ('sand_iam:federation:create', 'SandIAMFederationConfig', '创建联合身份源'),
            ('sand_iam:federation:configure', 'SandIAMFederationConfig', '配置联合身份源'),
            ('sand_iam:federation:mount', 'SandIAMFederationConfig', '挂载联合身份源'),
            ('sand_iam:federation:sync', 'SandIAMFederationConfig', '同步企业目录'),
            ('sand_iam:scim:token_issue', 'SandIAMScimTokens', '签发 SCIM 令牌')
    ), permission_def AS (
        SELECT slug, parent_code, permission_name FROM generated_permission
        UNION ALL
        SELECT slug, parent_code, permission_name FROM special_permission
    )
    SELECT md5(COALESCE(string_agg(logical_grant.role_id::text || ':' || logical_grant.slug, ',' ORDER BY logical_grant.role_id, logical_grant.slug), ''))
    INTO role_set_before
    FROM (
        SELECT DISTINCT role_menu.role_id, permission.slug
        FROM permission_def
        JOIN sand_system_menu permission ON permission.slug = permission_def.slug
        JOIN sand_system_role_menu role_menu ON role_menu.menu_id = permission.id
    ) logical_grant;

    FOR desired IN
        WITH resource_def(resource_code, resource_name, parent_code, actions) AS (
            VALUES
                ('organization', '客户主体', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
                ('application', '接入应用', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
                ('environment', '应用环境', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
                ('client', '服务调用身份', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
                ('grant', '服务授权', 'SandIAMConnection', ARRAY['index','read','save','update','revoke']::text[]),
                ('credential', '调用凭证', 'SandIAMConnection', ARRAY['index','read','issue','rotate','revoke']::text[]),
                ('service', '平台服务', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
                ('service_action', '服务动作', 'SandIAMConnection', ARRAY['index','read','save','update','disable']::text[]),
                ('identity_provider', '身份源', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
                ('identity', '应用用户', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
                ('identity_binding', '身份绑定', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
                ('user_type', '用户类型', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
                ('role', '角色', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
                ('identity_user_type', '身份用户类型', 'SandIAMPeopleAccess', ARRAY['index','grant','revoke']::text[]),
                ('identity_role', '身份角色', 'SandIAMPeopleAccess', ARRAY['index','grant','revoke']::text[]),
                ('resource', '业务资源', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable']::text[]),
                ('policy', '授权策略', 'SandIAMPeopleAccess', ARRAY['index','read','save','update','disable','publish','revoke']::text[]),
                ('admin_organization_grant', '客户主体管理委派', 'SandIAMAdminScope', ARRAY['index','read','save','update','disable']::text[]),
                ('audit', '访问审计', 'SandIAMAuditTroubleshooting', ARRAY['index','read']::text[]),
                ('auth_policy', '认证策略', 'SandIAMAuthSession', ARRAY['index','read','save','update','disable']::text[])
        ), generated_permission AS (
            SELECT
                'sand_iam:' || resource_def.resource_code || ':' || action.code AS slug,
                resource_def.parent_code,
                CASE action.code
                    WHEN 'index' THEN resource_def.resource_name || '列表'
                    WHEN 'read' THEN '查看' || resource_def.resource_name
                    WHEN 'save' THEN '新增' || resource_def.resource_name
                    WHEN 'update' THEN '编辑' || resource_def.resource_name
                    WHEN 'disable' THEN '停用' || resource_def.resource_name
                    WHEN 'revoke' THEN '撤销' || resource_def.resource_name
                    WHEN 'issue' THEN '签发' || resource_def.resource_name
                    WHEN 'rotate' THEN '轮换' || resource_def.resource_name
                    WHEN 'grant' THEN '授予' || resource_def.resource_name
                    WHEN 'publish' THEN '发布' || resource_def.resource_name
                    ELSE NULL
                END AS permission_name
            FROM resource_def
            CROSS JOIN LATERAL unnest(resource_def.actions) AS action(code)
        ), special_permission(slug, parent_code, permission_name) AS (
            VALUES
                ('sand_iam:federation:create', 'SandIAMFederationConfig', '创建联合身份源'),
                ('sand_iam:federation:configure', 'SandIAMFederationConfig', '配置联合身份源'),
                ('sand_iam:federation:mount', 'SandIAMFederationConfig', '挂载联合身份源'),
                ('sand_iam:federation:sync', 'SandIAMFederationConfig', '同步企业目录'),
                ('sand_iam:scim:token_issue', 'SandIAMScimTokens', '签发 SCIM 令牌')
        )
        SELECT slug, parent_code, permission_name FROM generated_permission
        UNION ALL
        SELECT slug, parent_code, permission_name FROM special_permission
        ORDER BY slug
    LOOP
        IF desired.permission_name IS NULL OR desired.permission_name = '' THEN
            RAISE EXCEPTION 'SandIAM permission hierarchy has no explicit name for %', desired.slug;
        END IF;

        SELECT count(*), min(id), min(status) INTO old_permission_rows, old_permission_id, old_permission_status
        FROM sand_system_menu
        WHERE slug = desired.slug
          AND type = 3
          AND code = ''
          AND delete_time IS NULL;

        SELECT count(*), min(id), min(status) INTO new_permission_rows, new_permission_id, new_permission_status
        FROM sand_system_menu
        WHERE slug = desired.slug
          AND type = 3
          AND code = desired.slug
          AND delete_time IS NULL;

        SELECT count(*) INTO active_slug_rows
        FROM sand_system_menu
        WHERE slug = desired.slug
          AND delete_time IS NULL;
        IF active_slug_rows <> old_permission_rows + new_permission_rows THEN
            RAISE EXCEPTION
                'SandIAM permission hierarchy found an unrecognized active row shape for %; active %, legacy %, repaired %',
                desired.slug, active_slug_rows, old_permission_rows, new_permission_rows;
        END IF;

        IF old_permission_rows = 1 AND new_permission_rows = 0 THEN
            target_permission_id := old_permission_id;
        ELSIF old_permission_rows = 1 AND new_permission_rows = 1 THEN
            IF old_permission_status IS DISTINCT FROM new_permission_status THEN
                RAISE EXCEPTION 'SandIAM permission hierarchy refuses conflicting active status for %', desired.slug;
            END IF;
            target_permission_id := old_permission_id;
            INSERT INTO sand_system_role_menu (role_id, menu_id)
            SELECT duplicate_role.role_id, target_permission_id
            FROM sand_system_role_menu duplicate_role
            WHERE duplicate_role.menu_id = new_permission_id
              AND NOT EXISTS (
                  SELECT 1 FROM sand_system_role_menu retained_role
                  WHERE retained_role.role_id = duplicate_role.role_id
                    AND retained_role.menu_id = target_permission_id
              );
            DELETE FROM sand_system_role_menu WHERE menu_id = new_permission_id;
            DELETE FROM sand_system_menu WHERE id = new_permission_id;
        ELSIF old_permission_rows = 0 AND new_permission_rows = 1 THEN
            target_permission_id := new_permission_id;
        ELSE
            RAISE EXCEPTION
                'SandIAM permission hierarchy requires one legacy row, one repaired row, or one of each for %; found legacy %, repaired %',
                desired.slug, old_permission_rows, new_permission_rows;
        END IF;

        SELECT count(*), min(parent.id) INTO parent_rows, target_parent_id
        FROM sand_system_menu parent
        JOIN sand_system_menu root
          ON root.id = parent.parent_id
         AND root.code = 'SandIAM'
         AND root.delete_time IS NULL
        WHERE parent.code = desired.parent_code
          AND parent.type IN (1, 2)
          AND parent.status = 1
          AND parent.delete_time IS NULL;
        IF parent_rows <> 1 THEN
            RAISE EXCEPTION 'SandIAM permission hierarchy requires exactly one active parent %, found %', desired.parent_code, parent_rows;
        END IF;

        SELECT count(*) INTO conflicting_code_rows
        FROM sand_system_menu
        WHERE code = desired.slug
          AND id <> target_permission_id
          AND delete_time IS NULL;
        IF conflicting_code_rows <> 0 THEN
            RAISE EXCEPTION 'SandIAM permission hierarchy found another active code row for %', desired.slug;
        END IF;

        UPDATE sand_system_menu
        SET parent_id = target_parent_id,
            name = desired.permission_name,
            code = desired.slug,
            type = 3,
            path = '',
            component = '',
            icon = '',
            is_hidden = 1,
            update_time = CURRENT_TIMESTAMP
        WHERE id = target_permission_id
          AND (
              sand_system_menu.parent_id IS DISTINCT FROM target_parent_id
              OR sand_system_menu.name IS DISTINCT FROM desired.permission_name
              OR sand_system_menu.code IS DISTINCT FROM desired.slug
              OR sand_system_menu.type IS DISTINCT FROM 3
              OR sand_system_menu.path IS DISTINCT FROM ''
              OR sand_system_menu.component IS DISTINCT FROM ''
              OR sand_system_menu.icon IS DISTINCT FROM ''
              OR sand_system_menu.is_hidden IS DISTINCT FROM 1
          );
        matched_permissions := matched_permissions + 1;
    END LOOP;

    IF matched_permissions <> 100 THEN
        RAISE EXCEPTION 'SandIAM permission hierarchy expected 100 legacy permissions, found %', matched_permissions;
    END IF;

    WITH resource_def(resource_code, actions) AS (
        VALUES
            ('organization', ARRAY['index','read','save','update','disable']::text[]),
            ('application', ARRAY['index','read','save','update','disable']::text[]),
            ('environment', ARRAY['index','read','save','update','disable']::text[]),
            ('client', ARRAY['index','read','save','update','disable']::text[]),
            ('grant', ARRAY['index','read','save','update','revoke']::text[]),
            ('credential', ARRAY['index','read','issue','rotate','revoke']::text[]),
            ('service', ARRAY['index','read','save','update','disable']::text[]),
            ('service_action', ARRAY['index','read','save','update','disable']::text[]),
            ('identity_provider', ARRAY['index','read','save','update','disable']::text[]),
            ('identity', ARRAY['index','read','save','update','disable']::text[]),
            ('identity_binding', ARRAY['index','read','save','update','disable']::text[]),
            ('user_type', ARRAY['index','read','save','update','disable']::text[]),
            ('role', ARRAY['index','read','save','update','disable']::text[]),
            ('identity_user_type', ARRAY['index','grant','revoke']::text[]),
            ('identity_role', ARRAY['index','grant','revoke']::text[]),
            ('resource', ARRAY['index','read','save','update','disable']::text[]),
            ('policy', ARRAY['index','read','save','update','disable','publish','revoke']::text[]),
            ('admin_organization_grant', ARRAY['index','read','save','update','disable']::text[]),
            ('audit', ARRAY['index','read']::text[]),
            ('auth_policy', ARRAY['index','read','save','update','disable']::text[])
    ), permission_def(slug) AS (
        SELECT 'sand_iam:' || resource_def.resource_code || ':' || action.code
        FROM resource_def
        CROSS JOIN LATERAL unnest(resource_def.actions) AS action(code)
        UNION ALL VALUES
            ('sand_iam:federation:create'),
            ('sand_iam:federation:configure'),
            ('sand_iam:federation:mount'),
            ('sand_iam:federation:sync'),
            ('sand_iam:scim:token_issue')
    )
    SELECT md5(COALESCE(string_agg(logical_grant.role_id::text || ':' || logical_grant.slug, ',' ORDER BY logical_grant.role_id, logical_grant.slug), ''))
    INTO role_set_after
    FROM (
        SELECT DISTINCT role_menu.role_id, permission.slug
        FROM permission_def
        JOIN sand_system_menu permission ON permission.slug = permission_def.slug
        JOIN sand_system_role_menu role_menu ON role_menu.menu_id = permission.id
    ) logical_grant;

    IF role_set_after IS DISTINCT FROM role_set_before THEN
        RAISE EXCEPTION 'SandIAM permission hierarchy changed the logical role-permission set';
    END IF;
END $$;

DO $$
DECLARE recorded record;
BEGIN
    SELECT revision, checksum, package_version INTO recorded
    FROM sand_iam_schema_migration
    WHERE migration_file = '042_permission_menu_hierarchy.pgsql';
    IF recorded.revision IS NOT NULL AND (
        recorded.revision <> 42
        OR recorded.checksum <> '62d79e86170788d40d39b528b604269e439e0d3588c011154c8e12080564fbeb'
        OR recorded.package_version <> '0.7.3'
    ) THEN
        RAISE EXCEPTION 'SandIAM migration 042 ledger identity conflicts with permission menu hierarchy repair';
    END IF;
END $$;

WITH self_checksum(checksum) AS (VALUES ('62d79e86170788d40d39b528b604269e439e0d3588c011154c8e12080564fbeb'))
INSERT INTO sand_iam_schema_migration (migration_file, revision, checksum, package_version, executed_time)
SELECT '042_permission_menu_hierarchy.pgsql', 42, self_checksum.checksum, '0.7.3', CURRENT_TIMESTAMP
FROM self_checksum
ON CONFLICT (migration_file) DO NOTHING;

DO $$
DECLARE recorded_rows integer;
BEGIN
    SELECT count(*) INTO recorded_rows
    FROM sand_iam_schema_migration
    WHERE migration_file = '042_permission_menu_hierarchy.pgsql'
      AND revision = 42
      AND checksum = '62d79e86170788d40d39b528b604269e439e0d3588c011154c8e12080564fbeb'
      AND package_version = '0.7.3';
    IF recorded_rows <> 1 OR (SELECT count(*) FROM sand_iam_schema_migration) <> 43 THEN
        RAISE EXCEPTION 'SandIAM migration 042 did not close the exact 001-042 ledger';
    END IF;
END $$;

COMMIT;
