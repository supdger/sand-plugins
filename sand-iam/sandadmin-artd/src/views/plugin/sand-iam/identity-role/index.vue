<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'identity_id', label: '应用身份' },
    { key: 'role_id', label: 'role_id' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['identity_id']
</script>

<template>
  <ResourceListPage
    title="身份角色关系"
    description="为指定应用身份授予或撤销角色。先选择应用身份，系统会只列出其所属应用中的角色。"
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
