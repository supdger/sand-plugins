<script setup lang="ts">
  /**
   * CAS 管理列表走冻结 ResourceController。服务地址创建后不可改，完整 URL 只在表单。
   */
  import { casServiceFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'
  import ResourceListPage from '../components/ResourceListPage.vue'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '服务名称', minWidth: 180 },
    { key: 'application_id', label: '所属接入应用', minWidth: 180 },
    { key: 'service_url', label: '服务地址摘要', minWidth: 180 },
    { key: 'released_attributes', label: '允许返回的用户资料', minWidth: 160 },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'organization_id', 'application_id', 'status']
</script>

<template>
  <ResourceListPage
    title="CAS 接入服务"
    create-title="新建 CAS 接入服务"
    object-hint="服务地址创建后不可修改。要改地址请新增服务并停用旧记录。完整地址只在表单出现。"
    description="登记应用用户确认后可跳转的 CAS 服务。默认列表只显示名称、所属应用、地址摘要、允许返回的用户资料和状态。请求、Ticket 和用户身份编号不进入本页。"
    endpoint="cas-service"
    index-permission="sand_iam:cas_service:index"
    permission-prefix="sand_iam:cas_service"
    disable-impact="停用后，该服务地址不能再发起新的 CAS 确认。已发出的 Ticket 仍按各自有效期失效。改地址必须新增记录。"
    :columns="columns"
    :filters="filters"
    :form-fields="casServiceFields"
  />
</template>
