<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { serviceFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'code', label: 'code' },
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status']
</script>

<template>
  <ResourceListPage
    title="平台服务"
    description="全局服务目录；仅超级管理员可改。委派管理员越权会 403。"
    endpoint="service"
    index-permission="sand_iam:service:index"
    permission-prefix="sand_iam:service"
    :columns="columns"
    :filters="filters"
    :form-fields="serviceFields"
  />
</template>
