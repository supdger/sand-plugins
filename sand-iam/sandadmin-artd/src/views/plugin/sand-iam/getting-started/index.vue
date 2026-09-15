<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, reactive, ref, watch } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import { readSandIamResource, saveSandIamResource, updateSandIamResource } from '../api/write'
  import type { SandIamResourceEndpoint, SandIamResourceRow } from '../api/types'
  import WizardStepForm from './WizardStepForm.vue'
  import {
    createWizardContext,
    canPostForPhase,
    canNavigateTo,
    isRecord,
    parseWizardContext,
    phaseAfterSave,
    phaseAfterCreateError,
    phaseAfterVerifiedRecord,
    verificationRecoveryForHttp,
    positiveId,
    recordFromResponse,
    saveResponseId,
    type WizardRecord,
    type WizardPhase,
    type WizardScreen,
    type WizardStep
  } from './wizardState'

  type SetupGoal = 'people' | 'service' | 'event'

  interface StepDefinition {
    readonly id: WizardStep
    readonly title: string
    /** 这一步解决什么问题。 */
    readonly problem: string
    /** 开始前准备什么。 */
    readonly preparation: string
    /** 在页面上怎么做。 */
    readonly how: string
    /** 怎样确认成功。 */
    readonly success: string
    /** 常见错误如何处理。 */
    readonly errors: string
    /** 下一步去哪里。 */
    readonly next: string
    readonly endpoint: SandIamResourceEndpoint
    readonly indexPermission: string
    readonly readPermission: string
    readonly savePermission: string
    readonly updatePermission: string
  }

  interface NextGoal {
    readonly value: SetupGoal
    readonly title: string
    readonly description: string
    readonly path: string
    readonly permission: string
  }

  const CONTEXT_KEY = 'sand-iam.getting-started.v1'
  const router = useRouter()
  const { hasAuth } = useAuth()
  const steps: readonly StepDefinition[] = [
    {
      id: 'organization',
      title: '客户主体',
      problem: '把不同客户主体的数据和管理范围分开。',
      preparation: '准备客户主体名称和稳定的系统代码。',
      how: '填写名称和系统代码后保存；也可以先点「选择已有记录」，再明确选用一条。保存后本页会重新读取刚保存的记录，确认无误才进入下一步。',
      success: '保存后重新读取刚创建的客户主体，确认无误再继续。',
      errors:
        '没有权限时请联系管理员开通管理范围。名称或系统代码已被使用时请修改后再保存。网络或服务失败时检查网络后重试；保存结果未确认前不要再次创建。',
      next: '确认后进入接入应用。',
      endpoint: 'organization',
      indexPermission: 'sand_iam:organization:index',
      readPermission: 'sand_iam:organization:read',
      savePermission: 'sand_iam:organization:save',
      updatePermission: 'sand_iam:organization:update'
    },
    {
      id: 'application',
      title: '接入应用',
      problem: '登记需要使用 SandIAM 的产品或系统。',
      preparation: '先确认本次向导中的客户主体，再准备应用名称和系统代码。',
      how: '确认上方所属客户主体后，填写接入应用名称和系统代码并保存；也可以选用该客户主体下已有的接入应用。保存后会重新读取并核对所属客户主体。',
      success: '保存后重新读取刚创建的应用，并确认所属客户主体正确。',
      errors:
        '缺少上一步客户主体时请先返回完成客户主体。校验失败请按页面提示修改。冲突、网络或服务失败时请重试，不要重复创建。',
      next: '确认后进入应用环境。',
      endpoint: 'application',
      indexPermission: 'sand_iam:application:index',
      readPermission: 'sand_iam:application:read',
      savePermission: 'sand_iam:application:save',
      updatePermission: 'sand_iam:application:update'
    },
    {
      id: 'environment',
      title: '应用环境',
      problem: '把同一应用的开发、测试和生产配置彼此隔离。',
      preparation: '先确认本次向导中的接入应用，再准备环境名称和系统代码。',
      how: '确认上方所属接入应用后，填写应用环境名称和系统代码并保存；也可以选用该接入应用下已有的环境。保存后会重新读取并核对所属接入应用。',
      success: '保存后重新读取刚创建的环境，并确认所属接入应用正确。',
      errors:
        '缺少上一步接入应用时请先返回完成接入应用。校验失败请按页面提示修改。冲突、网络或服务失败时请重试，不要重复创建。',
      next: '确认后进入完成页，按实际需要勾选下一步目标。',
      endpoint: 'environment',
      indexPermission: 'sand_iam:environment:index',
      readPermission: 'sand_iam:environment:read',
      savePermission: 'sand_iam:environment:save',
      updatePermission: 'sand_iam:environment:update'
    }
  ]

  const goals: readonly NextGoal[] = [
    {
      value: 'people',
      title: '让员工登录并分配权限',
      description: '继续配置身份来源、应用用户、角色、资源和策略。',
      path: '/sand-iam/people-access',
      permission: 'sand_iam:identity:index'
    },
    {
      value: 'service',
      title: '让系统调用接口',
      description: '继续配置服务调用身份、服务授权和凭证。',
      path: '/sand-iam/connection',
      permission: 'sand_iam:organization:index'
    },
    {
      value: 'event',
      title: '接收用户和权限变化通知',
      description: '继续配置事件接收地址并查看投递结果。',
      path: '/sand-iam/event-notification',
      permission: 'sand_iam:webhook:index'
    }
  ]

  const currentScreen = ref<WizardScreen>('organization')
  const selectedGoals = ref<SetupGoal[]>([])
  const records = reactive<Record<WizardStep, WizardRecord | null>>({
    organization: null,
    application: null,
    environment: null
  })
  const ids = reactive<Record<WizardStep, number | null>>({
    organization: null,
    application: null,
    environment: null
  })
  const phases = reactive<Record<WizardStep, WizardPhase>>({
    organization: 'draft',
    application: 'draft',
    environment: 'draft'
  })
  const pendingCodes = reactive<Record<WizardStep, string | null>>({
    organization: null,
    application: null,
    environment: null
  })
  const candidates = reactive<Record<WizardStep, SandIamResourceRow[]>>({
    organization: [],
    application: [],
    environment: []
  })
  const candidatesQueried = reactive<Record<WizardStep, boolean>>({
    organization: false,
    application: false,
    environment: false
  })
  const candidateId = ref<number | null>(null)
  const candidateLoading = ref(false)
  const candidatePage = ref(1)
  const candidateTotal = ref(0)
  let candidateVersion = 0
  let disposed = false
  watch(() => `${currentScreen.value}:${ids.organization}:${ids.application}`, () => {
    candidateVersion++
    candidateLoading.value = false
    candidateId.value = null
    candidatePage.value = 1
    candidateTotal.value = 0
    for (const step of steps) {
      candidates[step.id] = []
      candidatesQueried[step.id] = false
    }
  }, { flush: 'sync' })
  const saving = ref(false)
  const verifying = ref<WizardStep | null>(null)
  const verificationRecovery = ref<'retain_pending' | 'lookup_required'>('lookup_required')
  const pageMessage = ref('')

  const currentStep = computed(() =>
    currentScreen.value === 'complete' ? null : definition(currentScreen.value)
  )
  const selectedGoalDetails = computed(() =>
    goals.filter((goal) => selectedGoals.value.includes(goal.value))
  )
  const currentParentLabel = computed(() => {
    if (currentScreen.value === 'application') return records.organization?.name ?? ''
    if (currentScreen.value === 'environment') return records.application?.name ?? ''
    return ''
  })

  function definition(step: WizardStep): StepDefinition {
    return steps.find((item) => item.id === step) ?? steps[0]
  }

  function canRead(step: WizardStep): boolean {
    const item = definition(step)
    return hasAuth(item.indexPermission) && hasAuth(item.readPermission)
  }

  function canWrite(step: WizardStep, updating: boolean): boolean {
    return (
      canRead(step) &&
      hasAuth(updating ? definition(step).updatePermission : definition(step).savePermission)
    )
  }

  function stepIndex(step: WizardStep): number {
    return steps.findIndex((item) => item.id === step)
  }

  function nextScreen(step: WizardStep): WizardScreen {
    if (step === 'organization') return 'application'
    if (step === 'application') return 'environment'
    return 'complete'
  }

  function stepStatus(step: WizardStep): 'success' | 'process' | 'wait' {
    if (records[step] !== null) return 'success'
    return currentScreen.value === step ? 'process' : 'wait'
  }

  function statusText(step: WizardStep): string {
    if (phases[step] === 'disabled') return '需重新启用'
    if (phases[step] === 'created_pending_confirmation') return '等待确认'
    if (phases[step] === 'save_outcome_unknown') return '等待核对保存结果'
    if (records[step] !== null) return '已核验'
    return currentScreen.value === step ? '正在设置' : '等待上一步'
  }

  function parentIdFor(step: WizardStep): number | null {
    if (step === 'application') return ids.organization
    if (step === 'environment') return ids.application
    return null
  }

  function storageAvailable(): Storage | null {
    try {
      return window.localStorage
    } catch {
      return null
    }
  }

  function persistContext(): void {
    const storage = storageAvailable()
    if (storage === null) return
    try {
      storage.setItem(
        CONTEXT_KEY,
        JSON.stringify(
          createWizardContext({ ...ids }, { ...phases }, { ...pendingCodes }, Date.now())
        )
      )
    } catch {
      pageMessage.value = '无法在此浏览器保存继续设置的进度；当前页面仍可完成本次操作。'
    }
  }

  function clearPersistedContext(): void {
    try {
      storageAvailable()?.removeItem(CONTEXT_KEY)
    } catch {
      // Storage is a convenience only and never supplies authorization.
    }
  }

  function clearFrom(step: WizardStep): void {
    for (const item of steps.slice(stepIndex(step))) {
      ids[item.id] = null
      records[item.id] = null
      phases[item.id] = 'draft'
      pendingCodes[item.id] = null
      candidates[item.id] = []
      candidatesQueried[item.id] = false
    }
    candidateId.value = null
    persistContext()
  }

  function recoveryMessage(error: unknown, action: 'read' | 'save'): string {
    const described = describeSandIamError(error)
    if (described.http === 401) return '登录已失效。请重新登录后再继续。'
    if (described.http === 403)
      return '当前账号没有这条记录的管理范围。请联系管理员确认权限后重试。'
    if (
      action === 'save' &&
      (described.http === 400 || described.code?.includes('VALIDATION') === true)
    )
      return '请检查必填内容和填写格式后再次保存。'
    if (action === 'save' && described.code?.includes('CONFLICT') === true)
      return '名称或系统代码已被使用。请修改后再次保存。'
    return action === 'read'
      ? '暂时无法读取这条记录。请检查网络后重试；持续失败时联系管理员。'
      : '暂时无法保存。请检查网络后重试；持续失败时联系管理员。'
  }

  function assertParent(step: WizardStep, record: WizardRecord): boolean {
    if (step === 'application') return record.organizationId === ids.organization
    if (step === 'environment') return record.applicationId === ids.application
    return true
  }

  async function verifyRecord(step: WizardStep, id: number): Promise<WizardRecord | null> {
    verificationRecovery.value = 'lookup_required'
    if (!canRead(step)) {
      pageMessage.value = '需要读取权限才能确认刚才保存的记录。请联系管理员开通权限后重试确认。'
      return null
    }
    verifying.value = step
    try {
      const record = recordFromResponse(
        await readSandIamResource(definition(step).endpoint, id),
        step
      )
      if (record === null || record.id !== id) {
        pageMessage.value = '系统没有返回刚才选择的完整记录。为避免误认其他记录，请重试确认。'
        return null
      }
      if (!assertParent(step, record)) {
        pageMessage.value = '这条记录不属于本次向导已选择的上级对象。请返回上一步重新选择。'
        return null
      }
      return record
    } catch (error: unknown) {
      verificationRecovery.value = verificationRecoveryForHttp(describeSandIamError(error).http)
      pageMessage.value = recoveryMessage(error, 'read')
      return null
    } finally {
      verifying.value = null
    }
  }

  function applyVerifiedRecord(step: WizardStep, record: WizardRecord): boolean {
    const previousId = ids[step]
    if (previousId !== null && previousId !== record.id) clearFrom(step)
    ids[step] = record.id
    records[step] = record
    phases[step] = phaseAfterVerifiedRecord(record)
    pendingCodes[step] = null
    if (record.status !== 1) {
      if (step === 'organization') clearFrom('application')
      if (step === 'application') clearFrom('environment')
      records[step] = record
      ids[step] = record.id
      phases[step] = 'disabled'
      persistContext()
      currentScreen.value = step
      pageMessage.value = '这条记录已停用。请先重新启用，才能继续下一步。'
      return false
    }
    persistContext()
    return true
  }

  async function reverifyDownstream(step: WizardStep): Promise<void> {
    for (const child of steps.slice(stepIndex(step) + 1)) {
      const id = ids[child.id]
      if (id === null) continue
      const record = await verifyRecord(child.id, id)
      if (record === null) {
        clearFrom(child.id)
        currentScreen.value = child.id
        return
      }
      if (!applyVerifiedRecord(child.id, record)) return
    }
  }

  async function saveStep(payload: Readonly<Record<string, string | number>>): Promise<void> {
    const step = currentStep.value
    if (step === null || saving.value || verifying.value !== null) return
    const existing = records[step.id]
    if (existing === null && !canPostForPhase(phases[step.id])) return
    if (!canWrite(step.id, existing !== null)) {
      pageMessage.value = '当前账号没有保存这一步的权限。请联系管理员开通管理范围。'
      return
    }
    const parentId = parentIdFor(step.id)
    if (step.id !== 'organization' && parentId === null) {
      pageMessage.value = '请先完成上一步，再继续设置。'
      return
    }
    const creating = existing === null
    let createResponseReceived = false
    saving.value = true
    pageMessage.value = ''
    try {
      const body: Record<string, string | number> = { ...payload }
      if (step.id === 'application' && parentId !== null) body.organization_id = parentId
      if (step.id === 'environment' && parentId !== null) body.application_id = parentId
      let id: number
      if (existing === null) {
        const response = await saveSandIamResource(step.endpoint, body, false)
        createResponseReceived = true
        const createdId = saveResponseId(response)
        if (createdId === null) {
          phases[step.id] = phaseAfterSave(null)
          pendingCodes[step.id] = typeof payload.code === 'string' ? payload.code : null
          persistContext()
          pageMessage.value =
            '保存结果缺少记录编号，无法安全确认本次记录。请刷新后检查，再决定是否重试。'
          return
        }
        id = createdId
        ids[step.id] = id
        phases[step.id] = phaseAfterSave(id)
        pendingCodes[step.id] = typeof payload.code === 'string' ? payload.code : null
        persistContext()
      } else {
        id = existing.id
        await updateSandIamResource(step.endpoint, {
          id: existing.id,
          name: body.name,
          status: body.status
        })
      }
      const verified = await verifyRecord(step.id, id)
      if (verified === null) {
        return
      }
      if (!applyVerifiedRecord(step.id, verified)) return
      await reverifyDownstream(step.id)
      if (currentScreen.value === step.id) currentScreen.value = nextScreen(step.id)
      ElMessage.success(existing === null ? '已保存并确认' : '已修改并确认')
    } catch (error: unknown) {
      if (creating) {
        const described = describeSandIamError(error)
        phases[step.id] = createResponseReceived ? phaseAfterSave(null) : phaseAfterCreateError(described.http, described.code)
        pendingCodes[step.id] = phases[step.id] === 'draft' ? null : typeof payload.code === 'string' ? payload.code : null
        persistContext()
      }
      pageMessage.value = recoveryMessage(error, 'save')
    } finally {
      saving.value = false
    }
  }

  async function retryVerification(): Promise<void> {
    const step = currentStep.value
    const id = step === null ? null : ids[step.id]
    if (step === null || id === null || saving.value) return
    const verified = await verifyRecord(step.id, id)
    if (verified === null) return
    if (!applyVerifiedRecord(step.id, verified)) return
    await reverifyDownstream(step.id)
    if (currentScreen.value === step.id) currentScreen.value = nextScreen(step.id)
    ElMessage.success('已确认这条记录')
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    return isRecord(value) && Array.isArray(value.data) ? value.data.filter(isRecord) : []
  }

  async function loadCandidates(step: WizardStep, page = 1): Promise<void> {
    if (disposed || saving.value || verifying.value !== null) return
    if (!canRead(step)) {
      pageMessage.value = '当前账号没有读取已有记录的权限。请联系管理员开通管理范围。'
      return
    }
    const parentId = parentIdFor(step)
    const screen = currentScreen.value
    const version = ++candidateVersion
    const current = () => !disposed && version === candidateVersion && parentId === parentIdFor(step) && screen === currentScreen.value
    if (step !== 'organization' && parentId === null) return
    candidateLoading.value = true
    candidatePage.value = page
    candidates[step] = []
    candidateId.value = null
    candidatesQueried[step] = false
    try {
      const params =
        step === 'application'
          ? { page, limit: 100, organization_id: parentId ?? undefined }
          : step === 'environment'
            ? { page, limit: 100, application_id: parentId ?? undefined }
            : { page, limit: 100 }
      const response = await listSandIamResource(definition(step).endpoint, params)
      if (!current()) return
      const payload = isRecord(response) && isRecord(response.data) ? response.data : response
      const rows = listRows(payload)
      candidateTotal.value = isRecord(payload) && typeof payload.total === 'number' ? payload.total : rows.length
      const pendingCode = pendingCodes[step]
      candidates[step] =
        pendingCode === null ? rows : rows.filter((row) => row.code === pendingCode)
      candidatesQueried[step] = true
    } catch (error: unknown) {
      if (!current()) return
      candidates[step] = []
      pageMessage.value = recoveryMessage(error, 'read')
    } finally {
      if (current()) candidateLoading.value = false
    }
  }

  function candidateLabel(row: SandIamResourceRow): string {
    const name = typeof row.name === 'string' && row.name.trim() !== '' ? row.name : '未命名记录'
    const code = typeof row.code === 'string' && row.code.trim() !== '' ? ` · ${row.code}` : ''
    return `${name}${code}`
  }

  function changeCandidatePage(page: number): void {
    if (currentStep.value !== null) void loadCandidates(currentStep.value.id, page)
  }

  async function useSelectedCandidate(): Promise<void> {
    const step = currentStep.value
    const id = candidateId.value
    if (disposed || saving.value || verifying.value !== null || candidateLoading.value ||
      step === null || id === null || !candidates[step.id].some(row => positiveId(row.id) === id)) return
    const record = await verifyRecord(step.id, id)
    if (record === null) return
    if (!applyVerifiedRecord(step.id, record)) return
    await reverifyDownstream(step.id)
    currentScreen.value = nextScreen(step.id)
    ElMessage.success('已使用你选择的记录并完成确认')
  }

  function goTo(step: WizardStep): void {
    if (!canNavigateTo(step, phases)) return
    currentScreen.value = step
    pageMessage.value = ''
  }

  function openNext(path: string): void {
    void router.push(path)
  }

  async function restoreContext(): Promise<void> {
    const context = parseWizardContext(storageAvailable()?.getItem(CONTEXT_KEY) ?? null, Date.now())
    if (context === null) {
      clearPersistedContext()
      return
    }
    for (const step of steps) {
      ids[step.id] = context.ids[step.id]
      phases[step.id] = context.phases[step.id]
      pendingCodes[step.id] = context.pendingCodes[step.id]
    }
    for (const step of steps) {
      const id = ids[step.id]
      if (id === null) {
        currentScreen.value = step.id
        return
      }
      const record = await verifyRecord(step.id, id)
      if (record === null) {
        if (verificationRecovery.value === 'retain_pending') {
          currentScreen.value = step.id
          return
        }
        if (step.id === 'organization') clearFrom('application')
        if (step.id === 'application') clearFrom('environment')
        records[step.id] = null
        phases[step.id] = 'lookup_required'
        persistContext()
        currentScreen.value = step.id
        pageMessage.value = '之前保存的继续设置记录已失效、不可访问或不再存在。已安全回到这一步。'
        return
      }
      if (!applyVerifiedRecord(step.id, record)) return
    }
    currentScreen.value = 'complete'
  }

  onMounted(() => {
    void restoreContext()
  })
  onScopeDispose(() => { disposed = true; candidateVersion++ })
