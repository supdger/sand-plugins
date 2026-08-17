<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData" />
      <ArtTable
        rowKey="id"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #flow_status="{ row }">
          <ElTag :type="statusType(row.flow_status)" effect="light">
            {{ statusText(row.flow_status) }}
          </ElTag>
        </template>
        <template #operation="{ row }">
          <SaButton type="success" toolTip="查看详情" @click="openDetail(row.id)" />
        </template>
      </ArtTable>
    </ElCard>
    <InstanceDetailDialog v-model="detailVisible" :instance-id="detailInstanceId" />
  </div>
</template>

<script setup lang="ts">
  import { useTable } from '@/hooks/core/useTable'
  import type { FlowInstance, FlowTask } from '../../api'
  import InstanceDetailDialog from '../../components/instance-detail-dialog.vue'
  import api from '../../api'

  type InstanceListRow = FlowInstance & {
    definition?: { id?: number; name?: string } | null
    tasks?: FlowTask[]
  }

  const detailVisible = ref(false)
  const detailInstanceId = ref<string>()
  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    refreshData,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: api.instance.list,
      columnsFactory: () => [
        {
          prop: 'name',
          label: '审批标题',
          minWidth: 220,
          showOverflowTooltip: true
        },
        {
          prop: 'definition_name',
          label: '流程定义',
          minWidth: 160,
          showOverflowTooltip: true,
          formatter: (row: InstanceListRow) => definitionName(row)
        },
        {
          prop: 'act_node_name',
          label: '当前节点',
          minWidth: 140,
          showOverflowTooltip: true,
          formatter: (row: InstanceListRow) => currentNodeName(row)
        },
        {
          prop: 'flow_status',
          label: '状态',
          width: 110,
          useSlot: true
        },
        { prop: 'create_time', label: '创建时间', width: 180 },
        {
          prop: 'id',
          label: '编号',
          minWidth: 180,
          showOverflowTooltip: true,
          checked: false
        },
        { prop: 'operation', label: '操作', width: 100, fixed: 'right', useSlot: true }
      ]
    }
  })

  function openDetail(id: string) {
    detailInstanceId.value = id
    detailVisible.value = true
  }

  function definitionName(row: InstanceListRow): string {
    const name = row.definition?.name?.trim()
    if (name) return name
    return row.definition_id ? `流程 #${row.definition_id}` : '-'
  }

  function currentNodeName(row: InstanceListRow): string {
    const actNodeId = String(row.act_node_id || '').trim()
    if (actNodeId === 'end' || row.flow_status !== 0) return '已结束'
    const pending = Array.isArray(row.tasks) ? row.tasks.find((task) => task.task_status === 0) : undefined
    const pendingName = pending?.node_name?.trim()
    if (pendingName) return pendingName
    if (actNodeId) return '审批中'
    return '-'
  }

  function statusText(status: number): string {
    return ['审批中', '已通过', '已拒绝', '已撤销'][status] || '未知'
  }

  function statusType(status: number): 'primary' | 'success' | 'danger' | 'warning' | 'info' {
    if (status === 0) return 'primary'
    if (status === 1) return 'success'
    if (status === 2) return 'danger'
    if (status === 3) return 'warning'
    return 'info'
  }
</script>
