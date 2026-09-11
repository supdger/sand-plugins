<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import {
    recommendedSandIamTaskStep,
    SAND_IAM_APPLICATION_PORTAL_URL,
    sandIamTaskPaths,
    sandIamTaskPathIsComplete,
    sandIamTaskPathCanOpen,
    sandIamTaskStepCanOpen,
    sandIamTaskStepNeedsPageInspection,
    sandIamTaskStepNeedsRequest
  } from '../api/taskPaths'
  import { listSandIamResource } from '../api/resource'
  import type { SandIamTaskPath, SandIamTaskStepSnapshot } from '../api/taskPaths'

  interface Props {
    readonly path: SandIamTaskPath
    readonly summary?: boolean
  }

  const props = withDefaults(defineProps<Props>(), { summary: false })
  const router = useRouter()
  const { hasAuth } = useAuth()
  const loading = ref(false)
  const steps = ref<SandIamTaskStepSnapshot[]>([])
  const definition = computed(() => sandIamTaskPaths[props.path])
  const recommended = computed(() => recommendedSandIamTaskStep(steps.value))
  const completed = computed(() => sandIamTaskPathIsComplete(steps.value))

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function totalFromResponse(value: unknown): number | null {
    if (!isRecord(value)) return null
    const total = value.total
    return typeof total === 'number' && Number.isInteger(total) && total >= 0 ? total : null
  }

  async function load(): Promise<void> {
    loading.value = true
    steps.value = definition.value.steps.map((item) => ({
      definition: item,
      state: 'loading',
      total: null,
      detail: '正在检查已有数据…'
    }))
    const snapshots = await Promise.all(
      definition.value.steps.map(async (item): Promise<SandIamTaskStepSnapshot> => {
        if (item.contractStatus === 'runtime') {
          return {
            definition: item,
            state: 'runtime',
            total: null,
            detail: item.emptyHint
          }
        }
        if (item.permission !== undefined && !hasAuth(item.permission)) {
          return {
            definition: item,
            state: 'forbidden',
            total: null,
            detail: '当前账号暂时不能查看此项。请联系管理员开通相应管理范围后再试。'
          }
        }
        if (sandIamTaskStepNeedsPageInspection(item)) {
          return {
            definition: item,
            state: 'inspection',
            total: null,
            detail: '总览暂时不能自动确认当前设置。请进入页面查看；页面会显示当前情况。'
          }
        }
        if (!sandIamTaskStepNeedsRequest(item)) {
          return {
            definition: item,
            state: 'waiting',
            total: null,
            detail: item.emptyHint
          }
        }
        if (item.permission === undefined || !hasAuth(item.permission)) {
          return {
            definition: item,
            state: 'forbidden',
            total: null,
            detail: '当前账号暂时不能查看此项。请联系管理员开通相应管理范围后再试。'
          }
        }
        if (item.endpoint === undefined) {
          return {
            definition: item,
            state: 'waiting',
            total: null,
            detail: item.emptyHint
          }
        }
        try {
          const response = await listSandIamResource(item.endpoint, {
            page: 1,
            limit: 1
          })
          const total = totalFromResponse(response)
          if (total === null) {
            return {
              definition: item,
              state: 'error',
              total: null,
              detail: '暂时无法确认已有设置。请稍后重试；持续失败时联系管理员。'
            }
          }
          return {
            definition: item,
            state: total > 0 ? 'ready' : 'missing',
            total,
            detail: total > 0 ? `已有 ${String(total)} 条可见记录。` : item.emptyHint
          }
        } catch {
          return {
            definition: item,
            state: 'error',
            total: null,
            detail: '暂时无法读取当前设置。请检查网络后重试；持续失败时联系管理员。'
          }
        }
      })
    )
    steps.value = snapshots
    loading.value = false
  }

  function stepType(step: SandIamTaskStepSnapshot): 'success' | 'info' | 'warning' | 'danger' {
    if (step.state === 'ready') return 'success'
    if (
      step.state === 'missing' ||
      step.state === 'waiting' ||
      step.state === 'inspection' ||
      step.state === 'runtime'
    )
      return 'info'
    if (step.state === 'forbidden') return 'warning'
    return 'danger'
  }

  function stepLabel(step: SandIamTaskStepSnapshot): string {
    if (step.state === 'ready') return '已设置'
    if (step.state === 'missing') return '尚未设置'
    if (step.state === 'waiting' || step.state === 'inspection') return '进入页面查看'
    if (step.state === 'runtime') return '在接入应用中使用'
    if (step.state === 'forbidden' || step.state === 'error') return '暂时无法读取'
    return '加载中'
  }

  function go(path: string): void {
    if (path === SAND_IAM_APPLICATION_PORTAL_URL) {
      window.location.assign(path)
      return
    }
    void router.push(path)
  }

  function canOpen(step: SandIamTaskStepSnapshot | null): boolean {
    return step !== null && sandIamTaskStepCanOpen(step)
  }

  function canOpenPath(permission: string): boolean {
    return hasAuth(permission)
  }

  function canOpenDefinition(): boolean {
    return sandIamTaskPathCanOpen(definition.value, hasAuth)
  }

  onMounted(() => {
    void load()
  })
