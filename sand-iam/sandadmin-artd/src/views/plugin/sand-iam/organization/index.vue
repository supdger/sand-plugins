<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { organizationFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'code', label: 'code' },
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'status', label: '状态' },
    { key: 'update_time', label: '更新时间', minWidth: 180 }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status']
  const formFields = organizationFields
</script>

<template>
  <ResourceListPage
    title="组织"
    description="平台客户主体；仅展示当前后台委派范围内的数据。"
    endpoint="organization"
    index-permission="sand_iam:organization:index"
    permission-prefix="sand_iam:organization"
    :columns="columns"
    :filters="filters"
    :form-fields="formFields"
  />
</template>
