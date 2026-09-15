<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    parseRadiusNasRows,
    parseRadiusSecretConfigured,
    type SandIamRadiusNasRow
  } from '../api/radiusNasContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:radius_nas:index'))
  const canSave = computed(() => hasAuth('sand_iam:radius_nas:save'))
  const canUpdate = computed(() => hasAuth('sand_iam:radius_nas:update'))
  const canDisable = computed(() => hasAuth('sand_iam:radius_nas:disable'))
  const canConfigure = computed(() => hasAuth('sand_iam:radius_nas:configure'))

  const loading = ref(false)
  const saving = ref(false)
  const applicationLoading = ref(false)
  const page = ref(1)
  const pageSize = ref(20)
  const total = ref(0)
  let disposed = false
  let scopeVersion = 0
  let listVersion = 0
  let searchVersion = 0
  let secretVersion = 0
  let editingRow: SandIamRadiusNasRow | null = null
  const applications = ref<SandIamResourceRow[]>([])
  const devices = ref<SandIamRadiusNasRow[]>([])
  const applicationId = ref('')
  const name = ref('')
  const sourceCidr = ref('')
  const accountingEnabled = ref(false)
  const editingId = ref<number | null>(null)
  const sharedSecret = ref('')
  const secretTargetId = ref<number | null>(null)
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const successHint = ref('')
  watch(applicationId, () => {
    scopeVersion++
    listVersion++
    devices.value = []
    resetDraft()
    secretTargetId.value = null
    sharedSecret.value = ''
    page.value = 1
    total.value = 0
    loading.value = false
    requestError.value = null
    successHint.value = ''
  }, { flush: 'sync' })
  watch(secretTargetId, () => { secretVersion++; sharedSecret.value = '' }, { flush: 'sync' })
  watch([page, pageSize], () => { void loadDevices() }, { flush: 'sync' })
  function currentRow(row: SandIamRadiusNasRow): boolean {
    return !loading.value && devices.value.includes(row) &&
      (applicationId.value === '' || row.application_id === selectedId(applicationId.value))
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

  async function loadApplications(keywords = ''): Promise<void> {
    if (disposed || (!canIndex.value && !canSave.value)) return
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
   * 列表只解析 secret_configured，不把密文带进表格。
   */
  async function loadDevices(): Promise<void> {
    if (disposed || !canIndex.value) return
    const scope = scopeVersion, version = ++listVersion
    const current = () => !disposed && scope === scopeVersion && version === listVersion
    secretTargetId.value = null
    resetDraft()
    loading.value = true
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      const result = await getSandIamAdmin(
          'radius-nas/index',
          { ...(application === null ? {} : { application_id: application }), page: page.value, limit: pageSize.value }
        )
      if (!current()) return
      devices.value = parseRadiusNasRows(result)
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      total.value = isRecord(payload) && typeof payload.total === 'number' ? payload.total : devices.value.length
      viewState.value = devices.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (!current()) return
      requestError.value = describeSandIamError(error)
      devices.value = []
    } finally {
      if (current()) loading.value = false
    }
  }

  function beginCreate(): void {
    if (disposed || saving.value || !canSave.value) return
    resetDraft()
  }
  function resetDraft(): void {
    editingRow = null
    editingId.value = null
    name.value = ''
    sourceCidr.value = ''
    accountingEnabled.value = false
  }

  function beginEdit(row: SandIamRadiusNasRow): void {
    if (disposed || saving.value || !canUpdate.value || !currentRow(row)) return
    editingRow = row
    editingId.value = row.id
    name.value = row.name
    sourceCidr.value = row.source_cidr
    accountingEnabled.value = row.accounting_enabled
  }

  /**
   * 创建设备与轮换密钥分开。保存成功不等于密钥已配置。
   */
  async function saveDevice(): Promise<void> {
    if (disposed || saving.value || (editingId.value === null ? !canSave.value : !canUpdate.value)) return
    const row = editingRow, id = editingId.value
    if (id !== null && (row === null || row.id !== id || !currentRow(row))) return
    const application = row?.application_id ?? selectedId(applicationId.value)
    if (id === null && (applicationLoading.value || !applications.value.some(item => item.id === application))) return
    if (application === null || name.value.trim() === '' || sourceCidr.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写设备名称和规范来源网段')
      )
      return
    }
    saving.value = true
    const scope = scopeVersion, version = listVersion
    const current = () => !disposed && scope === scopeVersion && version === listVersion
    requestError.value = null
    successHint.value = ''
    try {
      if (id === null) {
        await postSandIamAction('radius-nas/save', {
          application_id: application,
          name: name.value.trim(),
          source_cidr: sourceCidr.value.trim(),
          accounting_enabled: accountingEnabled.value
        })
        if (!current()) return
        successHint.value = '已保存。共享密钥需单独设置，本页不会回显密钥。'
      } else {
        await postSandIamAction('radius-nas/update', {
          id,
          name: name.value.trim(),
          source_cidr: sourceCidr.value.trim(),
          accounting_enabled: accountingEnabled.value
        })
        if (!current()) return
        successHint.value = '已保存。来源网段重叠或应用已停用会由后端拒绝。'
      }
      ElMessage.success('已保存')
      await loadDevices()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function disableDevice(row: SandIamRadiusNasRow): Promise<void> {
    if (disposed || saving.value || !canDisable.value || !currentRow(row) || row.status !== 1) return
    const scope = scopeVersion, version = listVersion
    const current = () => !disposed && scope === scopeVersion && version === listVersion && currentRow(row)
    saving.value = true
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？停用后不能再设置共享密钥，已配置密钥不会回显。`,
        '停用 RADIUS 设备',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      saving.value = false
      return
    }
    if (!current() || !canDisable.value || row.status !== 1) { saving.value = false; return }
    requestError.value = null
    try {
      await postSandIamAction('radius-nas/disable', { id: row.id })
      if (!current()) return
      ElMessage.success('已停用')
      successHint.value = '已停用。'
      await loadDevices()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  /**
   * 共享密钥只写不读；请求体不进入 URL。成功只看后端 secret_configured。
   */
  async function configureSecret(): Promise<void> {
    const row = devices.value.find(item => item.id === secretTargetId.value)
    if (disposed || saving.value || !canConfigure.value || !row || !currentRow(row) || row.status !== 1) return
    if (secretTargetId.value === null || sharedSecret.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择设备并填写共享密钥')
      )
      return
    }
    saving.value = true
    const scope = scopeVersion, version = listVersion, targetVersion = secretVersion, secret = sharedSecret.value
    const current = () => !disposed && scope === scopeVersion && version === listVersion &&
      targetVersion === secretVersion && secretTargetId.value === row.id && currentRow(row)
    requestError.value = null
    try {
      const configured = parseRadiusSecretConfigured(
        await postSandIamAction('radius-nas/configure', {
          id: row.id,
          shared_secret: secret
        })
      )
      if (!current()) return
      if (sharedSecret.value === secret) sharedSecret.value = ''
      if (!configured) {
        requestError.value = describeSandIamError(
          new Error('SAND_IAM_VALIDATION_ERROR: 后端未确认密钥已配置，请重新保存，不要猜测旧值')
        )
        return
      }
      ElMessage.success('已保存')
      successHint.value = '共享密钥已保存，旧密钥立即失效且不会再次展示。'
      devices.value = devices.value.map(item => item === row ? { ...item, secret_configured: true } : item)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  onMounted(() => {
    void loadApplications()
    void loadDevices()
  })
  onScopeDispose(() => { disposed = true; sharedSecret.value = ''; secretTargetId.value = null; resetDraft() })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">RADIUS 网络设备</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          登记可发起 RADIUS
          认证的网络设备。默认列表只显示设备名称、所属应用、来源网段、密钥已配置/未配置和状态。共享密钥、密文、版本、报文和请求指纹不进入本页。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看 RADIUS 设备。请联系平台管理员开通设备查看权限。"
      />
      <ElAlert
        class="mb-4"
        type="warning"
        :closable="false"
        title="当前协议能力"
        description="当前 RADIUS 只支持 User-Name + User-Password。已启用 MFA 的账号会被拒绝。管理路由没有独立测试接口，本页不会伪造成功连通。"
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
      <ElAlert
        v-else-if="viewState === 'empty'"
        class="mb-4"
        type="info"
        :closable="false"
        title="当前没有设备"
        description="所选范围内还没有 RADIUS 网络设备。这与没有权限不同。"
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
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadDevices"
            >加载设备</ElButton
          >
          <ElButton @click="beginCreate">新建</ElButton>
        </ElFormItem>
        <ElFormItem label="设备名称">
          <ElInput v-model="name" placeholder="例如：办公区交换机" />
        </ElFormItem>
        <ElFormItem label="来源网段">
          <ElInput v-model="sourceCidr" placeholder="10.20.0.0/24" />
        </ElFormItem>
        <ElFormItem label="记账">
          <ElSwitch v-model="accountingEnabled" />
        </ElFormItem>
        <ElFormItem>
          <ElButton
            type="primary"
            :disabled="editingId === null ? !canSave : !canUpdate"
            :loading="saving"
            @click="saveDevice"
          >
            {{ editingId === null ? '创建设备' : '保存修改' }}
          </ElButton>
        </ElFormItem>
        <ElFormItem label="共享密钥">
          <ElInput
            v-model="sharedSecret"
            type="password"
            show-password
            autocomplete="new-password"
            placeholder="只写不读，轮换后旧密钥立即失效"
          />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canConfigure || saving || secretTargetId === null" @click="configureSecret">
            设置或轮换共享密钥
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="devices" border stripe empty-text="暂无可见设备">
        <ElTableColumn label="设备名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="160">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="来源网段" min-width="160">
          <template #default="scope">{{ scope.row.source_cidr }}</template>
        </ElTableColumn>
        <ElTableColumn label="密钥状态" min-width="120">
          <template #default="scope">{{
            scope.row.secret_configured ? '已配置' : '未配置'
          }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ scope.row.status === 1 ? '已启用' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="220" fixed="right">
          <template #default="scope">
            <ElButton size="small" @click="beginEdit(scope.row)">编辑</ElButton>
            <ElButton size="small" @click="secretTargetId = scope.row.id">选择设置密钥</ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable"
              @click="disableDevice(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-model:current-page="page" v-model:page-size="pageSize" :total="total"
        :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @size-change="page = 1" />
    </ElCard>
  </div>
</template>
