<script setup lang="ts">
  import './sandIamPage.css'
  import { computed, onMounted, reactive, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    sandIamFieldLabel,
    sandIamReferenceEndpoint,
    sandIamValueLabel
  } from '../api/presentation'
  import { listSandIamResource } from '../api/resource'
  import {
    cascadedReferenceParams,
    positiveReferenceId,
    sandIamActionImpact,
    sandIamReferenceLabel,
    summarizeStringList
  } from '../api/uxContracts'
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
    readonly createTitle?: string
    readonly objectHint?: string
    readonly endpoint: SandIamResourceEndpoint
    readonly indexPermission: string
    readonly permissionPrefix: string
    readonly columns: readonly SandIamResourceColumn[]
    readonly filters: readonly SandIamFilterKey[]
    readonly formFields?: readonly SandIamFormField[]
    readonly writeMode?: SandIamWriteMode
    readonly requireIdentityId?: boolean
    readonly relationGrantField?: 'role_id' | 'user_type_id'
    /** 停用确认中补充说明，例如应用用户停用会撤销现有登录。 */
    readonly disableImpact?: string
  }

  const props = withDefaults(defineProps<Props>(), {
    createTitle: '',
    objectHint: '',
    formFields: () => [],
    writeMode: 'crud',
    requireIdentityId: false,
    relationGrantField: 'role_id',
    disableImpact: ''
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
  const credentialDialogOpen = ref(false)
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
  const scopeType = ref('')
  const relationTargetId = ref('')
  const referenceOptions = reactive<Record<string, ReferenceOption[]>>({})
  const referenceLoading = reactive<Record<string, boolean>>({})
  const referenceError = reactive<Record<string, string>>({})
  const referenceRequestId = reactive<Record<string, number>>({})

  interface ReferenceOption {
    readonly label: string
    readonly value: string
    readonly row: SandIamResourceRow
  }

  const canIndex = computed(() => hasAuth(props.indexPermission))
  const canSave = computed(
    () =>
      hasAuth(`${props.permissionPrefix}:save`) ||
      hasAuth(`${props.permissionPrefix}:issue`) ||
      hasAuth(`${props.permissionPrefix}:grant`)
  )
  const canUpdate = computed(
    () => hasAuth(`${props.permissionPrefix}:update`) || hasAuth(`${props.permissionPrefix}:rotate`)
  )
  const canDisable = computed(
    () =>
      hasAuth(`${props.permissionPrefix}:disable`) || hasAuth(`${props.permissionPrefix}:revoke`)
  )
  const hasRows = computed(() => rows.value.length > 0)
  const referenceErrorMessages = computed(() =>
    Object.entries(referenceError)
      .filter(([, message]) => message !== '')
      .map(([key, message]) => `${sandIamFieldLabel(key)}：${message}`)
  )
  const identityMissing = computed(
    () => props.requireIdentityId && parsePositiveInt(identityId.value) === null
  )
  const showWrites = computed(() => props.writeMode !== 'readonly')
  const editorTitle = computed(() =>
    creating.value
      ? props.createTitle !== ''
        ? props.createTitle
        : `新建${props.title}`
      : `编辑${props.title}`
  )
  const createButtonLabel = computed(() =>
    props.writeMode === 'credential'
      ? '签发'
      : props.createTitle !== ''
        ? props.createTitle
        : '新建'
  )
  const hasApplicationGrantContext = computed(() =>
    props.formFields.some((field) => field.applicationGrantContext === 'application')
  )
  const embeddedOrganizationLabelKey = computed(
    () =>
      props.formFields.find(
        (field) => field.key === 'organization_id' && field.embeddedReferenceLabelKey !== undefined
      )?.embeddedReferenceLabelKey ?? null
  )
  const hasEmbeddedOrganizationSummary = computed(() => embeddedOrganizationLabelKey.value !== null)
  const usesApplicationIndexOrganizationContext = computed(
    () => hasApplicationGrantContext.value || hasEmbeddedOrganizationSummary.value
  )

  /**
   * 复制次要列原文（系统代码）。失败时给出可恢复提示，不把剪贴板异常伪装成保存成功。
   */
  async function copyCellValue(value: unknown): Promise<void> {
    if (typeof value !== 'string' || value.trim() === '') return
    try {
      await navigator.clipboard.writeText(value)
      ElMessage.success('系统代码已复制，可粘贴到接口配置')
    } catch {
      ElMessage.error('无法复制系统代码，请手动选择后复制')
    }
  }
  const referenceKeys = computed(() => {
    const keys = [...props.columns.map((column) => column.key), ...props.filters]
    if (props.writeMode === 'relation') keys.push(props.relationGrantField)
    const ordered: string[] = []
    const include = (key: string): void => {
      const parentKey = parentReferenceKey(key)
      if (parentKey !== null) include(parentKey)
      if (sandIamReferenceEndpoint(key) !== null && !ordered.includes(key)) {
        ordered.push(key)
      }
    }
    if (usesApplicationIndexOrganizationContext.value) {
      ordered.push('application_id')
      if (props.filters.includes('organization_id')) {
        ordered.push('organization_id')
      }
    }
    for (const key of keys) {
      if (
        usesApplicationIndexOrganizationContext.value &&
        (key === 'organization_id' || key === 'application_id')
      ) {
        continue
      }
      include(key)
    }
    return ordered
  })

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function toNonNegativeInteger(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value : fallback
  }

  function parsePositiveInt(value: string): number | null {
    return positiveReferenceId(value)
  }

  function rowId(row: SandIamResourceRow): number | null {
    return typeof row.id === 'number' && Number.isInteger(row.id) && row.id > 0 ? row.id : null
  }

  function referenceRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value) || !Array.isArray(value.data)) return []
    return value.data.filter(isRecord)
  }

  function parentReferenceKey(key: string): string | null {
    if (key === 'application_id') return 'organization_id'
    if (key === 'environment_id') return 'application_id'
    if (key === 'workload_client_id') return 'environment_id'
    if (key === 'service_action_id') return 'service_id'
    if (
      key === 'identity_id' ||
      key === 'identity_provider_id' ||
      key === 'role_id' ||
      key === 'user_type_id' ||
      key === 'resource_id'
    ) {
      return 'application_id'
    }
    return null
  }

  function referenceOption(row: SandIamResourceRow, key: string): ReferenceOption | null {
    const id = rowId(row)
    if (id === null) return null
    const parentKey = parentReferenceKey(key)
    const rowParentValue = parentKey === null ? null : row[parentKey]
    const parentValue =
      typeof rowParentValue === 'number' && Number.isInteger(rowParentValue) && rowParentValue > 0
        ? String(rowParentValue)
        : parentKey === null
          ? ''
          : selectedReferenceValue(parentKey)
    const parentLabel =
      parentKey === null
        ? ''
        : (referenceOptions[parentKey]?.find((option) => option.value === parentValue)?.label ?? '')
    return {
      value: String(id),
      label: sandIamReferenceLabel(row, parentLabel),
      row
    }
  }

  function organizationOptionFromApplication(row: SandIamResourceRow): ReferenceOption | null {
    const id = row.organization_id
    const labelKey = embeddedOrganizationLabelKey.value ?? 'organization_name'
    const name = row[labelKey]
    if (typeof id !== 'number' || !Number.isInteger(id) || id <= 0) return null
    if (typeof name !== 'string' || name.trim() === '') return null
    return {
      value: String(id),
      label: name,
      row: { id, name }
    }
  }

  function syncApplicationGrantOrganization(applications: readonly ReferenceOption[]): void {
    if (!usesApplicationIndexOrganizationContext.value) return
    const organizations = applications
      .map((option) => organizationOptionFromApplication(option.row))
      .filter((option): option is ReferenceOption => option !== null)
    referenceOptions.organization_id = organizations.filter(
      (option, index) =>
        organizations.findIndex((candidate) => candidate.value === option.value) === index
    )
    const selected = applications.find((option) => option.value === applicationId.value)
    const organization =
      selected === undefined ? null : organizationOptionFromApplication(selected.row)
    if (organization !== null) organizationId.value = organization.value
  }

  function referenceParams(key: string, keywords = ''): SandIamListParams | null {
    if (props.writeMode === 'relation' && key === props.relationGrantField) {
      const identity = referenceOptions.identity_id?.find(
        (option) => option.value === identityId.value
      )
      const applicationId = identity?.row.application_id
      if (
        typeof applicationId !== 'number' ||
        !Number.isInteger(applicationId) ||
        applicationId <= 0
      ) {
        return null
      }
      return {
        page: 1,
        limit: 100,
        application_id: applicationId,
        keywords: keywords.trim() || undefined
      }
    }
    if (usesApplicationIndexOrganizationContext.value && key === 'organization_id') {
      return null
    }
    if (usesApplicationIndexOrganizationContext.value && key === 'application_id') {
      return { page: 1, limit: 100, keywords: keywords.trim() || undefined }
    }
    const params = cascadedReferenceParams(key, props.filters, {
      organizationId: organizationId.value,
      applicationId: applicationId.value,
      environmentId: environmentId.value
    })
    return params === null ? null : { ...params, keywords: keywords.trim() || undefined }
  }

  function missingReferenceMessage(key: string): string {
    if (key === 'application_id') return '请先选择客户主体，再选择接入应用。'
    if (key === 'environment_id') return '请先选择接入应用，再选择应用环境。'
    if (key === 'workload_client_id') return '请先选择应用环境，再选择服务调用身份。'
    return '请先选择应用身份，系统会按其所属应用加载可授予对象。'
  }

  function selectedReferenceValue(key: string): string {
    if (key === 'organization_id') return organizationId.value
    if (key === 'application_id') return applicationId.value
    if (key === 'environment_id') return environmentId.value
    if (key === 'workload_client_id') return workloadClientId.value
    if (key === 'identity_id') return identityId.value
    if (key === 'service_id') return serviceId.value
    if (props.writeMode === 'relation' && key === props.relationGrantField) {
      return relationTargetId.value
    }
    return ''
  }

  async function loadReferenceOption(key: string, keywords = ''): Promise<void> {
    const endpoint = sandIamReferenceEndpoint(key)
    if (endpoint === null) return
    if (usesApplicationIndexOrganizationContext.value && key === 'organization_id') {
      syncApplicationGrantOrganization(referenceOptions.application_id ?? [])
      referenceError[key] = ''
      return
    }
    const params = referenceParams(key, keywords)
    if (params === null) {
      referenceOptions[key] = []
      referenceError[key] = missingReferenceMessage(key)
      return
    }
    const requestId = (referenceRequestId[key] ?? 0) + 1
    referenceRequestId[key] = requestId
    const selectedValue = selectedReferenceValue(key)
    const selectedOption = referenceOptions[key]?.find((option) => option.value === selectedValue)
    referenceLoading[key] = true
    referenceError[key] = ''
    try {
      const response = await listSandIamResource(endpoint, params)
      if (referenceRequestId[key] !== requestId) return
      const options = referenceRows(response)
        .map((row) => referenceOption(row, key))
        .filter((option): option is ReferenceOption => option !== null)
      referenceOptions[key] =
        selectedOption !== undefined &&
        !options.some((option) => option.value === selectedOption.value)
          ? [selectedOption, ...options]
          : options
      if (usesApplicationIndexOrganizationContext.value && key === 'application_id') {
        syncApplicationGrantOrganization(referenceOptions.application_id ?? [])
      }
      if (referenceOptions[key].length === 0) {
        referenceError[key] = `没有可用的${sandIamFieldLabel(key)}，请先创建或启用关联对象。`
      }
    } catch (error: unknown) {
      if (referenceRequestId[key] !== requestId) return
      const described = describeSandIamError(error)
      if (described.http === 401) clearSensitiveState()
      referenceOptions[key] = []
      referenceError[key] =
        described.http === 403
          ? `无权查看${sandIamFieldLabel(key)}，请联系平台管理员确认权限或管理范围。`
          : `无法加载${sandIamFieldLabel(key)}：${described.detail}`
    } finally {
      if (referenceRequestId[key] === requestId) {
        referenceLoading[key] = false
      }
    }
  }

  async function loadReferenceOptions(): Promise<void> {
    for (const key of referenceKeys.value) await loadReferenceOption(key)
  }

  function searchReferenceOptions(key: string, keywords: string): void {
    void loadReferenceOption(key, keywords)
  }

  function referenceValueLabel(key: string, value: string): string | null {
    return referenceOptions[key]?.find((option) => option.value === value)?.label ?? null
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

  /**
   * 默认列表只保留主机和路径摘要，完整 HTTPS 地址留在表单。
   */
  function summarizeHttpsHost(value: string): string {
    try {
      const parsed = new URL(value)
      const path = parsed.pathname === '/' ? '' : parsed.pathname
      return `${parsed.host}${path}`
    } catch {
      return '地址格式无法摘要'
    }
  }

  /**
   * CAS 可返回资料只解释已冻结的 display_name / email，其它原样保留但不猜测新语义。
   */
  function summarizeReleasedAttributes(value: unknown[]): string {
    const labels = value
      .filter((item): item is string => typeof item === 'string')
      .map((item) => sandIamValueLabel('released_attributes', item))
    return labels.length === 0 ? '不返回用户资料' : labels.join('、')
  }

  function displayValue(key: string, value: unknown, row?: SandIamResourceRow): string {
    if (key === 'frontchannel_logout_uri') {
      return typeof value === 'string' && value.trim() !== '' ? '前通道已配置' : '前通道未配置'
    }
    if (key === 'backchannel_logout_uri') {
      return typeof value === 'string' && value.trim() !== '' ? '后通道已配置' : '后通道未配置'
    }
    if (key === 'service_url') {
      return typeof value === 'string' && value.trim() !== '' ? summarizeHttpsHost(value) : '—'
    }
    if (
      key === 'organization_id' &&
      embeddedOrganizationLabelKey.value !== null &&
      row !== undefined
    ) {
      const label = row[embeddedOrganizationLabelKey.value]
      if (typeof label === 'string' && label.trim() !== '') return label
    }
    if (value === null || value === undefined || value === '') return '—'
    if (typeof value === 'string' || typeof value === 'number') {
      if (key === 'secret_version') {
        return value === '' || value === null ? '未配置' : '已配置'
      }
      const referenceLabel = referenceValueLabel(key, String(value))
      if (referenceLabel !== null) return referenceLabel
      if (sandIamReferenceEndpoint(key) !== null) return '关联对象名称暂不可用'
      return sandIamValueLabel(key, String(value))
    }
    if (typeof value === 'boolean') {
      return sandIamValueLabel(key, value ? 'true' : 'false')
    }
    if (Array.isArray(value)) {
      if (key === 'redirect_uris' || key === 'post_logout_redirect_uris') {
        return summarizeStringList(value, '回调地址')
      }
      if (key === 'allowed_scopes') return summarizeStringList(value, '范围')
      if (key === 'allowed_audiences') return summarizeStringList(value, '受众')
      if (key === 'login_methods') return summarizeStringList(value, '登录方式')
      if (key === 'registration_fields') return summarizeStringList(value, '注册字段')
      if (key === 'released_attributes') {
        return summarizeReleasedAttributes(value)
      }
      if (key === 'allow_cidrs') return `${String(value.length)} 个允许网段`
      if (key === 'deny_cidrs') return `${String(value.length)} 个拒绝网段`
      return `${String(value.length)} 项`
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

  function filterLabel(key: SandIamFilterKey): string {
    return sandIamFieldLabel(key)
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
      scope_type?: string
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
    if (shows('actor_type') && actorType.value.trim() !== '')
      next.actor_type = actorType.value.trim()
    if (shows('outcome') && outcome.value.trim() !== '') next.outcome = outcome.value.trim()
    if (shows('scope_type') && scopeType.value.trim() !== '')
      next.scope_type = scopeType.value.trim()
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
      const described = describeSandIamError(error)
      if (described.http === 401) clearSensitiveState()
      requestError.value = described
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
    scopeType.value = ''
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
    const record = isRecord(value) && isRecord(value.data) ? value.data : value
    if (!isRecord(record)) return
    const secret =
      typeof record.credential === 'string'
        ? record.credential
        : typeof record.client_secret === 'string'
          ? record.client_secret
          : null
    if (secret === null || secret.trim() === '') return
    issuedSecret.value = secret
    credentialDialogOpen.value = true
  }

  async function copyIssuedSecret(): Promise<void> {
    if (issuedSecret.value === null) return
    try {
      await navigator.clipboard.writeText(issuedSecret.value)
      ElMessage.success('凭证已复制，请立即交给应用安全保存')
    } catch {
      ElMessage.error('无法复制凭证，请手动复制后安全保存')
    }
  }

  function clearSensitiveState(): void {
    issuedSecret.value = null
    credentialDialogOpen.value = false
  }

  async function closeCredentialDialog(done: () => void): Promise<void> {
    try {
      await ElMessageBox.confirm(
        '关闭后，凭证明文将不再显示且无法从页面恢复。确认已完成安全交付吗？',
        '确认关闭凭证窗口',
        {
          type: 'warning',
          confirmButtonText: '已交付，关闭',
          cancelButtonText: '继续查看'
        }
      )
      clearSensitiveState()
      done()
    } catch {
      // Keep the one-time secret visible until the operator explicitly confirms delivery.
    }
  }

  function requestCredentialClose(): void {
    void closeCredentialDialog(() => {
      credentialDialogOpen.value = false
    })
  }

  function resourceLabel(row: SandIamResourceRow): string {
    return typeof row.name === 'string'
      ? row.name
      : typeof row.display_name === 'string'
        ? row.display_name
        : typeof row.code === 'string'
          ? row.code
          : props.title
  }

  function isDisabled(row: SandIamResourceRow): boolean {
    return row.status === 2 || row.status === '2'
  }

  async function restoreEnabled(row: SandIamResourceRow): Promise<void> {
    const id = rowId(row)
    if (id === null) return
    await runWrite(() => updateSandIamResource(props.endpoint, { id, status: 1 }), '已恢复启用')
  }

  async function runWrite(
    task: () => Promise<unknown>,
    successText: string,
    describeError: (error: unknown) => SandIamRequestError = describeSandIamError
  ): Promise<void> {
    saving.value = true
    requestError.value = null
    try {
      const result = await task()
      captureIssuedSecret(result)
      editorOpen.value = false
      ElMessage.success(successText)
      await load()
    } catch (error: unknown) {
      const described = describeError(error)
      if (described.http === 401) {
        clearSensitiveState()
      }
      requestError.value = described
    } finally {
      saving.value = false
    }
  }

  function describeEnvironmentEditorSaveError(error: unknown): SandIamRequestError {
    const described = describeSandIamError(error)
    if (described.code !== 'SAND_IAM_ENVIRONMENT_CONFLICT') return described
    return {
      ...described,
      title: '无法保存应用环境',
      detail: '该应用下已存在相同环境代码，请更换后重试。'
    }
  }

  async function onEditorSubmit(payload: Readonly<Record<string, unknown>>): Promise<void> {
    if (props.writeMode === 'credential') {
      await runWrite(() => postSandIamAction('credential/issue', payload), '凭证已签发')
      return
    }
    if (creating.value) {
      const isEnvironmentCreation = props.endpoint === 'environment'
      await runWrite(
        () => saveSandIamResource(props.endpoint, payload, !isEnvironmentCreation),
        '已保存',
        isEnvironmentCreation ? describeEnvironmentEditorSaveError : undefined
      )
      return
    }
    await runWrite(() => updateSandIamResource(props.endpoint, payload), '已保存')
  }

  async function confirmDisable(row: SandIamResourceRow): Promise<void> {
    const id = rowId(row)
    if (id === null) return
    try {
      await ElMessageBox.confirm(
        `确认停用「${resourceLabel(row)}」吗？${
          props.disableImpact !== ''
            ? props.disableImpact
            : '停用后该对象不能再用于新的配置或授权，既有审计记录会保留；需要恢复时可由有权管理员编辑并重新启用。'
        }`,
        `停用${props.title}`,
        {
          type: 'warning',
          confirmButtonText: '确认停用',
          cancelButtonText: '取消'
        }
      )
    } catch {
      return
    }
    await runWrite(() => disableSandIamResource(props.endpoint, id), '已停用')
  }

  async function confirmAction(
    path: string,
    data: Readonly<Record<string, unknown>>,
    title: string,
    row: SandIamResourceRow,
    impact: string
  ): Promise<void> {
    try {
      await ElMessageBox.confirm(`确认${title}「${resourceLabel(row)}」吗？${impact}`, title, {
        type: 'warning',
        confirmButtonText: `确认${title}`,
        cancelButtonText: '取消'
      })
    } catch {
      return
    }
    const successText = title.includes('撤销')
      ? '已撤销'
      : title.includes('发布')
        ? '已发布'
        : title.includes('轮换')
          ? '已轮换'
          : '已保存'
    await runWrite(() => postSandIamAction(path, data), successText)
  }

  async function grantRelation(): Promise<void> {
    const identity = parsePositiveInt(identityId.value)
    const target = parsePositiveInt(relationTargetId.value)
    if (identity === null || target === null) {
      requestError.value = describeSandIamError(new Error('请选择应用身份和要授予的对象'))
      return
    }
    const data =
      props.relationGrantField === 'user_type_id'
        ? { identity_id: identity, user_type_id: target }
        : { identity_id: identity, role_id: target }
    await runWrite(() => postSandIamAction(`${props.endpoint}/grant`, data), '已保存')
  }

  onMounted(() => {
    void load()
    void loadReferenceOptions()
  })

  watch(identityId, () => {
    if (props.writeMode !== 'relation') return
    relationTargetId.value = ''
    void loadReferenceOptions()
  })

  watch(organizationId, () => {
    if (hasApplicationGrantContext.value) return
    if (!shows('application_id')) return
    applicationId.value = ''
    environmentId.value = ''
    workloadClientId.value = ''
    void loadReferenceOptions()
  })

  watch(applicationId, () => {
    if (hasApplicationGrantContext.value) {
      syncApplicationGrantOrganization(referenceOptions.application_id ?? [])
    }
    if (!shows('environment_id')) return
    environmentId.value = ''
    workloadClientId.value = ''
    void loadReferenceOptions()
  })

  watch(environmentId, () => {
    if (!shows('workload_client_id')) return
    workloadClientId.value = ''
    void loadReferenceOptions()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4 flex items-start justify-between gap-4">
        <div>
          <h2 class="m-0 text-base font-semibold">{{ title }}</h2>
          <p class="mb-0 mt-1 text-sm text-gray-500">{{ description }}</p>
          <p v-if="objectHint !== ''" class="mb-0 mt-1 text-sm text-gray-500">
            {{ objectHint }}
          </p>
        </div>
        <ElSpace>
          <ElButton @click="resetFilters">重置</ElButton>
          <ElButton :loading="loading" type="primary" @click="search">查询</ElButton>
          <ElButton
            v-if="showWrites && canSave && writeMode !== 'relation'"
            type="success"
            @click="openCreate"
          >
            {{ createButtonLabel }}
          </ElButton>
        </ElSpace>
      </div>

      <ElForm v-if="filters.length > 0" class="mb-4" inline>
        <ElFormItem v-if="shows('keywords')" :label="filterLabel('keywords')">
          <ElInput v-model="keywords" clearable placeholder="名称关键字" @keyup.enter="search" />
        </ElFormItem>
        <ElFormItem v-if="shows('status')" :label="filterLabel('status')">
          <ElSelect v-model="statusFilter" clearable placeholder="全部" style="width: 120px">
            <ElOption :value="1" label="已启用" />
            <ElOption :value="2" label="已停用" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('organization_id')" :label="filterLabel('organization_id')">
          <ElSelect
            v-model="organizationId"
            clearable
            filterable
            remote
            :remote-method="
              (keywords: string) => searchReferenceOptions('organization_id', keywords)
            "
            :disabled="hasApplicationGrantContext"
            :loading="referenceLoading.organization_id === true"
            :placeholder="hasApplicationGrantContext ? '由获授接入应用派生' : '请选择客户主体'"
          >
            <ElOption
              v-for="option in referenceOptions.organization_id"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('application_id')" :label="filterLabel('application_id')">
          <ElSelect
            v-model="applicationId"
            clearable
            filterable
            remote
            :remote-method="
              (keywords: string) => searchReferenceOptions('application_id', keywords)
            "
            :loading="referenceLoading.application_id === true"
            placeholder="请选择接入应用"
          >
            <ElOption
              v-for="option in referenceOptions.application_id"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('environment_id')" :label="filterLabel('environment_id')">
          <ElSelect
            v-model="environmentId"
            clearable
            filterable
            remote
            :remote-method="
              (keywords: string) => searchReferenceOptions('environment_id', keywords)
            "
            :loading="referenceLoading.environment_id === true"
            placeholder="请选择应用环境"
          >
            <ElOption
              v-for="option in referenceOptions.environment_id"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('workload_client_id')" :label="filterLabel('workload_client_id')">
          <ElSelect
            v-model="workloadClientId"
            clearable
            filterable
            remote
            :remote-method="
              (keywords: string) => searchReferenceOptions('workload_client_id', keywords)
            "
            :loading="referenceLoading.workload_client_id === true"
            placeholder="请选择服务调用身份"
          >
            <ElOption
              v-for="option in referenceOptions.workload_client_id"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('service_id')" :label="filterLabel('service_id')">
          <ElSelect
            v-model="serviceId"
            clearable
            filterable
            remote
            :remote-method="(keywords: string) => searchReferenceOptions('service_id', keywords)"
            :loading="referenceLoading.service_id === true"
            placeholder="请选择平台服务"
          >
            <ElOption
              v-for="option in referenceOptions.service_id"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('identity_id')" :label="filterLabel('identity_id')">
          <ElSelect
            v-model="identityId"
            clearable
            filterable
            remote
            :remote-method="(keywords: string) => searchReferenceOptions('identity_id', keywords)"
            :loading="referenceLoading.identity_id === true"
            placeholder="请选择应用身份"
          >
            <ElOption
              v-for="option in referenceOptions.identity_id"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('actor_type')" :label="filterLabel('actor_type')">
          <ElSelect v-model="actorType" clearable placeholder="全部主体类型" style="width: 150px">
            <ElOption label="后台管理员" value="admin" />
            <ElOption label="服务调用身份" value="workload_client" />
            <ElOption label="身份上下文" value="context" />
            <ElOption label="应用身份" value="identity" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('scope_type')" :label="filterLabel('scope_type')">
          <ElSelect v-model="scopeType" clearable placeholder="全部范围" style="width: 180px">
            <ElOption label="仅本接入应用" value="application" />
            <ElOption label="整个客户主体可挂载" value="organization" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="shows('outcome')" :label="filterLabel('outcome')">
          <ElSelect v-model="outcome" clearable placeholder="全部结果" style="width: 120px">
            <ElOption label="成功" value="succeeded" />
            <ElOption label="允许" value="allowed" />
            <ElOption label="拒绝" value="denied" />
            <ElOption label="失败" value="failed" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="writeMode === 'relation'" :label="sandIamFieldLabel(relationGrantField)">
          <ElSelect
            v-model="relationTargetId"
            clearable
            filterable
            :disabled="identityMissing"
            :loading="referenceLoading[relationGrantField] === true"
            :placeholder="
              referenceError[relationGrantField] || `请选择${sandIamFieldLabel(relationGrantField)}`
            "
          >
            <ElOption
              v-for="option in referenceOptions[relationGrantField]"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </ElSelect>
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
        title="当前账号暂无页面查看权限"
        description="请联系平台管理员授予当前页面的查看权限。页面会如实显示服务端返回的拒绝或数据范围。"
      />

      <ElAlert
        v-for="message in referenceErrorMessages"
        :key="message"
        class="mb-4"
        type="info"
        :closable="false"
        title="关联对象尚不可选择"
        :description="message"
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
        title="请先选择应用身份"
        description="身份角色和身份用户类型仅查询指定身份；未选择时不请求，避免误把无权限显示为空数据。"
      />

      <ElTable
        v-loading="loading || saving"
        :data="rows"
        row-key="id"
        border
        stripe
        empty-text="暂无可见数据"
      >
        <ElTableColumn
          v-for="column in columns"
          :key="column.key"
          :label="sandIamFieldLabel(column.key, column.label)"
          :min-width="column.minWidth ?? 120"
          show-overflow-tooltip
        >
          <template #default="scope">
            <ElSpace v-if="column.copyable === true" :size="8">
              <span>{{ displayValue(column.key, scope.row[column.key], scope.row) }}</span>
              <ElButton
                v-if="typeof scope.row[column.key] === 'string' && scope.row[column.key] !== ''"
                link
                type="primary"
                size="small"
                @click="copyCellValue(scope.row[column.key])"
              >
                复制
              </ElButton>
            </ElSpace>
            <span v-else>{{ displayValue(column.key, scope.row[column.key], scope.row) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn
          v-if="showWrites || $slots['row-actions']"
          label="操作"
          min-width="280"
          fixed="right"
        >
          <template #default="scope">
            <ElSpace wrap>
              <ElButton
                v-if="writeMode !== 'relation' && writeMode !== 'credential' && canUpdate"
                size="small"
                @click="openEdit(scope.row)"
              >
                编辑
              </ElButton>
              <ElButton
                v-if="
                  (writeMode === 'crud' ||
                    writeMode === 'policy' ||
                    writeMode === 'binding' ||
                    writeMode === 'oauth-client' ||
                    writeMode === 'business-action') &&
                  canDisable &&
                  !isDisabled(scope.row)
                "
                size="small"
                type="warning"
                @click="confirmDisable(scope.row)"
              >
                停用
              </ElButton>
              <ElButton
                v-if="
                  (writeMode === 'crud' ||
                    writeMode === 'policy' ||
                    writeMode === 'binding' ||
                    writeMode === 'oauth-client' ||
                    writeMode === 'business-action') &&
                  canUpdate &&
                  isDisabled(scope.row)
                "
                size="small"
                type="success"
                @click="restoreEnabled(scope.row)"
              >
                恢复启用
              </ElButton>
              <ElButton
                v-if="writeMode === 'policy' && hasAuth(`${permissionPrefix}:publish`)"
                size="small"
                type="success"
                @click="
                  confirmAction(
                    'policy/publish',
                    { id: scope.row.id },
                    '发布策略',
                    scope.row,
                    sandIamActionImpact('publish-policy')
                  )
                "
              >
                发布
              </ElButton>
              <ElButton
                v-if="writeMode === 'policy' && hasAuth(`${permissionPrefix}:revoke`)"
                size="small"
                type="danger"
                @click="
                  confirmAction(
                    'policy/revoke',
                    { id: scope.row.id },
                    '撤销策略',
                    scope.row,
                    sandIamActionImpact('revoke-policy')
                  )
                "
              >
                撤销
              </ElButton>
              <ElButton
                v-if="writeMode === 'grant' && hasAuth(`${permissionPrefix}:revoke`)"
                size="small"
                type="danger"
                @click="
                  confirmAction(
                    'grant/revoke',
                    { id: scope.row.id },
                    '撤销授权',
                    scope.row,
                    sandIamActionImpact('revoke-grant')
                  )
                "
              >
                撤销
              </ElButton>
              <ElButton
                v-if="writeMode === 'relation' && canDisable"
                size="small"
                type="danger"
                @click="
                  confirmAction(
                    `${endpoint}/revoke`,
                    { id: scope.row.id },
                    '撤销关系',
                    scope.row,
                    sandIamActionImpact('revoke-relation')
                  )
                "
              >
                撤销
              </ElButton>
              <ElButton
                v-if="
                  writeMode === 'oauth-client' &&
                  canUpdate &&
                  scope.row.client_type === 'confidential' &&
                  scope.row.status === 1
                "
                size="small"
                @click="
                  confirmAction(
                    'oauth-client/secret/rotate',
                    { id: scope.row.id },
                    '轮换密钥',
                    scope.row,
                    sandIamActionImpact('rotate-oauth-secret')
                  )
                "
              >
                轮换密钥
              </ElButton>
              <ElButton
                v-if="writeMode === 'business-action' && hasAuth(`${permissionPrefix}:update`)"
                size="small"
                type="success"
                @click="
                  confirmAction(
                    'application-business-action/publish',
                    { id: scope.row.id },
                    '发布声明',
                    scope.row,
                    sandIamActionImpact('publish-business-action')
                  )
                "
              >
                发布
              </ElButton>
              <ElButton
                v-if="writeMode === 'credential' && canUpdate"
                size="small"
                @click="
                  confirmAction(
                    'credential/rotate',
                    { id: scope.row.id },
                    '轮换凭证',
                    scope.row,
                    sandIamActionImpact('rotate-credential')
                  )
                "
              >
                轮换
              </ElButton>
              <ElButton
                v-if="writeMode === 'credential' && canDisable"
                size="small"
                type="danger"
                @click="
                  confirmAction(
                    'credential/revoke',
                    { id: scope.row.id },
                    '撤销凭证',
                    scope.row,
                    sandIamActionImpact('revoke-credential')
                  )
                "
              >
                撤销
              </ElButton>
              <slot name="row-actions" :row="scope.row" />
            </ElSpace>
          </template>
        </ElTableColumn>
      </ElTable>

      <ElEmpty
        v-if="!loading && !requestError && !identityMissing && !hasRows"
        description="当前权限范围内暂无数据（不是全量成功）"
      />
      <slot name="extra" />

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

    <ElDialog
      v-model="credentialDialogOpen"
      title="请安全交付一次性明文"
      width="620px"
      :close-on-click-modal="false"
      :before-close="closeCredentialDialog"
      @closed="clearSensitiveState"
    >
      <ElAlert
        type="warning"
        :closable="false"
        title="明文仅在当前窗口展示一次"
        description="请复制并交给应用的安全配置渠道；不要写入文档、聊天记录或截图。关闭窗口后无法从页面恢复。"
      />
      <ElInput class="mt-4" :model-value="issuedSecret ?? ''" readonly type="textarea" :rows="4" />
      <template #footer>
        <ElButton type="primary" @click="copyIssuedSecret">复制凭证</ElButton>
        <ElButton @click="requestCredentialClose">已安全交付，关闭</ElButton>
      </template>
    </ElDialog>
  </div>
</template>
