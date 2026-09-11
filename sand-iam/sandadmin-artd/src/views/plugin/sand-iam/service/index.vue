<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { serviceFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'status', label: '状态' },
    { key: 'code', label: 'code' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status']
</script>

<template>
  <ResourceListPage
    title="平台服务"
    description="平台可对外提供的服务目录，仅平台超级管理员维护。服务下需要继续登记可授权的服务动作。"
    endpoint="service"
    index-permission="sand_iam:service:index"
    permission-prefix="sand_iam:service"
    :columns="columns"
    :filters="filters"
    :form-fields="serviceFields"
  />
</template>
