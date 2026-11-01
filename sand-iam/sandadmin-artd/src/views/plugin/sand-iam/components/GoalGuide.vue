<script setup lang="ts">
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import { listSandIamResource } from '../api/resource'
  import { readSandIamResource } from '../api/write'
  import { isRecord } from '../api/policyJson'
  import { parseTaskContext, verifyTaskContext } from '../api/taskContext'
  import type { TaskContext, TaskContextRows } from '../api/taskContext'
  import type { SandIamResourceRow } from '../api/types'
  import { canUseGoal, guidanceGoal, guidanceGoals, guidanceQuery, resolveGuidanceGoal, goalStepParams } from '../api/goalGuidance'
  import type { GuidanceGoal, GoalStep } from '../api/goalGuidance'
  import { newApplicationQuery } from '../getting-started/wizardEntry'

  const route = useRoute()
  const router = useRouter()
  const { hasAuth } = useAuth()
  const context = ref<TaskContext>({})
  const rows = ref<TaskContextRows>({})
  const applications = ref<SandIamResourceRow[]>([])
  const environments = ref<SandIamResourceRow[]>([])
  const clients = ref<SandIamResourceRow[]>([])
  const busy = ref(false)
  const error = ref('')
  const status = ref('')
  const suggestedStep = ref('')
  const statusError = ref(false)
  const searching = ref(false)
  const applicationPage = ref(1)
  const applicationTotal = ref(0)
  const keywords = ref('')
  const STORAGE = 'sand-iam.goal-guidance.v1'
  let generation = 0
  let searchGeneration = 0
  let statusGeneration = 0
  let disposed = false
  const method = computed(() => typeof route.query.method === 'string' ? route.query.method : undefined)
  const goal = computed(() => resolveGuidanceGoal(route.query.goal, method.value))
  const needsMethod = computed(() => goal.value?.id === 'login' ? !['invite', 'register', 'custom'].includes(method.value ?? '') : goal.value?.id === 'directory' ? !['connector', 'scim'].includes(method.value ?? '') : false)
  const availableGoals = computed(() => guidanceGoals.filter(item => canUseGoal(item, hasAuth)))
  const stepIndex = computed(() => Math.max(0, goal.value?.steps.findIndex(item => item.key === (route.query.step ?? suggestedStep.value)) ?? 0))
  const currentStep = computed(() => goal.value?.steps[stepIndex.value])
  const applicationLabel = computed(() => String(rows.value.application_id?.name ?? ''))
  const applicationValue = computed(() => context.value.application_id === undefined ? '' : String(context.value.application_id))
  const environmentValue = computed(() => context.value.environment_id === undefined ? '' : String(context.value.environment_id))
  const clientValue = computed(() => context.value.workload_client_id === undefined ? '' : String(context.value.workload_client_id))
  const needsClient = computed(() => goal.value?.id === 'machine' && ['grant', 'credential', 'machine-integration'].includes(currentStep.value?.key ?? '') && context.value.workload_client_id === undefined)
  const needsEnvironment = computed(() => goal.value?.id === 'machine' && context.value.environment_id === undefined)
  const blockedCount = computed(() => goal.value?.steps.filter(item => !item.optional && item.permission && !hasAuth(item.permission)).length ?? 0)
  const canOpen = (item: GoalStep) => item.contractStatus !== 'runtime' && item.permission !== undefined && hasAuth(item.permission)
  const id = (value: unknown): number | undefined => typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : undefined
  function list(value: unknown): { rows: SandIamResourceRow[]; total: number } {
    if (!isRecord(value) || !Array.isArray(value.data) || typeof value.total !== 'number') throw new Error('Invalid list')
    return { rows: value.data.filter(isRecord), total: value.total }
  }
  function store(): void {
    try { sessionStorage.setItem(STORAGE, JSON.stringify({ ...guidanceQuery(context.value, goal.value?.id ?? '', currentStep.value?.key, method.value) })) } catch { /* storage can be disabled */ }
  }
  async function chooseApplication(value: string): Promise<void> {
    if (!/^[1-9]\d*$/.test(value)) return
    await router.replace({ path: route.path, query: { ...guidanceQuery({ application_id: Number(value) }, goal.value?.id ?? '', undefined, method.value) } })
  }
  async function chooseEnvironment(value: string): Promise<void> {
    if (!/^[1-9]\d*$/.test(value)) return
    await router.replace({ path: route.path, query: guidanceQuery({ organization_id: context.value.organization_id, application_id: context.value.application_id, environment_id: Number(value) }, goal.value?.id ?? '', undefined, method.value) })
  }
  async function searchApplications(page = 1): Promise<void> {
    if (!hasAuth('sand_iam:application:index')) return
    const attempt = ++searchGeneration
    searching.value = true
    try {
      const data = list(await listSandIamResource('application', { page, limit: 30, keywords: keywords.value, status: 1 }))
      if (disposed || attempt !== searchGeneration) return
      error.value = ''
      applications.value = data.rows
      applicationPage.value = page
      applicationTotal.value = data.total
      // Only the unfiltered complete result establishes a unique visible application.
      if (!busy.value && context.value.application_id === undefined && data.total === 1 && keywords.value === '' && data.rows.length === 1) {
        const only = id(data.rows[0].id)
        if (only !== undefined) await chooseApplication(String(only))
      }
    } catch {
      if (!disposed && attempt === searchGeneration) error.value = '暂时无法读取可管理应用。请重试；若仍被拒绝，请由管理员核对应用委派。未把读取失败当作没有应用。'
    } finally { if (!disposed && attempt === searchGeneration) searching.value = false }
  }
  async function initialize(): Promise<void> {
    const attempt = ++generation
    statusGeneration++
    busy.value = true
    error.value = ''
    status.value = ''
    suggestedStep.value = ''
    context.value = {}
    rows.value = {}
    environments.value = []
    clients.value = []
    try {
      let verified = await verifyTaskContext(parseTaskContext(route.query), readSandIamResource)
      if (disposed || attempt !== generation) return
      context.value = verified.context
      rows.value = verified.rows
      if (goal.value?.id === 'machine' && verified.context.application_id && hasAuth('sand_iam:environment:index')) {
        const data = list(await listSandIamResource('environment', { page: 1, limit: 100, application_id: verified.context.application_id, status: 1 }))
        if (disposed || attempt !== generation) return
        environments.value = data.rows
        if (data.total === 1 && verified.context.environment_id === undefined && data.rows.length === 1) {
          const only = id(data.rows[0].id)
          if (only !== undefined) { await chooseEnvironment(String(only)); return }
        }
      }
      if (goal.value?.id === 'machine' && verified.context.environment_id && hasAuth('sand_iam:client:index')) {
        const data = list(await listSandIamResource('client', { page: 1, limit: 100, application_id: verified.context.application_id, environment_id: verified.context.environment_id, status: 1 }))
        if (disposed || attempt !== generation) return
        clients.value = data.rows
        if (data.total === 1 && verified.context.workload_client_id === undefined && data.rows.length === 1) {
          const only = id(data.rows[0].id)
          if (only !== undefined) {
            verified = await verifyTaskContext({ ...verified.context, workload_client_id: only }, readSandIamResource)
            if (disposed || attempt !== generation) return
            context.value = verified.context
            rows.value = verified.rows
          }
        }
      }
      // Stop at the first unverified prerequisite. Existing records are not business acceptance.
      if (goal.value && !route.query.step && verified.context.application_id) {
        for (const item of goal.value.steps) {
          if (item.optional) continue
          suggestedStep.value = item.key
          if (!item.permission || !hasAuth(item.permission)) break
          const params = goalStepParams(item, verified.context)
          if (!params || !item.endpoint) break
          try {
            const existing = list(await listSandIamResource(item.endpoint, params))
            if (disposed || attempt !== generation) return
            if (existing.total === 0) break
          } catch { break }
        }
      }
      store()
    } catch {
      if (!disposed && attempt === generation) error.value = '无法确认当前应用或环境：记录可能已停用、无权读取或归属不匹配。请重新选择应用；此前的步骤不会被当作完成。'
    } finally {
      if (!disposed && attempt === generation) { busy.value = false; void readStatus() }
    }
  }
  async function readStatus(): Promise<void> {
    const item = currentStep.value
    const attempt = ++statusGeneration
    statusError.value = false
    if (!item || context.value.application_id === undefined) { status.value = ''; return }
    if (item.permission && !hasAuth(item.permission)) { status.value = '当前账号没有本步骤权限，请将本步交给有权管理员。'; return }
    const params = goalStepParams(item, context.value)
    if (!params || !item.endpoint) { status.value = item.contractStatus === 'runtime' ? '由应用用户实际登录后核对结果。' : '请进入页面核对已有配置；本页不能自动确认使用结果。'; return }
    status.value = '正在读取当前应用的配置…'
    try {
      const result = list(await listSandIamResource(item.endpoint, params))
      if (disposed || attempt !== statusGeneration) return
      status.value = item.endpoint === 'application-experience'
        ? result.total > 0
          ? '当前应用已有启用的登录外观。请直接复用并核对注册方式与登录方式，再从应用门户验证。'
          : '当前应用还没有启用的登录外观。请打开本步骤核对已有记录：有停用记录先恢复，没有时再新建。'
        : result.total > 0 ? `当前应用已有 ${result.total} 条记录。先核对并复用；记录存在不代表已验证可用。` : '当前应用尚无这类记录，请按本步说明配置。'
    } catch {
      if (!disposed && attempt === statusGeneration) { statusError.value = true; status.value = '配置状态读取失败。请重试，不要因这次失败重新创建对象。' }
    }
  }
  function selectClient(value: string): void {
    if (!/^[1-9]\d*$/.test(value)) return
    void router.replace({ path: route.path, query: guidanceQuery({ ...context.value, workload_client_id: Number(value) }, goal.value?.id ?? '', currentStep.value?.key, method.value) })
  }
  function selectGoal(item: GuidanceGoal): void {
    void router.replace({ path: route.path, query: guidanceQuery(item.id === 'machine' ? context.value : { organization_id: context.value.organization_id, application_id: context.value.application_id }, item.id) })
  }
  function selectStep(item: GoalStep): void {
    if (goal.value) void router.replace({ path: route.path, query: guidanceQuery(context.value, goal.value.id, item.key, method.value) })
  }
  function openStep(): void {
    if (!goal.value || !currentStep.value || !canOpen(currentStep.value) || busy.value) return
    store()
    void router.push({ path: currentStep.value.path, query: guidanceQuery(context.value, goal.value.id, currentStep.value.key, method.value) })
  }
  function selectMethod(value: string): void { if (goal.value) void router.replace({ path: route.path, query: guidanceQuery(context.value, goal.value.id, undefined, value) }) }
  function register(): void {
    void router.push({ path: '/sand-iam/getting-started', query: newApplicationQuery(context.value, goal.value?.id ?? '', method.value, crypto.randomUUID()) })
  }
  function changeGoal(): void { void router.replace({ path: route.path, query: guidanceQuery(context.value, '') }) }
  onMounted(async () => {
    if (Object.keys(route.query).length === 0) {
      try {
        const saved: unknown = JSON.parse(sessionStorage.getItem(STORAGE) ?? 'null')
        if (isRecord(saved) && (guidanceGoal(saved.goal) || saved.goal === '')) {
          const restored = parseTaskContext(saved)
          await router.replace({ path: route.path, query: guidanceQuery(restored, String(saved.goal), typeof saved.step === 'string' ? saved.step : undefined, typeof saved.method === 'string' ? saved.method : undefined) })
        }
      } catch { /* stale progress is ignored and reselected */ }
    }
    await initialize()
    void searchApplications()
  })
  watch(() => route.fullPath, () => { void initialize() })
  onScopeDispose(() => { disposed = true; generation++; searchGeneration++; statusGeneration++ })
