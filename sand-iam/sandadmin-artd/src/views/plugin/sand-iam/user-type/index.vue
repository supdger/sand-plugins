<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { namedAppFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'application_id', label: 'application_id' },
    { key: 'code', label: 'code' },
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="用户类型"
    description="应用可配置的用户类型字典。"
    endpoint="user-type"
    index-permission="sand_iam:user_type:index"
    permission-prefix="sand_iam:user_type"
    :columns="columns"
    :filters="filters"
    :form-fields="namedAppFields"
  />
</template>
