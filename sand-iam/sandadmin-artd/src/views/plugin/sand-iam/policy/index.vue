<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { policyFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'application_id', label: 'application_id' },
    { key: 'resource_id', label: 'resource_id' },
    { key: 'role_id', label: 'role_id' },
    { key: 'identity_id', label: 'identity_id' },
    { key: 'action', label: 'action' },
    { key: 'effect', label: 'effect' },
    { key: 'priority', label: 'priority' },
    { key: 'state', label: 'state' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="策略"
    description="仅 state=published 且 status=1 参与运行时判定。发布/撤销走真实接口。"
    endpoint="policy"
    index-permission="sand_iam:policy:index"
    permission-prefix="sand_iam:policy"
    write-mode="policy"
    :columns="columns"
    :filters="filters"
    :form-fields="policyFields"
  />
</template>
