<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { organizationFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 220 },
    { key: 'status', label: '状态' },
    { key: 'code', label: '系统代码（用于接口配置）', minWidth: 220, copyable: true }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status']
  const formFields = organizationFields
</script>

<template>
  <ResourceListPage
    title="客户主体（组织）"
    create-title="新建客户主体"
    object-hint="填写独立客户主体的名称；具体产品请在「接入应用」中登记。"
    description="客户主体是接入 Sand 平台的独立组织，也是数据隔离和授权范围的上层边界。先创建客户主体，再在其下登记接入应用。"
    endpoint="organization"
    index-permission="sand_iam:organization:index"
    permission-prefix="sand_iam:organization"
    :columns="columns"
    :filters="filters"
    :form-fields="formFields"
  />
</template>
