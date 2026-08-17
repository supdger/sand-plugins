<template>
  <ElDialog
    :model-value="visible"
    title="打印预览"
    width="720px"
    class="flow-print-dialog"
    destroy-on-close
    @close="emit('update:visible', false)"
  >
    <div ref="printRoot" class="flow-print-preview">
      <div class="stamp-wrap">
        <FlowStatusStamp :status="status" :size="110" />
      </div>
      <h2>{{ title }}</h2>
      <p class="meta">审批编号：{{ instanceId || '-' }}</p>
      <p class="meta">提交人：{{ initiatorName || '-' }}</p>
      <p class="meta">提交时间：{{ createTime || '-' }}</p>
      <div class="section-title">表单详情</div>
      <FlowFormDetail :widgets="widgets" :form-value="formValue" />
      <div class="section-title">审批记录</div>
      <ApprovalFlowTimeline v-if="timelineItems.length" :items="timelineItems" variant="detail" />
      <ElEmpty v-else description="暂无审批记录" :image-size="56" />
    </div>
    <template #footer>
      <ElButton @click="emit('update:visible', false)">关闭</ElButton>
      <ElButton type="primary" @click="handlePrint">打印</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { nextTick, ref } from 'vue'
  import type { ApprovalVisualItem } from './approval-flow'
  import ApprovalFlowTimeline from './approval-flow-timeline.vue'
  import FlowFormDetail from './flow-form-detail.vue'
  import FlowStatusStamp from './flow-status-stamp.vue'

  interface PrintWidget {
    name: string
    label: string
    type: number
    details?: PrintWidget[]
  }

  defineProps<{
    visible: boolean
    title: string
    instanceId?: string
    initiatorName?: string
    createTime?: string
    status: number
    widgets: PrintWidget[]
    formValue: Record<string, unknown>
    timelineItems: ApprovalVisualItem[]
  }>()

  const emit = defineEmits<{
    'update:visible': [value: boolean]
  }>()

  const printRoot = ref<HTMLElement>()

  async function handlePrint(): Promise<void> {
    await nextTick()
    window.print()
  }
</script>

<style scoped>
  .flow-print-preview {
    position: relative;
    padding: 8px 4px 16px;
  }

  .stamp-wrap {
    position: absolute;
    top: 0;
    right: 12px;
    opacity: 0.9;
    transform: rotate(-10deg);
  }

  h2 {
    margin: 0 0 12px;
    padding-right: 120px;
    color: var(--el-text-color-primary);
    font-size: 22px;
    font-weight: 650;
  }

  .meta {
    margin: 0 0 6px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }

  .section-title {
    margin: 18px 0 10px;
    color: var(--el-text-color-primary);
    font-size: 15px;
    font-weight: 600;
  }
</style>
