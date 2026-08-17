<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { resourceFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'application_id', label: 'application_id' },
    { key: 'code', label: 'code' },
    { key: 'name', label: '名称', minWidth: 160 },
    { key: 'owner_field', label: 'owner_field' },
    { key: 'organization_field', label: 'organization_field' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="业务资源"
    description="应用声明的可授权资源，不是案件或订单实体本身。"
    endpoint="resource"
    index-permission="sand_iam:resource:index"
    permission-prefix="sand_iam:resource"
    :columns="columns"
    :filters="filters"
    :form-fields="resourceFields"
  />
</template>
