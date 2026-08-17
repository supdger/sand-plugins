<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { grantFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'workload_client_id', label: 'workload_client_id' },
    { key: 'service_action_id', label: 'service_action_id' },
    { key: 'audience', label: 'audience', minWidth: 140 },
    { key: 'data_class', label: 'data_class' },
    { key: 'expire_time', label: 'expire_time', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['status', 'workload_client_id']
</script>

<template>
  <ResourceListPage
    title="服务授权"
    description="工作负载客户端到服务动作的授权关系；撤销后保留审计事实。"
    endpoint="grant"
    index-permission="sand_iam:grant:index"
    permission-prefix="sand_iam:grant"
    write-mode="grant"
    :columns="columns"
    :filters="filters"
    :form-fields="grantFields"
  />
</template>
