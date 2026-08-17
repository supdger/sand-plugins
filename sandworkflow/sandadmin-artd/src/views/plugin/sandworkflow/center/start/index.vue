<template>
  <div class="art-full-height flow-start-page">
    <div class="flow-start-search">
      <ElInput v-model="keywords" clearable placeholder="请输入审批名称搜索" class="search-input" />
      <ElButton @click="refreshData">刷新</ElButton>
    </div>

    <div v-loading="loading" class="flow-group-list">
      <section v-for="group in filteredGroups" :key="group.name" class="flow-group-card">
        <header>{{ group.name }}</header>
        <div class="flow-definition-grid">
          <button
            v-for="definition in group.definitions"
            :key="definition.id"
            type="button"
            class="flow-definition-card"
            @click="openStart(definition)"
          >
            <span class="flow-definition-icon">{{ iconText(definition) }}</span>
            <span class="flow-definition-copy">
              <strong>{{ definition.name }}</strong>
              <small>{{ definition.description || '点击发起审批' }}</small>
            </span>
          </button>
        </div>
      </section>
      <ElEmpty v-if="!loading && filteredGroups.length === 0" description="暂无可发起流程" />
    </div>

    <FlowLaunch v-model="startVisible" :definition="activeDefinition" @success="refreshData" />
  </div>
</template>

<script setup lang="ts">
  import { computed, ref } from 'vue'
  import { useTable } from '@/hooks/core/useTable'
  import api, { type FlowDefinition } from '../../api'
  import FlowLaunch from '../components/flow-launch.vue'

  interface DefinitionGroup {
    name: string
    definitions: FlowDefinition[]
  }

  const keywords = ref('')
  const startVisible = ref(false)
  const activeDefinition = ref<FlowDefinition>()
  const { data, loading, refreshData } = useTable({
    core: {
      apiFn: api.definition.available,
      columnsFactory: () => []
    }
  })

  const filteredGroups = computed<DefinitionGroup[]>(() => {
    const keyword = keywords.value.trim().toLocaleLowerCase()
    const groups = new Map<string, FlowDefinition[]>()
    for (const definition of data.value) {
      if (keyword && !definition.name.toLocaleLowerCase().includes(keyword)) continue
      const groupName = definition.group_name || '常用流程'
      const definitions = groups.get(groupName) || []
      definitions.push(definition)
      groups.set(groupName, definitions)
    }
    return Array.from(groups, ([name, definitions]) => ({ name, definitions }))
  })

  function openStart(definition: FlowDefinition): void {
    activeDefinition.value = definition
    startVisible.value = true
  }

  function iconText(definition: FlowDefinition): string {
    return definition.icon?.trim().slice(0, 1) || definition.name.trim().slice(0, 1) || '审'
  }
</script>

<style scoped>
  .flow-start-page {
    display: grid;
    grid-template-rows: 58px minmax(0, 1fr);
    gap: 14px;
    min-height: 0;
  }

  .flow-start-search {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: #fff;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }

  .search-input {
    width: min(340px, 100%);
  }

  .flow-group-list {
    min-height: 0;
    overflow: auto;
  }

  .flow-group-card {
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }

  .flow-group-card + .flow-group-card {
    margin-top: 14px;
  }

  .flow-group-card header {
    display: flex;
    align-items: center;
    height: 46px;
    padding: 0 16px;
    color: var(--el-text-color-primary);
    font-size: 14px;
    font-weight: 600;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .flow-definition-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
    gap: 14px;
    padding: 16px;
  }

  .flow-definition-card {
    display: flex;
    align-items: center;
    min-width: 0;
    padding: 15px;
    color: inherit;
    text-align: left;
    background: #fff;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    cursor: pointer;
    transition:
      transform 0.2s,
      border-color 0.2s,
      box-shadow 0.2s;
  }

  .flow-definition-card:hover {
    border-color: var(--el-color-primary-light-5);
    box-shadow: 0 8px 22px rgb(15 23 42 / 8%);
    transform: translateY(-1px);
  }

  .flow-definition-icon {
    display: grid;
    flex: none;
    width: 44px;
    height: 44px;
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    background: linear-gradient(135deg, #2f7df6, #60a5fa);
    border-radius: 12px;
    place-items: center;
  }

  .flow-definition-copy {
    display: grid;
    min-width: 0;
    gap: 7px;
    margin-left: 12px;
  }

  .flow-definition-copy strong,
  .flow-definition-copy small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .flow-definition-copy strong {
    color: var(--el-text-color-primary);
    font-size: 14px;
  }

  .flow-definition-copy small {
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }
</style>
