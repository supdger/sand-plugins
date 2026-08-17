<template>
  <section class="workflow-workbench">
    <aside class="workflow-list-pane">
      <div class="workflow-search">
        <ElInput v-model="keywords" clearable placeholder="搜索审批标题" />
        <ElButton aria-label="刷新列表" @click="emit('refresh')">刷新</ElButton>
      </div>

      <div v-loading="loading" class="workflow-card-list">
        <button
          v-for="item in filteredItems"
          :key="item.key"
          type="button"
          class="workflow-card"
          :class="{ 'is-active': item.key === activeKey }"
          @click="activeKey = item.key"
        >
          <span class="workflow-card__title-row">
            <strong>{{ item.title }}</strong>
            <ElTag :type="item.statusType" effect="light" size="small">
              {{ item.statusText }}
            </ElTag>
          </span>
          <span v-if="item.initiatorName" class="workflow-card__initiator">
            {{ item.initiatorName }}
          </span>
          <span v-if="item.nodeName" class="workflow-card__node">
            {{ item.nodeName }}
          </span>
          <span
            v-for="(line, index) in item.summaryLines || []"
            :key="`${item.key}-summary-${index}`"
            class="workflow-card__summary"
          >
            {{ line }}
          </span>
          <span v-if="item.comment" class="workflow-card__comment">
            {{ item.comment }}
          </span>
          <time>{{ item.time || '-' }}</time>
        </button>
        <ElEmpty
          v-if="!loading && filteredItems.length === 0"
          description="暂无审批记录"
          :image-size="64"
        />
      </div>

      <ElPagination
        v-if="total > pageSize"
        small
        background
        layout="prev, pager, next"
        :current-page="currentPage"
        :page-size="pageSize"
        :total="total"
        class="workflow-pagination"
        @current-change="emit('page-change', $event)"
      />
    </aside>

    <main class="workflow-detail-pane">
      <InstanceDetailView
        v-if="activeItem"
        :instance-id="activeItem.instanceId"
        :task-id="activeItem.taskId"
        :cancelable="cancelable"
        @processed="emit('processed')"
      />
      <ElEmpty v-else description="请从左侧选择一条审批" />
    </main>
  </section>
</template>

<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import type { FlowWorkbenchItem } from './flow-workbench'
  import InstanceDetailView from './instance-detail-drawer.vue'

  const props = withDefaults(
    defineProps<{
      items: FlowWorkbenchItem[]
      loading?: boolean
      cancelable?: boolean
      currentPage?: number
      pageSize?: number
      total?: number
    }>(),
    {
      loading: false,
      cancelable: false,
      currentPage: 1,
      pageSize: 10,
      total: 0
    }
  )
  const emit = defineEmits<{
    refresh: []
    processed: []
    'page-change': [page: number]
  }>()

  const keywords = ref('')
  const activeKey = ref<string>()
  const filteredItems = computed(() => {
    const keyword = keywords.value.trim().toLocaleLowerCase()
    if (!keyword) return props.items
    return props.items.filter((item) =>
      [item.title, item.nodeName, item.comment, item.initiatorName, ...(item.summaryLines || [])]
        .filter((value): value is string => Boolean(value))
        .some((value) => value.toLocaleLowerCase().includes(keyword))
    )
  })
  const activeItem = computed(() => props.items.find((item) => item.key === activeKey.value))

  watch(
    () => props.items,
    (items) => {
      if (items.some((item) => item.key === activeKey.value)) return
      activeKey.value = items[0]?.key
    },
    { immediate: true }
  )
</script>

<style scoped>
  .workflow-workbench {
    display: grid;
    grid-template-columns: 330px minmax(0, 1fr);
    gap: 14px;
    width: 100%;
    height: 100%;
    min-height: 0;
    overflow: hidden;
  }

  .workflow-list-pane,
  .workflow-detail-pane {
    min-width: 0;
    min-height: 0;
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }

  .workflow-list-pane {
    display: grid;
    grid-template-rows: 58px minmax(0, 1fr) auto;
  }

  .workflow-search {
    display: flex;
    gap: 8px;
    padding: 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .workflow-card-list {
    min-height: 0;
    padding: 12px;
    overflow: auto;
  }

  .workflow-card {
    display: grid;
    width: 100%;
    gap: 9px;
    padding: 13px;
    color: inherit;
    text-align: left;
    background: #fff;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    cursor: pointer;
    transition:
      border-color 0.2s,
      box-shadow 0.2s;
  }

  .workflow-card + .workflow-card {
    margin-top: 10px;
  }

  .workflow-card:hover,
  .workflow-card.is-active {
    border-color: var(--el-color-primary);
    box-shadow: 0 5px 18px rgb(47 125 246 / 9%);
  }

  .workflow-card__title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
  }

  .workflow-card__title-row strong {
    min-width: 0;
    overflow: hidden;
    color: var(--el-text-color-primary);
    font-size: 14px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .workflow-card__node,
  .workflow-card__initiator,
  .workflow-card__summary,
  .workflow-card__comment,
  .workflow-card time {
    overflow: hidden;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .workflow-card__initiator {
    color: var(--el-text-color-regular);
  }

  .workflow-card__summary {
    color: var(--el-text-color-placeholder);
  }

  .workflow-card__comment {
    color: var(--el-text-color-regular);
  }

  .workflow-pagination {
    justify-content: center;
    padding: 12px 4px;
    border-top: 1px solid var(--el-border-color-lighter);
  }

  @media (max-width: 900px) {
    .workflow-workbench {
      grid-template-columns: 280px minmax(0, 1fr);
    }
  }

  @media (max-width: 720px) {
    .workflow-workbench {
      grid-template-columns: 1fr;
      grid-template-rows: 300px minmax(620px, 1fr);
      overflow: auto;
    }
  }
</style>
