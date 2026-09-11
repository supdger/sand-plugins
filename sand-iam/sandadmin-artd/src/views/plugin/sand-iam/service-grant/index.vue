<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { grantFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'workload_client_id', label: 'workload_client_id' },
    { key: 'service_action_id', label: 'service_action_id' },
    { key: 'status', label: '状态' },
    { key: 'audience', label: 'audience', minWidth: 140 },
    { key: 'data_class', label: 'data_class' },
    { key: 'expire_time', label: 'expire_time', minWidth: 180 }
  ]
  const filters: SandIamFilterKey[] = [
    'status',
    'organization_id',
    'application_id',
    'environment_id',
    'workload_client_id'
  ]
</script>

<template>
  <ResourceListPage
    title="服务授权"
    description="把一个服务动作授权给指定服务调用身份，可同时限制受众、有效期和数据等级。撤销不删除审计记录。"
    endpoint="grant"
    index-permission="sand_iam:grant:index"
    permission-prefix="sand_iam:grant"
    write-mode="grant"
    :columns="columns"
    :filters="filters"
    :form-fields="grantFields"
  />
</template>
