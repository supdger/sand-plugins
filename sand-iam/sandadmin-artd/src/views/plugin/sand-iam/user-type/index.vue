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
    title="用户类型"
    description="应用可配置的用户分类，例如管理员、成员或客户；用于组织应用身份和授权规则。"
    endpoint="user-type"
    index-permission="sand_iam:user_type:index"
    permission-prefix="sand_iam:user_type"
    :columns="columns"
    :filters="filters"
    :form-fields="namedAppFields"
  />
</template>
