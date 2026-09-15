<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    describeRegistrationTokenIssueError,
    parseRegistrationHosts,
    parseRegistrationScopes,
    parseRegistrationTokenIssue,
    parseRegistrationTokenPage,
    registrationTokenStatusLabel,
    summarizeHosts,
    summarizeScopes,
    type SandIamRegistrationTokenRow
  } from '../api/oauthRegistrationContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:oauth_registration_token:index'))
  const canIssue = computed(() => hasAuth('sand_iam:oauth_registration_token:issue'))
  const canRevoke = computed(() => hasAuth('sand_iam:oauth_registration_token:revoke'))

  const loading = ref(false)
  const saving = ref(false)
  const applicationLoading = ref(false)
  const page = ref(1)
  const pageSize = ref(20)
  const total = ref(0)
  const issuedOwner = ref('')
  let disposed = false
  let scopeVersion = 0
  let listVersion = 0
  let searchVersion = 0
  const applications = ref<SandIamResourceRow[]>([])
  const tokens = ref<SandIamRegistrationTokenRow[]>([])
  const applicationId = ref('')
  const tokenName = ref('')
  const hostsText = ref('app.example.com')
  const scopesText = ref('openid profile')
  const ttlHours = ref(24)
  const maxUses = ref(1)
  const requestError = ref<SandIamRequestError | null>(null)
  const issuedToken = ref('')
  const secretReplayHint = ref('')
  const secretDialogOpen = ref(false)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  watch(applicationId, () => {
    scopeVersion++
    listVersion++
    page.value = 1
    tokens.value = []
    total.value = 0
    tokenName.value = ''
    hostsText.value = 'app.example.com'
    scopesText.value = 'openid profile'
    ttlHours.value = 24
    maxUses.value = 1
    requestError.value = null
    loading.value = false
    viewState.value = 'idle'
  }, { flush: 'sync' })
  watch([page, pageSize], () => {
    listVersion++
    tokens.value = []
    void loadTokens()
  })

  function validApplication(): boolean {
    return !applicationLoading.value && applications.value.some(row => row.id === selectedId(applicationId.value))
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

  function applicationName(id: number): string {
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  function clearSecret(): void {
    issuedToken.value = ''
    secretReplayHint.value = ''
    issuedOwner.value = ''
  }

  async function loadApplications(keywords = ''): Promise<void> {
    if (disposed || (!canIndex.value && !canIssue.value && !canRevoke.value)) return
    const version = ++searchVersion
    applicationId.value = ''
    applications.value = []
    applicationLoading.value = true
    try {
      const rows = listRows(
        await listSandIamResource('application', { page: 1, limit: 100, keywords: keywords.trim() })
      )
      if (!disposed && version === searchVersion) applications.value = rows
    } catch (error: unknown) {
      if (!disposed && version === searchVersion) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === searchVersion) applicationLoading.value = false
    }
  }

  /**
   * 必须先按名称选接入应用。后端 index 要求 application_id。
   */
  async function loadTokens(): Promise<void> {
    if (disposed || !canIndex.value || !validApplication()) return
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    if (!canIndex.value) return
    loading.value = true
    const version = ++listVersion
    const scope = scopeVersion
    const current = () => !disposed && scope === scopeVersion && version === listVersion
    requestError.value = null
    try {
      const result = parseRegistrationTokenPage(
        await getSandIamAdmin('oauth-registration-token/index', {
          application_id: application, page: page.value, limit: pageSize.value
        })
      )
      if (!current()) return
      tokens.value = result.rows
      total.value = result.total
      viewState.value = result.rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (!current()) return
      requestError.value = describeSandIamError(error)
      tokens.value = []
    } finally {
      if (current()) loading.value = false
    }
  }

  /**
   * 签发成功才弹明文；重试或缺失 token 只引导重新签发，不回放旧值。
   */
  async function issueToken(): Promise<void> {
    if (disposed || saving.value || !canIssue.value || !validApplication() || issuedToken.value !== '') return
    const application = selectedId(applicationId.value)
    const issueError = describeRegistrationTokenIssueError(
      tokenName.value,
      hostsText.value,
      scopesText.value,
      ttlHours.value,
      maxUses.value
    )
    if (application === null || issueError !== null) {
      requestError.value = describeSandIamError(
        new Error(issueError ?? 'SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写签发参数')
      )
      return
    }
    saving.value = true
    const scope = scopeVersion
    const owner = `${applicationName(application)}（应用 ${application}） / ${tokenName.value.trim()}`
    requestError.value = null
    try {
      const parsed = parseRegistrationTokenIssue(
        await postSandIamAction('oauth-registration-token/issue', {
          application_id: application,
          name: tokenName.value.trim(),
          allowed_redirect_hosts: parseRegistrationHosts(hostsText.value),
          allowed_scopes: parseRegistrationScopes(scopesText.value),
          ttl_hours: ttlHours.value,
          max_uses: maxUses.value
        })
      )
      if (disposed) return
      issuedOwner.value = owner
      if (parsed === null) {
        issuedToken.value = ''
        secretReplayHint.value =
          '请求已处理；动态注册令牌不会再次显示。请重新签发以获得新令牌，不要猜测旧值。'
      } else {
        issuedToken.value = parsed.token
        secretReplayHint.value = ''
      }
      secretDialogOpen.value = true
      ElMessage.success('已保存')
      if (scope === scopeVersion) await loadTokens()
    } catch (error: unknown) {
      if (!disposed && scope === scopeVersion) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  /**
   * 撤销后不能恢复。成功只显示“已撤销”，失败走后端稳定码。
   */
  async function revoke(row: SandIamRegistrationTokenRow): Promise<void> {
    if (disposed || saving.value || loading.value || !canRevoke.value || !validApplication() ||
      !tokens.value.includes(row) || row.application_id !== selectedId(applicationId.value) || row.status !== 1) return
    const scope = scopeVersion
    const query = listVersion
    const current = () => !disposed && scope === scopeVersion && query === listVersion &&
      tokens.value.includes(row) && row.application_id === selectedId(applicationId.value) && row.status === 1
    saving.value = true
    try {
      await ElMessageBox.confirm(
        `确认撤销「${row.name}」吗？撤销后不能恢复，客户端必须改用新令牌。`,
        '撤销动态注册令牌',
        { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' }
      )
    } catch {
      saving.value = false
      return
    }
    if (!current() || !canRevoke.value) { saving.value = false; return }
    requestError.value = null
    try {
      await postSandIamAction('oauth-registration-token/revoke', { id: row.id })
      if (!current()) return
      ElMessage.success('已撤销')
      await loadTokens()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function closeSecretDialog(done: () => void): Promise<void> {
    const token = issuedToken.value
    if (issuedToken.value === '') {
      clearSecret()
      done()
      return
    }
    try {
      await ElMessageBox.confirm(
        '关闭后动态注册令牌不会再次显示。确认已交给指定客户端了吗？',
        '确认关闭令牌窗口',
        { type: 'warning', confirmButtonText: '已保存，关闭', cancelButtonText: '继续查看' }
      )
      if (disposed || issuedToken.value !== token) return
      clearSecret()
      done()
    } catch {
      // 保持一次性令牌，直到操作者确认已保存。
    }
  }

  onMounted(() => {
    void loadApplications()
  })
  onScopeDispose(() => {
    disposed = true
    listVersion++
    searchVersion++
    clearSecret()
    secretDialogOpen.value = false
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">动态注册令牌</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          给接入应用签发 OAuth
          动态注册初始访问令牌。默认列表只显示用途名称、所属应用、回调地址摘要、可用范围、剩余次数、到期和状态。令牌只展示一次，请立即安全保存。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号暂时不能查看动态注册令牌。请联系平台管理员开通动态注册令牌管理范围。"
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
        title="当前没有令牌"
        description="该接入应用还没有动态注册令牌。这与没有权限不同。"
      />
      <ElAlert
        class="mb-4"
        type="info"
        :closable="false"
        title="公开客户端与机密客户端"
        description="Web 客户端通常是机密客户端，需要客户端密钥；原生客户端通常是公开客户端，不能使用 none 以外的认证方式。本页只签发初始访问令牌，不会在管理页做匿名注册。"
      />

      <ElForm label-width="170px" class="mb-4">
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
          <ElButton type="primary" :disabled="!canIndex" :loading="loading" @click="loadTokens">
            加载令牌
          </ElButton>
        </ElFormItem>
        <ElFormItem label="用途名称">
          <ElInput v-model="tokenName" placeholder="例如：移动端动态注册" />
        </ElFormItem>
        <ElFormItem label="允许回调主机">
          <ElInput
            v-model="hostsText"
            type="textarea"
            :rows="2"
            placeholder="每行一个主机名，例如 app.example.com"
          />
        </ElFormItem>
        <ElFormItem label="允许范围">
          <ElInput v-model="scopesText" placeholder="openid profile" />
        </ElFormItem>
        <ElFormItem label="有效小时">
          <ElInputNumber v-model="ttlHours" :min="1" :max="168" />
        </ElFormItem>
        <ElFormItem label="最大使用次数">
          <ElInputNumber v-model="maxUses" :min="1" :max="1000" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canIssue || saving || issuedToken !== '' || !validApplication()" :loading="saving" @click="issueToken">签发</ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="tokens" border stripe empty-text="暂无可见令牌">
        <ElTableColumn label="令牌名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="160">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="回调主机摘要" min-width="180">
          <template #default="scope">{{
            summarizeHosts(scope.row.allowed_redirect_hosts)
          }}</template>
        </ElTableColumn>
        <ElTableColumn label="范围摘要" min-width="160">
          <template #default="scope">{{ summarizeScopes(scope.row.allowed_scopes) }}</template>
        </ElTableColumn>
        <ElTableColumn label="剩余次数" min-width="100">
          <template #default="scope">{{ scope.row.remaining_uses }}</template>
        </ElTableColumn>
        <ElTableColumn label="到期时间" min-width="180">
          <template #default="scope">{{ scope.row.expire_time ?? '未设置' }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ registrationTokenStatusLabel(scope.row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="120" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canRevoke || saving"
              @click="revoke(scope.row)"
            >
              撤销
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-model:current-page="page" v-model:page-size="pageSize" :total="total"
        :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next"
        @size-change="page = 1" />
    </ElCard>

    <ElDialog
      v-model="secretDialogOpen"
      title="请安全保存动态注册令牌"
      width="620px"
      :close-on-click-modal="false"
      :before-close="closeSecretDialog"
    >
      <p>{{ issuedOwner }}</p>
      <ElAlert
        v-if="issuedToken !== ''"
        type="warning"
        :closable="false"
        title="明文仅在当前窗口展示一次"
        description="请交给指定客户端并安全保存。不要写入文档、聊天、URL 或截图文件名。"
      />
      <ElAlert
        v-else
        type="info"
        :closable="false"
        title="令牌不会再次显示"
        :description="secretReplayHint"
      />
      <ElInput
        v-if="issuedToken !== ''"
        class="mt-4"
        :model-value="issuedToken"
        readonly
        type="textarea"
        :rows="3"
      />
    </ElDialog>
  </div>
</template>
