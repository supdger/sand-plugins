-- SandIAM top-level navigation regroup (parent_id and stored path only).
-- Three stable groups sit under the SandIAM root: 应用接入 / 用户与权限 / 高级.
-- Permission codes, slugs and components are unchanged; hidden entity pages
-- keep is_hidden=1 so they stay reachable but invisible in the sidebar. Moved
-- pages switch their stored path to the absolute route (for example
-- '/sand-iam/application') so the front-end hierarchical path join keeps every
-- effective URL identical; direct links to the old routes keep working. Every
-- statement is idempotent: re-running converges to the same tree without
-- duplicating menus or role links.

-- The groups are visible component-less menu nodes (type=2 with an empty
-- component). A directory row (type=1) would force the host layout component
-- '/index/index' on the nested level and render a second layout frame.
WITH group_def("name","code","path","sort") AS (
    VALUES
        ('应用接入','SandIAMGroupAccess','access',97),
        ('用户与权限','SandIAMGroupPeople','people',96),
        ('高级','SandIAMGroupAdvanced','advanced',20)
)
INSERT INTO "sand_system_menu"
    ("parent_id","name","code","slug","type","path","component","icon","sort","is_hidden","status","create_time","update_time")
SELECT root."id", group_def."name", group_def."code", '', 2, group_def."path", '', '', group_def."sort", 2, 1, NOW(), NOW()
FROM group_def
CROSS JOIN LATERAL (
    SELECT "id" FROM "sand_system_menu" WHERE "code" = 'SandIAM' ORDER BY "id" LIMIT 1
) root
WHERE NOT EXISTS (
    SELECT 1 FROM "sand_system_menu" existing WHERE existing."code" = group_def."code"
);

-- Moved pages keep their effective URL byte-for-byte: the stored path becomes
-- the absolute route, which the front-end path joiner returns unchanged
-- instead of prefixing it with the group path. is_hidden is not touched, so
-- hidden entity pages stay hidden.
UPDATE "sand_system_menu" menu
SET "parent_id" = grp."id",
    "path" = CASE WHEN menu."path" LIKE '/%' THEN menu."path" ELSE '/sand-iam/' || menu."path" END,
    "update_time" = NOW()
FROM "sand_system_menu" grp
WHERE grp."code" = 'SandIAMGroupAccess'
  AND menu."type" = 2
  AND menu."status" = 1
  AND menu."delete_time" IS NULL
  AND menu."code" IN (
      'SandIAMConnection','SandIAMApplication','SandIAMEnvironment',
      'SandIAMWorkload','SandIAMCredential','SandIAMGrant',
      'SandIAMApplicationNetworkPolicy'
  );

UPDATE "sand_system_menu" menu
SET "parent_id" = grp."id",
    "path" = CASE WHEN menu."path" LIKE '/%' THEN menu."path" ELSE '/sand-iam/' || menu."path" END,
    "update_time" = NOW()
FROM "sand_system_menu" grp
WHERE grp."code" = 'SandIAMGroupPeople'
  AND menu."type" = 2
  AND menu."status" = 1
  AND menu."delete_time" IS NULL
  AND menu."code" IN (
      'SandIAMPeopleAccess','SandIAMOrganization','SandIAMIdentity',
      'SandIAMIdentityBinding','SandIAMUserType','SandIAMIdentityUserType',
      'SandIAMRole','SandIAMIdentityRole','SandIAMIdentityGroup',
      'SandIAMIdentityInvitation','SandIAMIdentityImport'
  );

UPDATE "sand_system_menu" menu
SET "parent_id" = grp."id",
    "path" = CASE WHEN menu."path" LIKE '/%' THEN menu."path" ELSE '/sand-iam/' || menu."path" END,
    "update_time" = NOW()
FROM "sand_system_menu" grp
WHERE grp."code" = 'SandIAMGroupAdvanced'
  AND menu."type" = 2
  AND menu."status" = 1
  AND menu."delete_time" IS NULL
  AND menu."code" IN (
      'SandIAMGettingStarted','SandIAMAuditTroubleshooting','SandIAMApiGovernance',
      'SandIAMAuthSession','SandIAMEventNotification','SandIAMService',
      'SandIAMServiceAction','SandIAMIdentityProvider','SandIAMResource',
      'SandIAMPolicy','SandIAMAdminScope','SandIAMAdminOrganizationGrant',
      'SandIAMAdminApplicationGrant','SandIAMAudit','SandIAMApplicationBusinessAction',
      'SandIAMApiResource','SandIAMApiRouteBinding','SandIAMRouteManifest',
      'SandIAMPolicySimulate','SandIAMAuthPolicy','SandIAMApplicationExperience',
      'SandIAMMessageProvider','SandIAMFederationConfig','SandIAMScimTokens',
      'SandIAMRadiusNas','SandIAMOAuthClient','SandIAMOAuthRegistrationToken',
      'SandIAMCasService','SandIAMDeveloperDocs','SandIAMSyncConnector',
      'SandIAMWebhook','SandIAMWebhookDelivery','SandIAMSecurityAlert',
      'SandIAMAuditRetentionPolicy','SandIAMInitialization'
  );

-- Roles holding a permission button under a regrouped page but missing the
-- page link receive it; seeds grant both, this only repairs drift.
INSERT INTO "sand_system_role_menu" ("role_id","menu_id")
SELECT DISTINCT role_menu."role_id", page."id"
FROM "sand_system_role_menu" role_menu
JOIN "sand_system_menu" permission ON permission."id" = role_menu."menu_id" AND permission."type" = 3
JOIN "sand_system_menu" page ON page."id" = permission."parent_id"
JOIN "sand_system_menu" grp ON grp."id" = page."parent_id"
WHERE grp."code" IN ('SandIAMGroupAccess','SandIAMGroupPeople','SandIAMGroupAdvanced')
  AND NOT EXISTS (
      SELECT 1 FROM "sand_system_role_menu" existing
      WHERE existing."role_id" = role_menu."role_id" AND existing."menu_id" = page."id"
  );

-- Roles that can already see a moved page also receive the group link, so the
-- group entry renders for them; superadministrators read every menu regardless.
INSERT INTO "sand_system_role_menu" ("role_id","menu_id")
SELECT DISTINCT role_menu."role_id", grp."id"
FROM "sand_system_role_menu" role_menu
JOIN "sand_system_menu" child ON child."id" = role_menu."menu_id"
JOIN "sand_system_menu" grp ON grp."id" = child."parent_id"
WHERE grp."code" IN ('SandIAMGroupAccess','SandIAMGroupPeople','SandIAMGroupAdvanced')
  AND NOT EXISTS (
      SELECT 1 FROM "sand_system_role_menu" existing
      WHERE existing."role_id" = role_menu."role_id" AND existing."menu_id" = grp."id"
  );
