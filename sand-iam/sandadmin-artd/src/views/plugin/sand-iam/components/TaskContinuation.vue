<script setup lang="ts">
  import { computed } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import { sandIamTaskPaths } from '../api/taskPaths'
  import { taskContextQuery } from '../api/taskContext'
  import { taskStepHelp } from '../api/taskInstructions'
  import type { TaskContext } from '../api/taskContext'

  const props = defineProps<{ readonly context: TaskContext }>()
  const route = useRoute()
  const router = useRouter()
  const { hasAuth } = useAuth()
  const task = computed(() => route.query.task === 'people-access' || route.query.task === 'connection' ? route.query.task : null)
  const definition = computed(() => task.value === null ? null : sandIamTaskPaths[task.value])
  const index = computed(() => definition.value?.steps.findIndex(step => step.path === route.path) ?? -1)
  const step = computed(() => index.value < 0 ? null : definition.value?.steps[index.value])
  const help = computed(() => task.value && step.value ? taskStepHelp(task.value, step.value.key) : undefined)
  const next = computed(() => index.value < 0 ? null : definition.value?.steps[index.value + 1])
  function back(): void {
    if (definition.value && task.value) void router.push({ path: definition.value.entryPath, query: taskContextQuery(props.context, task.value) })
  }
  function proceed(): void {
    if (!next.value || !task.value || next.value.contractStatus === 'runtime') { back(); return }
    void router.push({ path: next.value.path, query: taskContextQuery(props.context, task.value) })
  }
</script>

<template>
  <ElCard v-if="definition && step" class="mb-4" shadow="never">
    <strong>{{ definition.title }} · 第 {{ index + 1 }} 步：{{ step.label }}</strong>
    <template v-if="help">
      <p class="text-sm">{{ help.requirement }} · {{ help.owner }}</p>
      <p class="text-sm">{{ help.input }}</p>
      <p class="text-sm">完成后核对：{{ help.result }}</p>
    </template>
    <div class="flex flex-wrap gap-2">
      <ElButton @click="back">返回任务步骤</ElButton>
      <ElButton v-if="next && (next.permission === undefined || hasAuth(next.permission))"
        type="primary" plain @click="proceed">核对完成后，继续{{ next.label }}</ElButton>
    </div>
  </ElCard>
</template>
