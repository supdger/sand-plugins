<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { credentialFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'workload_client_id', label: 'workload_client_id' },
    { key: 'name', label: 'name' },
    { key: 'key_prefix', label: 'key_prefix' },
    { key: 'expire_time', label: 'expire_time', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['workload_client_id']
</script>

<template>
  <ResourceListPage
    title="调用凭证"
    description="列表不返回明文。签发/轮换只展示一次 credential，随后不再回显。"
    endpoint="credential"
    index-permission="sand_iam:credential:index"
    permission-prefix="sand_iam:credential"
    write-mode="credential"
    :columns="columns"
    :filters="filters"
    :form-fields="credentialFields"
  />
</template>
