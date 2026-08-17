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
    title="角色"
    description="应用角色；授权判定的主体之一。"
    endpoint="role"
    index-permission="sand_iam:role:index"
    permission-prefix="sand_iam:role"
    :columns="columns"
    :filters="filters"
    :form-fields="namedAppFields"
  />
</template>
