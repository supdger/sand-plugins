<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { resourceFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 160 },
    { key: 'application_id', label: '所属接入应用' },
    { key: 'owner_field', label: 'owner_field' },
    { key: 'organization_field', label: 'organization_field' },
    { key: 'status', label: '状态' },
    { key: 'code', label: 'code' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="业务资源"
    description="应用声明的可授权资源类型，例如“订单”或“项目”；它不是具体的一条业务数据。"
    endpoint="resource"
    index-permission="sand_iam:resource:index"
    permission-prefix="sand_iam:resource"
    :columns="columns"
    :filters="filters"
    :form-fields="resourceFields"
  />
</template>