</script>

<template>
  <ElCard shadow="never" :class="{ 'task-path-summary': summary }">
    <div class="task-path__heading">
      <div>
        <h2 v-if="!summary" class="m-0 text-lg font-semibold">{{ definition.title }}</h2>
        <h3 v-else class="m-0 text-base font-semibold">{{ definition.title }}</h3>
        <p class="mb-0 mt-1 text-sm text-gray-500">{{ definition.description }}</p>
      </div>
      <ElButton
        v-if="summary && canOpenDefinition()"
        type="primary"
        text
        @click="go(definition.entryPath)"
      >
        进入本路径
      </ElButton>
      <span v-else-if="summary" class="text-sm text-gray-500">当前账号不能查看此路径。</span>
      <ElButton v-if="!summary" :loading="loading" @click="load">刷新状态</ElButton>
    </div>
    <div class="task-path__steps">
      <div v-for="(step, index) in steps" :key="step.definition.key" class="task-path__step">
        <div class="task-path__step-title">
          <span>{{ String(index + 1) }}. {{ step.definition.label }}</span>
          <ElTag :type="stepType(step)" effect="plain" size="small">{{ stepLabel(step) }}</ElTag>
        </div>
        <p class="mb-2 mt-1 text-sm text-gray-500">{{ step.detail }}</p>
        <ElButton v-if="canOpen(step)" text type="primary" @click="go(step.definition.path)"
          >打开{{ step.definition.label }}</ElButton
        >
        <p v-else class="mb-0 mt-1 text-sm text-gray-500">当前账号没有查看此页面的权限。</p>
      </div>
    </div>

    <section v-if="!summary && definition.guides && definition.guides.length > 0" class="mt-5">
      <h3 class="m-0 text-base font-semibold">常见拒绝怎么处理</h3>
      <p class="mb-3 mt-1 text-sm text-gray-500"
        >先在访问审计中确认发生了什么，再按对应恢复动作处理。</p
      >
      <div class="task-path__guides">
        <div v-for="guide in definition.guides" :key="guide.title" class="task-path__guide">
          <div class="flex flex-wrap items-center gap-2">
            <strong>{{ guide.title }}</strong>
          </div>
          <p class="mb-1 mt-2 text-sm text-gray-600">现象：{{ guide.symptom }}</p>
          <p class="mb-2 mt-1 text-sm text-gray-600">恢复：{{ guide.recovery }}</p>
          <ElButton
            v-if="canOpenPath(guide.permission)"
            text
            type="primary"
            @click="go(guide.path)"
          >
            {{ guide.actionLabel }}
          </ElButton>
          <span v-else class="text-sm text-gray-500">当前账号不能查看此页面。</span>
        </div>
      </div>
    </section>

    <ElAlert
      v-if="recommended !== null"
      class="mt-4"
      type="info"
      :closable="false"
      :title="`推荐下一步：${recommended.definition.label}`"
      :description="recommended.detail"
    >
      <template #default v-if="canOpen(recommended)">
        <ElButton text type="primary" @click="go(recommended.definition.path)">前往处理</ElButton>
      </template>
    </ElAlert>
    <ElAlert
      v-else-if="!loading && completed"
      class="mt-4"
      type="success"
      :closable="false"
      title="此路径当前已完成"
      description="可按需进入任一步骤复核或维护；后续配置变更仍会影响可见状态。"
    />
  </ElCard>
</template>

<style scoped lang="scss">
  .task-path__heading,
  .task-path__step-title {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
  }

  .task-path__steps {
    display: grid;
    gap: 12px;
    margin-top: 16px;
  }

  .task-path__step {
    padding: 12px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }

  .task-path__guides {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .task-path__guide {
    padding: 12px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
  }

  @media (max-width: 900px) {
    .task-path__guides {
      grid-template-columns: 1fr;
    }
  }

  .task-path-summary .task-path__steps {
    margin-bottom: 0;
  }
</style>
