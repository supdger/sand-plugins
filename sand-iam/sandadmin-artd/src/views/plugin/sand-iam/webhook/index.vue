<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
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

  function applicationName(id: number): string {
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  /**
   * 一次性密钥只留在当前弹窗；关闭后立即丢掉，不写 URL / 本地存储 / 日志。
   */
  function clearSecret(): void {
    issuedSecret.value = ''
    secretReplayHint.value = ''
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

  async function loadWebhooks(): Promise<void> {
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      const result = await getSandIamAdmin(
        'webhook/index',
        application === null ? {} : { application_id: application }
      )
      const rows = parseSandIamWebhooks(result)
      webhooks.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      webhooks.value = []
    } finally {
      loading.value = false
    }
  }

  function beginCreate(): void {
    editingId.value = null
    code.value = ''
    name.value = ''
    url.value = ''
    eventTypes.value = []
    timeoutSeconds.value = 10
    maxAttempts.value = 5
  }

  function beginEdit(row: SandIamWebhookRow): void {
    editingId.value = row.id
    applicationId.value = String(row.application_id)
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
  function handleSecretResult(value: unknown, replayMessage: string): void {
    const parsed = parseWebhookSecretResult(value)
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
    const application = selectedId(applicationId.value)
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
    loading.value = true
    requestError.value = null
    try {
      if (editingId.value === null) {
        const result = await postSandIamAction('webhook/save', {
          application_id: application,
          code: code.value.trim(),
          name: name.value.trim(),
          url: url.value.trim(),
          event_types: eventTypes.value,
          timeout_seconds: timeoutSeconds.value,
          max_attempts: maxAttempts.value
        })
        handleSecretResult(
          result,
          '请求已处理；签名密钥不会再次显示。请重新保存或轮换以获得新密钥，不要猜测旧值。'
        )
        ElMessage.success('已保存')
      } else {
        await postSandIamAction('webhook/update', {
          id: editingId.value,
          name: name.value.trim(),
          url: url.value.trim(),
          event_types: eventTypes.value,
          timeout_seconds: timeoutSeconds.value,
          max_attempts: maxAttempts.value
        })
        ElMessage.success('已保存')
      }
      await loadWebhooks()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function rotateSecret(row: SandIamWebhookRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认轮换「${row.name}」的签名密钥吗？旧密钥立即失效，接收方必须改用本次明文。`,
        '轮换签名密钥',
        { type: 'warning', confirmButtonText: '确认轮换', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const result = await postSandIamAction('webhook/secret/rotate', { id: row.id })
      handleSecretResult(
        result,
        '请求已处理；新签名密钥不会再次显示。请再次轮换以获得新密钥，不要重放旧值。'
      )
      ElMessage.success('已保存')
      await loadWebhooks()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableWebhook(row: SandIamWebhookRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？停用后不再投递，密钥不会再次显示。`,
        '停用事件通知',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('webhook/disable', { id: row.id })
      ElMessage.success('已停用')
      await loadWebhooks()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
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
    if (issuedSecret.value === '') {
      clearSecret()
      done()
      return
    }
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
      clearSecret()
      done()
    } catch {
      // 保持一次性密钥，直到操作者确认已保存。
    }
  }

  onMounted(() => {
    void loadApplications()
    void loadWebhooks()
  })
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
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadWebhooks"
            >加载通知</ElButton
          >
          <ElButton @click="beginCreate">新建</ElButton>
        </ElFormItem>
        <ElFormItem v-if="editingId === null" label="通知标识">
          <ElInput v-model="code" placeholder="创建后不可改" />
        </ElFormItem>
        <ElFormItem v-else label="通知标识">
          <span>{{ code }}（创建后不可改）</span>
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
            :disabled="editingId === null ? !canSave : !canUpdate"
            :loading="loading"
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
            <ElButton size="small" @click="beginEdit(scope.row)">编辑</ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              :disabled="!canRotate"
              @click="rotateSecret(scope.row)"
            >
              轮换密钥
            </ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable"
              @click="disableWebhook(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElDialog
      v-model="secretDialogOpen"
      title="请安全保存签名密钥"
      width="620px"
      :close-on-click-modal="false"
      :before-close="closeSecretDialog"
      @closed="clearSecret"
    >
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
        <ElButton @click="secretDialogOpen = false">
          {{ issuedSecret === '' ? '知道了' : '已安全保存，关闭' }}
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>
