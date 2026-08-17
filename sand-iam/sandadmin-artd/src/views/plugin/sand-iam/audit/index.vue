<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'id', label: 'ID', minWidth: 80 },
    { key: 'actor_type', label: 'actor_type' },
    { key: 'actor_ref', label: 'actor_ref', minWidth: 140 },
    { key: 'action', label: 'action', minWidth: 180 },
    { key: 'resource_type', label: 'resource_type' },
    { key: 'outcome', label: 'outcome' },
    { key: 'create_time', label: 'create_time', minWidth: 180 }
  ]
  const filters: SandIamFilterKey[] = ['organization_id', 'application_id', 'actor_type', 'outcome']
</script>

<template>
  <ResourceListPage
    title="访问审计"
    description="安全敏感操作的只读审计；不显示凭证明文或完整 token。"
    endpoint="audit"
    index-permission="sand_iam:audit:index"
    permission-prefix="sand_iam:audit"
    write-mode="readonly"
    :columns="columns"
    :filters="filters"
  />
</template>
