<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import {
    auditActionLabel,
    auditActorLabel,
    auditOutcomeLabel,
    auditResourceTypeLabel,
    auditTimeLabel,
    describeAuditExportRangeError,
    parseSandIamAudit,
    parseSandIamAudits,
    type SandIamAuditRow
  } from '../api/delegationContracts'
  import { describeSandIamError } from '../api/errors'
  import {
    auditApplicationOptions,
    auditFilterEndpoints,
    auditOrganizationOptionsFromApplications,
    type SandIamAuditFilterOption
  } from '../api/auditFilterOptions'
  import { listSandIamResource } from '../api/resource'
  import { downloadSandIamAdminBlob, getSandIamAdmin } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:audit:index'))
  const canRead = computed(() => hasAuth('sand_iam:audit:read'))
  const canExport = computed(() => hasAuth('sand_iam:audit:export'))
  const canIndexOrganization = computed(() => hasAuth('sand_iam:organization:index'))

  const loading = ref(false)
  const organizations = ref<readonly SandIamAuditFilterOption[]>([])
  const applications = ref<readonly SandIamAuditFilterOption[]>([])
  const applicationRows = ref<SandIamResourceRow[]>([])
  const rows = ref<SandIamAuditRow[]>([])
  const organizationId = ref('')
  const applicationId = ref('')
  const actorType = ref('')
  const outcome = ref('')
  const action = ref('')
  const resourceType = ref('')
  const requestId = ref('')
  const fromTime = ref('')
  const toTime = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const applicationOptionError = ref<SandIamRequestError | null>(null)
  const organizationOptionError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const detailOpen = ref(false)
  const detail = ref<SandIamAuditRow | null>(null)

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
    if (id === null) return '—'
    const row = applications.value.find((item) => item.id === id)
    return row?.name ?? '关联应用名称暂不可用'
  }

  const optionError = computed<SandIamRequestError | null>(() => {
    const messages = [
      applicationOptionError.value === null
        ? null
        : `接入应用筛选项：${applicationOptionError.value.detail}`,
      organizationOptionError.value === null
        ? null
        : `客户主体筛选项：${organizationOptionError.value.detail}`
    ].filter((message): message is string => message !== null)
    return messages.length === 0
      ? null
      : {
          code: null,
          http: null,
          title: '筛选项加载失败',
          detail: messages.join('；')
        }
  })
  const displayedError = computed(() => requestError.value ?? optionError.value)

  function actorLabel(type: string): string {
    return auditActorLabel(type)
  }

  function outcomeLabel(value: string): string {
    return auditOutcomeLabel(value)
  }

  function queryParams(): Record<string, string | number> {
    const params: Record<string, string | number> = { page: 1, limit: 50 }
    const organization = selectedId(organizationId.value)
    const application = selectedId(applicationId.value)
    if (organization !== null) params.organization_id = organization
    if (application !== null) params.application_id = application
    if (actorType.value !== '') params.actor_type = actorType.value
    if (outcome.value !== '') params.outcome = outcome.value
    if (action.value.trim() !== '') params.action = action.value.trim()
    if (resourceType.value.trim() !== '') params.resource_type = resourceType.value.trim()
    if (requestId.value.trim() !== '') params.request_id = requestId.value.trim()
    if (fromTime.value !== '') params.from = fromTime.value
    if (toTime.value !== '') params.to = toTime.value
    return params
  }

  async function loadOptions(): Promise<void> {
    applicationOptionError.value = null
    organizationOptionError.value = null
    try {
      const applicationResult = await listSandIamResource('application', {
        page: 1,
        limit: 100
      })
      applicationRows.value = listRows(applicationResult)
      applications.value = auditApplicationOptions(applicationRows.value)
    } catch (error: unknown) {
      applicationRows.value = []
      applications.value = []
      applicationOptionError.value = describeSandIamError(error)
    }

    if (!auditFilterEndpoints(canIndexOrganization.value).includes('organization')) {
      organizations.value = auditOrganizationOptionsFromApplications(applicationRows.value)
      return
    }

    try {
      const organizationResult = await listSandIamResource('organization', {
        page: 1,
        limit: 100
      })
      organizations.value = auditApplicationOptions(listRows(organizationResult))
    } catch (error: unknown) {
      organizations.value = []
      organizationOptionError.value = describeSandIamError(error)
    }
  }

  async function loadAudits(): Promise<void> {
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin('audit/index', queryParams())
      const parsed = parseSandIamAudits(result)
      rows.value = parsed
      viewState.value = parsed.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      rows.value = []
    } finally {
      loading.value = false
    }
  }

  async function openDetail(row: SandIamAuditRow): Promise<void> {
    if (!canRead.value) {
      requestError.value = describeSandIamError(
        new Error('当前账号无权查看审计详情。请联系平台管理员开通审计详情查看权限。')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin('audit/read', { id: row.id })
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const parsed = parseSandIamAudit(payload)
      if (parsed === null) {
        throw new Error('审计详情返回格式不符合已冻结约定')
      }
      detail.value = parsed
      detailOpen.value = true
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  /**
   * CSV 走独立下载，不当普通 list 解析。有列表权限不等于有导出权限。
   */
  async function exportAudits(): Promise<void> {
    if (!canExport.value) {
      requestError.value = describeSandIamError(
        new Error('当前账号无权导出审计。请联系平台管理员开通审计导出权限。')
      )
      return
    }
    const rangeError = describeAuditExportRangeError(fromTime.value, toTime.value)
    if (rangeError !== null) {
      requestError.value = describeSandIamError(new Error(rangeError))
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const params = queryParams()
      delete params.page
      delete params.limit
      const blob = await downloadSandIamAdminBlob('audit/export', params)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `sand-iam-audit-${fromTime.value}-${toTime.value}.csv`
      link.click()
      URL.revokeObjectURL(url)
      ElMessage.success('已保存')
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadOptions()
    void loadAudits()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">访问审计</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          按对象追溯允许、拒绝和安全操作。默认列表显示发生时间、接入应用、操作者、做了什么、影响对象和结果；需要排错时可在详情中查看技术信息。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看访问审计。请联系平台管理员开通审计查看范围。"
      />
      <ElAlert
        v-if="!canExport"
        class="mb-4"
        type="info"
        :closable="false"
        title="不能导出"
        description="当前账号可以查看，但不能导出。请联系平台管理员开通导出范围；导出时请选择不超过 31 天的时间段。"
      />
      <ElAlert
        v-if="displayedError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="displayedError.title"
        :description="displayedError.detail"
      />
      <ElAlert
        v-else-if="viewState === 'empty'"
        class="mb-4"
        type="info"
        :closable="false"
        title="当前没有数据"
        description="当前筛选范围内没有审计记录。这与没有权限不同。"
      />

      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="客户主体">
          <ElSelect v-model="organizationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in organizations"
              :key="row.id"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="row.id"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="操作者类型">
          <ElSelect v-model="actorType" clearable placeholder="全部">
            <ElOption label="后台管理员" value="admin" />
            <ElOption label="服务调用身份" value="workload_client" />
            <ElOption label="身份上下文" value="context" />
            <ElOption label="应用身份" value="identity" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="结果">
          <ElSelect v-model="outcome" clearable placeholder="全部">
            <ElOption label="成功" value="succeeded" />
            <ElOption label="拒绝" value="denied" />
            <ElOption label="允许" value="allowed" />
            <ElOption label="失败" value="failed" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="起始时间">
          <ElDatePicker v-model="fromTime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" />
        </ElFormItem>
        <ElFormItem label="结束时间">
          <ElDatePicker v-model="toTime" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canIndex" :loading="loading" @click="loadAudits">
            查询
          </ElButton>
          <ElButton :disabled="!canExport" :loading="loading" @click="exportAudits">
            导出 CSV
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElCollapse class="mb-4">
        <ElCollapseItem
          data-developer-details="true"
          title="高级筛选：按技术信息查找（供技术人员排查）"
          name="technical-filters"
        >
          <p class="mt-0 text-xs text-gray-500">
            仅在排查问题时填写由技术人员提供的操作名称、对象类型或请求编号。
          </p>
          <ElForm label-width="160px">
            <ElFormItem label="操作名称">
              <ElInput v-model="action" placeholder="输入技术人员提供的操作名称" />
            </ElFormItem>
            <ElFormItem label="对象类型">
              <ElInput v-model="resourceType" placeholder="输入技术人员提供的对象类型" />
            </ElFormItem>
            <ElFormItem label="请求编号">
              <ElInput v-model="requestId" placeholder="输入技术人员提供的请求编号" />
            </ElFormItem>
          </ElForm>
        </ElCollapseItem>
      </ElCollapse>

      <ElTable v-loading="loading" :data="rows" border stripe empty-text="暂无可见审计">
        <ElTableColumn label="发生时间" min-width="180">
          <template #default="scope">{{ auditTimeLabel(scope.row.create_time) }}</template>
        </ElTableColumn>
        <ElTableColumn label="接入应用" min-width="160">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作者" min-width="120">
          <template #default="scope">{{ actorLabel(scope.row.actor_type) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180">
          <template #default="scope">{{ auditActionLabel(scope.row.action) }}</template>
        </ElTableColumn>
        <ElTableColumn label="资源" min-width="140">
          <template #default="scope">{{
            auditResourceTypeLabel(scope.row.resource_type)
          }}</template>
        </ElTableColumn>
        <ElTableColumn label="结果" min-width="90">
          <template #default="scope">{{ outcomeLabel(scope.row.outcome) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="100" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canRead" @click="openDetail(scope.row)">
              详情
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElDrawer v-model="detailOpen" title="审计详情" size="480px">
      <template v-if="detail">
        <p>发生时间：{{ auditTimeLabel(detail.create_time) }}</p>
        <p>接入应用：{{ applicationName(detail.application_id) }}</p>
        <p>操作者类型：{{ actorLabel(detail.actor_type) }}</p>
        <p>操作：{{ auditActionLabel(detail.action) }}</p>
        <p>资源：{{ auditResourceTypeLabel(detail.resource_type) }}</p>
        <p>结果：{{ outcomeLabel(detail.outcome) }}</p>
        <ElCollapse class="mt-3">
          <ElCollapseItem
            data-developer-details="true"
            title="开发者详情（供技术人员排查）"
            name="technical-detail"
          >
            <p>原始操作名称：{{ detail.action }}</p>
            <p>原始对象类型：{{ detail.resource_type }}</p>
            <p>请求编号：{{ detail.request_id }}</p>
            <p>记录编号：{{ detail.id }}</p>
            <p>操作者参考：{{ detail.actor_ref || '—' }}</p>
            <p>资源参考：{{ detail.resource_id === null ? '—' : detail.resource_id }}</p>
            <p v-if="detail.context !== null">附加信息：{{ JSON.stringify(detail.context) }}</p>
          </ElCollapseItem>
        </ElCollapse>
      </template>
    </ElDrawer>
  </div>
</template>
