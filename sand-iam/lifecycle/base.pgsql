-- SandIAM current PostgreSQL installation base. Migration source range is 001–033;
-- this generated menu payload is rebuilt from the authoritative lifecycle sources.

-- SandIAM 管理入口与既有权限码。按 code / slug 幂等更新，保留已存在的菜单 ID 与角色绑定。
INSERT INTO "sand_system_menu"
    ("parent_id","name","code","slug","type","path","component","icon","sort","status","create_time","update_time")
SELECT 0,'SandIAM 身份与访问','SandIAM','',1,'/sand-iam','','ri:shield-user-line',98,1,NOW(),NOW()
WHERE NOT EXISTS (SELECT 1 FROM "sand_system_menu" WHERE "code" = 'SandIAM');

UPDATE "sand_system_menu"
SET "name" = 'SandIAM 身份与访问',
    "path" = '/sand-iam',
    "icon" = 'ri:shield-user-line',
    "sort" = 98,
    "status" = 1,
    "update_time" = NOW()
WHERE "code" = 'SandIAM';

WITH menu_def("code","name","path","component","sort","is_hidden") AS (
    VALUES
        ('SandIAMOverview','管理总览','overview','/plugin/sand-iam/index/index',100,2),
        ('SandIAMConnection','客户与应用接入','connection','/plugin/sand-iam/connection/index',99,2),
        ('SandIAMPeopleAccess','应用用户与权限','people-access','/plugin/sand-iam/people-access/index',98,2),
        ('SandIAMAuditTroubleshooting','审计与排错','audit-troubleshooting','/plugin/sand-iam/audit-troubleshooting/index',97,2),
        ('SandIAMApiGovernance','接口与访问控制','api-governance','/plugin/sand-iam/api-governance/index',96,2),
        ('SandIAMAuthSession','认证与会话','auth-session','/plugin/sand-iam/auth-session/index',95,2),
        ('SandIAMEventNotification','事件通知','event-notification','/plugin/sand-iam/event-notification/index',94,2),
        ('SandIAMGettingStarted','第一次使用','getting-started','/plugin/sand-iam/getting-started/index',93,1),
        ('SandIAMOrganization','客户主体（组织）','organization','/plugin/sand-iam/organization/index',89,1),
        ('SandIAMApplication','接入应用','application','/plugin/sand-iam/application/index',88,1),
        ('SandIAMEnvironment','应用环境','environment','/plugin/sand-iam/environment/index',87,1),
        ('SandIAMWorkload','服务调用身份','workload-client','/plugin/sand-iam/workload-client/index',86,1),
        ('SandIAMGrant','服务授权','service-grant','/plugin/sand-iam/service-grant/index',85,1),
        ('SandIAMCredential','调用凭证','credential','/plugin/sand-iam/credential/index',84,1),
        ('SandIAMService','平台服务','service','/plugin/sand-iam/service/index',83,1),
        ('SandIAMServiceAction','服务动作','action','/plugin/sand-iam/action/index',82,1),
        ('SandIAMIdentityProvider','身份源','identity-provider','/plugin/sand-iam/identity-provider/index',81,1),
        ('SandIAMIdentity','应用用户身份','identity','/plugin/sand-iam/identity/index',80,1),
        ('SandIAMIdentityBinding','身份绑定','identity-binding','/plugin/sand-iam/identity-binding/index',79,1),
        ('SandIAMUserType','用户类型','user-type','/plugin/sand-iam/user-type/index',78,1),
        ('SandIAMRole','角色','role','/plugin/sand-iam/role/index',77,1),
        ('SandIAMIdentityUserType','身份用户类型','identity-user-type','/plugin/sand-iam/identity-user-type/index',76,1),
        ('SandIAMIdentityRole','身份角色','identity-role','/plugin/sand-iam/identity-role/index',75,1),
        ('SandIAMResource','业务资源','resource','/plugin/sand-iam/resource/index',74,1),
        ('SandIAMPolicy','授权策略','policy','/plugin/sand-iam/policy/index',73,1),
        ('SandIAMAdminScope','管理范围','admin-scope','/plugin/sand-iam/admin-scope/index',72,1),
        ('SandIAMAdminOrganizationGrant','客户主体管理委派','admin-organization-grant','/plugin/sand-iam/admin-organization-grant/index',72,1),
        ('SandIAMAudit','访问审计','audit','/plugin/sand-iam/audit/index',71,1),
        ('SandIAMApplicationBusinessAction','应用业务动作','application-business-action','/plugin/sand-iam/application-business-action/index',70,2),
        ('SandIAMApiResource','接口目录','api-resource','/plugin/sand-iam/api-resource/index',69,2),
        ('SandIAMApiRouteBinding','路由绑定','api-route-binding','/plugin/sand-iam/api-route-binding/index',68,2),
        ('SandIAMRouteManifest','路由清单','route-manifest','/plugin/sand-iam/route-manifest/index',67,2),
        ('SandIAMPolicySimulate','策略模拟','policy-simulate','/plugin/sand-iam/policy-simulate/index',66,2),
        ('SandIAMAuthPolicy','认证设置','auth-policy','/plugin/sand-iam/auth-policy/index',65,1),
        ('SandIAMApplicationExperience','登录外观','application-experience','/plugin/sand-iam/application-experience/index',64,1),
        ('SandIAMMessageProvider','消息服务','message-provider','/plugin/sand-iam/message-provider/index',63,1),
        ('SandIAMFederationConfig','联合配置','federation-config','/plugin/sand-iam/federation-config/index',62,1),
        ('SandIAMScimTokens','SCIM 令牌','scim-tokens','/plugin/sand-iam/scim-tokens/index',61,1),
        ('SandIAMRadiusNas','RADIUS 网络设备','radius-nas','/plugin/sand-iam/radius-nas/index',60,1),
        ('SandIAMOAuthClient','OAuth 客户端','oauth-client','/plugin/sand-iam/oauth-client/index',59,1),
        ('SandIAMOAuthRegistrationToken','动态注册令牌','oauth-registration-token','/plugin/sand-iam/oauth-registration-token/index',58,1),
        ('SandIAMCasService','CAS 接入服务','cas-service','/plugin/sand-iam/cas-service/index',57,1),
        ('SandIAMDeveloperDocs','开发者接入','developer-docs','/plugin/sand-iam/developer-docs/index',56,1),
        ('SandIAMAdminApplicationGrant','应用管理员委派','admin-application-grant','/plugin/sand-iam/admin-application-grant/index',55,1),
        ('SandIAMIdentityGroup','用户组','identity-group','/plugin/sand-iam/identity-group/index',54,1),
        ('SandIAMIdentityInvitation','用户邀请','identity-invitation','/plugin/sand-iam/identity-invitation/index',53,1),
        ('SandIAMIdentityImport','用户导入导出','identity-import','/plugin/sand-iam/identity-import/index',52,1),
        ('SandIAMSyncConnector','用户同步','sync-connector','/plugin/sand-iam/sync-connector/index',51,1),
        ('SandIAMWebhook','事件通知地址','webhook','/plugin/sand-iam/webhook/index',50,1),
        ('SandIAMWebhookDelivery','投递记录','webhook-delivery','/plugin/sand-iam/webhook-delivery/index',49,1),
        ('SandIAMApplicationNetworkPolicy','应用网络规则','application-network-policy','/plugin/sand-iam/application-network-policy/index',48,1),
        ('SandIAMSecurityAlert','安全告警','security-alert','/plugin/sand-iam/security-alert/index',47,1),
        ('SandIAMAuditRetentionPolicy','审计保留策略','audit-retention-policy','/plugin/sand-iam/audit-retention-policy/index',46,1),
        ('SandIAMInitialization','初始化配置','initialization','/plugin/sand-iam/initialization/index',45,1)
    ), route_menu("code") AS (
    VALUES
        ('SandIAMConnection'),
        ('SandIAMPeopleAccess'),
        ('SandIAMAuthSession'),
        ('SandIAMAuditTroubleshooting'),
        ('SandIAMApiGovernance'),
        ('SandIAMAdminScope'),
        ('SandIAMEventNotification'),
        ('SandIAMGettingStarted'),
        ('SandIAMApplicationBusinessAction'),
        ('SandIAMApiResource'),
        ('SandIAMApiRouteBinding'),
        ('SandIAMRouteManifest'),
        ('SandIAMPolicySimulate'),
        ('SandIAMAuthPolicy'),
        ('SandIAMApplicationExperience'),
        ('SandIAMMessageProvider'),
        ('SandIAMFederationConfig'),
        ('SandIAMScimTokens'),
        ('SandIAMRadiusNas'),
        ('SandIAMOAuthClient'),
        ('SandIAMOAuthRegistrationToken'),
        ('SandIAMCasService'),
        ('SandIAMDeveloperDocs'),
        ('SandIAMAdminApplicationGrant'),
        ('SandIAMIdentityGroup'),
        ('SandIAMIdentityInvitation'),
        ('SandIAMIdentityImport'),
        ('SandIAMSyncConnector'),
        ('SandIAMWebhook'),
        ('SandIAMWebhookDelivery'),
        ('SandIAMApplicationNetworkPolicy'),
        ('SandIAMSecurityAlert'),
        ('SandIAMAuditRetentionPolicy'),
        ('SandIAMInitialization')
), canonical_route("id","code") AS (
    SELECT DISTINCT ON (candidate."code") candidate."id", candidate."code"
    FROM "sand_system_menu" candidate
    JOIN route_menu ON route_menu."code" = candidate."code"
    CROSS JOIN LATERAL (
        SELECT "id" FROM "sand_system_menu" WHERE "code" = 'SandIAM' ORDER BY "id" LIMIT 1
    ) root
    ORDER BY candidate."code",
             CASE WHEN candidate."parent_id" = root."id" AND candidate."type" = 2 THEN 0 ELSE 1 END,
             candidate."id"
)
UPDATE "sand_system_menu" menu
SET "parent_id" = CASE WHEN route_menu."code" IS NULL THEN menu."parent_id" ELSE root."id" END,
    "type" = CASE WHEN route_menu."code" IS NULL THEN menu."type" ELSE 2 END,
    "name" = menu_def."name",
    "path" = menu_def."path",
    "component" = menu_def."component",
    "sort" = menu_def."sort",
    "is_hidden" = menu_def."is_hidden",
    "status" = 1,
    "update_time" = NOW()
