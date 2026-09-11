<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { adminGrantFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'admin_user_name', label: '后台管理员', minWidth: 180 },
    { key: 'organization_id', label: '客户主体', minWidth: 180 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['status', 'organization_id']
</script>

<template>
  <ResourceListPage
    title="客户主体管理委派"
    description="指定哪些后台管理员可管理某个客户主体。默认列表显示管理员名称、客户主体和状态；请选择已有管理员和客户主体后保存。"
    endpoint="admin-organization-grant"
    index-permission="sand_iam:admin_organization_grant:index"
    permission-prefix="sand_iam:admin_organization_grant"
    :columns="columns"
    :filters="filters"
    :form-fields="adminGrantFields"
  />
</template>
