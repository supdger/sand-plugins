<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { environmentFields } from '../api/fields'
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
    title="环境"
    description="应用环境配置；只读取当前授权组织下的环境。"
    endpoint="environment"
    index-permission="sand_iam:environment:index"
    permission-prefix="sand_iam:environment"
    :columns="columns"
    :filters="filters"
    :form-fields="environmentFields"
  />
</template>
