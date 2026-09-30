<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'identity_id', label: '应用身份' },
    { key: 'role_id', label: 'role_id' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['application_id', 'identity_id']
</script>

<template>
  <ResourceListPage
    title="身份角色关系"
    description="先选择员工所在应用，再选择员工和该应用的角色。授予后请在业务系统验证权限；角色名称本身不会赋予具体业务操作。"
    endpoint="identity-role"
    index-permission="sand_iam:identity_role:index"
    permission-prefix="sand_iam:identity_role"
    write-mode="relation"
    relation-grant-field="role_id"
    require-identity-id
    :columns="columns"
    :filters="filters"
  />
</template>
