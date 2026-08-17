<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { identityFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'application_id', label: 'application_id' },
    { key: 'code', label: 'code' },
    { key: 'display_name', label: 'display_name', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="身份"
    description="应用人类用户身份目录；不复用宿主后台账号。"
    endpoint="identity"
    index-permission="sand_iam:identity:index"
    permission-prefix="sand_iam:identity"
    :columns="columns"
    :filters="filters"
    :form-fields="identityFields"
  />
</template>
