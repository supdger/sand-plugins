<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { applicationFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'organization_id', label: 'organization_id' },
    { key: 'code', label: 'code' },
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'organization_id']
</script>

<template>
  <ResourceListPage
    title="应用"
    description="应用登记信息；组织范围由服务端统一校验。"
    endpoint="application"
    index-permission="sand_iam:application:index"
    permission-prefix="sand_iam:application"
    :columns="columns"
    :filters="filters"
    :form-fields="applicationFields"
  />
</template>
