<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { adminGrantFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'admin_user_id', label: 'admin_user_id' },
    { key: 'organization_id', label: 'organization_id' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['status', 'organization_id']
</script>

<template>
  <ResourceListPage
    title="管理员组织委派"
    description="仅宿主超级管理员可管理。非超管会得到组织访问拒绝，而不是空成功。"
    endpoint="admin-organization-grant"
    index-permission="sand_iam:admin_organization_grant:index"
    permission-prefix="sand_iam:admin_organization_grant"
    :columns="columns"
    :filters="filters"
    :form-fields="adminGrantFields"
  />
</template>
