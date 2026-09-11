<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { credentialFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: 'name' },
    { key: 'workload_client_id', label: 'workload_client_id' },
    { key: 'status', label: '状态' },
    { key: 'key_prefix', label: 'key_prefix' },
    { key: 'expire_time', label: 'expire_time', minWidth: 180 }
  ]
  const filters: SandIamFilterKey[] = [
    'organization_id',
    'application_id',
    'environment_id',
    'workload_client_id'
  ]
</script>

<template>
  <ResourceListPage
    title="调用凭证"
    description="服务调用身份使用的访问凭证。签发或轮换后的明文仅显示一次，请立即交给应用安全保存。"
    endpoint="credential"
    index-permission="sand_iam:credential:index"
    permission-prefix="sand_iam:credential"
    write-mode="credential"
    :columns="columns"
    :filters="filters"
    :form-fields="credentialFields"
  />
</template>
