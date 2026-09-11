<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { identityProviderFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '名称', minWidth: 180 },
    { key: 'scope_type', label: '使用范围', minWidth: 160 },
    { key: 'provider_type', label: '协议' },
    { key: 'secret_configured', label: '密钥状态' },
    { key: 'status', label: '状态' },
    { key: 'code', label: '系统代码（用于接口配置）', minWidth: 200, copyable: true }
  ]
  const filters: SandIamFilterKey[] = [
    'keywords',
    'organization_id',
    'application_id',
    'scope_type',
    'status'
  ]
</script>

<template>
  <ResourceListPage
    title="身份源"
    create-title="新建身份源"
    object-hint="先登记名称和范围，再去「联合配置」填写协议密钥。本地账号也可以先建空身份源。"
    description="身份源是应用用户从哪里来：本地账号、企业统一登录或目录。默认列表只显示名称、范围、协议、密钥是否已配置和状态；密钥内容不会显示。"
    endpoint="identity-provider"
    index-permission="sand_iam:identity_provider:index"
    permission-prefix="sand_iam:identity_provider"
    disable-impact="停用后，该身份源不能再用于新的登录或绑定；已有用户不会自动删除。密钥不会回显。"
    :columns="columns"
    :filters="filters"
    :form-fields="identityProviderFields"
  />
</template>
