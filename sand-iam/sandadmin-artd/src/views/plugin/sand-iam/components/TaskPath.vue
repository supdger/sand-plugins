<script setup lang="ts">
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
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
  import { readSandIamResource } from '../api/write'
  import { parseTaskContext, taskContextQuery, taskStepParams, verifyTaskContext } from '../api/taskContext'
  import type { TaskContext } from '../api/taskContext'
  import type { SandIamResourceRow } from '../api/types'
  import { taskStepHelp } from '../api/taskInstructions'
  import type { SandIamTaskPath, SandIamTaskStepSnapshot } from '../api/taskPaths'

  interface Props {
    readonly path: SandIamTaskPath
    readonly summary?: boolean
  }

  const props = withDefaults(defineProps<Props>(), { summary: false })
  const router = useRouter()
  const route = useRoute()
  const context = ref<TaskContext>({})
  const applications = ref<SandIamResourceRow[]>([])
  const scopeError = ref('')
  const application = ref('')
  let version = 0
  let disposed = false
  async function initialize(): Promise<void> {
    const attempt = ++version
    loadVersion++
    searchVersion++
    steps.value = []
    loading.value = true
    context.value = {}
    scopeError.value = ''
    application.value = ''
    try {
      const verified = await verifyTaskContext(parseTaskContext(route.query), readSandIamResource)
      if (disposed || attempt !== version) return
      context.value = verified.context
      application.value = verified.context.application_id === undefined ? '' : String(verified.context.application_id)
      const selected = verified.rows.application_id
      applications.value = selected ? [selected] : []
    } catch {
      if (disposed || attempt !== version) return
      scopeError.value = '无法确认当前应用归属：可能已停用、无权读取或链接归属不匹配。请重新选择应用。'
    }
    if (disposed || attempt !== version) return
    void load()
    if (!props.summary) void searchApplications('')
  }
  let searchVersion = 0
  async function searchApplications(keywords: string): Promise<void> {
    const attempt = ++searchVersion
    try {
      const result = await listSandIamResource('application', { page: 1, limit: 100, keywords })
      if (disposed || attempt !== searchVersion) return
      const data = isRecord(result) ? result.data : result
      const rows = Array.isArray(data) ? data.filter(isRecord) : []
      const selected = applications.value.find(row => String(row.id) === application.value)
      applications.value = selected && !rows.some(row => row.id === selected.id) ? [selected, ...rows] : rows
    } catch {
      if (!disposed && attempt === searchVersion) scopeError.value = '无法搜索应用，请检查权限或网络后重试。'
    }
  }
  function selectApplication(value: string): void {
    const row = applications.value.find(item => String(item.id) === value)
    if (!row || typeof row.id !== 'number') return
    void router.replace({ path: route.path, query: taskContextQuery({ application_id: row.id }, props.path) })
  }
  onScopeDispose(() => { disposed = true; version++; searchVersion++; loadVersion++ })
  watch(() => route.fullPath, () => { void initialize() })
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

  let loadVersion = 0
  async function load(): Promise<void> {
    const attempt = ++loadVersion
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
        const params = taskStepParams(item.endpoint, context.value)
        if (params === null) return {
          definition: item, state: 'inspection', total: null,
          detail: context.value.application_id === undefined
            ? '先选择要配置的应用，再进入本步骤核对。' : '请打开页面核对所选应用的配置。'
        }
        try {
          const response = await listSandIamResource(item.endpoint, params)
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
            detail: total > 0 ? `当前应用有 ${String(total)} 条记录；请按下方结果核对，记录存在不代表已能使用。` : item.emptyHint
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
    if (disposed || attempt !== loadVersion) return
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
    if (step.state === 'ready') return '有记录，待验证'
    if (step.state === 'missing') return '当前应用无记录'
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
    void router.push({ path, query: taskContextQuery(context.value, props.path) })
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
    void initialize()
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
    <ElForm v-if="!summary" class="mt-4" label-position="top">
      <ElFormItem label="本次配置的应用">
        <ElSelect v-model="application" filterable remote :remote-method="searchApplications"
          placeholder="按应用名称搜索" style="width: 100%" @change="selectApplication">
          <ElOption v-for="item in applications" :key="String(item.id)" :value="String(item.id)"
            :label="String(item.name ?? item.code ?? '未命名应用')" />
        </ElSelect>
      </ElFormItem>
      <ElAlert v-if="scopeError" type="warning" :closable="false" :title="scopeError" />
      <p class="text-sm text-gray-500">按顺序完成必做步骤，可选步骤按实际需要设置。配置记录不会自动证明员工已登录或接口已调用成功。</p>
    </ElForm>
    <div class="task-path__steps">
      <div v-for="(step, index) in steps" :key="step.definition.key" class="task-path__step">
        <div class="task-path__step-title">
          <span>{{ String(index + 1) }}. {{ step.definition.label }}</span>
          <ElTag :type="stepType(step)" effect="plain" size="small">{{ stepLabel(step) }}</ElTag>
        </div>
        <p class="mb-2 mt-1 text-sm text-gray-500">{{ step.detail }}</p>
        <div v-if="taskStepHelp(path, step.definition.key)" class="text-sm">
          <p>{{ taskStepHelp(path, step.definition.key)?.requirement }} · {{ taskStepHelp(path, step.definition.key)?.owner }}</p>
          <p>填写与操作：{{ taskStepHelp(path, step.definition.key)?.input }}</p>
          <p>完成后核对：{{ taskStepHelp(path, step.definition.key)?.result }}</p>
        </div>
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
      type="info"
      :closable="false"
      title="已有配置记录，请继续验证实际使用"
      description="请完成本路径的登录、允许与拒绝验证，并核对访问审计。"
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