</script>

<template>
  <div class="sand-iam-page sand-iam-getting-started">
    <ElCard class="sand-iam-page-card" shadow="never">
      <header class="sand-iam-getting-started__heading">
        <div>
          <h2 class="m-0 text-lg font-semibold">第一次使用</h2>
          <p class="mb-0 mt-2 text-sm text-gray-500">
            按“客户主体 → 接入应用 → 应用环境”完成本次设置。每一步都会重新确认刚才选择的记录。
          </p>
        </div>
      </header>
      <ElSteps
        class="sand-iam-getting-started__steps"
        :active="currentScreen === 'complete' ? 3 : stepIndex(currentScreen)"
        finish-status="success"
      >
        <ElStep
          v-for="step in steps"
          :key="step.id"
          :title="step.title"
          :status="stepStatus(step.id)"
          :description="statusText(step.id)"
        />
      </ElSteps>
      <ElAlert
        v-if="pageMessage !== ''"
        class="mt-5"
        type="warning"
        :closable="true"
        title="请先处理这一步"
        :description="pageMessage"
        @close="pageMessage = ''"
      />
      <section v-if="currentStep !== null" class="sand-iam-getting-started__panel">
        <div class="sand-iam-getting-started__panel-heading">
          <div>
            <p class="mb-1 text-sm text-gray-500"> 第 {{ stepIndex(currentStep.id) + 1 }} 步 </p>
            <h3 class="m-0 text-base font-semibold">{{ currentStep.title }}</h3>
          </div>
          <ElTag :type="records[currentStep.id] === null ? 'info' : 'success'" effect="plain">{{
            statusText(currentStep.id)
          }}</ElTag>
        </div>
        <p><strong>这一步解决什么问题：</strong>{{ currentStep.problem }}</p>
        <p><strong>开始前准备：</strong>{{ currentStep.preparation }}</p>
        <p><strong>在页面上怎么做：</strong>{{ currentStep.how }}</p>
        <p><strong>成功标志：</strong>{{ currentStep.success }}</p>
        <p><strong>常见错误如何处理：</strong>{{ currentStep.errors }}</p>
        <p><strong>下一步去哪里：</strong>{{ currentStep.next }}</p>
        <div v-if="records[currentStep.id] !== null" class="sand-iam-getting-started__selected">
          <strong>本次向导已选择：</strong>{{ records[currentStep.id]?.name }}（{{
            records[currentStep.id]?.code
          }}）<span>已通过重新读取确认。</span>
        </div>
        <div class="sand-iam-getting-started__actions">
          <ElButton
            v-if="currentStep.id !== 'organization'"
            :disabled="saving || verifying !== null"
            @click="goTo(currentStep.id === 'application' ? 'organization' : 'application')"
            >上一步</ElButton
          >
          <ElButton
            :loading="candidateLoading"
            :disabled="saving || verifying !== null"
            @click="loadCandidates(currentStep.id)"
            >选择已有记录</ElButton
          >
          <ElButton
            v-if="ids[currentStep.id] !== null && records[currentStep.id] === null"
            :loading="verifying === currentStep.id"
            :disabled="saving"
            @click="retryVerification"
            >重试确认</ElButton
          >
        </div>
        <ElAlert
          v-if="phases[currentStep.id] === 'created_pending_confirmation'"
          class="mt-4"
          type="info"
          :closable="false"
          title="已收到保存结果，正在等待确认"
          description="为避免重复创建，请只重试读取这条记录。"
        />
        <ElAlert
          v-if="
            phases[currentStep.id] === 'save_outcome_unknown' ||
            phases[currentStep.id] === 'lookup_required'
          "
          class="mt-4"
          type="warning"
          :closable="false"
          title="需要核对已有记录"
          description="请重新登录或获取权限后重试读取；也可按本次系统代码查询，再由你明确选择已有记录。为避免重复创建，不能再次提交。"
        />
        <div
          v-if="candidates[currentStep.id].length > 0"
          class="sand-iam-getting-started__candidate"
        >
          <p class="mt-0 text-sm text-gray-500"> 只有你在这里明确选择的记录，才会用于本次向导。 </p>
          <ElSelect
            v-model="candidateId"
            placeholder="请选择已有记录"
            :disabled="saving || verifying !== null"
            style="width: 100%"
            ><ElOption
              v-for="row in candidates[currentStep.id]"
              :key="String(row.id)"
              :label="candidateLabel(row)"
              :value="positiveId(row.id) ?? 0"
          /></ElSelect>
          <ElButton
            class="mt-3"
            :disabled="candidateId === null || saving || verifying !== null"
            @click="useSelectedCandidate"
            >使用这条记录</ElButton
          >
        </div>
        <ElEmpty
          v-else-if="candidatesQueried[currentStep.id]"
          class="sand-iam-getting-started__candidate-empty"
          description="当前没有可选择的已有记录。你可以创建新的，或联系管理员确认是否有可见记录。"
          :image-size="72"
        />
        <template v-if="candidatesQueried[currentStep.id]">
          <p v-if="pendingCodes[currentStep.id] !== null">仅显示与本次系统代码完全一致的记录。当前页未找到时，请继续下一页核对。</p>
          <ElPagination :current-page="candidatePage" :page-size="100" :total="candidateTotal"
            layout="total, prev, pager, next" :disabled="saving || verifying !== null || candidateLoading"
            @current-change="changeCandidatePage" />
        </template>
        <WizardStepForm
          v-if="
            canWrite(currentStep.id, records[currentStep.id] !== null) &&
            (records[currentStep.id] !== null || canPostForPhase(phases[currentStep.id]))
          "
          class="sand-iam-getting-started__form"
          :step="currentStep.id"
          :record="records[currentStep.id]"
          :parent-label="currentParentLabel"
          :saving="saving || verifying !== null"
          @submit="saveStep"
        />
        <ElEmpty
          v-else-if="!canWrite(currentStep.id, records[currentStep.id] !== null)"
          description="当前账号没有保存这一步的权限，请联系管理员开通管理范围。"
          :image-size="80"
        />
      </section>
      <section v-else class="sand-iam-getting-started__panel">
        <ElResult
          icon="success"
          title="基础设置已完成"
          sub-title="客户主体、接入应用和应用环境均已通过重新读取确认。勾选下一步目标后，只会出现对应入口。"
        >
          <template #extra>
            <ElCheckboxGroup v-model="selectedGoals" class="sand-iam-getting-started__goals"
              ><ElCheckbox v-for="goal in goals" :key="goal.value" :label="goal.value"
                >{{ goal.title }}：{{ goal.description }}</ElCheckbox
              ></ElCheckboxGroup
            >
            <p v-if="selectedGoalDetails.length === 0" class="mt-4 mb-0 text-sm text-gray-500">
              请勾选下一步目标。未勾选时不会出现对应入口。
            </p>
            <div class="sand-iam-getting-started__goal-actions">
              <ElButton
                v-for="goal in selectedGoalDetails"
                :key="goal.value"
                type="primary"
                :disabled="!hasAuth(goal.permission)"
                @click="openNext(goal.path)"
                >前往{{ goal.title }}</ElButton
              >
            </div>
            <p
              v-if="selectedGoalDetails.some((goal) => !hasAuth(goal.permission))"
              class="text-sm text-gray-500"
            >
              没有入口权限时，请联系管理员开通相应管理范围。
            </p>
            <ElButton :disabled="saving || verifying !== null" @click="goTo('environment')"
              >返回修改环境</ElButton
            >
          </template>
        </ElResult>
      </section>
    </ElCard>
  </div>
