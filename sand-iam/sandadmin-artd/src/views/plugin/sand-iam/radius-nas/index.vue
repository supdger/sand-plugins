<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
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

  async function loadApplications(): Promise<void> {
    try {
      applications.value = listRows(
        await listSandIamResource('application', { page: 1, limit: 100 })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  /**
   * 列表只解析 secret_configured，不把密文带进表格。
   */
  async function loadDevices(): Promise<void> {
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      devices.value = parseRadiusNasRows(
        await getSandIamAdmin(
          'radius-nas/index',
          application === null ? {} : { application_id: application }
        )
      )
      viewState.value = devices.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      devices.value = []
    } finally {
      loading.value = false
    }
  }

  function beginCreate(): void {
    editingId.value = null
    name.value = ''
    sourceCidr.value = ''
    accountingEnabled.value = false
  }

  function beginEdit(row: SandIamRadiusNasRow): void {
    editingId.value = row.id
    applicationId.value = String(row.application_id)
    name.value = row.name
    sourceCidr.value = row.source_cidr
    accountingEnabled.value = row.accounting_enabled
  }

  /**
   * 创建设备与轮换密钥分开。保存成功不等于密钥已配置。
   */
  async function saveDevice(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null || name.value.trim() === '' || sourceCidr.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写设备名称和规范来源网段')
      )
      return
    }
    loading.value = true
    requestError.value = null
    successHint.value = ''
    try {
      if (editingId.value === null) {
        await postSandIamAction('radius-nas/save', {
          application_id: application,
          name: name.value.trim(),
          source_cidr: sourceCidr.value.trim(),
          accounting_enabled: accountingEnabled.value
        })
        successHint.value = '已保存。共享密钥需单独设置，本页不会回显密钥。'
      } else {
        await postSandIamAction('radius-nas/update', {
          id: editingId.value,
          name: name.value.trim(),
          source_cidr: sourceCidr.value.trim(),
          accounting_enabled: accountingEnabled.value
        })
        successHint.value = '已保存。来源网段重叠或应用已停用会由后端拒绝。'
      }
      ElMessage.success('已保存')
      await loadDevices()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableDevice(row: SandIamRadiusNasRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？停用后不能再设置共享密钥，已配置密钥不会回显。`,
        '停用 RADIUS 设备',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('radius-nas/disable', { id: row.id })
      ElMessage.success('已停用')
      successHint.value = '已停用。'
      await loadDevices()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  /**
   * 共享密钥只写不读；请求体不进入 URL。成功只看后端 secret_configured。
   */
  async function configureSecret(): Promise<void> {
    if (secretTargetId.value === null || sharedSecret.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择设备并填写共享密钥')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const configured = parseRadiusSecretConfigured(
        await postSandIamAction('radius-nas/configure', {
          id: secretTargetId.value,
          shared_secret: sharedSecret.value
        })
      )
      sharedSecret.value = ''
      if (!configured) {
        requestError.value = describeSandIamError(
          new Error('SAND_IAM_VALIDATION_ERROR: 后端未确认密钥已配置，请重新保存，不要猜测旧值')
        )
        return
      }
      ElMessage.success('已保存')
      successHint.value = '共享密钥已保存，旧密钥立即失效且不会再次展示。'
      await loadDevices()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadApplications()
    void loadDevices()
  })
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
            :loading="loading"
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
          <ElButton :disabled="!canConfigure || secretTargetId === null" @click="configureSecret">
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
    </ElCard>
  </div>
</template>
