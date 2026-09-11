<script setup lang="ts">
  import '../components/sandIamPage.css'
  /**
   * 每个客户主体一条审计保留策略。浏览器不提供清除确认摘要，也不执行清除。
   */
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  interface RetentionRow {
    readonly id: number
    readonly organization_id: number
    readonly archive_after_days: number
    readonly retention_days: number
    readonly purge_enabled: boolean
    readonly alert_window_seconds: number
    readonly alert_failure_threshold: number
    readonly status: number
  }

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:audit_retention_policy:index'))
  const canSave = computed(() => hasAuth('sand_iam:audit_retention_policy:save'))
  const canUpdate = computed(() => hasAuth('sand_iam:audit_retention_policy:update'))
  const canDisable = computed(() => hasAuth('sand_iam:audit_retention_policy:disable'))

  const loading = ref(false)
  const organizations = ref<SandIamResourceRow[]>([])
  const policies = ref<RetentionRow[]>([])
  const organizationId = ref('')
  const archiveAfterDays = ref(90)
  const retentionDays = ref(365)
  const purgeEnabled = ref(false)
  const alertWindowSeconds = ref(300)
  const alertFailureThreshold = ref(5)
  const editingId = ref<number | null>(null)
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const successHint = ref('')
  const showAdvanced = ref(false)

  /** 列表与详情接口都可能包一层对象，先收窄再读字段。 */
  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  /** 同时接受裸数组和 `{ data: [] }`，避免把未知壳当成空列表。 */
  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  /** 下拉值是字符串；只有正整数才当作客户主体编号提交。 */
  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  /** 名称一律用已冻结 organization 列表回显，不猜未返回字段。 */
  function organizationName(id: number): string {
    const row = organizations.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联客户主体名称暂不可用'
  }

  /**
   * 只保留列表需要的天数、开关和状态；不把清除确认摘要带进页面。
   */
  function parsePolicy(value: unknown): RetentionRow | null {
    if (!isRecord(value)) return null
    const id = value.id
    const organization = value.organization_id
    const archive = value.archive_after_days
    const retention = value.retention_days
    const status = value.status
    if (
      typeof id !== 'number' ||
      typeof organization !== 'number' ||
      typeof archive !== 'number' ||
      typeof retention !== 'number' ||
      typeof status !== 'number'
    ) {
      return null
    }
    return {
      id,
      organization_id: organization,
      archive_after_days: archive,
      retention_days: retention,
      purge_enabled: value.purge_enabled === true,
      alert_window_seconds:
        typeof value.alert_window_seconds === 'number' ? value.alert_window_seconds : 300,
      alert_failure_threshold:
        typeof value.alert_failure_threshold === 'number' ? value.alert_failure_threshold : 5,
      status
    }
  }

  /** 客户主体选项只读 organization 列表的 name，供筛选和回显。 */
  async function loadOrganizations(): Promise<void> {
    try {
      organizations.value = listRows(
        await listSandIamResource('organization', { page: 1, limit: 100 })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  /** 按可选客户主体过滤；空列表与无权分开展示。 */
  async function loadPolicies(): Promise<void> {
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const organization = selectedId(organizationId.value)
      const result = await getSandIamAdmin(
        'audit-retention-policy/index',
        organization === null ? {} : { organization_id: organization }
      )
      const rows = listRows(result)
        .map((item) => parsePolicy(item))
        .filter((item): item is RetentionRow => item !== null)
      policies.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      policies.value = []
    } finally {
      loading.value = false
    }
  }

  /** 新建时清空编号，保留与后端默认值一致的天数和告警阈值。 */
  function beginCreate(): void {
    editingId.value = null
    archiveAfterDays.value = 90
    retentionDays.value = 365
    purgeEnabled.value = false
    alertWindowSeconds.value = 300
    alertFailureThreshold.value = 5
  }

  /** 编辑只回填已解析字段，不把清除确认摘要带进表单。 */
  function beginEdit(row: RetentionRow): void {
    editingId.value = row.id
    organizationId.value = String(row.organization_id)
    archiveAfterDays.value = row.archive_after_days
    retentionDays.value = row.retention_days
    purgeEnabled.value = row.purge_enabled
    alertWindowSeconds.value = row.alert_window_seconds
    alertFailureThreshold.value = row.alert_failure_threshold
  }

  /**
   * purge_enabled 必须提交布尔值。页面开关只记录策略意图，不会计算或执行清除。
   */
  async function savePolicy(): Promise<void> {
    const organization = selectedId(organizationId.value)
    if (organization === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择客户主体')
      )
      return
    }
    if (retentionDays.value < archiveAfterDays.value) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_AUDIT_RETENTION_INVALID: 保留期不得短于归档期')
      )
      return
    }
    loading.value = true
    requestError.value = null
    successHint.value = ''
    try {
      const body = {
        organization_id: organization,
        archive_after_days: archiveAfterDays.value,
        retention_days: retentionDays.value,
        purge_enabled: purgeEnabled.value,
        alert_window_seconds: alertWindowSeconds.value,
        alert_failure_threshold: alertFailureThreshold.value
      }
      if (editingId.value === null) {
        await postSandIamAction('audit-retention-policy/save', body)
      } else {
        await postSandIamAction('audit-retention-policy/update', {
          id: editingId.value,
          ...body
        })
      }
      ElMessage.success('已保存')
      successHint.value = '已保存。浏览器不能执行审计清除。'
      await loadPolicies()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  /** 停用走已冻结 disable；本页不会因此执行审计清除。 */
  async function disablePolicy(row: RetentionRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认停用「${organizationName(row.organization_id)}」的审计保留策略吗？历史审计不会在本页被清除。`,
        '停用审计保留策略',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('audit-retention-policy/disable', { id: row.id })
      ElMessage.success('已停用')
      successHint.value = '已停用。'
      await loadPolicies()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadOrganizations()
    void loadPolicies()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">审计保留策略</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          按客户主体配置归档天数、保留天数和告警阈值。默认列表只显示客户主体、归档天数、保留天数、清除策略和状态。浏览器不能计算确认摘要，也不能执行清除。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看审计保留策略。请联系平台管理员开通审计保留策略查看权限。"
      />
      <ElAlert
        class="mb-4"
        type="warning"
        :closable="false"
        title="部署侧未开放清除"
        description="即使打开“记录为允许部署侧清除”，本页也不会执行删除或计算确认摘要。"
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
        title="当前没有策略"
        description="所选范围内还没有审计保留策略。这与没有权限不同。"
      />

      <ElForm label-width="170px" class="mb-4">
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
        <ElFormItem>
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadPolicies"
            >加载策略</ElButton
          >
          <ElButton @click="beginCreate">新建</ElButton>
        </ElFormItem>
        <ElFormItem label="归档天数">
          <ElInputNumber v-model="archiveAfterDays" :min="1" :max="3650" />
        </ElFormItem>
        <ElFormItem label="保留天数">
          <ElInputNumber v-model="retentionDays" :min="1" :max="3650" />
        </ElFormItem>
        <ElFormItem label="清除策略">
          <ElSwitch v-model="purgeEnabled" />
          <p class="mb-0 mt-1 text-xs text-gray-500">只记录策略意图。部署侧未开放浏览器清除。</p>
        </ElFormItem>
        <ElFormItem>
          <ElButton @click="showAdvanced = !showAdvanced">
            {{ showAdvanced ? '收起高级配置' : '打开高级配置' }}
          </ElButton>
        </ElFormItem>
        <template v-if="showAdvanced">
          <ElFormItem label="告警窗口秒数">
            <ElInputNumber v-model="alertWindowSeconds" :min="60" :max="86400" />
          </ElFormItem>
          <ElFormItem label="告警失败次数">
            <ElInputNumber v-model="alertFailureThreshold" :min="2" :max="10000" />
          </ElFormItem>
        </template>
        <ElFormItem>
          <ElButton
            type="primary"
            :disabled="editingId === null ? !canSave : !canUpdate"
            :loading="loading"
            @click="savePolicy"
          >
            {{ editingId === null ? '创建策略' : '保存修改' }}
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="policies" border stripe empty-text="暂无可见策略">
        <ElTableColumn label="客户主体" min-width="180">
          <template #default="scope">{{ organizationName(scope.row.organization_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="归档天数" min-width="100">
          <template #default="scope">{{ scope.row.archive_after_days }}</template>
        </ElTableColumn>
        <ElTableColumn label="保留天数" min-width="100">
          <template #default="scope">{{ scope.row.retention_days }}</template>
        </ElTableColumn>
        <ElTableColumn label="清除策略" min-width="220">
          <template #default>仅记录策略，部署侧未开放清除</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ scope.row.status === 1 ? '已启用' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180" fixed="right">
          <template #default="scope">
            <ElButton size="small" @click="beginEdit(scope.row)">编辑</ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable"
              @click="disablePolicy(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>
