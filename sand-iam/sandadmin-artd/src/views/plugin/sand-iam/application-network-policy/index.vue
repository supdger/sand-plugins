<script setup lang="ts">
  /**
   * 每个接入应用一条网络规则。列表只显示网段数量，拒绝优先说明写在表单帮助里。
   */
  import { applicationNetworkPolicyFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'
  import ResourceListPage from '../components/ResourceListPage.vue'

  const columns: SandIamResourceColumn[] = [
    { key: 'application_id', label: '所属接入应用', minWidth: 180 },
    { key: 'allow_cidrs', label: '允许网段摘要' },
    { key: 'deny_cidrs', label: '拒绝网段摘要' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['organization_id', 'application_id', 'status']
</script>

<template>
  <ResourceListPage
    title="应用网络规则"
    create-title="新建应用网络规则"
    object-hint="每个接入应用一条规则。拒绝网段优先于允许网段。完整 CIDR 只在表单出现。"
    description="按接入应用限制登录来源。启用后可能立即挡住当前登录。每个应用只能有一条规则，重复新建会返回冲突。"
    endpoint="application-network-policy"
    index-permission="sand_iam:application_network_policy:index"
    permission-prefix="sand_iam:application_network_policy"
    disable-impact="停用后不再按该规则拦截登录。重新启用会立即按拒绝优先生效。"
    :columns="columns"
    :filters="filters"
    :form-fields="applicationNetworkPolicyFields"
  />
</template>