FROM menu_def
LEFT JOIN route_menu ON route_menu."code" = menu_def."code"
CROSS JOIN LATERAL (
    SELECT "id" FROM "sand_system_menu" WHERE "code" = 'SandIAM' ORDER BY "id" LIMIT 1
) root
LEFT JOIN canonical_route ON canonical_route."code" = menu_def."code"
WHERE menu."code" = menu_def."code"
  AND (route_menu."code" IS NULL OR menu."id" = canonical_route."id");

WITH menu_def("code","name","path","component","sort","is_hidden") AS (
    VALUES
        ('SandIAMOverview','管理总览','overview','/plugin/sand-iam/index/index',100,2),
        ('SandIAMConnection','客户与应用接入','connection','/plugin/sand-iam/connection/index',99,2),
        ('SandIAMPeopleAccess','应用用户与权限','people-access','/plugin/sand-iam/people-access/index',98,2),
        ('SandIAMAuditTroubleshooting','审计与排错','audit-troubleshooting','/plugin/sand-iam/audit-troubleshooting/index',97,2),
        ('SandIAMApiGovernance','接口与访问控制','api-governance','/plugin/sand-iam/api-governance/index',96,2),
        ('SandIAMAuthSession','认证与会话','auth-session','/plugin/sand-iam/auth-session/index',95,2),
        ('SandIAMEventNotification','事件通知','event-notification','/plugin/sand-iam/event-notification/index',94,2),
        ('SandIAMGettingStarted','第一次使用','getting-started','/plugin/sand-iam/getting-started/index',93,1),
        ('SandIAMOrganization','客户主体（组织）','organization','/plugin/sand-iam/organization/index',89,1),
        ('SandIAMApplication','接入应用','application','/plugin/sand-iam/application/index',88,1),
        ('SandIAMEnvironment','应用环境','environment','/plugin/sand-iam/environment/index',87,1),
        ('SandIAMWorkload','服务调用身份','workload-client','/plugin/sand-iam/workload-client/index',86,1),
        ('SandIAMGrant','服务授权','service-grant','/plugin/sand-iam/service-grant/index',85,1),
        ('SandIAMCredential','调用凭证','credential','/plugin/sand-iam/credential/index',84,1),
        ('SandIAMService','平台服务','service','/plugin/sand-iam/service/index',83,1),
        ('SandIAMServiceAction','服务动作','action','/plugin/sand-iam/action/index',82,1),
        ('SandIAMIdentityProvider','身份源','identity-provider','/plugin/sand-iam/identity-provider/index',81,1),
        ('SandIAMIdentity','应用用户身份','identity','/plugin/sand-iam/identity/index',80,1),
        ('SandIAMIdentityBinding','身份绑定','identity-binding','/plugin/sand-iam/identity-binding/index',79,1),
        ('SandIAMUserType','用户类型','user-type','/plugin/sand-iam/user-type/index',78,1),
        ('SandIAMRole','角色','role','/plugin/sand-iam/role/index',77,1),
        ('SandIAMIdentityUserType','身份用户类型','identity-user-type','/plugin/sand-iam/identity-user-type/index',76,1),
        ('SandIAMIdentityRole','身份角色','identity-role','/plugin/sand-iam/identity-role/index',75,1),
        ('SandIAMResource','业务资源','resource','/plugin/sand-iam/resource/index',74,1),
        ('SandIAMPolicy','授权策略','policy','/plugin/sand-iam/policy/index',73,1),
        ('SandIAMAdminScope','管理范围','admin-scope','/plugin/sand-iam/admin-scope/index',72,1),
        ('SandIAMAdminOrganizationGrant','客户主体管理委派','admin-organization-grant','/plugin/sand-iam/admin-organization-grant/index',72,1),
        ('SandIAMAudit','访问审计','audit','/plugin/sand-iam/audit/index',71,1),
        ('SandIAMApplicationBusinessAction','应用业务动作','application-business-action','/plugin/sand-iam/application-business-action/index',70,2),
        ('SandIAMApiResource','接口目录','api-resource','/plugin/sand-iam/api-resource/index',69,2),
        ('SandIAMApiRouteBinding','路由绑定','api-route-binding','/plugin/sand-iam/api-route-binding/index',68,2),
        ('SandIAMRouteManifest','路由清单','route-manifest','/plugin/sand-iam/route-manifest/index',67,2),
        ('SandIAMPolicySimulate','策略模拟','policy-simulate','/plugin/sand-iam/policy-simulate/index',66,2),
        ('SandIAMAuthPolicy','认证设置','auth-policy','/plugin/sand-iam/auth-policy/index',65,1),
        ('SandIAMApplicationExperience','登录外观','application-experience','/plugin/sand-iam/application-experience/index',64,1),
        ('SandIAMMessageProvider','消息服务','message-provider','/plugin/sand-iam/message-provider/index',63,1),
        ('SandIAMFederationConfig','联合配置','federation-config','/plugin/sand-iam/federation-config/index',62,1),
        ('SandIAMScimTokens','SCIM 令牌','scim-tokens','/plugin/sand-iam/scim-tokens/index',61,1),
        ('SandIAMRadiusNas','RADIUS 网络设备','radius-nas','/plugin/sand-iam/radius-nas/index',60,1),
        ('SandIAMOAuthClient','OAuth 客户端','oauth-client','/plugin/sand-iam/oauth-client/index',59,1),
        ('SandIAMOAuthRegistrationToken','动态注册令牌','oauth-registration-token','/plugin/sand-iam/oauth-registration-token/index',58,1),
        ('SandIAMCasService','CAS 接入服务','cas-service','/plugin/sand-iam/cas-service/index',57,1),
        ('SandIAMDeveloperDocs','开发者接入','developer-docs','/plugin/sand-iam/developer-docs/index',56,1),
        ('SandIAMAdminApplicationGrant','应用管理员委派','admin-application-grant','/plugin/sand-iam/admin-application-grant/index',55,1),
        ('SandIAMIdentityGroup','用户组','identity-group','/plugin/sand-iam/identity-group/index',54,1),
        ('SandIAMIdentityInvitation','用户邀请','identity-invitation','/plugin/sand-iam/identity-invitation/index',53,1),
        ('SandIAMIdentityImport','用户导入导出','identity-import','/plugin/sand-iam/identity-import/index',52,1),
        ('SandIAMSyncConnector','用户同步','sync-connector','/plugin/sand-iam/sync-connector/index',51,1),
        ('SandIAMWebhook','事件通知地址','webhook','/plugin/sand-iam/webhook/index',50,1),
        ('SandIAMWebhookDelivery','投递记录','webhook-delivery','/plugin/sand-iam/webhook-delivery/index',49,1),
        ('SandIAMApplicationNetworkPolicy','应用网络规则','application-network-policy','/plugin/sand-iam/application-network-policy/index',48,1),
        ('SandIAMSecurityAlert','安全告警','security-alert','/plugin/sand-iam/security-alert/index',47,1),
        ('SandIAMAuditRetentionPolicy','审计保留策略','audit-retention-policy','/plugin/sand-iam/audit-retention-policy/index',46,1),
        ('SandIAMInitialization','初始化配置','initialization','/plugin/sand-iam/initialization/index',45,1)
)
INSERT INTO "sand_system_menu"
    ("parent_id","name","code","slug","type","path","component","icon","sort","is_hidden","status","create_time","update_time")
