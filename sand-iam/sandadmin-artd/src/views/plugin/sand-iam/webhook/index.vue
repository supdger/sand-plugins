<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import {
    parseSandIamWebhooks,
    parseWebhookSecretResult,
    SAND_IAM_WEBHOOK_EVENTS,
    summarizeWebhookEvents,
    summarizeWebhookUrl,
    type SandIamWebhookRow
  } from '../api/delegationContracts'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:webhook:index'))
  const canSave = computed(() => hasAuth('sand_iam:webhook:save'))
  const canUpdate = computed(() => hasAuth('sand_iam:webhook:update'))
  const canDisable = computed(() => hasAuth('sand_iam:webhook:disable'))
  const canRotate = computed(() => hasAuth('sand_iam:webhook:secret_rotate'))

  const loading = ref(false)
  const saving = ref(false)
  const applicationLoading = ref(false)
  const page = ref(1)
  const pageSize = ref(20)
  const total = ref(0)
  let disposed = false
  let listVersion = 0
  let applicationVersion = 0
  let draftVersion = 0
  let secretVersion = 0
  let editingRow: SandIamWebhookRow | null = null
  const closingSecret = ref(false)
  const secretOwner = ref('')
  const applications = ref<SandIamResourceRow[]>([])
  const webhooks = ref<SandIamWebhookRow[]>([])
  const applicationId = ref('')
  const code = ref('')
  const name = ref('')
  const url = ref('')
  const eventTypes = ref<string[]>([])
  const timeoutSeconds = ref(10)
  const maxAttempts = ref(5)
  const editingId = ref<number | null>(null)
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const issuedSecret = ref('')
  const secretDialogOpen = ref(false)
  const secretReplayHint = ref('')
  watch(applicationId, () => { page.value = 1 }, { flush: 'sync' })
  watch([applicationId, page, pageSize], () => { void loadWebhooks() }, { flush: 'sync' })
  watch(canIndex, () => { void loadWebhooks() }, { flush: 'sync' })
  watch([code, name, url, eventTypes, timeoutSeconds, maxAttempts], () => { draftVersion++ }, { deep: true, flush: 'sync' })
  function currentRow(row: SandIamWebhookRow): boolean {
    return !disposed && !loading.value && webhooks.value.includes(row) &&
      (applicationId.value === '' || row.application_id === selectedId(applicationId.value))
  }

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    if (isRecord(value.data) && Array.isArray(value.data.data)) return value.data.data.filter(isRecord)
    return []
  }

  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function applicationName(id: number): string {
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  /**
   * 一次性密钥只留在当前弹窗；关闭后立即丢掉，不写 URL / 本地存储 / 日志。
   */
  function clearSecret(): void {
    secretVersion++
    issuedSecret.value = ''
    secretReplayHint.value = ''
    secretOwner.value = ''
    secretDialogOpen.value = false
  }

  async function loadApplications(keywords = ''): Promise<void> {
    if (disposed || (!canIndex.value && !canSave.value)) return
    const version = ++applicationVersion
    applicationLoading.value = true
    applicationId.value = ''
    const list = listVersion
    applications.value = []
    try {
      const rows = listRows(
        await listSandIamResource('application', { page: 1, limit: 100, keywords: keywords.trim() })
      )
      if (!disposed && version === applicationVersion) applications.value = rows
    } catch (error: unknown) {
      if (!disposed && version === applicationVersion && list === listVersion) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === applicationVersion) applicationLoading.value = false
    }
  }

  async function loadWebhooks(): Promise<void> {
    const version = ++listVersion
    resetDraft()
    webhooks.value = []
    total.value = 0
    requestError.value = null
    if (disposed || !canIndex.value) { loading.value = false; return }
    loading.value = true
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      const result = await getSandIamAdmin(
        'webhook/index',
        { ...(application === null ? {} : { application_id: application }), page: page.value, limit: pageSize.value }
      )
      if (disposed || version !== listVersion || !canIndex.value) return
      const rows = parseSandIamWebhooks(result)
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      total.value = isRecord(payload) && typeof payload.total === 'number' ? payload.total : rows.length
      webhooks.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (disposed || version !== listVersion) return
      requestError.value = describeSandIamError(error)
      webhooks.value = []
    } finally {
      if (!disposed && version === listVersion) loading.value = false
    }
  }

  function beginCreate(): void {
    if (disposed || saving.value || !canSave.value) return
    resetDraft()
  }
  function resetDraft(): void {
    draftVersion++
    editingRow = null
    editingId.value = null
    code.value = ''
    name.value = ''
    url.value = ''
    eventTypes.value = []
    timeoutSeconds.value = 10
    maxAttempts.value = 5
  }

  function beginEdit(row: SandIamWebhookRow): void {
    if (disposed || saving.value || !canUpdate.value || !currentRow(row)) return
    draftVersion++
    editingRow = row
    editingId.value = row.id
    code.value = row.code
    name.value = row.name
    url.value = row.url
    eventTypes.value = [...row.event_types]
    timeoutSeconds.value = row.timeout_seconds
    maxAttempts.value = row.max_attempts
  }

  /**
   * 成功才弹密钥；secret_available=false 只引导重新保存或轮换，不回显 secret。
   */
  function handleSecretResult(value: unknown, replayMessage: string, owner: string): void {
    if (disposed) return
    const parsed = parseWebhookSecretResult(value)
    secretVersion++
    secretOwner.value = owner
    if (!parsed.available) {
      issuedSecret.value = ''
      secretReplayHint.value = replayMessage
      secretDialogOpen.value = true
      return
    }
    issuedSecret.value = parsed.secret ?? ''
    secretReplayHint.value = ''
    secretDialogOpen.value = true
  }

  async function saveWebhook(): Promise<void> {
    const row = editingRow, id = editingId.value
    if (disposed || saving.value || (id === null ? !canSave.value : !canUpdate.value)) return
    if (id === null && issuedSecret.value !== '') return
    if (id !== null && (!row || row.id !== id || !currentRow(row))) return
    const application = row?.application_id ?? selectedId(applicationId.value)
    if (id === null && (applicationLoading.value || !applications.value.some(item => item.id === application && item.status !== 2))) return
    if (application === null || name.value.trim() === '' || url.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('请选择接入应用并填写名称和 HTTPS 接收地址')
      )
      return
    }
    if (eventTypes.value.length === 0) {
      requestError.value = describeSandIamError(new Error('请至少订阅一类事件'))
      return
    }
    saving.value = true
    const list = listVersion, draft = draftVersion
    const current = () => !disposed && list === listVersion && draft === draftVersion
    const owner = `${applicationName(application)}（应用 ${String(application)}） · ${name.value.trim()}`
    requestError.value = null
    try {
      if (id === null) {
        const result = await postSandIamAction('webhook/save', {
          application_id: application,
          code: code.value.trim(),
          name: name.value.trim(),
          url: url.value.trim(),
          event_types: [...eventTypes.value],
          timeout_seconds: timeoutSeconds.value,
          max_attempts: maxAttempts.value
        })
        handleSecretResult(
          result,
          '请求已处理；签名密钥不会再次显示。请找到原通知并轮换以获得新密钥。',
          owner
        )
        if (!current()) return
        ElMessage.success('已保存')
      } else {
        await postSandIamAction('webhook/update', {
          id,
          name: name.value.trim(),
          url: url.value.trim(),
          event_types: [...eventTypes.value],
          timeout_seconds: timeoutSeconds.value,
          max_attempts: maxAttempts.value
        })
        if (!current()) return
        ElMessage.success('已保存')
      }
      await loadWebhooks()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function rotateSecret(row: SandIamWebhookRow): Promise<void> {
    if (disposed || saving.value || issuedSecret.value !== '' || !canRotate.value || !currentRow(row) || row.status !== 1) return
    const list = listVersion
    const current = () => !disposed && list === listVersion && currentRow(row)
    const owner = `${applicationName(row.application_id)}（应用 ${String(row.application_id)}） · ${row.name}`
    saving.value = true
    try {
      await ElMessageBox.confirm(
        `确认轮换「${row.name}」的签名密钥吗？旧密钥立即失效，接收方必须改用本次明文。`,
        '轮换签名密钥',
        { type: 'warning', confirmButtonText: '确认轮换', cancelButtonText: '取消' }
      )
    } catch {
      saving.value = false
      return
    }
    if (!current() || !canRotate.value || row.status !== 1 || issuedSecret.value !== '') { saving.value = false; return }
    requestError.value = null
    try {
      const result = await postSandIamAction('webhook/secret/rotate', { id: row.id })
      handleSecretResult(
        result,
        '请求已处理；新签名密钥不会再次显示。请再次轮换以获得新密钥，不要重放旧值。',
        owner
      )
      if (!current()) return
      ElMessage.success('已保存')
      await loadWebhooks()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function disableWebhook(row: SandIamWebhookRow): Promise<void> {
    if (disposed || saving.value || !canDisable.value || !currentRow(row) || row.status !== 1) return
    const list = listVersion
    const current = () => !disposed && list === listVersion && currentRow(row)
    saving.value = true
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？停用后不再投递，密钥不会再次显示。`,
        '停用事件通知',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      saving.value = false
      return
    }
    if (!current() || !canDisable.value || row.status !== 1) { saving.value = false; return }
    requestError.value = null
    try {
      await postSandIamAction('webhook/disable', { id: row.id })
      if (!current()) return
      ElMessage.success('已停用')
      await loadWebhooks()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function copySecret(): Promise<void> {
    if (issuedSecret.value === '') return
    try {
      await navigator.clipboard.writeText(issuedSecret.value)
      ElMessage.success('密钥已复制，请立即交给接收服务保存')
    } catch {
      ElMessage.error('无法复制密钥，请手动复制后安全保存')
    }
  }

  async function closeSecretDialog(done: () => void): Promise<void> {
    if (disposed || closingSecret.value) return
    const version = secretVersion
    if (issuedSecret.value === '') {
      clearSecret()
      done()
      return
    }
    closingSecret.value = true
    try {
      await ElMessageBox.confirm(
        '关闭后签名密钥不会再次显示。确认已保存到接收服务了吗？',
        '确认关闭密钥窗口',
        {
          type: 'warning',
          confirmButtonText: '已保存，关闭',
          cancelButtonText: '继续查看'
        }
      )
      if (disposed || version !== secretVersion) return
      clearSecret()
      done()
    } catch {
      // 保持一次性密钥，直到操作者确认已保存。
    } finally { closingSecret.value = false }
  }

  onMounted(() => {
    void loadApplications()
    void loadWebhooks()
  })
  onScopeDispose(() => { disposed = true; listVersion++; applicationVersion++; clearSecret(); resetDraft() })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">事件通知</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          为接入应用订阅身份与安全事件。默认列表只显示名称、应用、地址摘要、事件摘要、密钥版本和状态；完整
          URL 只在表单里出现。签名密钥只展示一次。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号暂时不能查看事件通知。请联系平台管理员开通事件通知管理范围。"
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
        description="所选范围内还没有事件通知。这与没有权限不同。"
      />

      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable remote :remote-method="loadApplications" :loading="applicationLoading" clearable placeholder="按名称搜索">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadWebhooks"
            >加载通知</ElButton
          >
          <ElButton :disabled="!canSave || saving" @click="beginCreate">新建</ElButton>
        </ElFormItem>
        <ElFormItem v-if="editingId === null" label="通知标识">
          <ElInput v-model="code" placeholder="创建后不可改" />
        </ElFormItem>
        <ElFormItem v-else label="通知标识">
          <span>{{ code }}（创建后不可改）</span>
        </ElFormItem>
        <ElFormItem v-if="editingRow" label="编辑归属">
          <span>{{ applicationName(editingRow.application_id) }}（应用 {{ editingRow.application_id }}）</span>
        </ElFormItem>
        <ElFormItem label="通知名称">
          <ElInput v-model="name" />
        </ElFormItem>
        <ElFormItem label="HTTPS 接收地址">
          <ElInput v-model="url" placeholder="https://example.com/hooks/sand-iam" />
        </ElFormItem>
        <ElFormItem label="订阅事件">
          <ElSelect v-model="eventTypes" multiple filterable placeholder="从事件目录选择">
            <ElOption
              v-for="event in SAND_IAM_WEBHOOK_EVENTS"
              :key="event.code"
              :label="event.name"
              :value="event.code"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="超时秒数">
          <ElInputNumber v-model="timeoutSeconds" :min="1" :max="30" />
        </ElFormItem>
        <ElFormItem label="最大尝试次数">
          <ElInputNumber v-model="maxAttempts" :min="1" :max="10" />
        </ElFormItem>
        <ElFormItem>
          <ElButton
            type="primary"
            :disabled="saving || (editingId === null ? !canSave || issuedSecret !== '' : !canUpdate)"
            :loading="saving"
            @click="saveWebhook"
          >
            {{ editingId === null ? '创建通知' : '保存修改' }}
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="webhooks" border stripe empty-text="暂无可见通知">
        <ElTableColumn label="通知名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="160">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="地址摘要" min-width="200">
          <template #default="scope">{{ summarizeWebhookUrl(scope.row.url) }}</template>
        </ElTableColumn>
        <ElTableColumn label="事件摘要" min-width="180">
          <template #default="scope">{{ summarizeWebhookEvents(scope.row.event_types) }}</template>
        </ElTableColumn>
        <ElTableColumn label="密钥版本" min-width="100">
          <template #default="scope">{{ scope.row.secret_version }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ scope.row.status === 1 ? '正常' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="220" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canUpdate || saving" @click="beginEdit(scope.row)">编辑</ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              :disabled="!canRotate || saving || issuedSecret !== ''"
              @click="rotateSecret(scope.row)"
            >
              轮换密钥
            </ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable || saving"
              @click="disableWebhook(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-model:current-page="page" v-model:page-size="pageSize" :total="total"
        :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @size-change="page = 1" />
    </ElCard>

    <ElDialog
      v-model="secretDialogOpen"
      title="请安全保存签名密钥"
      width="620px"
      :close-on-click-modal="false"
      :before-close="closeSecretDialog"
    >
      <p>{{ secretOwner }}</p>
      <ElAlert
        v-if="issuedSecret !== ''"
        type="warning"
        :closable="false"
        title="明文仅在当前窗口展示一次"
        description="请复制并交给接收服务；不要写入文档、聊天或截图。关闭后无法从页面恢复。"
      />
      <ElAlert
        v-else
        type="info"
        :closable="false"
        title="密钥不会再次显示"
        :description="secretReplayHint"
      />
      <ElInput
        v-if="issuedSecret !== ''"
        class="mt-4"
        :model-value="issuedSecret"
        readonly
        type="textarea"
        :rows="4"
      />
      <template #footer>
        <ElButton v-if="issuedSecret !== ''" type="primary" @click="copySecret">复制密钥</ElButton>
        <ElButton :loading="closingSecret" @click="closeSecretDialog(() => { secretDialogOpen = false })">
          {{ issuedSecret === '' ? '知道了' : '已安全保存，关闭' }}
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>
