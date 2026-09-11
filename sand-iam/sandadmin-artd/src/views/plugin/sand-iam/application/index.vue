<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { applicationFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'organization_id', label: '所属客户主体', minWidth: 200 },
    { key: 'status', label: '状态' },
    { key: 'code', label: '系统代码（用于接口配置）', minWidth: 220, copyable: true }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'organization_id']
</script>

<template>
  <ResourceListPage
    title="接入应用"
    create-title="新建接入应用"
    object-hint="填写需要接入的产品或项目名称；人员、客户主体和后台账号都不是接入应用。"
    description="在客户主体下登记要接入 Sand 平台的产品或项目。登记后，继续创建该应用的开发、测试或生产环境。"
    endpoint="application"
    index-permission="sand_iam:application:index"
    permission-prefix="sand_iam:application"
    :columns="columns"
    :filters="filters"
    :form-fields="applicationFields"
  />
</template>