SELECT root."id", menu_def."name", menu_def."code", '', 2, menu_def."path", menu_def."component", '', menu_def."sort", menu_def."is_hidden", 1, NOW(), NOW()
FROM menu_def
JOIN LATERAL (
    SELECT "id" FROM "sand_system_menu" WHERE "code" = 'SandIAM' ORDER BY "id" LIMIT 1
) root ON TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM "sand_system_menu" existing WHERE existing."code" = menu_def."code"
);

-- 已有受限角色保留原实体权限时，同时补上新的任务路径入口；这里只补导航，不授予接口权限。
WITH path_entity("path_code","entity_code") AS (
    VALUES
        ('SandIAMConnection','SandIAMOrganization'),
        ('SandIAMConnection','SandIAMApplication'),
        ('SandIAMConnection','SandIAMEnvironment'),
        ('SandIAMConnection','SandIAMWorkload'),
        ('SandIAMConnection','SandIAMGrant'),
        ('SandIAMConnection','SandIAMCredential'),
        ('SandIAMConnection','SandIAMService'),
        ('SandIAMConnection','SandIAMServiceAction'),
        ('SandIAMPeopleAccess','SandIAMIdentityProvider'),
        ('SandIAMPeopleAccess','SandIAMIdentity'),
        ('SandIAMPeopleAccess','SandIAMIdentityBinding'),
        ('SandIAMPeopleAccess','SandIAMUserType'),
        ('SandIAMPeopleAccess','SandIAMRole'),
        ('SandIAMPeopleAccess','SandIAMIdentityUserType'),
        ('SandIAMPeopleAccess','SandIAMIdentityRole'),
        ('SandIAMPeopleAccess','SandIAMResource'),
        ('SandIAMPeopleAccess','SandIAMPolicy'),
        ('SandIAMPeopleAccess','SandIAMAdminScope'),
        ('SandIAMPeopleAccess','SandIAMAdminOrganizationGrant'),
        ('SandIAMAuditTroubleshooting','SandIAMAudit'),
        ('SandIAMAuditTroubleshooting','SandIAMGrant'),
        ('SandIAMAuditTroubleshooting','SandIAMCredential'),
        ('SandIAMApiGovernance','SandIAMApiResource'),
        ('SandIAMApiGovernance','SandIAMApiRouteBinding'),
        ('SandIAMApiGovernance','SandIAMPolicy'),
        ('SandIAMApiGovernance','SandIAMConnection'),
        ('SandIAMApplicationBusinessAction','SandIAMApiResource'),
        ('SandIAMApiResource','SandIAMApiResource'),
        ('SandIAMApiRouteBinding','SandIAMApiRouteBinding'),
        ('SandIAMRouteManifest','SandIAMConnection'),
        ('SandIAMPolicySimulate','SandIAMPolicy')
), route_menu("code") AS (
    SELECT menu."code"
    FROM "sand_system_menu" menu
    JOIN "sand_system_menu" root ON root."id" = menu."parent_id"
    WHERE root."code" = 'SandIAM'
      AND menu."type" = 2
), sandiam_role("role_id","entity_code") AS (
    SELECT DISTINCT role_menu."role_id", entity."code"
    FROM "sand_system_role_menu" role_menu
    JOIN "sand_system_menu" assigned ON assigned."id" = role_menu."menu_id"
    JOIN "sand_system_menu" entity
      ON entity."id" = assigned."id" OR entity."id" = assigned."parent_id"
    JOIN "sand_system_menu" root
      ON root."id" = entity."parent_id" AND root."code" = 'SandIAM'
    WHERE entity."code" <> 'SandIAMOverview'
      AND NOT EXISTS (
          SELECT 1 FROM route_menu WHERE route_menu."code" = entity."code"
      )
), route_menu_anchor("anchor_code","route_code") AS (
    VALUES
        ('SandIAMConnection','SandIAMApiGovernance'),
        ('SandIAMConnection','SandIAMRouteManifest'),
        ('SandIAMPolicy','SandIAMApiGovernance'),
        ('SandIAMPolicy','SandIAMPolicySimulate')
), route_permission_anchor("anchor_slug","route_code") AS (
    VALUES
        ('sand_iam:organization:index','SandIAMConnection'),
        ('sand_iam:identity:index','SandIAMPeopleAccess'),
        ('sand_iam:auth_policy:index','SandIAMAuthSession'),
        ('sand_iam:audit:index','SandIAMAuditTroubleshooting'),
        ('sand_iam:api_resource:index','SandIAMApiGovernance'),
        ('sand_iam:api_resource:index','SandIAMApplicationBusinessAction'),
        ('sand_iam:api_resource:index','SandIAMApiResource'),
        ('sand_iam:api_route_binding:index','SandIAMApiGovernance'),
        ('sand_iam:api_route_binding:index','SandIAMApiRouteBinding'),
        ('sand_iam:onboarding:preview','SandIAMRouteManifest'),
        ('sand_iam:policy:index','SandIAMPolicySimulate'),
        ('sand_iam:auth_policy:index','SandIAMAuthPolicy'),
        ('sand_iam:application_experience:index','SandIAMApplicationExperience'),
        ('sand_iam:message_provider:index','SandIAMMessageProvider'),
        ('sand_iam:federation:configure','SandIAMFederationConfig'),
        ('sand_iam:scim:token_index','SandIAMScimTokens'),
        ('sand_iam:radius_nas:index','SandIAMRadiusNas'),
        ('sand_iam:oauth_client:index','SandIAMOAuthClient'),
        ('sand_iam:oauth_registration_token:index','SandIAMOAuthRegistrationToken'),
        ('sand_iam:cas_service:index','SandIAMCasService'),
        ('sand_iam:developer:openapi','SandIAMDeveloperDocs'),
        ('sand_iam:admin_organization_grant:index','SandIAMAdminScope'),
        ('sand_iam:admin_application_grant:index','SandIAMAdminScope'),
        ('sand_iam:admin_application_grant:index','SandIAMAdminApplicationGrant'),
        ('sand_iam:identity_group:index','SandIAMIdentityGroup'),
        ('sand_iam:identity_invitation:index','SandIAMIdentityInvitation'),
        ('sand_iam:identity_import:index','SandIAMIdentityImport'),
        ('sand_iam:sync_connector:index','SandIAMSyncConnector'),
        ('sand_iam:webhook:index','SandIAMEventNotification'),
        ('sand_iam:webhook:index','SandIAMWebhook'),
        ('sand_iam:webhook_delivery:index','SandIAMWebhookDelivery'),
        ('sand_iam:application_network_policy:index','SandIAMApplicationNetworkPolicy'),
        ('sand_iam:security_alert:index','SandIAMSecurityAlert'),
        ('sand_iam:audit_retention_policy:index','SandIAMAuditRetentionPolicy'),
        ('sand_iam:initialization:index','SandIAMInitialization')
), sandiam_root("id") AS (
    SELECT "id"
    FROM "sand_system_menu"
    WHERE "code" = 'SandIAM'
    ORDER BY "id"
    LIMIT 1
), route_target("code","menu_id") AS (
    SELECT menu."code", menu."id"
    FROM "sand_system_menu" menu
    JOIN sandiam_root ON menu."parent_id" = sandiam_root."id"
    JOIN route_menu ON route_menu."code" = menu."code"
    WHERE menu."type" = 2
      AND NOT EXISTS (
          SELECT 1
          FROM "sand_system_menu" duplicate_route
          WHERE duplicate_route."code" = menu."code"
            AND duplicate_route."parent_id" = menu."parent_id"
            AND duplicate_route."type" = 2
            AND duplicate_route."id" <> menu."id"
      )
), route_anchor_required("role_id","menu_id") AS (
    -- Keep route inheritance exact: navigation/entity menu anchors use code,
    -- while hidden machine-action leaves use their stable permission slug.
    SELECT DISTINCT role_menu."role_id", route_target."menu_id"
    FROM "sand_system_role_menu" role_menu
    JOIN "sand_system_menu" anchor ON anchor."id" = role_menu."menu_id"
    JOIN route_menu_anchor ON route_menu_anchor."anchor_code" = anchor."code"
    JOIN route_target ON route_target."code" = route_menu_anchor."route_code"
    UNION
    SELECT DISTINCT role_menu."role_id", route_target."menu_id"
    FROM "sand_system_role_menu" role_menu
    JOIN "sand_system_menu" permission ON permission."id" = role_menu."menu_id"
    JOIN route_permission_anchor ON route_permission_anchor."anchor_slug" = permission."slug"
    JOIN route_target ON route_target."code" = route_permission_anchor."route_code"
), route_role("role_id") AS (
    SELECT DISTINCT "role_id" FROM route_anchor_required
), overview_access_role("role_id") AS (
    SELECT "role_id" FROM sandiam_role
    UNION
    SELECT "role_id" FROM route_role
    UNION
    SELECT role_menu."role_id"
    FROM "sand_system_role_menu" role_menu
    JOIN "sand_system_menu" overview ON overview."id" = role_menu."menu_id"
    WHERE overview."code" = 'SandIAMOverview'
), required_menu("role_id","menu_id") AS (
    SELECT DISTINCT sandiam_role."role_id", menu."id"
    FROM sandiam_role
    JOIN "sand_system_menu" menu ON menu."code" IN ('SandIAM','SandIAMOverview')
    UNION
    SELECT DISTINCT route_role."role_id", menu."id"
    FROM route_role
    JOIN "sand_system_menu" menu ON menu."code" IN ('SandIAM','SandIAMOverview')
    UNION
    SELECT DISTINCT overview_access_role."role_id", menu."id"
    FROM overview_access_role
    JOIN "sand_system_menu" menu ON menu."code" = 'SandIAMGettingStarted'
    UNION
    SELECT DISTINCT sandiam_role."role_id", menu."id"
    FROM sandiam_role
    JOIN path_entity ON path_entity."entity_code" = sandiam_role."entity_code"
    JOIN "sand_system_menu" menu ON menu."code" = path_entity."path_code"
    LEFT JOIN route_menu ON route_menu."code" = path_entity."path_code"
    WHERE route_menu."code" IS NULL
    UNION
    SELECT "role_id", "menu_id"
    FROM route_anchor_required
)
INSERT INTO "sand_system_role_menu" ("role_id","menu_id")
SELECT required_menu."role_id", required_menu."menu_id"
FROM required_menu
WHERE NOT EXISTS (
    SELECT 1 FROM "sand_system_role_menu" existing
    WHERE existing."role_id" = required_menu."role_id"
      AND existing."menu_id" = required_menu."menu_id"
);

