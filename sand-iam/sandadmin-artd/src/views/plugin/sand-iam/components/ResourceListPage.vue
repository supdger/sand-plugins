<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import {
    disableSandIamResource,
    postSandIamAction,
    saveSandIamResource,
    updateSandIamResource
  } from '../api/write'
  import ResourceEditor from './ResourceEditor.vue'
  import type {
    SandIamFilterKey,
    SandIamFormField,
    SandIamListParams,
    SandIamRequestError,
    SandIamResourceColumn,
    SandIamResourceEndpoint,
    SandIamResourcePage,
    SandIamResourceRow,
    SandIamWriteMode
  } from '../api/types'

  interface Props {
    readonly title: string
    readonly description: string
    readonly endpoint: SandIamResourceEndpoint
    readonly indexPermission: string
    readonly permissionPrefix: string
    readonly columns: readonly SandIamResourceColumn[]
    readonly filters: readonly SandIamFilterKey[]
    readonly formFields?: readonly SandIamFormField[]
    readonly writeMode?: SandIamWriteMode
    readonly requireIdentityId?: boolean
    readonly relationGrantField?: 'role_id' | 'user_type_id'
  }

  const props = withDefaults(defineProps<Props>(), {
    formFields: () => [],
    writeMode: 'crud',
    requireIdentityId: false,
    relationGrantField: 'role_id'
  })

  const { hasAuth } = useAuth()
  const rows = ref<SandIamResourceRow[]>([])
  const total = ref(0)
  const currentPage = ref(1)
  const pageSize = ref(20)
  const loading = ref(false)
  const saving = ref(false)
  const requestError = ref<SandIamRequestError | null>(null)
  const editorOpen = ref(false)
  const creating = ref(true)
  const editingRow = ref<SandIamResourceRow | null>(null)
  const issuedSecret = ref<string | null>(null)
  const keywords = ref('')
  const statusFilter = ref<number | ''>('')
  const organizationId = ref('')
  const applicationId = ref('')
  const environmentId = ref('')
  const workloadClientId = ref('')
  const identityId = ref('')
  const serviceId = ref('')
  const actorType = ref('')
  const outcome = ref('')
  const relationTargetId = ref('')

  const canIndex = computed(() => hasAuth(props.indexPermission))
  const canSave = computed(() => hasAuth(`${props.permissionPrefix}:save`) || hasAuth(`${props.permissionPrefix}:issue`) || hasAuth(`${props.permissionPrefix}:grant`))
  const canUpdate = computed(() => hasAuth(`${props.permissionPrefix}:update`) || hasAuth(`${props.permissionPrefix}:rotate`))
  const canDisable = computed(() => hasAuth(`${props.permissionPrefix}:disable`) || hasAuth(`${props.permissionPrefix}:revoke`))
  const hasRows = computed(() => rows.value.length > 0)
  const identityMissing = computed(
    () => props.requireIdentityId && parsePositiveInt(identityId.value) === null
  )
  const showWrites = computed(() => props.writeMode !== 'readonly')
  const editorTitle = computed(() => (creating.value ? `新建 ${props.title}` : `更新 ${props.title}`))

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function toNonNegativeInteger(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : fallback
  }

  function parsePositiveInt(value: string): number | null {
    if (value.trim() === '') return null
    const parsed = Number(value)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function rowId(row: SandIamResourceRow): number | null {
    return typeof row.id === 'number' && Number.isInteger(row.id) && row.id > 0 ? row.id : null
  }

  function normalizePage(value: unknown): SandIamResourcePage {
    if (Array.isArray(value)) {
      const data = value.filter(isRecord)
      return {
        data,
        total: data.length,
        currentPage: 1,
        pageSize: data.length === 0 ? pageSize.value : data.length
      }
    }
    if (!isRecord(value) || !Array.isArray(value.data)) {
      throw new Error('SandIAM 管理 API 返回格式不符合已冻结的分页约定')
    }
    const data = value.data.filter(isRecord)
    return {
      data,
      total: toNonNegativeInteger(value.total, data.length),
      currentPage: toNonNegativeInteger(value.current_page, currentPage.value),
      pageSize: toNonNegativeInteger(value.per_page, pageSize.value)
    }
  }

  function displayValue(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—'
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      return String(value)
    }
    try {
      return JSON.stringify(value)
    } catch {
      return '—'
    }
  }

  function shows(key: SandIamFilterKey): boolean {
    return props.filters.includes(key)
  }

  function buildParams(): SandIamListParams {
    const params: SandIamListParams = {
      page: currentPage.value,
      limit: pageSize.value
    }
    const next: {
      keywords?: string
      status?: number
      organization_id?: number
      application_id?: number
      environment_id?: number
      workload_client_id?: number
      identity_id?: number
      service_id?: number
      actor_type?: string
      outcome?: string
    } = {}
    if (shows('keywords') && keywords.value.trim() !== '') next.keywords = keywords.value.trim()
    if (shows('status') && statusFilter.value !== '') next.status = statusFilter.value
    const organization = parsePositiveInt(organizationId.value)
    if (shows('organization_id') && organization !== null) next.organization_id = organization
    const application = parsePositiveInt(applicationId.value)
    if (shows('application_id') && application !== null) next.application_id = application
    const environment = parsePositiveInt(environmentId.value)
    if (shows('environment_id') && environment !== null) next.environment_id = environment
    const client = parsePositiveInt(workloadClientId.value)
    if (shows('workload_client_id') && client !== null) next.workload_client_id = client
    const identity = parsePositiveInt(identityId.value)
    if (shows('identity_id') && identity !== null) next.identity_id = identity
    const service = parsePositiveInt(serviceId.value)
    if (shows('service_id') && service !== null) next.service_id = service
    if (shows('actor_type') && actorType.value.trim() !== '') next.actor_type = actorType.value.trim()
    if (shows('outcome') && outcome.value.trim() !== '') next.outcome = outcome.value.trim()
    return { ...params, ...next }
  }

  async function load(): Promise<void> {
    if (identityMissing.value) {
      rows.value = []
      total.value = 0
      requestError.value = null
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const response = await listSandIamResource(props.endpoint, buildParams())
      const page = normalizePage(response)
      rows.value = page.data
      total.value = page.total
      currentPage.value = page.currentPage
      pageSize.value = page.pageSize
    } catch (error: unknown) {
      rows.value = []
      total.value = 0
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  function search(): void {
    currentPage.value = 1
    void load()
  }

  function resetFilters(): void {
    keywords.value = ''
    statusFilter.value = ''
    organizationId.value = ''
    applicationId.value = ''
    environmentId.value = ''
    workloadClientId.value = ''
    identityId.value = ''
    serviceId.value = ''
    actorType.value = ''
    outcome.value = ''
    currentPage.value = 1
    void load()
  }

  function handlePageChange(page: number): void {
    currentPage.value = page
    void load()
  }

  function handleSizeChange(size: number): void {
    pageSize.value = size
    currentPage.value = 1
    void load()
  }

  function openCreate(): void {
    creating.value = true
    editingRow.value = null
    editorOpen.value = true
  }

  function openEdit(row: SandIamResourceRow): void {
    creating.value = false
    editingRow.value = row
    editorOpen.value = true
  }

  function captureIssuedSecret(value: unknown): void {
    if (!isRecord(value) || typeof value.credential !== 'string') return
    issuedSecret.value = value.credential
  }

  async function runWrite(task: () => Promise<unknown>): Promise<void> {
    saving.value = true
    requestError.value = null
    try {
      const result = await task()
      captureIssuedSecret(result)
      editorOpen.value = false
      ElMessage.success('已提交到真实接口；失败会显示稳定错误码，不会假装成功')
      await load()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function onEditorSubmit(payload: Readonly<Record<string, unknown>>): Promise<void> {
    if (props.writeMode === 'credential') {
      await runWrite(() => postSandIamAction('credential/issue', payload))
      return
    }
    if (creating.value) {
      await runWrite(() => saveSandIamResource(props.endpoint, payload))
      return
    }
    await runWrite(() => updateSandIamResource(props.endpoint, payload))
  }

  async function confirmDisable(row: SandIamResourceRow): Promise<void> {
    const id = rowId(row)
    if (id === null) return
    try {
      await ElMessageBox.confirm('确认停用该记录？停用会走真实 disable 接口。', '停用', { type: 'warning' })
    } catch {
      return
    }
    await runWrite(() => disableSandIamResource(props.endpoint, id))
  }

  async function confirmAction(path: string, data: Readonly<Record<string, unknown>>, title: string): Promise<void> {
    try {
      await ElMessageBox.confirm(`确认执行「${title}」？这会调用真实接口。`, title, { type: 'warning' })
    } catch {
      return
    }
    await runWrite(() => postSandIamAction(path, data))
  }

  async function grantRelation(): Promise<void> {
    const identity = parsePositiveInt(identityId.value)
    const target = parsePositiveInt(relationTargetId.value)
    if (identity === null || target === null) {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: identity_id and target id are required'))
      return
    }
    const data =
      props.relationGrantField === 'user_type_id'
        ? { identity_id: identity, user_type_id: target }
        : { identity_id: identity, role_id: target }
    await runWrite(() => postSandIamAction(`${props.endpoint}/grant`, data))
  }

  onMounted(() => {
    void load()
  })
</script>

<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <div class="mb-4 flex items-start justify-between gap-4">
        <div>
          <h2 class="m-0 text-base font-semibold">{{ title }}</h2>
          <p class="mb-0 mt-1 text-sm text-gray-500">{{ description }}</p>
          <p class="mb-0 mt-1 text-xs text-gray-400">权限 {{ indexPermission }}</p>
        </div>
        <ElSpace>
          <ElButton @click="resetFilters">重置</ElButton>
          <ElButton :loading="loading" type="primary" @click="search">查询</ElButton>
          <ElButton v-if="showWrites && canSave && writeMode !== 'relation'" type="success" @click="openCreate">
            {{ writeMode === 'credential' ? '签发' : '新建' }}
          </ElButton>
        </ElSpace>
      </div>

      <ElForm v-if="filters.length > 0" class="mb-4" inline>
        <ElFormItem v-if="shows('keywords')" label="keywords">
          <ElInput v-model="keywords" clearable placeholder="名称关键字" @keyup.enter="search" />
        </ElFormItem>
        <ElFormItem v-if="shows('status')" label="status">
          <ElSelect v-model="statusFilter" clearable placeholder="全部" style="width: 120px">
            <ElOption :value="1" label="启用 (1)" />
            <ElOption :value="2" label="停用 (2)" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('organization_id')" label="organization_id">
          <ElInput v-model="organizationId" clearable placeholder="正整数" />
        </ElFormItem>
        <ElFormItem v-if="shows('application_id')" label="application_id">
          <ElInput v-model="applicationId" clearable placeholder="正整数" />
        </ElFormItem>
        <ElFormItem v-if="shows('environment_id')" label="environment_id">
          <ElInput v-model="environmentId" clearable placeholder="正整数" />
        </ElFormItem>
        <ElFormItem v-if="shows('workload_client_id')" label="workload_client_id">
          <ElInput v-model="workloadClientId" clearable placeholder="正整数" />
        </ElFormItem>
        <ElFormItem v-if="shows('service_id')" label="service_id">
          <ElInput v-model="serviceId" clearable placeholder="正整数" />
        </ElFormItem>
        <ElFormItem v-if="shows('identity_id')" label="identity_id">
          <ElInput v-model="identityId" clearable placeholder="必填正整数" />
        </ElFormItem>
        <ElFormItem v-if="shows('actor_type')" label="actor_type">
          <ElInput v-model="actorType" clearable />
        </ElFormItem>
        <ElFormItem v-if="shows('outcome')" label="outcome">
          <ElInput v-model="outcome" clearable />
        </ElFormItem>
        <ElFormItem v-if="writeMode === 'relation'" :label="relationGrantField">
          <ElInput v-model="relationTargetId" clearable placeholder="正整数" />
        </ElFormItem>
        <ElFormItem v-if="writeMode === 'relation'">
          <ElButton :disabled="!canSave" type="success" @click="grantRelation">授予</ElButton>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="当前会话未包含该列表权限码"
        :description="`缺少 ${indexPermission}。仍向服务端发请求；403 或空范围会如实显示，不会当成全量成功。`"
      />

      <ElAlert
        v-if="issuedSecret"
        class="mb-4"
        type="warning"
        :closable="false"
        title="凭证明文只显示这一次"
        :description="issuedSecret"
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
        v-else-if="identityMissing"
        class="mb-4"
        type="info"
        :closable="false"
        title="需要 identity_id"
        description="该接口必须带 identity_id。未填写时不请求，避免把资源不存在显示成空授权。"
      />

      <ElTable v-loading="loading || saving" :data="rows" row-key="id" border stripe empty-text="暂无可见数据">
        <ElTableColumn
          v-for="column in columns"
          :key="column.key"
          :label="column.label"
          :min-width="column.minWidth ?? 120"
          show-overflow-tooltip
        >
          <template #default="scope">
            {{ displayValue(scope.row[column.key]) }}
          </template>
        </ElTableColumn>
        <ElTableColumn v-if="showWrites" label="操作" min-width="280" fixed="right">
          <template #default="scope">
            <ElSpace wrap>
              <ElButton
                v-if="writeMode !== 'relation' && writeMode !== 'credential' && canUpdate"
                size="small"
                @click="openEdit(scope.row)"
              >
                更新
              </ElButton>
              <ElButton
                v-if="(writeMode === 'crud' || writeMode === 'policy' || writeMode === 'binding') && canDisable"
                size="small"
                type="warning"
                @click="confirmDisable(scope.row)"
              >
                停用
              </ElButton>
              <ElButton
                v-if="writeMode === 'policy' && hasAuth(`${permissionPrefix}:publish`)"
                size="small"
                type="success"
                @click="confirmAction('policy/publish', { id: scope.row.id }, '发布策略')"
              >
                发布
              </ElButton>
              <ElButton
                v-if="writeMode === 'policy' && hasAuth(`${permissionPrefix}:revoke`)"
                size="small"
                type="danger"
                @click="confirmAction('policy/revoke', { id: scope.row.id }, '撤销策略')"
              >
                撤销
              </ElButton>
              <ElButton
                v-if="writeMode === 'grant' && hasAuth(`${permissionPrefix}:revoke`)"
                size="small"
                type="danger"
                @click="confirmAction('grant/revoke', { id: scope.row.id }, '撤销授权')"
              >
                撤销
              </ElButton>
              <ElButton
                v-if="writeMode === 'relation' && canDisable"
                size="small"
                type="danger"
                @click="confirmAction(`${endpoint}/revoke`, { id: scope.row.id }, '撤销关系')"
              >
                撤销
              </ElButton>
              <ElButton
                v-if="writeMode === 'credential' && canUpdate"
                size="small"
                @click="confirmAction('credential/rotate', { id: scope.row.id }, '轮换凭证')"
              >
                轮换
              </ElButton>
              <ElButton
                v-if="writeMode === 'credential' && canDisable"
                size="small"
                type="danger"
                @click="confirmAction('credential/revoke', { id: scope.row.id }, '撤销凭证')"
              >
                撤销
              </ElButton>
            </ElSpace>
          </template>
        </ElTableColumn>
      </ElTable>

      <ElEmpty
        v-if="!loading && !requestError && !identityMissing && !hasRows"
        description="当前权限范围内暂无数据（不是全量成功）"
      />

      <div v-if="!requireIdentityId" class="mt-4 flex justify-end">
        <ElPagination
          background
          layout="total, sizes, prev, pager, next"
          :total="total"
          :page-size="pageSize"
          :current-page="currentPage"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="handlePageChange"
          @size-change="handleSizeChange"
        />
      </div>
    </ElCard>

    <ResourceEditor
      v-model="editorOpen"
      :title="editorTitle"
      :fields="formFields"
      :creating="creating"
      :row="editingRow"
      :write-mode="writeMode"
      @submit="onEditorSubmit"
    />
  </div>
</template>
