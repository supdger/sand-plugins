<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'identity_id', label: '应用身份' },
    { key: 'user_type_id', label: 'user_type_id' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['identity_id']
</script>

<template>
  <ResourceListPage
    title="身份用户类型关系"
    description="为指定应用身份标记用户类型。先选择应用身份，系统会只列出其所属应用中的用户类型。"
    endpoint="identity-user-type"
    index-permission="sand_iam:identity_user_type:index"
    permission-prefix="sand_iam:identity_user_type"
    write-mode="relation"
    relation-grant-field="user_type_id"
    require-identity-id
    :columns="columns"
    :filters="filters"
  />
</template>