-- The host does not enforce this pair as a unique constraint. Keep one row
-- per relationship so a previous lifecycle run cannot retain true duplicates.
DELETE FROM "sand_system_role_menu" duplicate
USING "sand_system_role_menu" retained, "sand_system_menu" menu
WHERE duplicate."role_id" = retained."role_id"
  AND duplicate."menu_id" = retained."menu_id"
  AND menu."id" = duplicate."menu_id"
  AND (
      menu."code" = 'SandIAM'
      OR menu."code" LIKE 'SandIAM%'
      OR menu."code" LIKE 'sand\_iam:%' ESCAPE '\'
  )
  AND duplicate.ctid > retained.ctid;

WITH permission_def("parent_code","name","slug","sort") AS (
    VALUES
        ('SandIAMOrganization','列表','sand_iam:organization:index',100),
        ('SandIAMOrganization','读取','sand_iam:organization:read',99),
        ('SandIAMOrganization','保存','sand_iam:organization:save',98),
        ('SandIAMOrganization','更新','sand_iam:organization:update',97),
        ('SandIAMOrganization','停用','sand_iam:organization:disable',96),
        ('SandIAMApplication','列表','sand_iam:application:index',100),
        ('SandIAMApplication','读取','sand_iam:application:read',99),
        ('SandIAMApplication','保存','sand_iam:application:save',98),
        ('SandIAMApplication','更新','sand_iam:application:update',97),
        ('SandIAMApplication','停用','sand_iam:application:disable',96),
        ('SandIAMEnvironment','列表','sand_iam:environment:index',100),
        ('SandIAMEnvironment','读取','sand_iam:environment:read',99),
        ('SandIAMEnvironment','保存','sand_iam:environment:save',98),
        ('SandIAMEnvironment','更新','sand_iam:environment:update',97),
        ('SandIAMEnvironment','停用','sand_iam:environment:disable',96),
        ('SandIAMWorkload','列表','sand_iam:client:index',100),
        ('SandIAMWorkload','读取','sand_iam:client:read',99),
        ('SandIAMWorkload','保存','sand_iam:client:save',98),
        ('SandIAMWorkload','更新','sand_iam:client:update',97),
        ('SandIAMWorkload','停用','sand_iam:client:disable',96),
        ('SandIAMGrant','列表','sand_iam:grant:index',100),
        ('SandIAMGrant','读取','sand_iam:grant:read',99),
        ('SandIAMGrant','保存','sand_iam:grant:save',98),
        ('SandIAMGrant','更新','sand_iam:grant:update',97),
        ('SandIAMGrant','撤销','sand_iam:grant:revoke',96),
        ('SandIAMCredential','列表','sand_iam:credential:index',100),
        ('SandIAMCredential','读取','sand_iam:credential:read',99),
        ('SandIAMCredential','签发','sand_iam:credential:issue',98),
        ('SandIAMCredential','轮换','sand_iam:credential:rotate',97),
        ('SandIAMCredential','撤销','sand_iam:credential:revoke',96),
        ('SandIAMService','列表','sand_iam:service:index',100),
        ('SandIAMService','读取','sand_iam:service:read',99),
        ('SandIAMService','保存','sand_iam:service:save',98),
        ('SandIAMService','更新','sand_iam:service:update',97),
        ('SandIAMService','停用','sand_iam:service:disable',96),
        ('SandIAMServiceAction','列表','sand_iam:service_action:index',100),
        ('SandIAMServiceAction','读取','sand_iam:service_action:read',99),
        ('SandIAMServiceAction','保存','sand_iam:service_action:save',98),
        ('SandIAMServiceAction','更新','sand_iam:service_action:update',97),
        ('SandIAMServiceAction','停用','sand_iam:service_action:disable',96),
        ('SandIAMIdentityProvider','列表','sand_iam:identity_provider:index',100),
        ('SandIAMIdentityProvider','读取','sand_iam:identity_provider:read',99),
        ('SandIAMIdentityProvider','保存','sand_iam:identity_provider:save',98),
        ('SandIAMIdentityProvider','更新','sand_iam:identity_provider:update',97),
        ('SandIAMIdentityProvider','停用','sand_iam:identity_provider:disable',96),
        ('SandIAMIdentity','列表','sand_iam:identity:index',100),
        ('SandIAMIdentity','读取','sand_iam:identity:read',99),
        ('SandIAMIdentity','保存','sand_iam:identity:save',98),
        ('SandIAMIdentity','更新','sand_iam:identity:update',97),
        ('SandIAMIdentity','停用','sand_iam:identity:disable',96),
        ('SandIAMIdentityBinding','列表','sand_iam:identity_binding:index',100),
        ('SandIAMIdentityBinding','读取','sand_iam:identity_binding:read',99),
        ('SandIAMIdentityBinding','保存','sand_iam:identity_binding:save',98),
        ('SandIAMIdentityBinding','更新','sand_iam:identity_binding:update',97),
        ('SandIAMIdentityBinding','停用','sand_iam:identity_binding:disable',96),
        ('SandIAMUserType','列表','sand_iam:user_type:index',100),
        ('SandIAMUserType','读取','sand_iam:user_type:read',99),
        ('SandIAMUserType','保存','sand_iam:user_type:save',98),
        ('SandIAMUserType','更新','sand_iam:user_type:update',97),
        ('SandIAMUserType','停用','sand_iam:user_type:disable',96),
        ('SandIAMRole','列表','sand_iam:role:index',100),
        ('SandIAMRole','读取','sand_iam:role:read',99),
        ('SandIAMRole','保存','sand_iam:role:save',98),
        ('SandIAMRole','更新','sand_iam:role:update',97),
        ('SandIAMRole','停用','sand_iam:role:disable',96),
        ('SandIAMIdentityUserType','列表','sand_iam:identity_user_type:index',100),
        ('SandIAMIdentityUserType','授予','sand_iam:identity_user_type:grant',99),
        ('SandIAMIdentityUserType','撤销','sand_iam:identity_user_type:revoke',98),
        ('SandIAMIdentityRole','列表','sand_iam:identity_role:index',100),
        ('SandIAMIdentityRole','授予','sand_iam:identity_role:grant',99),
        ('SandIAMIdentityRole','撤销','sand_iam:identity_role:revoke',98),
        ('SandIAMResource','列表','sand_iam:resource:index',100),
        ('SandIAMResource','读取','sand_iam:resource:read',99),
        ('SandIAMResource','保存','sand_iam:resource:save',98),
        ('SandIAMResource','更新','sand_iam:resource:update',97),
        ('SandIAMResource','停用','sand_iam:resource:disable',96),
        ('SandIAMPolicy','列表','sand_iam:policy:index',100),
        ('SandIAMPolicy','读取','sand_iam:policy:read',99),
        ('SandIAMPolicy','保存','sand_iam:policy:save',98),
        ('SandIAMPolicy','更新','sand_iam:policy:update',97),
        ('SandIAMPolicy','停用','sand_iam:policy:disable',96),
        ('SandIAMPolicy','发布','sand_iam:policy:publish',95),
        ('SandIAMPolicy','撤销','sand_iam:policy:revoke',94),
        ('SandIAMAdminOrganizationGrant','列表','sand_iam:admin_organization_grant:index',100),
        ('SandIAMAdminOrganizationGrant','读取','sand_iam:admin_organization_grant:read',99),
        ('SandIAMAdminOrganizationGrant','保存','sand_iam:admin_organization_grant:save',98),
        ('SandIAMAdminOrganizationGrant','更新','sand_iam:admin_organization_grant:update',97),
        ('SandIAMAdminOrganizationGrant','停用','sand_iam:admin_organization_grant:disable',96),
        ('SandIAMAudit','列表','sand_iam:audit:index',100),
        ('SandIAMIdentity','认证策略列表','sand_iam:auth_policy:index',95),
        ('SandIAMIdentity','认证策略读取','sand_iam:auth_policy:read',94),
        ('SandIAMIdentity','认证策略保存','sand_iam:auth_policy:save',93),
        ('SandIAMIdentity','认证策略更新','sand_iam:auth_policy:update',92),
        ('SandIAMIdentity','认证策略停用','sand_iam:auth_policy:disable',91),
        ('SandIAMAudit','读取','sand_iam:audit:read',99)
)
INSERT INTO "sand_system_menu"
    ("parent_id","name","code","slug","type","path","component","icon","sort","status","create_time","update_time")
