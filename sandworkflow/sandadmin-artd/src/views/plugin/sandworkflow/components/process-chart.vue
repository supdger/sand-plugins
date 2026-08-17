<template>
  <div class="process-chart" :class="`is-${variant}`">
    <div
      v-for="(item, index) in items"
      :key="item.key"
      class="process-chart__item"
      :class="[`is-${item.state}`, `is-kind-${item.kind}`]"
    >
      <div class="process-chart__rail">
        <span class="process-chart__dot">
          <span v-if="item.state === 'done'">✓</span>
          <span v-else-if="item.state === 'rejected'">×</span>
          <span v-else-if="item.state === 'active'">●</span>
        </span>
        <span v-if="index < items.length - 1" class="process-chart__line" />
      </div>
      <div class="process-chart__body">
        <strong>{{ item.title }}</strong>
        <span class="process-chart__state">{{ stateLabel(item.state) }}</span>
      </div>
    </div>
    <ElEmpty v-if="items.length === 0" description="暂无流程图" :image-size="56" />
  </div>
</template>

<script setup lang="ts">
  import type { ApprovalVisualItem, ApprovalVisualState } from './approval-flow'

  withDefaults(
    defineProps<{
      items: ApprovalVisualItem[]
      variant?: 'detail' | 'compact'
    }>(),
    { variant: 'detail' }
  )

  function stateLabel(state: ApprovalVisualState): string {
    if (state === 'done') return '已完成'
    if (state === 'active') return '进行中'
    if (state === 'rejected') return '已拒绝'
    if (state === 'skipped') return '已跳过'
    return '未到达'
  }
</script>

<style scoped>
  .process-chart {
    display: grid;
    gap: 0;
    padding: 4px 0;
  }

  .process-chart__item {
    --chart-color: #94a3b8;
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr);
    min-height: 48px;
  }

  .process-chart__rail {
    position: relative;
    display: flex;
    justify-content: center;
  }

  .process-chart__dot {
    z-index: 1;
    display: grid;
    width: 14px;
    height: 14px;
    margin-top: 4px;
    color: #fff;
    font-size: 9px;
    line-height: 1;
    background: var(--chart-color);
    border-radius: 50%;
    place-items: center;
  }

  .process-chart__line {
    position: absolute;
    top: 20px;
    bottom: -4px;
    width: 2px;
    background: #dbe3ef;
  }

  .process-chart__body {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    padding: 0 0 14px 10px;
  }

  .process-chart__body strong {
    color: var(--el-text-color-primary);
    font-size: 13px;
    font-weight: 600;
  }

  .process-chart__state {
    color: var(--el-text-color-placeholder);
    font-size: 12px;
  }

  .process-chart__item.is-active {
    --chart-color: #2f7df6;
  }

  .process-chart__item.is-done {
    --chart-color: #22c55e;
  }

  .process-chart__item.is-rejected {
    --chart-color: #ef4444;
  }

  .process-chart__item.is-skipped {
    --chart-color: #94a3b8;
  }
</style>