</script>

<template>
  <ElCard shadow="never" class="goal-guide" v-loading="busy">
    <header class="goal-guide__heading">
      <div><h2>这次要完成什么？</h2><p>已按当前账号的权限显示可办理事项。选择目标后，我们会保留应用并带你逐步完成配置和验证。</p></div>
      <ElButton v-if="goal" @click="changeGoal">选择其他目标</ElButton>
    </header>
    <ElAlert v-if="error" type="warning" :closable="false" :title="error" class="mb-4" />
    <section class="goal-guide__scope" aria-label="本次管理范围">
      <strong v-if="applicationLabel">本次应用：{{ applicationLabel }}</strong>
      <strong v-else>本次管理范围</strong>
      <p v-if="rows.organization_id?.name">所属客户主体：{{ rows.organization_id.name }}</p>
      <template v-if="hasAuth('sand_iam:application:index')">
        <ElSelect :model-value="applicationValue" filterable remote :loading="searching" :remote-method="(value: string) => { keywords = value; void searchApplications() }"
          placeholder="选择可管理的应用，或先选择下方目标" aria-label="选择可管理的应用" @change="chooseApplication">
          <ElOption v-if="rows.application_id && !applications.some(item => item.id === rows.application_id?.id)" :value="applicationValue" :label="applicationLabel" />
          <ElOption v-for="item in applications" :key="String(item.id)" :value="String(item.id)" :label="String(item.name ?? item.code)" />
        </ElSelect>
        <ElButton :loading="searching" @click="searchApplications(applicationPage)">重新读取应用</ElButton>
        <ElPagination v-if="applicationTotal > 30" :current-page="applicationPage" :page-size="30" :total="applicationTotal" layout="prev, pager, next" @current-change="searchApplications" />
      </template>
      <p v-else-if="!applicationLabel">当前账号不能浏览应用列表。请从获授权的应用入口进入，或请管理员提供该应用的管理入口并核对委派。</p>
      <ElButton v-if="hasAuth('sand_iam:application:save')" text type="primary" @click="register">登记新应用</ElButton>
      <p v-if="!applicationLabel && !busy && !searching && applications.length === 0 && !error">没有已选应用。已有应用请先选择；新接入时登记公司和应用，后续目标都可复用。</p>
      <template v-if="goal?.id === 'machine' && applicationLabel">
        <p>本次调用环境</p>
        <ElSelect :model-value="environmentValue" placeholder="选择测试或生产环境" aria-label="本次调用环境" @change="chooseEnvironment">
          <ElOption v-for="item in environments" :key="String(item.id)" :value="String(item.id)" :label="String(item.name ?? item.code)" />
        </ElSelect>
        <p v-if="needsEnvironment">请先选择环境。没有环境时，由有权管理员通过登记向导复用当前应用并添加环境。</p>
        <template v-if="!needsEnvironment && clients.length > 0">
          <p>本次服务调用身份{{ clients.length === 1 ? '（唯一可见记录已读取确认）' : '（请选择，后续授权和凭证会沿用）' }}</p>
          <ElSelect :model-value="clientValue" placeholder="选择服务调用身份" aria-label="本次服务调用身份" @change="selectClient">
            <ElOption v-for="item in clients" :key="String(item.id)" :value="String(item.id)" :label="String(item.name ?? item.code)" />
          </ElSelect>
        </template>
        <p v-if="needsClient">本步骤需要调用身份。没有记录时先完成“服务调用身份”；已有多个时请选择本次要授权的身份。</p>
      </template>
    </section>
    <template v-if="!goal">
      <p v-if="availableGoals.length === 0">当前账号没有这些目标的管理权限。请联系管理员核对应用委派和操作权限。</p>
      <div class="goal-guide__goals">
        <button v-for="item in availableGoals" :key="item.id" type="button" class="goal-guide__goal" @click="selectGoal(item)">
          <strong>{{ item.title }}</strong><span>{{ item.situation }}</span><span>完成后：{{ item.outcome }}</span>
        </button>
      </div>
      <p class="goal-guide__hint">已有 SandIAM 用户身份接入、只改权限，选“控制权限和数据范围”；只让程序调用服务，选“让后端调用服务”。委派、通知及排错可从下方全部功能进入。</p>
    </template>
    <section v-else-if="currentStep" class="goal-guide__current">
      <h3>{{ goal.title }}</h3><p>{{ goal.skip }}</p><p v-if="!route.query.step && stepIndex > 0" class="goal-guide__hint">已找到前面步骤的配置记录，建议从这里继续；仍可展开全部步骤复核。</p>
      <ElAlert v-if="blockedCount > 0" type="warning" :closable="false" :title="`此目标有 ${blockedCount} 个必做步骤超出当前账号权限`" description="可先办理你有权限的部分；其余步骤需该应用的管理员或接入负责人完成。切换目标不会增加权限。" />
      <p v-if="!applicationLabel">先选择或登记要配置的应用。下面可以预览步骤，选择应用后即可开始。</p>
      <section v-if="needsMethod" class="goal-guide__step">
        <h4>{{ goal.id === 'login' ? '用户从哪里登录？' : '目录由哪一端发起同步？' }}</h4>
        <template v-if="goal.id === 'login'">
          <p>没有登录页面时，可使用 SandIAM 自带的应用门户：邀请适合指定人员，公开注册允许用户自行开户。已有应用用户无需再次开户，核对门户配置后直接登录。</p>
          <ElButton @click="selectMethod('invite')">使用内置门户，邀请开户</ElButton><ElButton @click="selectMethod('register')">使用内置门户，自行注册</ElButton>
          <p>已有自有登录页面时，复用现有页面并接入 SandIAM 认证，无需配置内置门户外观。</p>
          <ElButton @click="selectMethod('custom')">使用已有的自有登录页面</ElButton>
        </template>
        <template v-else>
          <p>向目录负责人确认已有对接方式；选择一种即可，另一种不必配置。</p>
          <ElButton @click="selectMethod('connector')">使用同步连接器</ElButton><ElButton @click="selectMethod('scim')">上游通过 SCIM 推送</ElButton>
        </template>
      </section>
      <div v-else class="goal-guide__step">
        <p>第 {{ stepIndex + 1 }} / {{ goal.steps.length }} 步 · {{ currentStep.optional ? '按需，可跳过' : '需要核对' }} · {{ currentStep.owner }}</p>
        <h4>{{ currentStep.label }}</h4>
        <p><strong>准备与操作：</strong>{{ currentStep.input }}</p>
        <p><strong>结果核对：</strong>{{ currentStep.result }}</p>
        <p><strong>遇到问题：</strong>{{ currentStep.recovery }}</p>
        <ElAlert v-if="status" :type="statusError ? 'warning' : 'info'" :closable="false" :title="status" />
        <div class="goal-guide__actions">
          <ElButton v-if="canOpen(currentStep)" type="primary" :disabled="!applicationLabel || needsEnvironment || needsClient || !!error" @click="openStep">打开{{ currentStep.label }}</ElButton>
          <p v-else-if="currentStep.permission">当前账号无权打开本步骤，请交给上方所列负责人。</p>
          <p v-else>由应用负责人提供用户入口与应用代码，在接入应用中完成。后台账号不用于应用用户登录。</p>
          <ElButton v-if="statusError" @click="readStatus">重试读取状态</ElButton>
          <ElButton v-if="stepIndex > 0" @click="selectStep(goal.steps[stepIndex - 1])">上一步</ElButton>
          <ElButton v-if="stepIndex + 1 < goal.steps.length" :disabled="!applicationLabel || needsEnvironment" @click="selectStep(goal.steps[stepIndex + 1])">{{ currentStep.optional ? '跳过或核对后，' : '核对后，' }}继续{{ goal.steps[stepIndex + 1].label }}</ElButton>
          <ElButton v-else @click="changeGoal">为同一应用增加其他目标</ElButton>
        </div>
        <p class="goal-guide__hint">继续只切换步骤，不表示配置或实际业务验证已经通过。</p>
      </div>
      <details v-if="!needsMethod"><summary>查看全部步骤与分工</summary><ol><li v-for="item in goal.steps" :key="item.key"><ElButton text @click="selectStep(item)">{{ item.label }}{{ item.optional ? '（可选）' : '' }}</ElButton><span>{{ item.owner }}{{ item.permission && !hasAuth(item.permission) ? ' · 当前账号无权办理' : '' }}</span></li></ol></details>
    </section>
  </ElCard>
