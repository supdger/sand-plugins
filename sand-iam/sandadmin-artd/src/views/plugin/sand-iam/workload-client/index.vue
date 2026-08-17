<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { clientFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'environment_id', label: 'environment_id' },
    { key: 'code', label: 'code' },
    { key: 'name', label: '名称', minWidth: 160 },
    { key: 'audience', label: 'audience', minWidth: 160 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'environment_id']
</script>

<template>
  <ResourceListPage
    title="工作负载客户端"
    description="服务调用方的工作负载客户端；凭证不在列表返回。"
    endpoint="client"
    index-permission="sand_iam:client:index"
    permission-prefix="sand_iam:client"
    :columns="columns"
    :filters="filters"
    :form-fields="clientFields"
  />
</template>
