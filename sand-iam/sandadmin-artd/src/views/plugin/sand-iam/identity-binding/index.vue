<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { identityBindingFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'identity_id', label: '应用身份' },
    { key: 'identity_provider_id', label: '身份源' },
    { key: 'subject', label: 'subject', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['identity_id']
</script>

<template>
  <ResourceListPage
    title="身份绑定"
    description="把应用身份关联到该应用下的身份源账号。新建时选择身份源，不再手填旧版身份源代码；这里不保存登录凭证。"
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
