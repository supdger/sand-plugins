<template>
  <div class="art-full-height workflow-center-page">
    <FlowWorkbench
      :items="workbenchItems"
      :loading="loading"
      :current-page="pagination.current"
      :page-size="pagination.size"
      :total="pagination.total"
      cancelable
      @refresh="refreshData"
      @page-change="handleCurrentChange"
      @processed="refreshData"
    />
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { useTable } from '@/hooks/core/useTable'
  import FlowWorkbench from '../../components/flow-workbench.vue'
  import type { FlowWorkbenchItem, WorkbenchTagType } from '../../components/flow-workbench'
  import {
    extractInitiatorName,
    extractSummaryLines
  } from '../../components/flow-workbench'
  import api from '../../api'

  const { data, loading, pagination, refreshData, handleCurrentChange } = useTable({
    core: {
      apiFn: api.instance.mine,
      columnsFactory: () => []
    }
  })

  const workbenchItems = computed<FlowWorkbenchItem[]>(() =>
    data.value.map((instance) => ({
      key: instance.id,
      instanceId: instance.id,
      title: instance.name,
      initiatorName: extractInitiatorName(instance),
      summaryLines: extractSummaryLines(instance.form_value),
      nodeName: instance.flow_status === 0 ? '审批流转中' : '流程已结束',
      time: instance.create_time,
      statusText: statusText(instance.flow_status),
      statusType: statusType(instance.flow_status)
    }))
  )

  function statusText(status: number): string {
    return ['审批中', '已通过', '已拒绝', '已撤销'][status] ?? '未知'
  }

  function statusType(status: number): WorkbenchTagType {
    if (status === 0) return 'primary'
    if (status === 1) return 'success'
    if (status === 2) return 'danger'
    if (status === 3) return 'warning'
    return 'info'
  }
</script>

<style scoped>
  .workflow-center-page {
    min-height: 0;
  }
</style>
