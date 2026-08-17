<template>
  <div class="art-full-height workflow-center-page">
    <FlowWorkbench
      :items="workbenchItems"
      :loading="loading"
      :current-page="pagination.current"
      :page-size="pagination.size"
      :total="pagination.total"
      @refresh="refreshData"
      @page-change="handleCurrentChange"
    />
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { useTable } from '@/hooks/core/useTable'
  import FlowWorkbench from '../../components/flow-workbench.vue'
  import type { FlowWorkbenchItem } from '../../components/flow-workbench'
  import {
    extractInitiatorName,
    extractSummaryLines
  } from '../../components/flow-workbench'
  import api from '../../api'

  const { data, loading, pagination, refreshData, handleCurrentChange } = useTable({
    core: {
      apiFn: api.instance.processed,
      columnsFactory: () => []
    }
  })

  const workbenchItems = computed<FlowWorkbenchItem[]>(() =>
    data.value.map((task) => ({
      key: task.id,
      instanceId: task.instance_id,
      title: task.instance?.name || `审批实例 #${task.instance_id}`,
      initiatorName: task.instance ? extractInitiatorName(task.instance) : undefined,
      summaryLines: task.instance ? extractSummaryLines(task.instance.form_value) : undefined,
      nodeName: task.node_name || '审批节点',
      comment: task.comment,
      time: task.ended_time || task.create_time,
      statusText: task.operation_type === 4 ? '已同意' : '已拒绝',
      statusType: task.operation_type === 4 ? 'success' : 'danger'
    }))
  )
</script>

<style scoped>
  .workflow-center-page {
    min-height: 0;
  }
</style>
