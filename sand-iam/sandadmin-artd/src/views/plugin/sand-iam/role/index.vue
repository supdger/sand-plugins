<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { namedAppFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'application_id', label: '所属接入应用' },
    { key: 'status', label: '状态' },
    { key: 'code', label: 'code' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="角色"
    description="应用内可复用的权限角色。给身份授予角色后，可在策略中统一配置其可执行的操作。"
    endpoint="role"
    index-permission="sand_iam:role:index"
    permission-prefix="sand_iam:role"
    :columns="columns"
    :filters="filters"
    :form-fields="namedAppFields"
  />
</template>
