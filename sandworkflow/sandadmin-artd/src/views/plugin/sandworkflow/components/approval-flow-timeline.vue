<template>
  <div class="approval-flow" :class="`approval-flow--${variant}`">
    <div
      v-for="(item, index) in items"
      :key="item.key"
      class="approval-flow__item"
      :class="[`is-${item.state}`, `is-kind-${item.kind}`]"
    >
      <div class="approval-flow__rail">
        <span class="approval-flow__dot">
          <span v-if="item.state === 'done'">✓</span>
          <span v-else-if="item.state === 'rejected'">×</span>
        </span>
        <span v-if="index < items.length - 1" class="approval-flow__line" />
      </div>

      <div class="approval-flow__content">
        <div class="approval-flow__heading">
          <div class="approval-flow__title-wrap">
            <strong>{{ item.title }}</strong>
            <span v-if="kindLabel(item.kind)" class="approval-flow__badge">{{ kindLabel(item.kind) }}</span>
          </div>
          <time v-if="item.time">{{ item.time }}</time>
        </div>
        <div v-if="item.people.length" class="approval-flow__people">
          <span
            v-for="person in item.people"
            :key="person.id || person.name"
            class="approval-flow__person"
            :class="`is-${person.state}`"
          >
            <span class="approval-flow__avatar">{{ initials(person.name) }}</span>
            <span>{{ person.name }}</span>
            <span v-if="person.state === 'done'" class="approval-flow__check">✓</span>
          </span>
        </div>
        <p v-if="item.comment" class="approval-flow__comment">{{ item.comment }}</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import type { ApprovalVisualItem } from './approval-flow'

  withDefaults(
    defineProps<{
      items: ApprovalVisualItem[]
      variant?: 'preview' | 'detail'
    }>(),
    { variant: 'detail' }
  )

  function initials(name: string): string {
    return name.trim().slice(0, 2) || '人'
  }

  function kindLabel(kind: ApprovalVisualItem['kind']): string {
    const labels: Partial<Record<ApprovalVisualItem['kind'], string>> = {
      comment: '评论',
      transfer: '转办',
      'add-sign': '加签',
      'del-sign': '减签',
      back: '回退',
      copy: '抄送'
    }
    return labels[kind] || ''
  }
</script>

<style scoped>
  .approval-flow {
    --flow-color: #94a3b8;
    display: grid;
    min-width: 0;
  }

  .approval-flow__item {
    display: grid;
    grid-template-columns: 24px minmax(0, 1fr);
    min-height: 82px;
  }

  .approval-flow__rail {
    position: relative;
    display: flex;
    justify-content: center;
  }

  .approval-flow__dot {
    z-index: 1;
    display: grid;
    width: 12px;
    height: 12px;
    margin-top: 5px;
    color: #fff;
    font-size: 9px;
    line-height: 1;
    background: var(--flow-color);
    border: 2px solid #fff;
    border-radius: 50%;
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--flow-color) 45%, transparent);
    place-items: center;
  }

  .approval-flow__line {
    position: absolute;
    top: 18px;
    bottom: -5px;
    width: 2px;
    background: #dbe3ef;
  }

  .approval-flow__content {
    min-width: 0;
    padding: 0 0 22px 12px;
  }

  .approval-flow__heading {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    min-height: 24px;
  }

  .approval-flow__heading strong {
    color: var(--el-text-color-primary);
    font-size: 14px;
    font-weight: 600;
  }

  .approval-flow__title-wrap {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    min-width: 0;
  }

  .approval-flow__badge {
    display: inline-flex;
    align-items: center;
    height: 20px;
    padding: 0 7px;
    color: var(--flow-color);
    font-size: 11px;
    font-weight: 600;
    background: color-mix(in srgb, var(--flow-color) 12%, #fff);
    border: 1px solid color-mix(in srgb, var(--flow-color) 28%, transparent);
    border-radius: 999px;
  }

  .approval-flow__heading time {
    flex: none;
    color: var(--el-text-color-placeholder);
    font-size: 12px;
  }

  .approval-flow__people {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
  }

  .approval-flow__person {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 28px;
    padding: 2px 9px 2px 3px;
    color: var(--el-text-color-regular);
    font-size: 12px;
    background: var(--el-fill-color-light);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 18px;
  }

  .approval-flow__avatar {
    display: grid;
    width: 22px;
    height: 22px;
    color: #fff;
    font-size: 10px;
    background: linear-gradient(135deg, #3b82f6, #22c55e);
    border-radius: 50%;
    place-items: center;
  }

  .approval-flow__check {
    position: absolute;
    right: -3px;
    bottom: -2px;
    display: grid;
    width: 13px;
    height: 13px;
    color: #fff;
    font-size: 9px;
    background: #22c55e;
    border: 2px solid #fff;
    border-radius: 50%;
    place-items: center;
  }

  .approval-flow__comment {
    margin: 9px 0 0;
    padding: 9px 12px;
    color: var(--el-text-color-regular);
    font-size: 13px;
    line-height: 1.6;
    background: var(--el-fill-color-lighter);
    border-radius: 4px;
  }

  .approval-flow__item.is-active {
    --flow-color: #2f7df6;
  }

  .approval-flow__item.is-done {
    --flow-color: #22c55e;
  }

  .approval-flow__item.is-kind-comment {
    --flow-color: #64748b;
  }

  .approval-flow__item.is-kind-transfer {
    --flow-color: #0ea5e9;
  }

  .approval-flow__item.is-kind-add-sign {
    --flow-color: #8b5cf6;
  }

  .approval-flow__item.is-kind-del-sign {
    --flow-color: #f97316;
  }

  .approval-flow__item.is-kind-back {
    --flow-color: #eab308;
  }

  .approval-flow__item.is-kind-copy {
    --flow-color: #14b8a6;
  }

  .approval-flow__item.is-rejected {
    --flow-color: #ef4444;
  }

  .approval-flow__item.is-skipped {
    --flow-color: #94a3b8;
  }

  .approval-flow--preview .approval-flow__item {
    min-height: 70px;
  }

  .approval-flow--preview .approval-flow__content {
    padding-bottom: 16px;
  }

  @media (max-width: 720px) {
    .approval-flow__heading {
      align-items: flex-start;
      flex-direction: column;
      gap: 2px;
    }
  }
</style>
