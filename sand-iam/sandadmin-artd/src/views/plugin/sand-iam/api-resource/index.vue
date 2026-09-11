<script setup lang="ts">
  import { apiResourceFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'
  import ResourceListPage from '../components/ResourceListPage.vue'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '接口名称', minWidth: 180 },
    { key: 'code', label: '接口代码', minWidth: 160, copyable: true },
    { key: 'application_id', label: '所属接入应用', minWidth: 180 },
    { key: 'resource_id', label: '业务资源', minWidth: 160 },
    { key: 'action', label: '业务动作', minWidth: 160 },
    { key: 'operation', label: '操作类型' },
    { key: 'api_version', label: '版本' },
    { key: 'audience', label: '受众' },
    { key: 'risk_level', label: '风险等级' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'organization_id', 'application_id', 'status']
</script>

<template>
  <ResourceListPage
    title="接口目录"
    create-title="登记接口"
    object-hint="必须先有已启用业务动作。接口代码给 SDK 用；长期权限依据是业务资源 + 语义动作，不是 URL。"
    description="默认列表只展示接入应用、业务资源、接口代码、语义动作、操作类型和版本。创建后这些关键归属不可改。"
    endpoint="api-resource"
    index-permission="sand_iam:api_resource:index"
    permission-prefix="sand_iam:api_resource"
    disable-impact="停用后，该接口不能再被路由绑定或授权决策命中；已有审计会保留。"
    :columns="columns"
    :filters="filters"
    :form-fields="apiResourceFields"
  />
</template>
