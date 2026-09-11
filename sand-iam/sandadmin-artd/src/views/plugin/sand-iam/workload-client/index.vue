<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { clientFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'environment_id', label: '所属应用环境', minWidth: 200 },
    { key: 'audience', label: '服务受众', minWidth: 160 },
    { key: 'status', label: '状态' },
    { key: 'code', label: '系统代码（用于接口配置）', minWidth: 220, copyable: true }
  ]
  const filters: SandIamFilterKey[] = [
    'keywords',
    'status',
    'organization_id',
    'application_id',
    'environment_id'
  ]
</script>

<template>
  <ResourceListPage
    title="服务调用身份"
    create-title="新建服务调用身份"
    object-hint="应用后端调用已登记服务时使用的机器身份；SandAI 只是其中一种服务，不是人的登录账号。"
    description="接入应用后端调用平台服务时使用的机器身份。登记后可为它签发调用凭证。"
    endpoint="client"
    index-permission="sand_iam:client:index"
    permission-prefix="sand_iam:client"
    :columns="columns"
    :filters="filters"
    :form-fields="clientFields"
  />
</template>
