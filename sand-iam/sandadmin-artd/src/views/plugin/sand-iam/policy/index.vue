<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { policyFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'application_id', label: '所属接入应用' },
    { key: 'resource_id', label: 'resource_id' },
    { key: 'role_id', label: 'role_id' },
    { key: 'identity_id', label: '应用身份' },
    { key: 'action', label: '业务动作' },
    { key: 'effect', label: '授权结果' },
    { key: 'state', label: '发布状态' },
    { key: 'status', label: '状态' },
    { key: 'priority', label: 'priority' }
  ]
  const filters: SandIamFilterKey[] = ['status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="策略"
    description="定义谁能对哪类业务资源做什么。只有已发布且已启用的策略才会参与运行时授权判断。"
    endpoint="policy"
    index-permission="sand_iam:policy:index"
    permission-prefix="sand_iam:policy"
    write-mode="policy"
    :columns="columns"
    :filters="filters"
    :form-fields="policyFields"
  />
</template>
