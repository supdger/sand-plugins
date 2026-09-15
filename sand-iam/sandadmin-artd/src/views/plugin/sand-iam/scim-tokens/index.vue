<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  interface ScimTokenRow {
    readonly id: number
    readonly name: string
    readonly status: number
    readonly expire_time: string | null
    readonly last_used_time: string | null
  }

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:scim:token_index'))
  const canIssue = computed(() => hasAuth('sand_iam:scim:token_issue'))
  const canRevoke = computed(() => hasAuth('sand_iam:scim:token_revoke'))

  const loading = ref(false)
  const saving = ref(false)
  const providerLoading = ref(false)
  const applicationLoading = ref(false)
  let disposed = false
  let scopeVersion = 0
  let listVersion = 0
  const searchVersions = { provider: 0, application: 0 }
  const providers = ref<SandIamResourceRow[]>([])
  const applications = ref<SandIamResourceRow[]>([])
  const tokens = ref<ScimTokenRow[]>([])
  const providerId = ref('')
  const applicationId = ref('')
  const tokenName = ref('')
  const expireTime = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const issuedToken = ref('')
  const issuedOwner = ref('')
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  watch([providerId, applicationId], () => {
    scopeVersion++
    listVersion++
    tokens.value = []
    tokenName.value = ''
    expireTime.value = ''
    requestError.value = null
    viewState.value = 'idle'
    loading.value = false
  }, { flush: 'sync' })
  onScopeDispose(() => { disposed = true; scopeVersion++; listVersion++; issuedToken.value = ''; issuedOwner.value = '' })
  function validSelection(): boolean {
    const provider = providers.value.find(row => String(row.id) === providerId.value)
    const application = applications.value.find(row => String(row.id) === applicationId.value)
    return provider !== undefined && application !== undefined && provider.status === 1 &&
      application.status === 1 && provider.organization_id === application.organization_id
  }
  function acknowledgeToken(): void {
    if (!saving.value) { issuedToken.value = ''; issuedOwner.value = '' }
  }

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  /**
   * 兼容宿主拦截器解包一层或两层后的列表：数组、`{data:[]}`、`{data:{data:[]}}`。
   */
  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    if (isRecord(value.data) && Array.isArray(value.data.data)) {
      return value.data.data.filter(isRecord)
    }
    return []
  }

  /**
   * 只保留列表需要的用途名称、状态和两个时间；token / hash 即使误回也不进入行对象。
   */
  function parseToken(value: unknown): ScimTokenRow | null {
    if (!isRecord(value)) return null
    const id = value.id
    const name = value.name
    const status = value.status
    if (
      typeof id !== 'number' ||
      !Number.isInteger(id) ||
      id <= 0 ||
      typeof name !== 'string' ||
      typeof status !== 'number'
    ) {
      return null
    }
    return {
      id,
      name,
      status,
      expire_time: typeof value.expire_time === 'string' ? value.expire_time : null,
      last_used_time: typeof value.last_used_time === 'string' ? value.last_used_time : null
    }
  }

  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  async function loadOptions(): Promise<void> {
    await Promise.all([searchOptions('provider', ''), searchOptions('application', '')])
  }

  async function searchOptions(kind: 'provider' | 'application', keywords: string): Promise<void> {
    const version = ++searchVersions[kind]
    const busy = kind === 'provider' ? providerLoading : applicationLoading
    const options = kind === 'provider' ? providers : applications
    if (kind === 'provider') providerId.value = ''
    else applicationId.value = ''
    options.value = []
    const permission = kind === 'provider' ? 'sand_iam:identity_provider:index' : 'sand_iam:application:index'
    if (disposed || !hasAuth(permission)) return
    busy.value = true
    try {
      const result = await listSandIamResource(kind === 'provider' ? 'identity-provider' : 'application',
        { page: 1, limit: 100, keywords: keywords.trim() })
      if (!disposed && version === searchVersions[kind]) options.value = listRows(result)
    } catch (error: unknown) {
      if (!disposed && version === searchVersions[kind]) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === searchVersions[kind]) busy.value = false
    }
  }

  async function loadTokens(): Promise<void> {
    if (disposed || !canIndex.value || !validSelection()) return
    const version = ++listVersion
    const provider = selectedId(providerId.value)
    const application = selectedId(applicationId.value)
    if (provider === null || application === null) {
      requestError.value = describeSandIamError(new Error('请先按名称选择身份源和接入应用。'))
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin('scim/token/index', {
        provider_id: provider,
        application_id: application
      })
      if (disposed || version !== listVersion) return
      const rows = listRows(result)
        .map((item) => parseToken(item))
        .filter((item): item is ScimTokenRow => item !== null)
      tokens.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (disposed || version !== listVersion) return
      requestError.value = describeSandIamError(error)
      tokens.value = []
    } finally {
      if (!disposed && version === listVersion) loading.value = false
    }
  }

  async function issueToken(): Promise<void> {
    if (disposed || saving.value || !canIssue.value || issuedToken.value !== '' || !validSelection()) return
    const version = scopeVersion
    const providerRow = providers.value.find(row => String(row.id) === providerId.value)
    const applicationRow = applications.value.find(row => String(row.id) === applicationId.value)
    const owner = `${String(providerRow?.name ?? providerId.value)} / ${String(applicationRow?.name ?? applicationId.value)} / ${tokenName.value.trim()}`
    const provider = selectedId(providerId.value)
    const application = selectedId(applicationId.value)
    if (provider === null || application === null || tokenName.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_SCIM_TOKEN_NAME_INVALID: 请选择身份源、接入应用并填写用途名称')
      )
      return
    }
    saving.value = true
    requestError.value = null
    try {
      const result = await postSandIamAction('scim/token/issue', {
        provider_id: provider,
        application_id: application,
        name: tokenName.value.trim(),
        expire_time: expireTime.value.trim() === '' ? null : expireTime.value
      })
      if (disposed) return
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const token = isRecord(payload) && typeof payload.token === 'string' ? payload.token : ''
      if (token === '') {
        throw new Error('签发响应没有一次性令牌，请不要重试猜测。')
      }
      issuedToken.value = token
      issuedOwner.value = owner
      if (version !== scopeVersion) return
      ElMessage.success('已保存')
      await loadTokens()
    } catch (error: unknown) {
      if (!disposed && version === scopeVersion) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function revoke(row: ScimTokenRow): Promise<void> {
    const version = scopeVersion
    const request = listVersion
    const current = (): boolean => !disposed && version === scopeVersion &&
      request === listVersion && tokens.value.includes(row)
    if (disposed || saving.value || !canRevoke.value || row.status !== 1 || !current() || !validSelection()) return
    const provider = selectedId(providerId.value)
    const application = selectedId(applicationId.value)
    if (provider === null || application === null) return
    saving.value = true
    try {
      await ElMessageBox.confirm(
        `确认撤销「${row.name}」吗？撤销后不能恢复，目录同步必须改用新令牌。`,
        '撤销 SCIM 令牌',
        { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' }
      )
    } catch {
      saving.value = false
      return
    }
    if (!current() || !canRevoke.value || row.status !== 1 || !validSelection()) {
      saving.value = false
      return
    }
    requestError.value = null
    try {
      await postSandIamAction('scim/token/revoke', {
        provider_id: provider,
        application_id: application,
        token_id: row.id
      })
      if (!current()) return
      ElMessage.success('已撤销')
      await loadTokens()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  onMounted(() => {
    void loadOptions()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">SCIM 供给令牌</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          给已挂载到接入应用的身份源签发目录同步令牌，具体可用范围由后端校验。默认列表只显示用途名称、状态、到期和最近使用；令牌明文只在签发成功后出现一次，刷新或关闭后无法再看。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看目录同步令牌。请联系平台管理员开通目录同步令牌查看权限。"
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
        description="该身份源在所选接入应用下还没有有效 SCIM 令牌。这与没有权限不同。"
      />

      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="身份源">
          <ElSelect v-model="providerId" filterable remote :remote-method="(keywords: string) => searchOptions('provider', keywords)"
            :loading="providerLoading" clearable placeholder="输入身份源名称搜索">
            <ElOption
              v-for="row in providers"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名身份源'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable remote :remote-method="(keywords: string) => searchOptions('application', keywords)"
            :loading="applicationLoading" clearable placeholder="输入应用名称搜索">
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
          <ElInput v-model="tokenName" placeholder="例如：生产目录同步" />
        </ElFormItem>
        <ElFormItem label="到期时间">
          <ElDatePicker v-model="expireTime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canIssue || saving || issuedToken !== ''" @click="issueToken">签发</ElButton>
        </ElFormItem>
        <ElFormItem v-if="issuedToken !== ''" label="一次性令牌">
          <ElInput :model-value="issuedToken" type="textarea" readonly />
          <p>令牌归属：{{ issuedOwner }}</p>
          <ElButton :disabled="saving" @click="acknowledgeToken">我已安全保存，清除令牌</ElButton>
          <p class="mb-0 mt-1 text-xs text-gray-500"
            >关闭后无法再显示。不要写入日志、URL 或截图文件名。</p
          >
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="tokens" border stripe empty-text="暂无可见令牌">
        <ElTableColumn label="用途名称" min-width="180">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ scope.row.status === 1 ? '正常' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="到期时间" min-width="180">
          <template #default="scope">{{ scope.row.expire_time ?? '未设置' }}</template>
        </ElTableColumn>
        <ElTableColumn label="最近使用" min-width="180">
          <template #default="scope">{{ scope.row.last_used_time ?? '尚未使用' }}</template>
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
    </ElCard>
  </div>
</template>