</template>

<style scoped lang="scss">
  .sand-iam-getting-started {
    height: auto;
    overflow: visible;
    padding-bottom: 24px;
  }
  .sand-iam-getting-started__heading {
    display: flex;
    justify-content: space-between;
    gap: 16px;
  }
  .sand-iam-getting-started__steps {
    margin-top: 28px;
  }
  .sand-iam-getting-started__panel {
    max-width: 760px;
    height: auto;
    overflow: visible;
    margin: 28px auto 0;
    padding: 20px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
  }
  .sand-iam-getting-started__panel-heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
  }
  .sand-iam-getting-started__panel > p {
    color: var(--el-text-color-regular);
    line-height: 1.65;
  }
  .sand-iam-getting-started__actions,
  .sand-iam-getting-started__goal-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 20px;
  }
  .sand-iam-getting-started__candidate,
  .sand-iam-getting-started__selected {
    margin-top: 20px;
    padding: 16px;
    border-radius: 6px;
    background: var(--el-fill-color-light);
    overflow-wrap: anywhere;
    word-break: break-word;
  }
  .sand-iam-getting-started__candidate-empty {
    margin-top: 20px;
  }
  .sand-iam-getting-started__selected span {
    display: block;
    margin-top: 6px;
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .sand-iam-getting-started__form {
    margin-top: 24px;
  }
  .sand-iam-getting-started__goals {
    display: grid;
    gap: 12px;
    text-align: left;
  }
  @media (max-width: 600px) {
    .sand-iam-getting-started__heading,
    .sand-iam-getting-started__panel-heading {
      flex-direction: column;
    }
    .sand-iam-getting-started__panel {
      margin-top: 20px;
      padding: 16px;
    }
  }
</style>
