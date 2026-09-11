-- SandIAM 0.7.0: catalog only the user-group role administration permissions.
-- The published 0.6.0 migration 021 is immutable. These permissions are not
-- inherited by existing roles because granting or revoking group roles changes
-- effective authorization and requires an explicit administrator decision.
BEGIN;

DO $$
DECLARE
    parent_menu_id bigint;
BEGIN
    SELECT id
    INTO parent_menu_id
    FROM sand_system_menu
    WHERE code = 'SandIAMPeopleAccess'
    ORDER BY id
    LIMIT 1;

    IF parent_menu_id IS NULL THEN
        RAISE EXCEPTION 'SandIAM 0.7.0 cannot add identity-group-role permissions: parent menu SandIAMPeopleAccess is missing';
    END IF;
END
$$;

WITH desired(code, name, sort) AS (
    VALUES
        ('sand_iam:identity_group_role:index', '查看用户组角色', 1871),
        ('sand_iam:identity_group_role:grant', '授予用户组角色', 1872),
        ('sand_iam:identity_group_role:revoke', '撤销用户组角色', 1873)
), parent AS (
    SELECT id
    FROM sand_system_menu
    WHERE code = 'SandIAMPeopleAccess'
    ORDER BY id
    LIMIT 1
), refresh_existing AS (
    UPDATE sand_system_menu existing
    SET parent_id = parent.id,
        name = desired.name,
        slug = desired.code,
        type = 3,
        path = '',
        component = '',
        icon = '',
        sort = desired.sort,
        is_hidden = 1,
        status = 1,
        update_time = CURRENT_TIMESTAMP
    FROM desired
    CROSS JOIN parent
    WHERE existing.code = desired.code
      AND (
          existing.parent_id IS DISTINCT FROM parent.id
          OR existing.name IS DISTINCT FROM desired.name
          OR existing.slug IS DISTINCT FROM desired.code
          OR existing.type IS DISTINCT FROM 3
          OR existing.path IS DISTINCT FROM ''
          OR existing.component IS DISTINCT FROM ''
          OR existing.icon IS DISTINCT FROM ''
          OR existing.sort IS DISTINCT FROM desired.sort
          OR existing.is_hidden IS DISTINCT FROM 1
          OR existing.status IS DISTINCT FROM 1
      )
    RETURNING existing.id
)
INSERT INTO sand_system_menu (parent_id, name, code, slug, type, path, component, icon, sort, is_hidden, status, create_time, update_time)
SELECT parent.id, desired.name, desired.code, desired.code,
       3, '', '', '', desired.sort, 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM desired
CROSS JOIN parent
WHERE NOT EXISTS (
    SELECT 1
    FROM sand_system_menu existing
    WHERE existing.code = desired.code
);

COMMIT;
