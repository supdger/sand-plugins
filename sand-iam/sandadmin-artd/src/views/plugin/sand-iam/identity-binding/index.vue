<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { identityBindingFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'identity_id', label: 'identity_id' },
    { key: 'provider_code', label: 'provider_code' },
    { key: 'subject', label: 'subject', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['identity_id']
</script>

<template>
  <ResourceListPage
    title="身份绑定"
    description="外部身份源绑定。不接收 token；更新只允许改 status。"
    endpoint="identity-binding"
    index-permission="sand_iam:identity_binding:index"
    permission-prefix="sand_iam:identity_binding"
    write-mode="binding"
    require-identity-id
    :columns="columns"
    :filters="filters"
    :form-fields="identityBindingFields"
  />
</template>
