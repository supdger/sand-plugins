<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { environmentFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'application_id', label: '所属接入应用', minWidth: 200 },
    { key: 'status', label: '状态' },
    { key: 'code', label: '系统代码（用于接口配置）', minWidth: 220, copyable: true }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'status', 'organization_id', 'application_id']
</script>

<template>
  <ResourceListPage
    title="应用环境"
    create-title="新建应用环境"
    object-hint="同一接入应用的开发、测试或生产隔离边界，不是某个模型或某位用户。"
    description="接入应用的开发、测试、生产隔离范围。创建环境后，可为该环境登记服务调用身份。"
    endpoint="environment"
    index-permission="sand_iam:environment:index"
    permission-prefix="sand_iam:environment"
    :columns="columns"
    :filters="filters"
    :form-fields="environmentFields"
  />
</template>
