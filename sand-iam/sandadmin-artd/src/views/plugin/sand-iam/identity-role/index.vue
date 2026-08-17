<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'identity_id', label: 'identity_id' },
    { key: 'role_id', label: 'role_id' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['identity_id']
</script>

<template>
  <ResourceListPage
    title="身份角色关系"
    description="必须提供 identity_id。授予/撤销走真实接口。"
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
