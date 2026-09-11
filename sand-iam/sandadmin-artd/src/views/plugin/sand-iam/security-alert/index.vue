<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import {
    parseSecurityAlertRows,
    securityAlertSeverityLabel,
    securityAlertStatusLabel,
    type SandIamSecurityAlertRow
  } from '../api/securityAlertContracts'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:security_alert:index'))
  const canResolve = computed(() => hasAuth('sand_iam:security_alert:resolve'))

  const loading = ref(false)
  const applications = ref<SandIamResourceRow[]>([])
  const organizations = ref<SandIamResourceRow[]>([])
  const alerts = ref<SandIamSecurityAlertRow[]>([])
  const organizationId = ref('')
  const applicationId = ref('')
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

  function applicationName(id: number | null): string {
    if (id === null) return '客户主体范围'
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  function organizationName(id: number): string {
    const row = organizations.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联客户主体名称暂不可用'
  }

  async function loadOptions(): Promise<void> {
    try {
      const [organizationResult, applicationResult] = await Promise.all([
        listSandIamResource('organization', { page: 1, limit: 100 }),
        listSandIamResource('application', { page: 1, limit: 100 })
      ])
      organizations.value = listRows(organizationResult)
      applications.value = listRows(applicationResult)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  /**
   * 按客户主体或接入应用名称筛选；无权与空数据分开提示。
   */
  async function loadAlerts(): Promise<void> {
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const params: Record<string, string | number> = {}
      const organization = selectedId(organizationId.value)
      const application = selectedId(applicationId.value)
      if (organization !== null) params.organization_id = organization
      if (application !== null) params.application_id = application
      alerts.value = parseSecurityAlertRows(await getSandIamAdmin('security-alert/index', params))
      viewState.value = alerts.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      alerts.value = []
    } finally {
      loading.value = false
    }
  }

  /**
   * 处理结果只由后端决定；已处理的告警不能再次标记。
   */
  async function resolveAlert(row: SandIamSecurityAlertRow): Promise<void> {
    loading.value = true
    requestError.value = null
    successHint.value = ''
    try {
      await postSandIamAction('security-alert/resolve', { id: row.id })
      ElMessage.success('已处理')
      successHint.value = '告警已标记为已处理。'
      await loadAlerts()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadOptions()
    void loadAlerts()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">安全告警</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          查看当前管理范围内的安全告警并标记处理。默认列表只显示客户主体、应用、等级、规则、次数、首次与最近时间和状态；敏感来源信息不会显示在列表中。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看安全告警。请联系平台管理员开通安全告警查看权限。"
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
        title="当前没有告警"
        description="所选范围内没有安全告警。这与没有权限不同。"
      />

      <ElForm label-width="160px" class="mb-4" inline>
        <ElFormItem label="客户主体">
          <ElSelect v-model="organizationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in organizations"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名客户主体'"
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
          <ElButton type="primary" :disabled="!canIndex" :loading="loading" @click="loadAlerts">
            加载告警
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="alerts" border stripe empty-text="暂无可见告警">
        <ElTableColumn label="客户主体" min-width="160">
          <template #default="scope">{{ organizationName(scope.row.organization_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="接入应用" min-width="160">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="告警等级" min-width="100">
          <template #default="scope">{{ securityAlertSeverityLabel(scope.row.severity) }}</template>
        </ElTableColumn>
        <ElTableColumn label="告警规则" min-width="160">
          <template #default="scope">{{ scope.row.rule_code }}</template>
        </ElTableColumn>
        <ElTableColumn label="出现次数" min-width="100">
          <template #default="scope">{{ scope.row.occurrence_count }}</template>
        </ElTableColumn>
        <ElTableColumn label="首次时间" min-width="170">
          <template #default="scope">{{ scope.row.first_seen_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="最近时间" min-width="170">
          <template #default="scope">{{ scope.row.last_seen_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ securityAlertStatusLabel(scope.row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="120" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="scope.row.status !== 'resolved'"
              size="small"
              :disabled="!canResolve"
              @click="resolveAlert(scope.row)"
            >
              标记已处理
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>
