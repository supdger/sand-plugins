<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    parseSandIamSyncConnectors,
    parseSandIamSyncOutboxRows,
    parseSandIamSyncRuns,
    parseSandIamSyncPagination,
    syncConfigLabel,
    syncDirectionLabel,
    syncOutboxOperationLabel,
    syncOutboxStateLabel,
    syncRunStateLabel,
    type SandIamSyncConnectorRow,
    type SandIamSyncDirection,
    type SandIamSyncOutboxRow,
    type SandIamSyncRunRow
  } from '../api/syncConnectorContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:sync_connector:index'))
  const canSave = computed(() => hasAuth('sand_iam:sync_connector:save'))
  const canUpdate = computed(() => hasAuth('sand_iam:sync_connector:update'))
  const canDisable = computed(() => hasAuth('sand_iam:sync_connector:disable'))
  const canConfigure = computed(() => hasAuth('sand_iam:sync_connector:configure'))
  const canTest = computed(() => hasAuth('sand_iam:sync_connector:test'))
  const canRun = computed(() => hasAuth('sand_iam:sync_run:run'))
  const canRuns = computed(() => hasAuth('sand_iam:sync_run:index'))

  const loading = ref(false)
  const connectorPage = ref(1)
  const connectorPageSize = ref(20)
  const connectorTotal = ref(0)
  const runPage = ref(1)
  const runPageSize = ref(20)
  const runTotal = ref(0)
  const connectorLoading = ref(false)
  const runLoading = ref(false)
  let connectorRequest = 0
  let runRequest = 0
  let disposed = false
  const applications = ref<SandIamResourceRow[]>([])
  const connectors = ref<SandIamSyncConnectorRow[]>([])
  const runs = ref<SandIamSyncRunRow[]>([])
  const failedOutbox = ref<SandIamSyncOutboxRow[]>([])
  const outboxLoading = ref(false)
  const outboxPage = ref(1)
  const outboxPageSize = ref(20)
  const outboxTotal = ref(0)
  let outboxRequestVersion = 0
  let failedOutboxConnectorId: number | null = null
  const applicationId = ref('')
  const name = ref('')
  const code = ref('')
  const driverCode = ref('postgresql')
  const direction = ref<SandIamSyncDirection>('inbound')
  const selectedIdValue = ref('')
  const configText = ref('')
  const displayNameAuthority = ref('source')
  const groupAuthority = ref('source')
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const settingsDraft = ref<{
    name: string
    missing_protection_hours: number
    disable_threshold_percent: number
    conflict_policy: string
  } | null>(null)
  let scopeVersion = 0
  let settingsVersion = 0
  let settingsRow: SandIamSyncConnectorRow | null = null

  function cancelSettings(): void {
    settingsVersion++
    settingsRow = null
    settingsDraft.value = null
  }

  function currentRow(row: SandIamSyncConnectorRow, scope: number, request: number): boolean {
    return !disposed && canIndex.value && scope === scopeVersion && request === connectorRequest &&
      row.application_id === selectedId(applicationId.value) && connectors.value.includes(row)
  }

  function beginSettings(): void {
    const row = selectedConnector.value
    if (disposed || loading.value || connectorLoading.value || !canUpdate.value || row === null ||
      !currentRow(row, scopeVersion, connectorRequest)) return
    settingsVersion++
    settingsRow = row
    settingsDraft.value = {
      name: row.name, missing_protection_hours: row.missing_protection_hours,
      disable_threshold_percent: row.disable_threshold_percent, conflict_policy: row.conflict_policy
    }
    requestError.value = null
  }

  async function saveSettings(): Promise<void> {
    const row = settingsRow
    const draft = settingsDraft.value
    if (disposed || loading.value || connectorLoading.value || !canUpdate.value || !row || !draft ||
      selectedConnector.value !== row || !currentRow(row, scopeVersion, connectorRequest)) return
    const trimmedName = draft.name.trim()
    if (trimmedName === '' || Array.from(trimmedName).length > 128 ||
      !Number.isInteger(draft.missing_protection_hours) || draft.missing_protection_hours < 1 || draft.missing_protection_hours > 720 ||
      !Number.isInteger(draft.disable_threshold_percent) || draft.disable_threshold_percent < 1 || draft.disable_threshold_percent > 100 ||
      !['manual', 'reject', 'source_wins', 'local_wins'].includes(draft.conflict_policy)) {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 请填写有效名称、1–720 小时保护窗口、1–100% 停用阈值及冲突策略'))
      return
    }
    const scope = scopeVersion, request = connectorRequest, version = settingsVersion
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('sync-connector/update', {
        id: row.id, name: trimmedName, missing_protection_hours: draft.missing_protection_hours,
        disable_threshold_percent: draft.disable_threshold_percent, conflict_policy: draft.conflict_policy
      })
      if (!currentRow(row, scope, request) || !canUpdate.value || version !== settingsVersion) return
      ElMessage.success('保护设置已保存')
      cancelSettings()
      await loadConnectors()
    } catch (error: unknown) {
      if (currentRow(row, scope, request) && canUpdate.value && version === settingsVersion) {
        requestError.value = describeSandIamError(error)
      }
    } finally {
      loading.value = false
    }
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

  const selectedConnector = computed(() => {
    const id = selectedId(selectedIdValue.value)
    if (id === null) return null
    return connectors.value.find((item) => item.id === id) ?? null
  })

  const inboundOnlySelected = computed(() => selectedConnector.value?.direction === 'inbound')

  const selectedRunRunning = computed(() => runs.value.some((item) => item.state === 'running'))

  function selectedApplicationName(): string {
    const id = selectedId(applicationId.value)
    if (id === null) return '请先选择接入应用'
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  async function loadApplications(): Promise<void> {
    try {
      applications.value = listRows(
        await listSandIamResource('application', { page: 1, limit: 100 })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  async function loadConnectors(): Promise<void> {
    if (disposed) return
    const attempt = ++connectorRequest
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    if (!canIndex.value) return
    connectorLoading.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin('sync-connector/index', {
        application_id: application, page: connectorPage.value, limit: connectorPageSize.value
      })
      if (disposed || attempt !== connectorRequest) return
      connectors.value = parseSandIamSyncConnectors(result)
      if (selectedConnector.value === null) selectedIdValue.value = ''
      const page = parseSandIamSyncPagination(result)
      connectorTotal.value = page.total
      connectorPage.value = page.currentPage
      connectorPageSize.value = page.pageSize
      viewState.value = connectors.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (disposed || attempt !== connectorRequest) return
      requestError.value = describeSandIamError(error)
      connectors.value = []
    } finally {
      if (!disposed && attempt === connectorRequest) connectorLoading.value = false
    }
  }

  async function createConnector(): Promise<void> {
    if (disposed || loading.value || connectorLoading.value || !canSave.value) return
    const application = selectedId(applicationId.value)
    if (application === null || name.value.trim() === '' || code.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写连接名称和系统代码')
      )
      return
    }
    const scope = scopeVersion
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('sync-connector/save', {
        application_id: application,
        name: name.value.trim(),
        code: code.value.trim(),
        driver_code: driverCode.value,
        direction: direction.value
      })
      if (disposed || scope !== scopeVersion || !canSave.value) return
      ElMessage.success('已保存')
      name.value = ''
      code.value = ''
      await loadConnectors()
    } catch (error: unknown) {
      if (!disposed && scope === scopeVersion && canSave.value) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  function parseConfigObject(): Record<string, unknown> | null {
    const text = configText.value.trim()
    if (text === '') return null
    try {
      const parsed: unknown = JSON.parse(text)
      return isRecord(parsed) ? parsed : null
    } catch {
      return null
    }
  }

  /**
   * 配置只写不读。提交后立刻清空输入，重新打开只能看到“已配置”和版本。
   */
  async function configureConnector(): Promise<void> {
    if (disposed || connectorLoading.value || loading.value || !canConfigure.value) return
    const id = selectedId(selectedIdValue.value)
    const config = parseConfigObject()
    if (id === null || selectedConnector.value === null || config === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_SYNC_CONFIGURATION_INVALID: 请选择连接并填写完整配置对象，原值不会回显')
      )
      return
    }
    const row = selectedConnector.value, scope = scopeVersion, request = connectorRequest
    const submittedConfig = configText.value
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('sync-connector/configure', {
        id,
        config,
        authority_map: {
          display_name: displayNameAuthority.value,
          group: groupAuthority.value
        }
      })
      if (!currentRow(row, scope, request) || !canConfigure.value) return
      ElMessage.success('已保存')
      if (configText.value === submittedConfig) configText.value = ''
      await loadConnectors()
    } catch (error: unknown) {
      if (currentRow(row, scope, request) && canConfigure.value) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function act(path: string, success: string): Promise<void> {
    if (disposed || connectorLoading.value || loading.value) return
    if (!['sync-connector/run', 'sync-connector/test', 'sync-connector/disable'].includes(path)) return
    if (path === 'sync-connector/run' && !canRun.value) return
    if (path === 'sync-connector/test' && !canTest.value) return
    if (path === 'sync-connector/disable' && !canDisable.value) return
    const id = selectedId(selectedIdValue.value)
    if (id === null || selectedConnector.value === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择同步连接')
      )
      return
    }
    const row = selectedConnector.value, scope = scopeVersion, request = connectorRequest
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction(path, { id })
      if (!currentRow(row, scope, request)) return
      ElMessage.success(success)
      await loadConnectors()
      if (path === 'sync-connector/run') {
        if (disposed || scope !== scopeVersion) return
        await loadRuns()
        if (disposed || scope !== scopeVersion) return
        await loadFailedOutbox()
      }
    } catch (error: unknown) {
      if (!disposed && scope === scopeVersion) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableConnector(row: SandIamSyncConnectorRow): Promise<void> {
    if (disposed || loading.value || connectorLoading.value || !canDisable.value || row.status !== 1 ||
      !currentRow(row, scopeVersion, connectorRequest)) return
    const scope = scopeVersion, request = connectorRequest
    loading.value = true
    try {
      try {
        await ElMessageBox.confirm(
          `确认停用「${row.name}」吗？运行中的同步会以后端冲突提示为准。`,
          '停用同步连接',
          { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
        )
      } catch { return }
      if (connectorLoading.value || !canDisable.value || row.status !== 1 || !currentRow(row, scope, request)) return
      requestError.value = null
      await postSandIamAction('sync-connector/disable', { id: row.id })
      if (!currentRow(row, scope, request) || !canDisable.value) return
      ElMessage.success('已停用')
      await loadConnectors()
    } catch (error: unknown) {
      if (currentRow(row, scope, request) && canDisable.value) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function loadRuns(): Promise<void> {
    if (disposed) return
    const attempt = ++runRequest
    const id = selectedId(selectedIdValue.value)
    if (id === null || !canRuns.value) return
    runLoading.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin('sync-connector/runs', {
        id, page: runPage.value, limit: runPageSize.value
      })
      if (disposed || attempt !== runRequest) return
      runs.value = parseSandIamSyncRuns(result)
      const page = parseSandIamSyncPagination(result)
      runTotal.value = page.total
      runPage.value = page.currentPage
      runPageSize.value = page.pageSize
    } catch (error: unknown) {
      if (disposed || attempt !== runRequest) return
      requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && attempt === runRequest) runLoading.value = false
    }
  }

  /**
   * 只拉 failed 出站事件。请求不带载荷字段，解析器也不读取密文。
   */
  function isCurrentOutboxRequest(version: number, connectorId: number): boolean {
    return !disposed && version === outboxRequestVersion && selectedId(selectedIdValue.value) === connectorId
  }

  async function loadFailedOutbox(): Promise<void> {
    await loadFailedOutboxPage(true)
  }

  async function loadFailedOutboxPage(allowFallback: boolean): Promise<void> {
    const id = selectedId(selectedIdValue.value)
    if (disposed || id === null || !canRuns.value) return
    const version = ++outboxRequestVersion
    outboxLoading.value = true
    failedOutbox.value = []
    failedOutboxConnectorId = null
    requestError.value = null
    try {
      const result = await getSandIamAdmin('sync-connector/outbox', {
        id, state: 'failed', page: outboxPage.value, limit: outboxPageSize.value
      })
      if (!isCurrentOutboxRequest(version, id) || !canRuns.value) return
      const rows = parseSandIamSyncOutboxRows(result)
      const page = parseSandIamSyncPagination(result)
      outboxTotal.value = page.total
      outboxPageSize.value = page.pageSize
      const lastPage = Math.max(1, Math.ceil(page.total / page.pageSize))
      if (allowFallback && rows.length === 0 && page.currentPage > lastPage) {
        outboxPage.value = lastPage
        await loadFailedOutboxPage(false)
        return
      }
      outboxPage.value = page.currentPage
      failedOutbox.value = rows
      failedOutboxConnectorId = id
    } catch (error: unknown) {
      if (!isCurrentOutboxRequest(version, id)) return
      requestError.value = describeSandIamError(error)
      failedOutbox.value = []
      failedOutboxConnectorId = null
    } finally {
      if (isCurrentOutboxRequest(version, id)) outboxLoading.value = false
    }
  }

  /**
   * 精确重试单条失败事件。postSandIamAction 每次生成新的 X-Request-Id，
   * 只提交连接 id 与 outbox_id，不回传任何载荷。
   */
  async function retryFailedOutbox(row: SandIamSyncOutboxRow): Promise<void> {
    if (disposed || outboxLoading.value) return
    const id = failedOutboxConnectorId
    if (
      id === null ||
      id !== selectedId(selectedIdValue.value) ||
      !canRun.value ||
      row.state !== 'failed' ||
      !failedOutbox.value.includes(row)
    ) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_SYNC_OUTBOX_NOT_RETRYABLE: 只有失败的出站事件可以重试')
      )
      return
    }
    const version = ++outboxRequestVersion
    outboxLoading.value = true
    requestError.value = null
    try {
      await postSandIamAction('sync-connector/outbox-retry', { id, outbox_id: row.id })
      if (!isCurrentOutboxRequest(version, id)) return
      ElMessage.success('已重新排队')
      await loadFailedOutbox()
    } catch (error: unknown) {
      if (!isCurrentOutboxRequest(version, id)) return
      requestError.value = describeSandIamError(error)
    } finally {
      if (isCurrentOutboxRequest(version, id)) outboxLoading.value = false
    }
  }

  watch(
    selectedIdValue,
    () => {
      scopeVersion++
      cancelSettings()
      configText.value = ''
      runRequest++
      runPage.value = 1
      runTotal.value = 0
      runLoading.value = false
      outboxRequestVersion++
      outboxPage.value = 1
      outboxTotal.value = 0
      failedOutboxConnectorId = null
      outboxLoading.value = false
      runs.value = []
      failedOutbox.value = []
    },
    { flush: 'sync' }
  )

  watch(applicationId, () => {
    scopeVersion++
    cancelSettings()
    connectorRequest++
    runRequest++
    connectorPage.value = 1
    connectorTotal.value = 0
    connectorLoading.value = false
    selectedIdValue.value = ''
    outboxRequestVersion++
    outboxPage.value = 1
    outboxTotal.value = 0
    failedOutbox.value = []
    failedOutboxConnectorId = null
    outboxLoading.value = false
    connectors.value = []
    runs.value = []
    runPage.value = 1
    runTotal.value = 0
    runLoading.value = false
    requestError.value = null
    viewState.value = 'idle'
  }, { flush: 'sync' })

  watch(connectors, () => {
    if (settingsRow && !connectors.value.includes(settingsRow)) cancelSettings()
  }, { flush: 'sync' })

  onScopeDispose(() => { disposed = true; scopeVersion++; cancelSettings(); connectorRequest++; runRequest++ })

  onMounted(() => {
    void loadApplications()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">用户同步</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          按接入应用创建同步连接。连接信息在保存后不会再次显示。失败后可在同页查看失败出站事件并按事件重试，页面不会展示或索取载荷密文。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号暂时不能查看同步连接。请联系管理员开通用户同步管理范围。"
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
        v-else-if="viewState === 'empty'"
        class="mb-4"
        type="info"
        :closable="false"
        title="当前没有数据"
        description="该接入应用还没有同步连接。这与没有权限或未连接生产目录不同。"
      />

      <ElForm label-width="180px" class="mb-4">
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canIndex" :loading="connectorLoading" @click="loadConnectors">
            加载连接
          </ElButton>
        </ElFormItem>
        <ElFormItem label="连接名称">
          <ElInput v-model="name" :disabled="loading" />
        </ElFormItem>
        <ElFormItem label="系统代码">
          <ElInput v-model="code" :disabled="loading" />
        </ElFormItem>
        <ElFormItem label="驱动">
          <ElSelect v-model="driverCode" :disabled="loading">
            <ElOption label="postgresql（仅入站）" value="postgresql" />
            <ElOption label="microsoft_graph" value="microsoft_graph" />
            <ElOption label="google_workspace" value="google_workspace" />
            <ElOption label="keycloak" value="keycloak" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="方向">
          <ElSelect v-model="direction" :disabled="loading">
            <ElOption label="外部写入本系统" value="inbound" />
            <ElOption label="本系统写出" value="outbound" />
            <ElOption label="双向" value="bidirectional" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canSave" :loading="loading" @click="createConnector">
            新建连接
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="connectorLoading" :data="connectors" border stripe empty-text="暂无同步连接">
        <ElTableColumn label="同步连接名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="140">
          <template #default>{{ selectedApplicationName() }}</template>
        </ElTableColumn>
        <ElTableColumn label="方向" min-width="140">
          <template #default="scope">{{ syncDirectionLabel(scope.row.direction) }}</template>
        </ElTableColumn>
        <ElTableColumn label="驱动" min-width="140">
          <template #default="scope">{{ scope.row.driver_code }}</template>
        </ElTableColumn>
        <ElTableColumn label="配置状态" min-width="160">
          <template #default="scope">
            {{ syncConfigLabel(scope.row.config_configured, scope.row.config_version) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="最近同步" min-width="160">
          <template #default="scope">{{ scope.row.last_sync_time ?? '尚未同步' }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="90">
          <template #default="scope">{{ scope.row.status === 1 ? '正常' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="160" fixed="right">
          <template #default="scope">
            <ElButton size="small" @click="selectedIdValue = String(scope.row.id)">选择</ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable || connectorLoading || loading"
              @click="disableConnector(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <ElPagination
        v-if="connectorTotal > 0"
        v-model:current-page="connectorPage"
        v-model:page-size="connectorPageSize"
        :total="connectorTotal"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="loadConnectors"
        @size-change="connectorPage = 1; loadConnectors()"
      />
      <h3 class="mt-8 text-base">连接配置与执行</h3>
      <ElForm label-width="180px" class="mb-4">
        <ElFormItem label="已选连接">
          <ElSelect v-model="selectedIdValue" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in connectors"
              :key="String(row.id)"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="canUpdate" label="保护设置">
          <ElButton :disabled="loading || connectorLoading || !selectedConnector" @click="beginSettings">
            编辑保护设置
          </ElButton>
        </ElFormItem>
        <template v-if="settingsDraft && canUpdate">
          <ElFormItem label="连接名称">
            <ElInput v-model="settingsDraft.name" :maxlength="128" :disabled="loading" />
          </ElFormItem>
          <ElFormItem label="缺失保护时长（小时）">
            <ElInputNumber v-model="settingsDraft.missing_protection_hours" :min="1" :max="720" :precision="0" :disabled="loading" />
          </ElFormItem>
          <ElFormItem label="停用比例阈值（%）">
            <ElInputNumber v-model="settingsDraft.disable_threshold_percent" :min="1" :max="100" :precision="0" :disabled="loading" />
          </ElFormItem>
          <ElFormItem label="冲突策略">
            <ElSelect v-model="settingsDraft.conflict_policy" :disabled="loading">
              <ElOption label="标记冲突，等待人工处理" value="manual" />
              <ElOption label="拒绝并使本次运行失败" value="reject" />
              <ElOption label="来源字段优先" value="source_wins" />
              <ElOption label="本地字段优先" value="local_wins" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <p class="text-sm text-gray-500">来源缺失超过保护窗口且通过比例检查后才可停用，超过阈值会中止并等待确认。字段优先策略只作用于已声明的来源字段。</p>
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" :disabled="loading || connectorLoading || !selectedConnector" :loading="loading" @click="saveSettings">保存保护设置</ElButton>
            <ElButton :disabled="loading" @click="cancelSettings">取消</ElButton>
          </ElFormItem>
        </template>
        <ElFormItem label="连接配置">
          <ElInput
            v-model="configText"
            type="textarea"
            :rows="6"
            placeholder="只写不读。重新打开看不到原值。可含 group_map。"
          />
        </ElFormItem>
        <ElFormItem label="显示名称权威">
          <ElSelect v-model="displayNameAuthority">
            <ElOption label="以来源为准" value="source" />
            <ElOption label="以本地为准" value="local" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="用户组权威">
          <ElSelect v-model="groupAuthority">
            <ElOption label="以来源为准" value="source" />
            <ElOption label="以本地为准" value="local" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canConfigure || connectorLoading || !selectedConnector" :loading="loading" @click="configureConnector">
            保存配置
          </ElButton>
          <ElButton
            :disabled="!canTest || connectorLoading || !selectedConnector"
            :loading="loading"
            @click="act('sync-connector/test', '已预检')"
          >
            测试连接
          </ElButton>
          <ElButton
            :disabled="!canRun || connectorLoading || !selectedConnector"
            :loading="loading"
            @click="act('sync-connector/run', '已保存')"
          >
            手动执行
          </ElButton>
          <ElButton :disabled="!canRuns" :loading="runLoading" @click="loadRuns"
            >查看运行记录</ElButton
          >
          <ElButton
            :disabled="!canRuns"
            :loading="loading || outboxLoading"
            @click="loadFailedOutbox"
          >
            查看失败出站
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="runLoading" :data="runs" border stripe empty-text="先选择连接并加载运行记录">
        <ElTableColumn label="开始时间" min-width="160">
          <template #default="scope">{{ scope.row.start_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="结束时间" min-width="160">
          <template #default="scope">{{ scope.row.finish_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="结果" min-width="90">
          <template #default="scope">{{ syncRunStateLabel(scope.row.state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="读取/写出" min-width="120">
          <template #default="scope">{{ scope.row.pulled }} / {{ scope.row.pushed }}</template>
        </ElTableColumn>
        <ElTableColumn label="新增/更新" min-width="120">
          <template #default="scope">{{ scope.row.created }} / {{ scope.row.updated }}</template>
        </ElTableColumn>
        <ElTableColumn label="缺失/停用/冲突" min-width="160">
          <template #default="scope">
            {{ scope.row.missing }} / {{ scope.row.disabled }} / {{ scope.row.conflict }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="错误码" min-width="200">
          <template #default="scope">{{ scope.row.error_code || '—' }}</template>
        </ElTableColumn>
      </ElTable>

      <ElPagination
        v-if="runTotal > 0"
        v-model:current-page="runPage"
        v-model:page-size="runPageSize"
        :total="runTotal"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="loadRuns"
        @size-change="runPage = 1; loadRuns()"
      />
      <h3 class="mt-8 text-base">失败出站事件</h3>
      <ElAlert
        v-if="inboundOnlySelected"
        class="mb-4"
        type="warning"
        :closable="false"
        title="纯入站连接不能重试出站"
        description="当前连接只接收外部写入，没有可写出的外部目录。失败出站事件不能重新排队。"
      />
      <ElAlert
        v-if="selectedRunRunning"
        class="mb-4"
        type="warning"
        :closable="false"
        title="同步仍在执行"
        description="同一连接同一应用只允许一个运行中任务。执行结束前重试会被拒绝。"
      />
      <ElTable
        v-loading="outboxLoading"
        :data="failedOutbox"
        border
        stripe
        empty-text="先选择连接并加载失败出站事件"
      >
        <ElTableColumn label="事件标识" min-width="180">
          <template #default="scope">{{ scope.row.event_id }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="90">
          <template #default="scope">{{ syncOutboxOperationLabel(scope.row.operation) }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="90">
          <template #default="scope">{{ syncOutboxStateLabel(scope.row.state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="尝试次数" min-width="90">
          <template #default="scope">{{ scope.row.attempt_count }}</template>
        </ElTableColumn>
        <ElTableColumn label="错误码" min-width="220">
          <template #default="scope">{{ scope.row.error_code || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="时间" min-width="160">
          <template #default="scope">{{ scope.row.time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="100" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="scope.row.state === 'failed'"
              size="small"
              :disabled="!canRun"
              :loading="loading || outboxLoading"
              @click="retryFailedOutbox(scope.row)"
            >
              重试
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination
        v-if="outboxTotal > 0"
        v-model:current-page="outboxPage"
        v-model:page-size="outboxPageSize"
        :total="outboxTotal"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="loadFailedOutbox"
        @size-change="outboxPage = 1; loadFailedOutbox()"
      />
    </ElCard>
  </div>
</template>
