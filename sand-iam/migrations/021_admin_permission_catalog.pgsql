-- SandIAM terminal administration permission catalog.
-- Permission nodes stay hidden; operators see task paths instead of a flat
-- list of machine actions. Chinese names describe the actual operation.
BEGIN;

WITH permission_group(resource_code, resource_name, parent_code, base_sort, actions) AS (
    VALUES
        ('admin_application_grant', '应用管理委派', 'SandIAMConnection', 2100, ARRAY['index','read','save','update','disable']::text[]),
        ('application_experience', '登录与用户体验', 'SandIAMConnection', 2080, ARRAY['index','read','save','update','disable']::text[]),
        ('message_provider', '消息服务商', 'SandIAMConnection', 2060, ARRAY['index','read','save','update','configure','test','disable']::text[]),
        ('message_provider_mount', '应用消息服务', 'SandIAMConnection', 2040, ARRAY['index','save','disable']::text[]),
        ('initialization', '应用初始化方案', 'SandIAMConnection', 2020, ARRAY['index','read','preview','apply','rollback','export']::text[]),
        -- Explicit assignment only: onboarding can create or change several
        -- application-scoped authorization records in one operation.
        ('onboarding', '开发者接入', 'SandIAMConnection', 2010, ARRAY['preview','apply']::text[]),

        ('api_resource', '接口资源', 'SandIAMPeopleAccess', 2000, ARRAY['index','read','save','update','disable']::text[]),
        ('api_route_binding', '接口路由绑定', 'SandIAMPeopleAccess', 1980, ARRAY['index','read','save','update','disable']::text[]),
        ('cas_service', 'CAS 接入服务', 'SandIAMPeopleAccess', 1960, ARRAY['index','read','save','update','disable']::text[]),
        ('identity', '应用用户', 'SandIAMPeopleAccess', 1940, ARRAY['enable','delete','restore']::text[]),
        ('identity_export', '应用用户导出', 'SandIAMPeopleAccess', 1920, ARRAY['masked','sensitive']::text[]),
        ('identity_group', '用户组', 'SandIAMPeopleAccess', 1900, ARRAY['index','read','save','update','disable']::text[]),
        ('identity_group_member', '用户组成员', 'SandIAMPeopleAccess', 1880, ARRAY['index','add','remove']::text[]),
        ('identity_import', '应用用户导入', 'SandIAMPeopleAccess', 1860, ARRAY['index','read','preview','confirm']::text[]),
        ('identity_invitation', '用户邀请', 'SandIAMPeopleAccess', 1840, ARRAY['index','read','send','resend','revoke']::text[]),
        ('oauth_registration_token', 'OAuth 动态注册令牌', 'SandIAMPeopleAccess', 1820, ARRAY['index','issue','revoke']::text[]),
        ('radius_nas', 'RADIUS 接入设备', 'SandIAMPeopleAccess', 1800, ARRAY['index','read','save','update','configure','disable']::text[]),
        ('sync_connector', '身份同步连接器', 'SandIAMPeopleAccess', 1780, ARRAY['index','read','save','update','configure','test','disable']::text[]),
        ('sync_run', '身份同步任务', 'SandIAMPeopleAccess', 1760, ARRAY['index','run']::text[]),

        ('audit', '访问审计', 'SandIAMAuditTroubleshooting', 1740, ARRAY['export']::text[]),
        ('application_network_policy', '应用访问网络规则', 'SandIAMAuditTroubleshooting', 1720, ARRAY['index','read','save','update','disable']::text[]),
        ('audit_retention_policy', '审计保留规则', 'SandIAMAuditTroubleshooting', 1700, ARRAY['index','read','save','update','disable']::text[]),
        ('developer', '开发者资料', 'SandIAMAuditTroubleshooting', 1680, ARRAY['openapi','events']::text[]),
        ('security_alert', '安全告警', 'SandIAMAuditTroubleshooting', 1660, ARRAY['index','read','resolve']::text[]),
        ('webhook', '事件通知地址', 'SandIAMAuditTroubleshooting', 1640, ARRAY['index','read','save','update','disable']::text[]),
        ('webhook_delivery', '事件通知记录', 'SandIAMAuditTroubleshooting', 1620, ARRAY['index','read','retry']::text[])
), permission_def AS (
    SELECT
        permission_group.resource_code,
        permission_group.resource_name,
        permission_group.parent_code,
        permission_group.base_sort + action.position::integer AS sort,
        action.code AS action_code,
        'sand_iam:' || permission_group.resource_code || ':' || action.code AS permission_code,
        CASE
            WHEN permission_group.resource_code = 'onboarding' AND action.code = 'preview' THEN '开发者接入预检'
            WHEN permission_group.resource_code = 'onboarding' AND action.code = 'apply' THEN '确认应用开发者接入清单'
            ELSE permission_group.resource_name || CASE action.code
            WHEN 'index' THEN '查看'
            WHEN 'read' THEN '查看详情'
            WHEN 'save' THEN '新建'
            WHEN 'update' THEN '编辑'
            WHEN 'disable' THEN '停用'
            WHEN 'enable' THEN '启用'
            WHEN 'delete' THEN '删除'
            WHEN 'restore' THEN '恢复'
            WHEN 'export' THEN '导出'
            WHEN 'masked' THEN '脱敏导出'
            WHEN 'sensitive' THEN '敏感导出'
            WHEN 'add' THEN '添加'
            WHEN 'remove' THEN '移除'
            WHEN 'preview' THEN '预检'
            WHEN 'confirm' THEN '确认导入'
            WHEN 'send' THEN '发出邀请'
            WHEN 'resend' THEN '重新发送'
            WHEN 'revoke' THEN '撤销'
            WHEN 'configure' THEN '配置'
            WHEN 'test' THEN '测试连接'
            WHEN 'openapi' THEN '查看接口文档'
            WHEN 'events' THEN '查看事件目录'
            WHEN 'apply' THEN '应用方案'
            WHEN 'rollback' THEN '回滚'
            WHEN 'issue' THEN '签发'
            WHEN 'resolve' THEN '处理'
            WHEN 'run' THEN '执行'
            WHEN 'retry' THEN '重试'
            ELSE action.code
            END
        END AS permission_name
    FROM permission_group
    CROSS JOIN LATERAL unnest(permission_group.actions) WITH ORDINALITY AS action(code, position)
), permission_menu AS (
    SELECT parent.id AS parent_id,
           permission_def.resource_code,
           permission_def.resource_name,
           permission_def.parent_code,
           permission_def.sort,
           permission_def.action_code,
           permission_def.permission_code,
           permission_def.permission_code AS slug,
           permission_def.permission_name
    FROM permission_def
    JOIN LATERAL (
        SELECT id FROM sand_system_menu WHERE code = permission_def.parent_code ORDER BY id LIMIT 1
    ) parent ON TRUE
), refresh_existing AS (
    -- Earlier 021 revisions used a short dashed slug. SandAdmin's non-super
    -- administrator permission middleware compares the type=3 slug with the
    -- controller Permission code, so correct existing rows before inserting.
    UPDATE sand_system_menu existing
    SET parent_id = desired.parent_id,
        name = desired.permission_name,
        slug = desired.permission_code,
        is_hidden = 1,
        status = 1,
        update_time = CURRENT_TIMESTAMP
    FROM permission_menu desired
    WHERE existing.type = 3
      AND existing.code = desired.permission_code
      AND (
          existing.parent_id IS DISTINCT FROM desired.parent_id
          OR existing.name IS DISTINCT FROM desired.permission_name
          OR existing.slug IS DISTINCT FROM desired.permission_code
          OR existing.is_hidden IS DISTINCT FROM 1
          OR existing.status IS DISTINCT FROM 1
      )
    RETURNING existing.id
)
INSERT INTO sand_system_menu (parent_id, name, code, slug, type, path, component, icon, sort, is_hidden, status, create_time, update_time)
SELECT permission_menu.parent_id, permission_menu.permission_name, permission_menu.permission_code, permission_menu.slug,
       3, '', '', '', permission_menu.sort, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM permission_menu
WHERE NOT EXISTS (
    SELECT 1 FROM sand_system_menu known WHERE known.code = permission_menu.permission_code
);

INSERT INTO sand_system_role_menu (role_id, menu_id)
SELECT DISTINCT inherited.role_id, permission.id
FROM sand_system_role_menu inherited
JOIN sand_system_menu parent
  ON parent.id = inherited.menu_id
 AND parent.code IN ('SandIAMConnection', 'SandIAMPeopleAccess', 'SandIAMAuditTroubleshooting')
JOIN sand_system_menu permission
  ON permission.parent_id = parent.id
 AND permission.code LIKE 'sand\_iam:%' ESCAPE '\'
 AND permission.code NOT IN (
     'sand_iam:onboarding:preview',
     'sand_iam:onboarding:apply',
     'sand_iam:api_resource:index',
     'sand_iam:api_route_binding:index'
 )
WHERE NOT EXISTS (
    SELECT 1
    FROM sand_system_role_menu existing
    WHERE existing.role_id = inherited.role_id
      AND existing.menu_id = permission.id
);

COMMIT;
