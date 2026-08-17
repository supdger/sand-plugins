<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { actionFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'service_id', label: 'service_id' },
    { key: 'code', label: 'code' },
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'service_id']
</script>

<template>
  <ResourceListPage
    title="服务动作"
    description="语义服务动作，例如 sand_ai.document_parse。不是 HTTP 路径。"
    endpoint="action"
    index-permission="sand_iam:service_action:index"
    permission-prefix="sand_iam:service_action"
    :columns="columns"
    :filters="filters"
    :form-fields="actionFields"
  />
</template>