SELECT parent."id", permission_def."name", '', permission_def."slug", 3, '', '', '', permission_def."sort", 1, NOW(), NOW()
FROM permission_def
JOIN "sand_system_menu" parent ON parent."code" = permission_def."parent_code"
WHERE NOT EXISTS (
    SELECT 1 FROM "sand_system_menu" existing WHERE existing."slug" = permission_def."slug"
);

-- Authoritative source for a fresh package installation.
-- This script owns only sand_iam_* objects.
BEGIN;

CREATE TABLE IF NOT EXISTS sand_iam_organization (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_organization_code UNIQUE (code)
);

CREATE TABLE IF NOT EXISTS sand_iam_application (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    organization_id bigint NOT NULL REFERENCES sand_iam_organization(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_application_organization_code UNIQUE (organization_id, code)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_application_organization_id ON sand_iam_application (organization_id);

CREATE TABLE IF NOT EXISTS sand_iam_environment (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_environment_application_code UNIQUE (application_id, code)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_environment_application_id ON sand_iam_environment (application_id);

CREATE TABLE IF NOT EXISTS sand_iam_workload_client (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    environment_id bigint NOT NULL REFERENCES sand_iam_environment(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    audience varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_workload_client_environment_code UNIQUE (environment_id, code)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_workload_client_environment_id ON sand_iam_workload_client (environment_id);

CREATE TABLE IF NOT EXISTS sand_iam_credential (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    workload_client_id bigint NOT NULL REFERENCES sand_iam_workload_client(id) ON DELETE RESTRICT,
    name varchar(128) NOT NULL,
    key_prefix varchar(32) NOT NULL,
    secret_hash varchar(255) NOT NULL,
    expire_time timestamp(0) without time zone NULL,
    revoked_time timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_credential_key_prefix UNIQUE (key_prefix),
    CONSTRAINT uk_sand_iam_credential_secret_hash UNIQUE (secret_hash)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_credential_client_active ON sand_iam_credential (workload_client_id, status, expire_time);

CREATE TABLE IF NOT EXISTS sand_iam_service (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_service_code UNIQUE (code)
);

CREATE TABLE IF NOT EXISTS sand_iam_service_action (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    service_id bigint NOT NULL REFERENCES sand_iam_service(id) ON DELETE RESTRICT,
    code varchar(96) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_service_action_service_code UNIQUE (service_id, code)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_service_action_service_id ON sand_iam_service_action (service_id);

CREATE TABLE IF NOT EXISTS sand_iam_service_grant (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    workload_client_id bigint NOT NULL REFERENCES sand_iam_workload_client(id) ON DELETE RESTRICT,
    service_action_id bigint NOT NULL REFERENCES sand_iam_service_action(id) ON DELETE RESTRICT,
    audience varchar(128) NOT NULL,
    quota_policy jsonb NOT NULL DEFAULT '{}'::jsonb,
    data_class varchar(32) NULL DEFAULT 'internal',
    network_policy jsonb NOT NULL DEFAULT '{}'::jsonb,
    expire_time timestamp(0) without time zone NULL,
    revoked_time timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_service_grant_client_action_audience UNIQUE (workload_client_id, service_action_id, audience)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_service_grant_action_active ON sand_iam_service_grant (service_action_id, status, expire_time);

CREATE TABLE IF NOT EXISTS sand_iam_identity (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    display_name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_identity_application_code UNIQUE (application_id, code),
    CONSTRAINT uk_sand_iam_identity_id_application UNIQUE (id, application_id)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_application_id ON sand_iam_identity (application_id);

CREATE TABLE IF NOT EXISTS sand_iam_identity_provider (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_identity_provider_application_code UNIQUE (application_id, code),
    CONSTRAINT uk_sand_iam_identity_provider_id_application UNIQUE (id, application_id)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_provider_application_id ON sand_iam_identity_provider (application_id);

CREATE TABLE IF NOT EXISTS sand_iam_identity_binding (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL,
    identity_id bigint NOT NULL,
    identity_provider_id bigint NOT NULL,
    subject varchar(191) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_identity_binding_provider_subject UNIQUE (identity_provider_id, subject),
    CONSTRAINT fk_sand_iam_identity_binding_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_identity_binding_provider_application FOREIGN KEY (identity_provider_id, application_id) REFERENCES sand_iam_identity_provider(id, application_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_binding_identity_id ON sand_iam_identity_binding (identity_id);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_binding_provider_id ON sand_iam_identity_binding (identity_provider_id);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_binding_application_id ON sand_iam_identity_binding (application_id);

CREATE TABLE IF NOT EXISTS sand_iam_user_type (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_user_type_application_code UNIQUE (application_id, code)
);

CREATE TABLE IF NOT EXISTS sand_iam_identity_user_type (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    identity_id bigint NOT NULL REFERENCES sand_iam_identity(id) ON DELETE RESTRICT,
    user_type_id bigint NOT NULL REFERENCES sand_iam_user_type(id) ON DELETE RESTRICT,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_identity_user_type_identity_type UNIQUE (identity_id, user_type_id)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_user_type_type_id ON sand_iam_identity_user_type (user_type_id);

CREATE TABLE IF NOT EXISTS sand_iam_role (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_role_application_code UNIQUE (application_id, code)
);

CREATE TABLE IF NOT EXISTS sand_iam_identity_role (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    identity_id bigint NOT NULL REFERENCES sand_iam_identity(id) ON DELETE RESTRICT,
    role_id bigint NOT NULL REFERENCES sand_iam_role(id) ON DELETE RESTRICT,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_identity_role_identity_role UNIQUE (identity_id, role_id)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_role_role_id ON sand_iam_identity_role (role_id);

CREATE TABLE IF NOT EXISTS sand_iam_resource (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    code varchar(64) NOT NULL,
    name varchar(128) NOT NULL,
    owner_field varchar(64) NULL,
    organization_field varchar(64) NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_resource_application_code UNIQUE (application_id, code)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_resource_application_id ON sand_iam_resource (application_id);

CREATE TABLE IF NOT EXISTS sand_iam_policy (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    resource_id bigint NOT NULL REFERENCES sand_iam_resource(id) ON DELETE RESTRICT,
    role_id bigint NULL REFERENCES sand_iam_role(id) ON DELETE RESTRICT,
    identity_id bigint NULL REFERENCES sand_iam_identity(id) ON DELETE RESTRICT,
    action varchar(64) NOT NULL,
    effect varchar(8) NOT NULL,
    condition jsonb NOT NULL DEFAULT '{}'::jsonb,
    scope jsonb NOT NULL DEFAULT '{}'::jsonb,
    priority integer NOT NULL DEFAULT 100,
    state varchar(16) NOT NULL DEFAULT 'draft',
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT ck_sand_iam_policy_subject CHECK ((role_id IS NOT NULL AND identity_id IS NULL) OR (role_id IS NULL AND identity_id IS NOT NULL)),
    CONSTRAINT ck_sand_iam_policy_effect CHECK (effect IN ('allow', 'deny')),
    CONSTRAINT ck_sand_iam_policy_state CHECK (state IN ('draft', 'published', 'revoked'))
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_policy_resource_action_state ON sand_iam_policy (resource_id, action, state, status, priority);
CREATE INDEX IF NOT EXISTS idx_sand_iam_policy_role_id ON sand_iam_policy (role_id) WHERE role_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sand_iam_policy_identity_id ON sand_iam_policy (identity_id) WHERE identity_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS sand_iam_admin_organization_grant (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    admin_user_id bigint NOT NULL,
    organization_id bigint NOT NULL REFERENCES sand_iam_organization(id) ON DELETE RESTRICT,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_admin_organization_grant_admin_organization UNIQUE (admin_user_id, organization_id)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_admin_organization_grant_organization_active ON sand_iam_admin_organization_grant (organization_id, status);

CREATE TABLE IF NOT EXISTS sand_iam_audit_log (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    actor_type varchar(32) NOT NULL,
    actor_ref varchar(128) NOT NULL,
    organization_id bigint NULL REFERENCES sand_iam_organization(id) ON DELETE RESTRICT,
    application_id bigint NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    action varchar(96) NOT NULL,
    resource_type varchar(64) NOT NULL,
    resource_id bigint NULL,
    outcome varchar(16) NOT NULL CHECK (outcome IN ('allowed', 'denied', 'succeeded', 'failed')),
    request_id varchar(96) NOT NULL,
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_sand_iam_audit_log_request_action UNIQUE (request_id, action)
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_audit_log_application_time ON sand_iam_audit_log (application_id, create_time);
CREATE INDEX IF NOT EXISTS idx_sand_iam_audit_log_action_outcome_time ON sand_iam_audit_log (action, outcome, create_time);

CREATE TABLE IF NOT EXISTS sand_iam_identity_auth (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL,
    identity_id bigint NOT NULL,
    username varchar(64) NOT NULL,
    email varchar(320) NULL,
    phone varchar(16) NULL,
    password_hash varchar(255) NOT NULL,
    password_changed_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email_verified_time timestamp(0) without time zone NULL,
    phone_verified_time timestamp(0) without time zone NULL,
    last_login_time timestamp(0) without time zone NULL,
    failed_login_count integer NOT NULL DEFAULT 0 CHECK (failed_login_count >= 0),
    locked_until timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_identity_auth_identity UNIQUE (identity_id),
    CONSTRAINT uk_sand_iam_identity_auth_identity_application UNIQUE (identity_id, application_id),
    CONSTRAINT uk_sand_iam_identity_auth_application_username UNIQUE (application_id, username),
    CONSTRAINT fk_sand_iam_identity_auth_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_identity_auth_application_email ON sand_iam_identity_auth (application_id, email) WHERE email IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_identity_auth_application_phone ON sand_iam_identity_auth (application_id, phone) WHERE phone IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_sand_iam_identity_auth_application_active ON sand_iam_identity_auth (application_id, status);

CREATE TABLE IF NOT EXISTS sand_iam_auth_session (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL,
    identity_id bigint NOT NULL,
    access_token_hash char(64) NOT NULL,
    refresh_token_hash char(64) NOT NULL,
    previous_refresh_token_hash char(64) NULL,
    access_expire_time timestamp(0) without time zone NOT NULL,
    refresh_expire_time timestamp(0) without time zone NOT NULL,
    last_used_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_time timestamp(0) without time zone NULL,
    ip_hash char(64) NOT NULL,
    user_agent varchar(255) NOT NULL DEFAULT '',
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_auth_session_access_token_hash UNIQUE (access_token_hash),
    CONSTRAINT uk_sand_iam_auth_session_refresh_token_hash UNIQUE (refresh_token_hash),
    CONSTRAINT uk_sand_iam_auth_session_previous_refresh_token_hash UNIQUE (previous_refresh_token_hash),
    CONSTRAINT fk_sand_iam_auth_session_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_auth_session_identity_active ON sand_iam_auth_session (application_id, identity_id, status, refresh_expire_time);

CREATE TABLE IF NOT EXISTS sand_iam_auth_verification (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL,
    identity_id bigint NOT NULL,
    purpose varchar(32) NOT NULL CHECK (purpose IN ('email_verify', 'phone_verify', 'password_reset')),
    channel varchar(16) NOT NULL CHECK (channel IN ('email', 'phone')),
    destination_hash char(64) NOT NULL,
    code_hash char(64) NOT NULL,
    expire_time timestamp(0) without time zone NOT NULL,
    consumed_time timestamp(0) without time zone NULL,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    created_by bigint NULL,
    updated_by bigint NULL,
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT fk_sand_iam_auth_verification_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_auth_verification_active ON sand_iam_auth_verification (application_id, identity_id, purpose, channel, status, expire_time);

CREATE TABLE IF NOT EXISTS sand_iam_auth_rate_limit (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    action varchar(32) NOT NULL,
    subject_hash char(64) NOT NULL,
    window_start timestamp(0) without time zone NOT NULL,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_auth_rate_limit_application_action_subject UNIQUE (application_id, action, subject_hash)
);

ALTER TABLE sand_iam_identity_auth ADD COLUMN IF NOT EXISTS pepper_version varchar(32) NOT NULL DEFAULT 'v1';
ALTER TABLE sand_iam_auth_session ADD COLUMN IF NOT EXISTS pepper_version varchar(32) NOT NULL DEFAULT 'v1';
ALTER TABLE sand_iam_auth_verification ADD COLUMN IF NOT EXISTS pepper_version varchar(32) NOT NULL DEFAULT 'v1';
CREATE TABLE IF NOT EXISTS sand_iam_auth_refresh_token (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    session_id bigint NOT NULL REFERENCES sand_iam_auth_session(id) ON DELETE RESTRICT,
    token_hash char(64) NOT NULL, pepper_version varchar(32) NOT NULL,
    expire_time timestamp(0) without time zone NOT NULL, used_time timestamp(0) without time zone NULL, revoked_time timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)), create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP, update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_auth_refresh_token_hash UNIQUE (token_hash)
);
ALTER TABLE sand_iam_auth_rate_limit ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
ALTER TABLE sand_iam_auth_refresh_token ADD COLUMN IF NOT EXISTS delete_time timestamp(0) without time zone NULL;
CREATE INDEX IF NOT EXISTS idx_sand_iam_auth_refresh_token_session_active ON sand_iam_auth_refresh_token (session_id, status, expire_time);
CREATE TABLE IF NOT EXISTS sand_iam_auth_policy (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    password_min_length integer NOT NULL DEFAULT 12 CHECK (password_min_length BETWEEN 12 AND 128), password_max_length integer NOT NULL DEFAULT 128 CHECK (password_max_length BETWEEN 12 AND 128),
    require_uppercase smallint NOT NULL DEFAULT 1 CHECK (require_uppercase IN (1, 2)), require_lowercase smallint NOT NULL DEFAULT 1 CHECK (require_lowercase IN (1, 2)), require_digit smallint NOT NULL DEFAULT 1 CHECK (require_digit IN (1, 2)), require_symbol smallint NOT NULL DEFAULT 1 CHECK (require_symbol IN (1, 2)),
    require_email_verification smallint NOT NULL DEFAULT 2 CHECK (require_email_verification IN (1, 2)), require_phone_verification smallint NOT NULL DEFAULT 2 CHECK (require_phone_verification IN (1, 2)),
    access_token_ttl_seconds integer NOT NULL DEFAULT 900 CHECK (access_token_ttl_seconds BETWEEN 60 AND 3600), refresh_token_ttl_seconds integer NOT NULL DEFAULT 2592000 CHECK (refresh_token_ttl_seconds BETWEEN 300 AND 7776000), verification_ttl_seconds integer NOT NULL DEFAULT 600 CHECK (verification_ttl_seconds BETWEEN 60 AND 3600),
    max_login_failures integer NOT NULL DEFAULT 5 CHECK (max_login_failures BETWEEN 3 AND 20), lock_seconds integer NOT NULL DEFAULT 900 CHECK (lock_seconds BETWEEN 60 AND 86400), rate_limit_per_minute integer NOT NULL DEFAULT 10 CHECK (rate_limit_per_minute BETWEEN 1 AND 1000),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)), created_by bigint NULL, updated_by bigint NULL, create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP, update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP, delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_auth_policy_application UNIQUE (application_id), CONSTRAINT ck_sand_iam_auth_policy_password_range CHECK (password_min_length <= password_max_length)
);
ALTER TABLE sand_iam_auth_policy ADD COLUMN IF NOT EXISTS registration_enabled smallint NOT NULL DEFAULT 2 CHECK (registration_enabled IN (1, 2));
WITH oauth_parent AS (
    SELECT id FROM sand_system_menu WHERE code = 'SandIAMPeopleAccess' ORDER BY id LIMIT 1
), oauth_permission(name, code, slug, sort) AS (
    VALUES
      ('OAuth客户端查看','sand_iam:oauth_client:index','oauth-client-index',901),
      ('OAuth客户端读取','sand_iam:oauth_client:read','oauth-client-read',902),
      ('OAuth客户端创建','sand_iam:oauth_client:save','oauth-client-save',903),
      ('OAuth客户端更新','sand_iam:oauth_client:update','oauth-client-update',904),
      ('OAuth客户端停用','sand_iam:oauth_client:disable','oauth-client-disable',905),
      ('OAuth客户端轮换密钥','sand_iam:oauth_client:rotate','oauth-client-rotate',906)
)
INSERT INTO sand_system_menu (parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
SELECT oauth_parent.id, oauth_permission.name, oauth_permission.code, oauth_permission.slug, 3, '', '', '', oauth_permission.sort, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM oauth_parent CROSS JOIN oauth_permission
WHERE NOT EXISTS (SELECT 1 FROM sand_system_menu known WHERE known.code = oauth_permission.code);

INSERT INTO sand_system_role_menu (role_id,menu_id)
SELECT DISTINCT inherited.role_id, permission.id
FROM sand_system_role_menu inherited
JOIN sand_system_menu parent ON parent.id = inherited.menu_id AND parent.code = 'SandIAMPeopleAccess'
JOIN sand_system_menu permission ON permission.code IN (
  'sand_iam:oauth_client:index','sand_iam:oauth_client:read','sand_iam:oauth_client:save',
  'sand_iam:oauth_client:update','sand_iam:oauth_client:disable','sand_iam:oauth_client:rotate'
)
WHERE NOT EXISTS (
    SELECT 1
    FROM sand_system_role_menu existing
    WHERE existing.role_id = inherited.role_id
      AND existing.menu_id = permission.id
);

COMMIT;

-- IAM-T02-004 BEGIN: keep this marker for the isolated 003 -> current upgrade test.
BEGIN;
ALTER TABLE sand_iam_auth_policy ADD COLUMN IF NOT EXISTS webauthn_rp_id varchar(255) NULL, ADD COLUMN IF NOT EXISTS webauthn_allowed_origins jsonb NOT NULL DEFAULT '[]'::jsonb, ADD COLUMN IF NOT EXISTS webauthn_user_verification varchar(16) NOT NULL DEFAULT 'required';
DO $$ BEGIN ALTER TABLE sand_iam_auth_policy ADD CONSTRAINT ck_sand_iam_auth_policy_webauthn_uv CHECK (webauthn_user_verification IN ('required', 'preferred', 'discouraged')); EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;
DO $$ BEGIN ALTER TABLE sand_iam_identity ADD CONSTRAINT uk_sand_iam_identity_id_application UNIQUE (id, application_id); EXCEPTION WHEN duplicate_object OR duplicate_table THEN NULL; END $$;
CREATE TABLE IF NOT EXISTS sand_iam_mfa_factor (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    identity_id bigint NOT NULL,
    type varchar(16) NOT NULL CHECK (type = 'totp'),
    name varchar(128) NOT NULL,
    encrypted_secret text NULL,
    encryption_version varchar(32) NULL,
    last_used_counter bigint NULL CHECK (last_used_counter >= 0),
    last_used_time timestamp(0) without time zone NULL,
    revoked_time timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT ck_sand_iam_mfa_factor_material CHECK (encrypted_secret IS NOT NULL),
    CONSTRAINT uk_sand_iam_mfa_factor_id_application_identity UNIQUE (id, application_id, identity_id),
    CONSTRAINT fk_sand_iam_mfa_factor_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_mfa_factor_identity_active ON sand_iam_mfa_factor (application_id, identity_id, status, type);
CREATE UNIQUE INDEX IF NOT EXISTS uk_sand_iam_mfa_factor_identity_active_totp ON sand_iam_mfa_factor (application_id, identity_id) WHERE status = 1 AND delete_time IS NULL;
CREATE TABLE IF NOT EXISTS sand_iam_mfa_recovery_code (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    identity_id bigint NOT NULL,
    factor_id bigint NOT NULL,
    code_hash char(64) NOT NULL,
    generation bigint NOT NULL,
    used_time timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_mfa_recovery_code_hash UNIQUE (application_id, code_hash),
    CONSTRAINT fk_sand_iam_mfa_recovery_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT,
    CONSTRAINT fk_sand_iam_mfa_recovery_factor_scope FOREIGN KEY (factor_id, application_id, identity_id) REFERENCES sand_iam_mfa_factor(id, application_id, identity_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_mfa_recovery_code_identity_active ON sand_iam_mfa_recovery_code (application_id, identity_id, status);
CREATE TABLE IF NOT EXISTS sand_iam_auth_challenge (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    identity_id bigint NULL,
    purpose varchar(32) NOT NULL CHECK (purpose IN ('mfa_login', 'webauthn_register', 'webauthn_auth')),
    token_hash char(64) NOT NULL,
    encrypted_challenge text NOT NULL,
    encrypted_user_handle text NULL,
    encryption_version varchar(32) NOT NULL,
    context jsonb NOT NULL DEFAULT '{}'::jsonb,
    expire_time timestamp(0) without time zone NOT NULL,
    consumed_time timestamp(0) without time zone NULL,
    attempt_count integer NOT NULL DEFAULT 0 CHECK (attempt_count BETWEEN 0 AND 5),
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_auth_challenge_token UNIQUE (token_hash),
    CONSTRAINT ck_sand_iam_auth_challenge_identity CHECK ((purpose = 'webauthn_auth' AND identity_id IS NULL) OR (purpose <> 'webauthn_auth' AND identity_id IS NOT NULL)),
    CONSTRAINT fk_sand_iam_auth_challenge_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_auth_challenge_active ON sand_iam_auth_challenge (application_id, purpose, status, expire_time);
CREATE TABLE IF NOT EXISTS sand_iam_webauthn_credential (
    id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    application_id bigint NOT NULL REFERENCES sand_iam_application(id) ON DELETE RESTRICT,
    identity_id bigint NOT NULL,
    name varchar(128) NOT NULL,
    credential_id varchar(1400) NOT NULL,
    public_key text NOT NULL,
    user_handle varchar(128) NOT NULL,
    sign_count bigint NOT NULL DEFAULT 0 CHECK (sign_count >= 0),
    last_used_time timestamp(0) without time zone NULL,
    revoked_time timestamp(0) without time zone NULL,
    status smallint NOT NULL DEFAULT 1 CHECK (status IN (1, 2)),
    create_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_time timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delete_time timestamp(0) without time zone NULL,
    CONSTRAINT uk_sand_iam_webauthn_credential_application_credential UNIQUE (application_id, credential_id),
    CONSTRAINT fk_sand_iam_webauthn_credential_identity_application FOREIGN KEY (identity_id, application_id) REFERENCES sand_iam_identity(id, application_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS idx_sand_iam_webauthn_credential_identity_active ON sand_iam_webauthn_credential (application_id, identity_id, status);
COMMIT;
