<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    buildInitializationRollbackConfirmation,
    initializationChangeLabel,
    initializationDraftStatusLabel,
    initializationStateLabel,
    parseInitializationDraftDetail,
    parseInitializationDraftMutation,
    parseInitializationDrafts,
    parseInitializationManifest,
    parseInitializationPreview,
    parseInitializationRuns,
    parseInitializationPagination,
    type SandIamInitializationDraft,
    type SandIamInitializationDraftDetail,
    type SandIamInitializationPreview,
    type SandIamInitializationRun
  } from '../api/initializationContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:initialization:index'))
  const canExport = computed(() => hasAuth('sand_iam:initialization:export'))
  const canPreview = computed(() => hasAuth('sand_iam:initialization:preview'))
  const canApply = computed(() => hasAuth('sand_iam:initialization:apply'))
  const canRollback = computed(() => hasAuth('sand_iam:initialization:rollback'))
  const canSaveDraft = computed(() => hasAuth('sand_iam:initialization:save'))
  const canUpdateDraft = computed(() => hasAuth('sand_iam:initialization:update'))
  const canDisableDraft = computed(() => hasAuth('sand_iam:initialization:disable'))
  const canRead = computed(() => hasAuth('sand_iam:initialization:read'))

  const loadingDepth = ref(0)
  const loading = computed(() => loadingDepth.value > 0)
  const applications = ref<SandIamResourceRow[]>([])
  const organizations = ref<SandIamResourceRow[]>([])
  const runs = ref<SandIamInitializationRun[]>([])
  const drafts = ref<SandIamInitializationDraft[]>([])
  const runPage = ref(1)
  const runSize = ref(20)
  const runTotal = ref(0)
  const draftPage = ref(1)
  const draftSize = ref(20)
  const draftTotal = ref(0)
  const applicationId = ref('')
  const exportPackageCode = ref('')
  const manifestText = ref('')
  const preview = ref<SandIamInitializationPreview | null>(null)
  const pendingManifest = ref<Record<string, unknown> | null>(null)
  const editingDraft = ref<SandIamInitializationDraftDetail | null>(null)
  const requestError = ref<SandIamRequestError | null>(null)
  const runViewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const draftViewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const successHint = ref('')
  let disposed = false
  let contextVersion = 0
  let scopeVersion = 0
  let runVersion = 0
  let draftVersion = 0
  watch([runPage, runSize], () => { runVersion++; runs.value = [] }, { flush: 'sync' })
  watch([draftPage, draftSize], () => { draftVersion++; drafts.value = [] }, { flush: 'sync' })
  watch(manifestText, () => {
    contextVersion++
    preview.value = null
    pendingManifest.value = null
    successHint.value = ''
  }, { flush: 'sync' })
  watch(applicationId, () => {
    scopeVersion++
    contextVersion++
    runVersion++
    draftVersion++
    runs.value = []
    drafts.value = []
    runPage.value = 1
    draftPage.value = 1
    runTotal.value = 0
    draftTotal.value = 0
    editingDraft.value = null
    manifestText.value = ''
    preview.value = null
    pendingManifest.value = null
    requestError.value = null
    successHint.value = ''
    runViewState.value = 'idle'
    draftViewState.value = 'idle'
  }, { flush: 'sync' })
  onScopeDispose(() => { disposed = true; scopeVersion++; contextVersion++; runVersion++; draftVersion++ })
  function captureContext(): () => boolean {
    const version = contextVersion
    return () => !disposed && version === contextVersion
  }
  function visibleRow(row: { application_id: number | null }): boolean {
    return selectedId(applicationId.value) === null || row.application_id === selectedId(applicationId.value)
  }

  const canSaveCurrentDraft = computed(() =>
    editingDraft.value === null ? canSaveDraft.value : canUpdateDraft.value
  )

  function beginLoading(): void {
    loadingDepth.value += 1
  }

  function endLoading(): void {
    loadingDepth.value = Math.max(0, loadingDepth.value - 1)
  }

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function applicationName(id: number | null): string {
    if (id === null) return '尚未创建接入应用'
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  function organizationName(id: number): string {
    const row = organizations.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联客户主体名称暂不可用'
  }

  /**
   * 当前记录只有操作人编号，没有操作人名称；不把编号当成人名展示。
   */
  function operatorLabel(run: SandIamInitializationRun): string {
    return run.applied_time === null ? '尚未应用' : '已记录操作人'
  }

  const applicationLoading = ref(false)
  const applicationError = ref('')
  const applicationKeywords = ref('')
  let applicationRequest = 0

  async function loadApplications(keywords = ''): Promise<void> {
    const attempt = ++applicationRequest
    if (disposed) return
    applicationKeywords.value = keywords
    applicationLoading.value = true
    applicationError.value = ''
    try {
      const result = await listSandIamResource('application', { page: 1, limit: 100, keywords })
      if (disposed || attempt !== applicationRequest) return
      const selected = applications.value.find(row => String(row.id) === applicationId.value)
      const rows = listRows(result)
      applications.value = selected && !rows.some(row => row.id === selected.id) ? [selected, ...rows] : rows
    } catch (error: unknown) {
      if (!disposed && attempt === applicationRequest) applicationError.value = describeSandIamError(error).detail
    } finally {
      if (!disposed && attempt === applicationRequest) applicationLoading.value = false
    }
  }

  async function loadOptions(): Promise<void> {
    void loadApplications()
    try {
      const result = await listSandIamResource('organization', { page: 1, limit: 100 })
      if (!disposed) organizations.value = listRows(result)
    } catch (error: unknown) {
      if (!disposed) requestError.value = describeSandIamError(error)
    }
  }

  async function loadRuns(): Promise<void> {
    if (disposed || !canIndex.value) return
    const version = ++runVersion
    beginLoading()
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      const response = await getSandIamAdmin(
          'initialization/index',
          { page: runPage.value, limit: runSize.value, ...(application === null ? {} : { application_id: application }) }
        )
      if (disposed || version !== runVersion) return
      const result = parseInitializationRuns(response)
      runs.value = result
      runTotal.value = parseInitializationPagination(response, result.length).total
      runViewState.value = runs.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (disposed || version !== runVersion) return
      requestError.value = describeSandIamError(error)
      runs.value = []
    } finally {
      endLoading()
    }
  }

  async function loadDrafts(): Promise<void> {
    if (disposed || !canIndex.value) return
    const version = ++draftVersion
    beginLoading()
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      const response = await getSandIamAdmin(
          'initialization/draft-index',
          { page: draftPage.value, limit: draftSize.value, ...(application === null ? {} : { application_id: application }) }
        )
      if (disposed || version !== draftVersion) return
      const result = parseInitializationDrafts(response)
      drafts.value = result
      draftTotal.value = parseInitializationPagination(response, result.length).total
      draftViewState.value = drafts.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (disposed || version !== draftVersion) return
      requestError.value = describeSandIamError(error)
      drafts.value = []
    } finally {
      endLoading()
    }
  }

  async function refreshRecords(): Promise<void> {
    await Promise.all([loadRuns(), loadDrafts()])
  }

  /**
   * 只把后端已返回的导出结果存成 JSON 文件，不在浏览器改写字段。
   */
  function downloadJson(filename: string, value: unknown): void {
    const blob = new Blob([JSON.stringify(value, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  }

  /**
   * 导出只下载系统生成的包。密钥、用户和凭证不会进入初始化包。
   */
  async function exportPackage(): Promise<void> {
    if (disposed || loading.value || !canExport.value) return
    const current = captureContext()
    const packageCode = exportPackageCode.value.trim()
    const application = selectedId(applicationId.value)
    if (application === null || exportPackageCode.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写初始化包代码')
      )
      return
    }
    beginLoading()
    requestError.value = null
    try {
      const result = await getSandIamAdmin('initialization/export', {
        application_id: application,
        package_code: packageCode
      })
      if (!current() || packageCode !== exportPackageCode.value.trim()) return
      const payload = isRecord(result) && 'data' in result ? result.data : result
      downloadJson(`${packageCode}.json`, payload)
      ElMessage.success('已保存')
      successHint.value = '初始化包已导出。导出结果不含密钥、用户账号和凭证。'
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      endLoading()
    }
  }

  /**
   * 必须先预检。preview_hash 只留在内存，过期后只能重新预检，不能强制覆盖。
   */
  async function previewManifest(): Promise<void> {
    if (disposed || loading.value || !canPreview.value) return
    const current = captureContext()
    beginLoading()
    requestError.value = null
    preview.value = null
    pendingManifest.value = null
    successHint.value = ''
    try {
      const manifest = parseInitializationManifest(manifestText.value)
      const parsed = parseInitializationPreview(
        await postSandIamAction('initialization/preview', { manifest })
      )
      if (!current()) return
      if (parsed === null) {
        requestError.value = describeSandIamError(
          new Error('SAND_IAM_INITIALIZATION_INVALID: 预检返回格式不符合已冻结约定')
        )
        return
      }
      preview.value = parsed
      pendingManifest.value = manifest
      successHint.value = '已预检。请按新增、修改、不变确认差异后再应用。'
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      endLoading()
    }
  }

  function describeDraftError(error: unknown): SandIamRequestError {
    const response = isRecord(error) && isRecord(error.data) ? error.data : null
    const message = [
      error instanceof Error ? error.message : '',
      response !== null && typeof response.msg === 'string' ? response.msg : '',
      response !== null && typeof response.message === 'string' ? response.message : '',
      response !== null && typeof response.code === 'string' ? response.code : ''
    ].join(' ')
    if (message.includes('SAND_IAM_INITIALIZATION_DRAFT_REVISION_STALE')) {
      return {
        code: 'SAND_IAM_INITIALIZATION_DRAFT_REVISION_STALE',
        http: 409,
        title: '草稿已被更新',
        detail: '请重新打开草稿并确认最新内容，不会覆盖其他管理员的修改。'
      }
    }
    if (message.includes('SAND_IAM_INITIALIZATION_DRAFT_DISABLED')) {
      return {
        code: 'SAND_IAM_INITIALIZATION_DRAFT_DISABLED',
        http: 409,
        title: '草稿已停用',
        detail: '已停用草稿不能继续更新。请新建草稿，或刷新列表后选择其他草稿。'
      }
    }
    if (message.includes('SAND_IAM_INITIALIZATION_DRAFT_CONFLICT')) {
      return {
        code: 'SAND_IAM_INITIALIZATION_DRAFT_CONFLICT',
        http: 409,
        title: '已有同范围草稿',
        detail: '请从草稿列表继续编辑现有草稿，不会重复创建。'
      }
    }
    if (message.includes('SAND_IAM_INITIALIZATION_DRAFT_NOT_FOUND')) {
      return {
        code: 'SAND_IAM_INITIALIZATION_DRAFT_NOT_FOUND',
        http: 404,
        title: '草稿不存在',
        detail: '草稿可能已被删除或超出当前管理范围，请刷新草稿列表。'
      }
    }
    if (message.includes('SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID')) {
      return {
        code: 'SAND_IAM_INITIALIZATION_DRAFT_REVISION_INVALID',
        http: 400,
        title: '草稿版本无效',
        detail: '请重新打开草稿后再保存，页面不会猜测或补写版本号。'
      }
    }
    return describeSandIamError(error)
  }

  async function loadDraftForEditing(row: SandIamInitializationDraft): Promise<void> {
    if (disposed || loading.value || row.status !== 1 || !canRead.value ||
      !drafts.value.includes(row) || !visibleRow(row)) return
    const current = captureContext()
    beginLoading()
    requestError.value = null
    try {
      const draft = parseInitializationDraftDetail(
        await getSandIamAdmin('initialization/draft-read', { id: row.id })
      )
      if (!current() || !drafts.value.includes(row) || !canRead.value) return
      if (draft === null) {
        requestError.value = describeSandIamError(
          new Error('SAND_IAM_INITIALIZATION_INVALID: 草稿返回格式不符合已冻结约定')
        )
        return
      }
      editingDraft.value = draft
      manifestText.value = JSON.stringify(draft.manifest, null, 2)
      preview.value = null
      pendingManifest.value = null
      successHint.value = `已打开草稿「${draft.package_code}」的第 ${String(draft.revision)} 版。请预检后再更新。`
    } catch (error: unknown) {
      if (current()) requestError.value = describeDraftError(error)
    } finally {
      endLoading()
    }
  }

  function startNewDraft(): void {
    if (disposed || loading.value) return
    contextVersion++
    editingDraft.value = null
    preview.value = null
    pendingManifest.value = null
    manifestText.value = ''
    successHint.value = '已切换为新草稿。导入内容后请先预检。'
  }

  async function saveDraft(): Promise<void> {
    if (disposed || loading.value) return
    if (preview.value === null || pendingManifest.value === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_INITIALIZATION_PREVIEW_STALE: 请先完成预检再保存草稿')
      )
      return
    }
    if (!canSaveCurrentDraft.value) return
    const current = captureContext()
    const manifest = pendingManifest.value
    beginLoading()
    requestError.value = null
    try {
      const currentDraft = editingDraft.value
      const result =
        currentDraft === null
          ? await postSandIamAction('initialization/save', { manifest })
          : await postSandIamAction('initialization/update', {
              id: currentDraft.id,
              revision: currentDraft.revision,
              manifest
            })
      if (!current()) return
      const mutation = parseInitializationDraftMutation(result)
      if (mutation === null) {
        requestError.value = describeSandIamError(
          new Error('SAND_IAM_INITIALIZATION_INVALID: 草稿保存返回格式不符合已冻结约定')
        )
        return
      }
      if (currentDraft !== null) {
        editingDraft.value = {
          ...currentDraft,
          revision: mutation.revision,
          status: mutation.status,
          manifest_hash: mutation.manifest_hash,
          manifest
        }
      } else {
        editingDraft.value = null
        manifestText.value = ''
      }
      preview.value = null
      pendingManifest.value = null
      ElMessage.success('已保存')
      successHint.value =
        currentDraft === null
          ? '初始化草稿已保存。需要继续修改时，请从下方草稿列表重新打开。'
          : '初始化草稿已更新。'
      await loadDrafts()
    } catch (error: unknown) {
      if (current()) requestError.value = describeDraftError(error)
    } finally {
      endLoading()
    }
  }

  async function disableDraft(row: SandIamInitializationDraft): Promise<void> {
    if (disposed || loading.value || row.status !== 1 || !canDisableDraft.value ||
      !drafts.value.includes(row) || !visibleRow(row)) return
    const contextCurrent = captureContext()
    const version = draftVersion
    const current = (): boolean => contextCurrent() && version === draftVersion && drafts.value.includes(row)
    beginLoading()
    try {
      await ElMessageBox.confirm(
        `确认停用草稿「${row.package_code}」吗？停用后不能继续编辑，需新建草稿。`,
        '停用初始化草稿',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      endLoading()
      return
    }
    if (!current() || !canDisableDraft.value || !drafts.value.includes(row) || row.status !== 1) {
      endLoading()
      return
    }
    requestError.value = null
    try {
      const mutation = parseInitializationDraftMutation(
        await postSandIamAction('initialization/disable', { id: row.id, revision: row.revision })
      )
      if (!current()) return
      if (mutation === null) {
        requestError.value = describeSandIamError(
          new Error('SAND_IAM_INITIALIZATION_INVALID: 草稿停用返回格式不符合已冻结约定')
        )
        return
      }
      if (editingDraft.value?.id === mutation.draft_id) editingDraft.value = null
      ElMessage.success('已保存')
      successHint.value = '初始化草稿已停用。'
      await loadDrafts()
    } catch (error: unknown) {
      if (current()) requestError.value = describeDraftError(error)
    } finally {
      endLoading()
    }
  }

  /**
   * 只能提交最近一次预检的 preview_hash。失败后必须重新预检，不能强制覆盖。
   */
  async function applyManifest(): Promise<void> {
    if (disposed || loading.value || !canApply.value) return
    if (preview.value === null || pendingManifest.value === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_INITIALIZATION_PREVIEW_STALE: 请先完成预检并确认中文差异')
      )
      return
    }
    const current = captureContext()
    const manifest = pendingManifest.value
    const hash = preview.value.preview_hash
    preview.value = null
    pendingManifest.value = null
    beginLoading()
    requestError.value = null
    try {
      await postSandIamAction('initialization/apply', {
        manifest,
        preview_hash: hash
      })
      if (!current()) return
      ElMessage.success('已保存')
      successHint.value = '初始化配置已应用。'
      preview.value = null
      pendingManifest.value = null
      await loadRuns()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      endLoading()
    }
  }

  /**
   * 回滚确认摘要按后端冻结公式计算，页面不展示该摘要，也不提供强制覆盖。
   */
  async function rollbackRun(row: SandIamInitializationRun): Promise<void> {
    if (disposed || loading.value || !canRollback.value || !runs.value.includes(row) || !visibleRow(row)) return
    if (row.state !== 'applied') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_INITIALIZATION_ROLLBACK_INVALID: 只有已应用的记录可以回滚')
      )
      return
    }
    const scope = scopeVersion
    const current = (): boolean => !disposed && scope === scopeVersion && runs.value.includes(row)
    const hash = row.package_hash
    beginLoading()
    requestError.value = null
    try {
      try {
        await ElMessageBox.confirm(`确认回滚「${row.package_code}」吗？系统会检查配置漂移，不会强制覆盖后续修改。`,
          '回滚初始化配置', { type: 'warning', confirmButtonText: '确认回滚', cancelButtonText: '取消' })
      } catch { return }
      if (!current() || !canRollback.value || row.state !== 'applied' || row.package_hash !== hash) return
      const confirmation = await buildInitializationRollbackConfirmation(row.id, hash)
      if (!current() || !canRollback.value || row.state !== 'applied' || row.package_hash !== hash) return
      await postSandIamAction('initialization/rollback', {
        id: row.id,
        confirmation
      })
      if (!current()) return
      ElMessage.success('已保存')
      successHint.value = '初始化配置已回滚。若出现漂移，请重新预检，不要强制覆盖。'
      await loadRuns()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      endLoading()
    }
  }

  /**
   * 按新增/修改/不变分组，供中文差异确认使用。
   */
  function groupedChanges(operation: 'create' | 'update' | 'no_change'): string[] {
    return (preview.value?.changes ?? [])
      .filter((item) => item.operation === operation)
      .map((item) => initializationChangeLabel(item))
  }

  onMounted(() => {
    void loadOptions()
    void loadRuns()
    void loadDrafts()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">初始化配置</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          导出初始化包、上传后预检、按中文差异确认后应用，并在无漂移时回滚。默认列表只显示包代码、状态、应用时间和操作人。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看初始化草稿或执行记录。请联系平台管理员确认您的管理范围。"
      />
      <ElAlert
        class="mb-4"
        type="info"
        :closable="false"
        title="必须先预检"
        description="预检结果过期或回滚发现差异时，只能重新预检。本页不会强制覆盖已有配置。"
      />
      <ElAlert
        v-if="requestError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="requestError.detail"
      />
      <ElAlert
        v-else-if="successHint !== ''"
        class="mb-4"
        type="success"
        :closable="false"
        title="已保存"
        :description="successHint"
      />
      <ElForm label-width="170px" class="mb-4">
        <ElFormItem v-if="applicationError" label="应用搜索失败">
          <span role="alert">{{ applicationError }}</span>
          <ElButton :loading="applicationLoading" @click="loadApplications(applicationKeywords)">重试搜索</ElButton>
        </ElFormItem>
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable remote :remote-method="loadApplications" :loading="applicationLoading" clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="导出包代码">
          <ElInput v-model="exportPackageCode" placeholder="例如：customer-service-bootstrap" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canExport" :loading="loading" @click="exportPackage"
            >导出初始化包</ElButton
          >
          <ElButton :disabled="!canIndex" :loading="loading" @click="refreshRecords"
            >刷新草稿和运行记录</ElButton
          >
        </ElFormItem>
      </ElForm>
      <ElCollapse class="mb-4">
        <ElCollapseItem
          data-developer-details="true"
          title="高级操作：导入初始化包（供技术人员使用）"
          name="initialization-package"
        >
          <p class="mt-0 text-xs text-gray-500">
            请只导入由受信任团队提供的初始化包。系统会先检查内容，再显示中文差异；不要在包中放入密码、密钥或个人敏感信息。
          </p>
          <ElForm label-width="170px">
            <ElAlert
              v-if="editingDraft !== null"
              class="mb-4"
              type="info"
              :closable="false"
              title="正在编辑已保存草稿"
              :description="`当前版本：第 ${String(editingDraft.revision)} 版。保存时会提交此版本号；如已被更新，系统不会覆盖。`"
            />
            <ElFormItem label="初始化包内容">
              <ElInput
                v-model="manifestText"
                type="textarea"
                :rows="8"
                placeholder="粘贴初始化包内容"
              />
              <p class="mb-0 mt-1 text-xs text-gray-500">提交前会检查内容格式是否正确。</p>
            </ElFormItem>
            <ElFormItem>
              <ElButton :disabled="!canPreview" :loading="loading" @click="previewManifest"
                >预检</ElButton
              >
              <ElButton
                :disabled="!canSaveCurrentDraft || preview === null"
                :loading="loading"
                @click="saveDraft"
              >
                {{ editingDraft === null ? '保存草稿' : '更新草稿' }}
              </ElButton>
              <ElButton v-if="editingDraft !== null" :disabled="loading" @click="startNewDraft"
                >新建草稿</ElButton
              >
              <ElButton
                type="primary"
                :disabled="!canApply || preview === null"
                :loading="loading"
                @click="applyManifest"
              >
                确认应用
              </ElButton>
            </ElFormItem>
          </ElForm>
        </ElCollapseItem>
      </ElCollapse>

      <template v-if="preview !== null">
        <p class="mb-2 text-sm">
          预检结果：新增 {{ preview.counts.create ?? 0 }}，修改
          {{ preview.counts.update ?? 0 }}，不变 {{ preview.counts.no_change ?? 0 }}。
        </p>
        <p class="mb-2 text-sm text-gray-500">
          {{ preview.warnings.join(' ') }}
        </p>
        <ElAlert
          v-if="groupedChanges('create').length > 0"
          class="mb-2"
          type="info"
          :closable="false"
          title="新增"
          :description="groupedChanges('create').join('；')"
        />
        <ElAlert
          v-if="groupedChanges('update').length > 0"
          class="mb-2"
          type="warning"
          :closable="false"
          title="修改"
          :description="groupedChanges('update').join('；')"
        />
        <ElAlert
          v-if="groupedChanges('no_change').length > 0"
          class="mb-4"
          type="success"
          :closable="false"
          title="不变"
          :description="groupedChanges('no_change').join('；')"
        />
      </template>

      <section class="mt-6">
        <div class="mb-3">
          <h3 class="m-0 text-base font-semibold">草稿</h3>
          <p class="mb-0 mt-1 text-sm text-gray-500">
            草稿尚未应用。列表只显示范围、版本和状态，不展示初始化包内容；继续编辑时仍由后端校验内容不含秘密。
          </p>
        </div>
        <ElAlert
          v-if="canIndex && draftViewState === 'empty'"
          class="mb-3"
          type="info"
          :closable="false"
          title="当前范围没有草稿"
          description="还没有保存过初始化草稿。这与没有权限不同。"
        />
        <ElTable
          v-if="canIndex"
          v-loading="loading"
          :data="drafts"
          border
          stripe
          empty-text="暂无可见草稿"
        >
          <ElTableColumn label="初始化包代码" min-width="180">
            <template #default="scope">{{ scope.row.package_code }}</template>
          </ElTableColumn>
          <ElTableColumn label="客户主体" min-width="160">
            <template #default="scope">{{ organizationName(scope.row.organization_id) }}</template>
          </ElTableColumn>
          <ElTableColumn label="接入应用" min-width="160">
            <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
          </ElTableColumn>
          <ElTableColumn label="版本" min-width="90">
            <template #default="scope">第 {{ scope.row.revision }} 版</template>
          </ElTableColumn>
          <ElTableColumn label="状态" min-width="110">
            <template #default="scope">{{
              initializationDraftStatusLabel(scope.row.status)
            }}</template>
          </ElTableColumn>
          <ElTableColumn label="更新时间" min-width="170">
            <template #default="scope">{{
              scope.row.update_time ?? scope.row.create_time ?? '—'
            }}</template>
          </ElTableColumn>
          <ElTableColumn label="操作" min-width="180" fixed="right">
            <template #default="scope">
              <ElButton
                v-if="scope.row.status === 1"
                size="small"
                :disabled="!canRead"
                @click="loadDraftForEditing(scope.row)"
              >
                继续编辑
              </ElButton>
              <ElButton
                v-if="scope.row.status === 1"
                size="small"
                type="warning"
                :disabled="!canDisableDraft"
                @click="disableDraft(scope.row)"
              >
                停用
              </ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
        <ElPagination v-model:current-page="draftPage" v-model:page-size="draftSize" :total="draftTotal"
          :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next"
          @current-change="loadDrafts" @size-change="draftPage = 1; loadDrafts()" />
      </section>

      <section class="mt-6">
        <div class="mb-3">
          <h3 class="m-0 text-base font-semibold">执行记录</h3>
          <p class="mb-0 mt-1 text-sm text-gray-500">
            执行记录只代表已经应用或回滚的配置，和可继续编辑的草稿分开显示。
          </p>
        </div>
        <ElAlert
          v-if="canIndex && runViewState === 'empty'"
          class="mb-3"
          type="info"
          :closable="false"
          title="当前范围没有执行记录"
          description="还没有应用过初始化包。这与没有草稿或没有权限不同。"
        />
        <ElTable
          v-if="canIndex"
          v-loading="loading"
          :data="runs"
          border
          stripe
          empty-text="暂无可见执行记录"
        >
          <ElTableColumn label="初始化包代码" min-width="180">
            <template #default="scope">{{ scope.row.package_code }}</template>
          </ElTableColumn>
          <ElTableColumn label="客户主体" min-width="160">
            <template #default="scope">{{ organizationName(scope.row.organization_id) }}</template>
          </ElTableColumn>
          <ElTableColumn label="接入应用" min-width="160">
            <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
          </ElTableColumn>
          <ElTableColumn label="状态" min-width="100">
            <template #default="scope">{{ initializationStateLabel(scope.row.state) }}</template>
          </ElTableColumn>
          <ElTableColumn label="应用时间" min-width="170">
            <template #default="scope">{{ scope.row.applied_time ?? '—' }}</template>
          </ElTableColumn>
          <ElTableColumn label="操作人" min-width="120">
            <template #default="scope">{{ operatorLabel(scope.row) }}</template>
          </ElTableColumn>
          <ElTableColumn label="操作" min-width="120" fixed="right">
            <template #default="scope">
              <ElButton
                v-if="scope.row.state === 'applied'"
                size="small"
                type="warning"
                :disabled="!canRollback"
                @click="rollbackRun(scope.row)"
              >
                回滚
              </ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
        <ElPagination v-model:current-page="runPage" v-model:page-size="runSize" :total="runTotal"
          :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next"
          @current-change="loadRuns" @size-change="runPage = 1; loadRuns()" />
      </section>
    </ElCard>
  </div>
</template>
