<script setup lang="ts">
  import { routeBindingFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'
  import ResourceListPage from '../components/ResourceListPage.vue'

  const columns: SandIamResourceColumn[] = [
    { key: 'http_method', label: '请求方法' },
    { key: 'route_template', label: '路由模板', minWidth: 240 },
    { key: 'api_resource_id', label: '绑定接口', minWidth: 180 },
    { key: 'source', label: '来源' },
    { key: 'status', label: '状态' },
    { key: 'application_id', label: '所属接入应用', minWidth: 180 }
  ]
  const filters: SandIamFilterKey[] = ['organization_id', 'application_id', 'status']
</script>

<template>
  <ResourceListPage
    title="路由绑定"
    create-title="手工登记路由"
    object-hint="扫描只发现路由，不自动创建业务资源、动作、策略或授权。变更方法或模板请新建绑定。"
    description="默认列表只展示请求方式、路由、接口和状态。导入清单时，请先到「开发者接入清单」查看变更，再确认保存。"
    endpoint="api-route-binding"
    index-permission="sand_iam:api_route_binding:index"
    permission-prefix="sand_iam:api_route_binding"
    write-mode="binding"
    disable-impact="停用后，该请求方法 + 模板不再参与授权定位；冲突绑定不会被系统猜测覆盖。"
    :columns="columns"
    :filters="filters"
    :form-fields="routeBindingFields"
  />
</template>
