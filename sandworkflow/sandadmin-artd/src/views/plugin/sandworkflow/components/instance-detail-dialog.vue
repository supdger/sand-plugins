<template>
  <ElDialog
    :model-value="modelValue"
    width="min(1180px, calc(100vw - 48px))"
    top="3vh"
    title="审批详情"
    :close-on-click-modal="false"
    destroy-on-close
    class="instance-detail-dialog"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <InstanceDetailView
      class="dialog-detail-view"
      :instance-id="instanceId"
      :task-id="taskId"
      :cancelable="cancelable"
      @processed="emit('processed')"
    />
  </ElDialog>
</template>

<script setup lang="ts">
  import type { WorkflowRuntimeId } from '../api'
  import InstanceDetailView from './instance-detail-drawer.vue'

  defineProps<{
    modelValue: boolean
    instanceId?: WorkflowRuntimeId
    taskId?: WorkflowRuntimeId
    cancelable?: boolean
  }>()
  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    processed: []
  }>()
</script>

<style scoped>
  :deep(.instance-detail-dialog) {
    max-width: calc(100vw - 32px);
    overflow: hidden;
    border-radius: 6px;
  }

  :deep(.instance-detail-dialog .el-dialog__header) {
    padding: 14px 20px;
    margin: 0;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  :deep(.instance-detail-dialog .el-dialog__title) {
    font-size: 15px;
    font-weight: 600;
  }

  :deep(.instance-detail-dialog .el-dialog__body) {
    padding: 0;
  }

  .dialog-detail-view {
    height: calc(94vh - 74px);
  }
</style>
