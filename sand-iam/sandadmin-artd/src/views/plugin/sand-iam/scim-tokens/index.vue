<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
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
  const providers = ref<SandIamResourceRow[]>([])
  const applications = ref<SandIamResourceRow[]>([])
  const tokens = ref<ScimTokenRow[]>([])
  const providerId = ref('')
  const applicationId = ref('')
  const tokenName = ref('')
  const expireTime = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const issuedToken = ref('')
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')

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
    try {
      const [providerResult, applicationResult] = await Promise.all([
        listSandIamResource('identity-provider', { page: 1, limit: 100 }),
        listSandIamResource('application', { page: 1, limit: 100 })
      ])
      providers.value = listRows(providerResult)
      applications.value = listRows(applicationResult)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  async function loadTokens(): Promise<void> {
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
      const rows = listRows(result)
        .map((item) => parseToken(item))
        .filter((item): item is ScimTokenRow => item !== null)
      tokens.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      tokens.value = []
    } finally {
      loading.value = false
    }
  }

  async function issueToken(): Promise<void> {
    const provider = selectedId(providerId.value)
    const application = selectedId(applicationId.value)
    if (provider === null || application === null || tokenName.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_SCIM_TOKEN_NAME_INVALID: 请选择身份源、接入应用并填写用途名称')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const result = await postSandIamAction('scim/token/issue', {
        provider_id: provider,
        application_id: application,
        name: tokenName.value.trim(),
        expire_time: expireTime.value.trim() === '' ? null : expireTime.value
      })
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const token = isRecord(payload) && typeof payload.token === 'string' ? payload.token : ''
      if (token === '') {
        throw new Error('签发响应没有一次性令牌，请不要重试猜测。')
      }
      issuedToken.value = token
      ElMessage.success('已保存')
      await loadTokens()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function revoke(row: ScimTokenRow): Promise<void> {
    const provider = selectedId(providerId.value)
    const application = selectedId(applicationId.value)
    if (provider === null || application === null) return
    try {
      await ElMessageBox.confirm(
        `确认撤销「${row.name}」吗？撤销后不能恢复，目录同步必须改用新令牌。`,
        '撤销 SCIM 令牌',
        { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('scim/token/revoke', {
        provider_id: provider,
        application_id: application,
        token_id: row.id
      })
      ElMessage.success('已撤销')
      await loadTokens()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
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
          给已配置为 SCIM
          的身份源签发目录同步令牌。默认列表只显示用途名称、状态、到期和最近使用；令牌明文只在签发成功后出现一次，刷新或关闭后无法再看。
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
          <ElSelect v-model="providerId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in providers"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名身份源'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
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
          <ElButton :disabled="!canIssue" @click="issueToken">签发</ElButton>
        </ElFormItem>
        <ElFormItem v-if="issuedToken !== ''" label="一次性令牌">
          <ElInput :model-value="issuedToken" type="textarea" readonly />
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
              :disabled="!canRevoke"
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
