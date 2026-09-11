<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    parseSandIamSyncConnectors,
    parseSandIamSyncRuns,
    syncConfigLabel,
    syncDirectionLabel,
    syncRunStateLabel,
    type SandIamSyncConnectorRow,
    type SandIamSyncDirection,
    type SandIamSyncRunRow
  } from '../api/syncConnectorContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:sync_connector:index'))
  const canSave = computed(() => hasAuth('sand_iam:sync_connector:save'))
  const canDisable = computed(() => hasAuth('sand_iam:sync_connector:disable'))
  const canConfigure = computed(() => hasAuth('sand_iam:sync_connector:configure'))
  const canTest = computed(() => hasAuth('sand_iam:sync_connector:test'))
  const canRun = computed(() => hasAuth('sand_iam:sync_run:run'))
  const canRuns = computed(() => hasAuth('sand_iam:sync_run:index'))

  const loading = ref(false)
  const applications = ref<SandIamResourceRow[]>([])
  const connectors = ref<SandIamSyncConnectorRow[]>([])
  const runs = ref<SandIamSyncRunRow[]>([])
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
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      connectors.value = parseSandIamSyncConnectors(
        await getSandIamAdmin('sync-connector/index', { application_id: application })
      )
      viewState.value = connectors.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      connectors.value = []
    } finally {
      loading.value = false
    }
  }

  async function createConnector(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null || name.value.trim() === '' || code.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写连接名称和系统代码')
      )
      return
    }
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
      ElMessage.success('已保存')
      name.value = ''
      code.value = ''
      await loadConnectors()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
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
    const id = selectedId(selectedIdValue.value)
    const config = parseConfigObject()
    if (id === null || config === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_SYNC_CONFIGURATION_INVALID: 请选择连接并填写完整配置对象，原值不会回显')
      )
      return
    }
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
      ElMessage.success('已保存')
      configText.value = ''
      await loadConnectors()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function act(path: string, success: string): Promise<void> {
    const id = selectedId(selectedIdValue.value)
    if (id === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择同步连接')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction(path, { id })
      ElMessage.success(success)
      await loadConnectors()
      if (path === 'sync-connector/run') await loadRuns()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableConnector(row: SandIamSyncConnectorRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？运行中的同步会以后端冲突提示为准。`,
        '停用同步连接',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    selectedIdValue.value = String(row.id)
    await act('sync-connector/disable', '已停用')
  }

  async function loadRuns(): Promise<void> {
    const id = selectedId(selectedIdValue.value)
    if (id === null || !canRuns.value) return
    loading.value = true
    requestError.value = null
    try {
      runs.value = parseSandIamSyncRuns(await getSandIamAdmin('sync-connector/runs', { id }))
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

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
          按接入应用创建同步连接。连接信息在保存后不会再次显示，请在同步记录中查看处理结果。
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
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadConnectors">
            加载连接
          </ElButton>
        </ElFormItem>
        <ElFormItem label="连接名称">
          <ElInput v-model="name" />
        </ElFormItem>
        <ElFormItem label="系统代码">
          <ElInput v-model="code" />
        </ElFormItem>
        <ElFormItem label="驱动">
          <ElSelect v-model="driverCode">
            <ElOption label="postgresql（仅入站）" value="postgresql" />
            <ElOption label="microsoft_graph" value="microsoft_graph" />
            <ElOption label="google_workspace" value="google_workspace" />
            <ElOption label="keycloak" value="keycloak" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="方向">
          <ElSelect v-model="direction">
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

      <ElTable v-loading="loading" :data="connectors" border stripe empty-text="暂无同步连接">
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
              :disabled="!canDisable"
              @click="disableConnector(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

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
          <ElButton :disabled="!canConfigure" :loading="loading" @click="configureConnector">
            保存配置
          </ElButton>
          <ElButton
            :disabled="!canTest"
            :loading="loading"
            @click="act('sync-connector/test', '已预检')"
          >
            测试连接
          </ElButton>
          <ElButton
            :disabled="!canRun"
            :loading="loading"
            @click="act('sync-connector/run', '已保存')"
          >
            手动执行
          </ElButton>
          <ElButton :disabled="!canRuns" :loading="loading" @click="loadRuns"
            >查看运行记录</ElButton
          >
        </ElFormItem>
      </ElForm>

      <ElTable :data="runs" border stripe empty-text="先选择连接并加载运行记录">
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
    </ElCard>
  </div>
</template>
