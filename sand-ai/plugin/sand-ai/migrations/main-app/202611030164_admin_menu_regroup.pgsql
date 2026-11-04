-- SandAI administrator navigation regroup: fold the low-frequency configuration
-- and diagnostics surfaces under one visible "高级" group, leaving the daily
-- entries (概览 / 首次开通 / 模型 / 服务商 / 文件中心 / 费用与账单) at the top
-- level. Permission codes, slugs, paths and components are unchanged; direct
-- links to the old routes keep working. The block is idempotent: re-running it
-- converges to the same tree without duplicating menus or role links.
DO $sand_ai_menu_regroup$
DECLARE
    root_id bigint;
    group_id bigint;
    count_nodes integer;
BEGIN
    IF to_regclass('sand_ai_configuration_navigation_ownership') IS NULL THEN
        RAISE EXCEPTION 'SandAI configuration navigation ownership is missing';
    END IF;
    LOCK TABLE sand_system_menu IN SHARE ROW EXCLUSIVE MODE;
    LOCK TABLE sand_system_role_menu IN SHARE ROW EXCLUSIVE MODE;
    SELECT count(*),min(id) INTO count_nodes,root_id FROM sand_system_menu
    WHERE code='SandAI' AND parent_id=0 AND type=1 AND status=1 AND delete_time IS NULL;
    IF count_nodes<>1 THEN
        RAISE EXCEPTION 'SandAI root navigation is missing or duplicated';
    END IF;

    -- The group itself is a visible directory (type=1); children collapse under it.
    SELECT count(*),min(id) INTO count_nodes,group_id FROM sand_system_menu WHERE code='SandAIAdvanced';
    IF count_nodes>1 THEN
        RAISE EXCEPTION 'SandAIAdvanced menu is duplicated';
    END IF;
    IF count_nodes=0 THEN
        INSERT INTO sand_system_menu(parent_id,name,code,slug,type,path,component,icon,sort,is_hidden,status,create_time,update_time)
        VALUES(root_id,'高级','SandAIAdvanced','',1,'advanced','','',10,0,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
        RETURNING id INTO group_id;
        INSERT INTO sand_ai_configuration_navigation_ownership
        SELECT 'menu',id,to_jsonb(menu)-ARRAY['id','create_time','update_time','created_by','updated_by']
        FROM sand_system_menu menu WHERE id=group_id;
    ELSIF NOT EXISTS(
        SELECT 1 FROM sand_system_menu WHERE id=group_id AND parent_id=root_id AND type=1
          AND is_hidden=0 AND status=1 AND delete_time IS NULL
    ) THEN
        RAISE EXCEPTION 'Existing SandAIAdvanced menu is incompatible';
    END IF;

    UPDATE sand_system_menu menu
    SET parent_id=group_id, update_time=CURRENT_TIMESTAMP
    WHERE menu.code IN (
        'SandAITaskTrial','SandAIInvocation','SandAIAudit',
        'SandAIKnowledgeConfiguration','SandAIAgentConfiguration','SandAIComposition',
        'SandAICapability','SandAIMaterialConfig'
    ) AND menu.type=2 AND menu.status=1 AND menu.delete_time IS NULL
      AND menu.parent_id IS DISTINCT FROM group_id;

    -- Ownership snapshots stay immutable: reparented pages keep their original
    -- recorded definition, matching the append-only ownership contract.

    -- Roles that hold a permission button under a regrouped page but miss the
    -- page link receive it; seeds grant both, this only repairs drift.
    WITH ins AS (
        INSERT INTO sand_system_role_menu(role_id,menu_id)
        SELECT DISTINCT role_menu.role_id, page.id
        FROM sand_system_role_menu role_menu
        JOIN sand_system_menu permission ON permission.id=role_menu.menu_id AND permission.type=3
        JOIN sand_system_menu page ON page.id=permission.parent_id AND page.parent_id=group_id
        WHERE NOT EXISTS(
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id=role_menu.role_id AND existing.menu_id=page.id
        )
        RETURNING id,role_id,menu_id
    )
    INSERT INTO sand_ai_configuration_navigation_ownership
    SELECT 'role_menu',ins.id,jsonb_build_object('role_id',ins.role_id,'menu_id',ins.menu_id) FROM ins;

    -- Roles that can already see a moved page also receive the group link, so
    -- the group entry renders for them.
    WITH ins AS (
        INSERT INTO sand_system_role_menu(role_id,menu_id)
        SELECT DISTINCT role_menu.role_id, group_id
        FROM sand_system_role_menu role_menu
        JOIN sand_system_menu child ON child.id=role_menu.menu_id AND child.parent_id=group_id
        WHERE NOT EXISTS(
            SELECT 1 FROM sand_system_role_menu existing
            WHERE existing.role_id=role_menu.role_id AND existing.menu_id=group_id
        )
        RETURNING id,role_id,menu_id
    )
    INSERT INTO sand_ai_configuration_navigation_ownership
    SELECT 'role_menu',ins.id,jsonb_build_object('role_id',ins.role_id,'menu_id',ins.menu_id) FROM ins;
END
$sand_ai_menu_regroup$;
