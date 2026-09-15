<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
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
    parseSandIamAuditPage,
    parseSandIamArchivedAudit,
    parseSandIamArchivedAuditPage,
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
  const mode = ref<'current' | 'archive'>(
    !hasAuth('sand_iam:audit:index') && hasAuth('sand_iam:audit:archive_index') ? 'archive' : 'current'
  )
  const canIndex = computed(() => hasAuth(mode.value === 'archive' ? 'sand_iam:audit:archive_index' : 'sand_iam:audit:index'))
  const canRead = computed(() => hasAuth(mode.value === 'archive' ? 'sand_iam:audit:archive_read' : 'sand_iam:audit:read'))
  const canExport = computed(() => mode.value === 'current' && hasAuth('sand_iam:audit:export'))
  const canIndexOrganization = computed(() => hasAuth('sand_iam:organization:index'))

  const loading = ref(false)
  const detailLoading = ref(false)
  const exportLoading = ref(false)
  const currentPage = ref(1)
  const pageSize = ref(50)
  const total = ref(0)
  let listVersion = 0
  let detailVersion = 0
  let disposed = false
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
  const resourceId = ref('')
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
    const params: Record<string, string | number> = { page: currentPage.value, limit: pageSize.value }
    const objectId = resourceId.value.trim()
    if (objectId !== '' && !/^[1-9]\d*$/.test(objectId)) {
      throw new Error('对象编号必须是正整数')
    }
    if (objectId !== '') params.resource_id = objectId
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

  const applicationLoading = ref(false)
  const organizationLoading = ref(false)
  const applicationKeywords = ref('')
  const organizationKeywords = ref('')
  let applicationRequest = 0
  let organizationRequest = 0

  async function loadApplications(keywords = ''): Promise<void> {
    const attempt = ++applicationRequest
    if (disposed) return
    applicationKeywords.value = keywords
    applicationLoading.value = true
    applicationOptionError.value = null
    try {
      const result = await listSandIamResource('application', { page: 1, limit: 100, keywords })
      if (disposed || attempt !== applicationRequest) return
      const selected = applicationRows.value.find(row => String(row.id) === applicationId.value)
      const fetched = listRows(result)
      applicationRows.value = selected && !fetched.some(row => row.id === selected.id) ? [selected, ...fetched] : fetched
      applications.value = auditApplicationOptions(applicationRows.value)
      if (!canIndexOrganization.value) {
        const retained = organizations.value.find(row => String(row.id) === organizationId.value)
        const derived = auditOrganizationOptionsFromApplications(applicationRows.value)
        organizations.value = retained && !derived.some(row => row.id === retained.id) ? [retained, ...derived] : derived
      }
    } catch (error: unknown) {
      if (!disposed && attempt === applicationRequest) applicationOptionError.value = describeSandIamError(error)
    } finally {
      if (!disposed && attempt === applicationRequest) applicationLoading.value = false
    }
  }

  async function loadOrganizations(keywords = ''): Promise<void> {
    const attempt = ++organizationRequest
    if (disposed || !auditFilterEndpoints(canIndexOrganization.value).includes('organization')) return
    organizationKeywords.value = keywords
    organizationLoading.value = true
    organizationOptionError.value = null
    try {
      const result = await listSandIamResource('organization', { page: 1, limit: 100, keywords })
      if (disposed || !canIndexOrganization.value || attempt !== organizationRequest) return
      const selected = organizations.value.find(row => String(row.id) === organizationId.value)
      const fetched = auditApplicationOptions(listRows(result))
      organizations.value = selected && !fetched.some(row => row.id === selected.id) ? [selected, ...fetched] : fetched
    } catch (error: unknown) {
      if (!disposed && canIndexOrganization.value && attempt === organizationRequest) organizationOptionError.value = describeSandIamError(error)
    } finally {
      if (!disposed && attempt === organizationRequest) organizationLoading.value = false
    }
  }

  async function loadOptions(): Promise<void> {
    await Promise.all([loadApplications(), loadOrganizations()])
  }

  async function loadAudits(): Promise<void> {
    if (disposed || !canIndex.value) return
    const version = ++listVersion
    loading.value = true
    requestError.value = null
    try {
      const archive = mode.value === 'archive'
      const result = await getSandIamAdmin(archive ? 'audit/archive/index' : 'audit/index', queryParams())
      if (disposed || version !== listVersion) return
      const page = archive ? parseSandIamArchivedAuditPage(result) : parseSandIamAuditPage(result)
      rows.value = page.data
      total.value = page.total
      currentPage.value = page.currentPage
      pageSize.value = page.pageSize
      viewState.value = rows.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (disposed || version !== listVersion) return
      requestError.value = describeSandIamError(error)
      rows.value = []
    } finally {
      if (!disposed && version === listVersion) loading.value = false
    }
  }

  async function openDetail(row: SandIamAuditRow): Promise<void> {
    if (disposed) return
    if (!canRead.value) {
      requestError.value = describeSandIamError(
        new Error('当前账号无权查看审计详情。请联系平台管理员开通审计详情查看权限。')
      )
      return
    }
    const version = ++detailVersion
    detailLoading.value = true
    requestError.value = null
    try {
      const archive = mode.value === 'archive'
      const result = await getSandIamAdmin(archive ? 'audit/archive/read' : 'audit/read', { id: row.id })
      if (disposed || version !== detailVersion) return
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const parsed = archive ? parseSandIamArchivedAudit(payload) : parseSandIamAudit(payload)
      if (parsed === null) {
        throw new Error('审计详情返回格式不符合已冻结约定')
      }
      detail.value = parsed
      detailOpen.value = true
    } catch (error: unknown) {
      if (disposed || version !== detailVersion) return
      requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === detailVersion) detailLoading.value = false
    }
  }

  /**
   * CSV 走独立下载，不当普通 list 解析。有列表权限不等于有导出权限。
   */
  async function exportAudits(): Promise<void> {
    if (disposed || exportLoading.value) return
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
    const from = fromTime.value
    const to = toTime.value
    const version = listVersion
    exportLoading.value = true
    requestError.value = null
    try {
      const params = queryParams()
      delete params.page
      delete params.limit
      const blob = await downloadSandIamAdminBlob('audit/export', params)
      if (disposed) return
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `sand-iam-audit-${from}-${to}.csv`
      link.click()
      URL.revokeObjectURL(url)
      ElMessage.success('已保存')
    } catch (error: unknown) {
      if (!disposed && version === listVersion) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed) exportLoading.value = false
    }
  }

  watch([mode, organizationId, applicationId, actorType, outcome, action, resourceType, resourceId, requestId, fromTime, toTime], () => {
    listVersion++
    detailVersion++
    currentPage.value = 1
    total.value = 0
    rows.value = []
    detail.value = null
    detailOpen.value = false
    loading.value = false
    detailLoading.value = false
    requestError.value = null
    viewState.value = 'idle'
  }, { flush: 'sync' })

  onScopeDispose(() => { disposed = true; listVersion++; detailVersion++ })

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
      <ElRadioGroup v-model="mode" class="mb-4" aria-label="审计数据范围">
        <ElRadioButton value="current">当前审计</ElRadioButton>
        <ElRadioButton value="archive">归档审计</ElRadioButton>
      </ElRadioGroup>

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
        :description="mode === 'archive'
          ? '当前仅支持在线审计导出，归档审计可查询和查看详情。'
          : '导出需要独立权限，并请选择不超过 31 天的时间段。'"
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

      <ElButton v-if="applicationOptionError" :loading="applicationLoading" @click="loadApplications(applicationKeywords)">重试应用搜索</ElButton>
      <ElButton v-if="organizationOptionError && canIndexOrganization" :loading="organizationLoading" @click="loadOrganizations(organizationKeywords)">重试客户主体搜索</ElButton>
      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="客户主体">
          <ElSelect v-model="organizationId" filterable :remote="canIndexOrganization" :remote-method="loadOrganizations" :loading="organizationLoading" clearable placeholder="按名称选择">
            <ElOption
              v-for="row in organizations"
              :key="row.id"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable remote :remote-method="loadApplications" :loading="applicationLoading" clearable placeholder="按名称选择">
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
          <ElButton :disabled="!canExport" :loading="exportLoading" @click="exportAudits">
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
            <ElFormItem label="对象编号">
              <ElInput v-model="resourceId" placeholder="输入正整数对象编号" />
            </ElFormItem>
            <ElFormItem label="请求编号">
              <ElInput v-model="requestId" placeholder="输入技术人员提供的请求编号" />
            </ElFormItem>
          </ElForm>
        </ElCollapseItem>
      </ElCollapse>

      <ElTable v-loading="loading" :data="rows" border stripe empty-text="暂无可见审计">
        <ElTableColumn v-if="mode === 'archive'" prop="original_audit_id" label="原审计ID" min-width="120" />
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
            <ElButton size="small" :disabled="!canRead" :loading="detailLoading" @click="openDetail(scope.row)">
              详情
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination
        v-if="total > 0"
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="loadAudits"
        @size-change="currentPage = 1; loadAudits()"
      />
    </ElCard>

    <ElDrawer v-model="detailOpen" title="审计详情" size="480px">
      <template v-if="detail">
        <p v-if="detail.original_audit_id !== undefined">原审计ID：{{ detail.original_audit_id }}</p>
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
            <p>{{ mode === 'archive' ? '归档记录编号' : '记录编号' }}：{{ detail.id }}</p>
            <p>操作者参考：{{ detail.actor_ref || '—' }}</p>
            <p>资源参考：{{ detail.resource_id === null ? '—' : detail.resource_id }}</p>
            <p v-if="detail.context !== null">附加信息：{{ JSON.stringify(detail.context) }}</p>
          </ElCollapseItem>
        </ElCollapse>
      </template>
    </ElDrawer>
  </div>
</template>
