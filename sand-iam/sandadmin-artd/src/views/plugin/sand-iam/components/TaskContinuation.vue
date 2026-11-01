<script setup lang="ts">
  import { computed } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import { sandIamTaskPaths } from '../api/taskPaths'
  import { taskContextQuery } from '../api/taskContext'
  import { taskStepHelp } from '../api/taskInstructions'
  import { resolveGuidanceGoal, guidanceLocation, nextGoalStep } from '../api/goalGuidance'
  import type { TaskContext } from '../api/taskContext'

  const props = defineProps<{ readonly context: TaskContext }>()
  const route = useRoute()
  const router = useRouter()
  const { hasAuth } = useAuth()
  const goal = computed(() => resolveGuidanceGoal(route.query.goal, route.query.method))
  const goalStep = computed(() => goal.value?.steps.find(item => item.key === route.query.step && item.path === route.path))
  const goalNext = computed(() => goal.value && goalStep.value ? nextGoalStep(goal.value, goalStep.value.key) : undefined)
  const goalIndex = computed(() => goal.value?.steps.findIndex(item => item.key === goalStep.value?.key) ?? -1)
  function returnGoal(next = false): void {
    if (goal.value) void router.push(guidanceLocation(props.context, goal.value.id, next ? goalNext.value?.key : goalStep.value?.key, typeof route.query.method === 'string' ? route.query.method : undefined))
  }
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
  <ElCard v-if="goal && goalStep" class="mb-4" shadow="never">
    <strong>{{ goal.title }} · 第 {{ goalIndex + 1 }} / {{ goal.steps.length }} 步：{{ goalStep.label }}</strong>
    <p class="text-sm">{{ goalStep.owner }} · {{ goalStep.input }}</p>
    <p class="text-sm">完成后核对：{{ goalStep.result }}</p>
    <p class="text-sm">{{ goalStep.recovery }}</p>
    <div class="flex flex-wrap gap-2">
      <ElButton @click="returnGoal()">返回目标引导</ElButton>
      <ElButton v-if="goalNext" type="primary" plain @click="returnGoal(true)">核对后继续：{{ goalNext.label }}</ElButton>
    </div>
    <p class="text-sm">继续会返回引导并读取当前状态，不会把本次配置自动标记为业务验证通过。</p>
  </ElCard>
  <ElCard v-else-if="definition && step" class="mb-4" shadow="never">
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
