<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { actionFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'service_id', label: 'service_id' },
    { key: 'status', label: '状态' },
    { key: 'code', label: 'code' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'service_id']
</script>

<template>
  <ResourceListPage
    title="服务动作"
    description="服务可提供的具体能力，例如文档解析；它是业务能力标识，不是 HTTP 地址。创建后才能向服务调用身份授权。"
    endpoint="action"
    index-permission="sand_iam:service_action:index"
    permission-prefix="sand_iam:service_action"
    :columns="columns"
    :filters="filters"
    :form-fields="actionFields"
  />
</template>