</template>

<style scoped>
.goal-guide h2 { margin: 0; font-size: 20px; }
.goal-guide h3 { font-size: 18px; }
.goal-guide h4 { margin: 10px 0; font-size: 18px; }
.goal-guide p { line-height: 1.7; }
.goal-guide__heading { display: flex; gap: 16px; justify-content: space-between; align-items: flex-start; }
.goal-guide__scope { padding: 16px; margin: 16px 0; background: var(--el-fill-color-light); border-radius: 8px; }
.goal-guide__scope > strong { display: block; margin-bottom: 12px; }
.goal-guide__scope .el-select { width: min(100%, 360px); margin-right: 12px; }
.goal-guide__goals { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; }
.goal-guide__goal { text-align: left; font: inherit; color: inherit; background: var(--el-bg-color); border: 1px solid var(--el-border-color); border-radius: 8px; padding: 18px; cursor: pointer; }
.goal-guide__goal:hover, .goal-guide__goal:focus-visible { border-color: var(--el-color-primary); outline: 2px solid var(--el-color-primary-light-5); }
.goal-guide__goal strong { display: block; font-size: 16px; margin-bottom: 8px; }
.goal-guide__goal span { display: block; font-size: 14px; line-height: 1.7; color: var(--el-text-color-regular); }
.goal-guide__step { border: 1px solid var(--el-border-color); padding: 20px; margin: 20px 0; border-radius: 8px; }
.goal-guide__actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 18px; }
.goal-guide__actions .el-button { margin-left: 0; white-space: normal; height: auto; min-height: 36px; }
.goal-guide__hint { color: var(--el-text-color-secondary); font-size: 13px; }
.goal-guide details summary { cursor: pointer; padding: 12px 0; }
@media(max-width: 640px) { .goal-guide__goals { grid-template-columns: 1fr; } .goal-guide__heading { flex-direction: column; } .goal-guide__step { padding: 12px; } .goal-guide__scope .el-select { width: 100%; margin-bottom: 10px; } }
</style>
