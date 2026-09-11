<script setup lang="ts">
  import { computed, reactive, ref, watch } from 'vue'
  import { ElMessage } from 'element-plus'
  import { parseConditionOrScope, parseJsonObject } from '../api/policyJson'
  import { listSandIamResource } from '../api/resource'
  import { readSandIamResource } from '../api/write'
  import { describeSandIamError } from '../api/errors'
  import {
    createSandIamEditorSessionTracker,
    planSandIamEditorHydrationFinish
  } from '../api/editorLifecycle'
  import { sandIamTextFieldValue } from '../api/formValues'
  import {
    normalizeReferenceRow,
    normalizeReferenceValue,
    resolvedReferenceOptions,
    sandIamReferenceSelectKey
  } from '../api/referenceValues'
  import {
    choosePolicySubject,
    describeApiResourcePayloadError,
    describeAuthPolicyPayloadError,
    describeBusinessActionPayloadError,
    describeIdentityProviderPayloadError,
    describeOAuthClientPayloadError,
    describeRouteBindingPayloadError,
    describeSandIamObjectCodeError,
    sandIamReferenceLabel,
    shouldSubmitSandIamField
  } from '../api/uxContracts'
  import ConditionEditor from './ConditionEditor.vue'
  import type {
    SandIamFormField,
    SandIamListParams,
    SandIamResourceRow,
    SandIamWriteMode
  } from '../api/types'

  interface Props {
    readonly modelValue: boolean
    readonly title: string
    readonly fields: readonly SandIamFormField[]
    readonly creating: boolean
    readonly row: SandIamResourceRow | null
    readonly writeMode: SandIamWriteMode
  }

  const props = defineProps<Props>()
  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    submit: [payload: Readonly<Record<string, unknown>>]
  }>()

  const form = reactive<Record<string, string | number | string[]>>({})
  const referenceOptions = reactive<Record<string, ReferenceOption[]>>({})
  const referenceLoading = reactive<Record<string, boolean>>({})
  const referenceError = reactive<Record<string, string>>({})
  const referenceRequestId = reactive<Record<string, number>>({})
  const hydratingContext = ref(false)
  let hydratingSession: number | null = null
  let editorSession = 0
  const editorSessions = createSandIamEditorSessionTracker()
  let lastDependencyValues: string[] = []
  const showAdvancedConfig = ref(false)
  const policySubjectMode = ref<'role' | 'identity'>('role')

  interface ReferenceOption {
    readonly label: string
    readonly value: number
    readonly row: SandIamResourceRow
  }

  const visibleFields = computed(() =>
    props.fields.filter((field) => {
      if (props.creating) return field.updateOnly !== true
      return field.createOnly !== true
    })
  )
  const usesApplicationGrantContext = computed(() =>
    visibleFields.value.some((field) => field.applicationGrantContext === 'application')
  )
  const embeddedOrganizationField = computed(() =>
    visibleFields.value.find(
      (field) =>
        field.key === 'organization_id' &&
        field.embeddedReferenceLabelKey !== undefined &&
        field.embeddedReferenceEditableKey !== undefined
    )
  )
  const hasAdvancedFields = computed(() =>
    visibleFields.value.some((field) => field.advanced === true)
  )

  function fieldString(
    row: SandIamResourceRow | null,
    field: SandIamFormField
  ): string | number | string[] {
    const key = field.key
    if (row === null) {
      if (field.defaultValue !== undefined) {
        if (field.kind === 'select' && field.multiple === true) {
          return field.defaultValue.split(',').filter((item) => item !== '')
        }
        return field.kind === 'reference'
          ? (normalizeReferenceValue(field.defaultValue) ?? '')
          : field.defaultValue
      }
      if (field.kind === 'select' && field.multiple === true) return []
      return key === 'status'
        ? '1'
        : key === 'condition' ||
            key === 'scope' ||
            key === 'quota_policy' ||
            key === 'network_policy'
          ? '{}'
          : field.jsonArray === true
            ? ''
            : ''
    }
    const value = row[key]
    if (field.kind === 'reference') return normalizeReferenceValue(value) ?? ''
    if (value === null || value === undefined) {
      if (field.kind === 'select' && field.multiple === true) return []
      return key === 'condition' ||
        key === 'scope' ||
        key === 'quota_policy' ||
        key === 'network_policy'
        ? '{}'
        : field.jsonArray === true
          ? ''
          : ''
    }
    if (field.kind === 'select' && field.multiple === true && Array.isArray(value)) {
      return value.filter((item): item is string => typeof item === 'string')
    }
    if (field.jsonArray === true && Array.isArray(value)) {
      return value.filter((item): item is string => typeof item === 'string').join('\n')
    }
    if (typeof value === 'object') return JSON.stringify(value)
    return String(value)
  }

  function resetForm(): void {
    for (const key of Object.keys(form)) delete form[key]
    for (const key of Object.keys(referenceOptions)) delete referenceOptions[key]
    for (const key of Object.keys(referenceLoading)) delete referenceLoading[key]
    for (const key of Object.keys(referenceError)) delete referenceError[key]
    for (const field of props.fields) {
      form[field.key] = fieldString(props.creating ? null : props.row, field)
    }
    lastDependencyValues = dependencyValues()
    showAdvancedConfig.value = false
    policySubjectMode.value = form.identity_id !== '' ? 'identity' : 'role'
  }

  function dependencyValues(): string[] {
    return visibleFields.value.map((field) =>
      field.dependency === undefined
        ? ''
        : String(normalizeReferenceValue(form[field.dependency.sourceKey]) ?? '')
    )
  }

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function lineItems(value: string, label: string): string[] {
    const entries = value
      .split(/\r?\n/)
      .map((item) => item.trim())
      .filter((item) => item !== '')
    if (entries.length === 0) throw new Error(`请至少填写一项${label}`)
    if (entries.length !== new Set(entries).size) {
      throw new Error(`${label}不能重复`)
    }
    return entries
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value) || !Array.isArray(value.data)) return []
    return value.data.filter(isRecord)
  }

  function referenceOption(
    row: SandIamResourceRow,
    field?: SandIamFormField
  ): ReferenceOption | null {
    const id = row.id
    const value = normalizeReferenceValue(id)
    if (value === null) return null
    const sourceKey = field?.dependency?.sourceKey
    const selectedParent =
      sourceKey === undefined
        ? undefined
        : referenceOptions[sourceKey]?.find(
            (option) => option.value === normalizeReferenceValue(form[sourceKey])
          )
    return {
      value,
      label: sandIamReferenceLabel(row, selectedParent?.label),
      row: { ...row, id: value }
    }
  }

  function isActiveEditorSession(session: number | undefined): boolean {
    return (
      session === undefined ||
      (editorSessions.isCurrent(session) && editorSession === session && props.modelValue)
    )
  }

  function organizationOptionFromApplication(
    row: SandIamResourceRow,
    labelKey = 'organization_name'
  ): ReferenceOption | null {
    const id = normalizeReferenceValue(row.organization_id)
    const name = row[labelKey]
    if (id === null) return null
    if (typeof name !== 'string' || name.trim() === '') return null
    return {
      value: id,
      label: name,
      row: { id, name }
    }
  }

  function syncApplicationGrantOrganization(
    applications: readonly ReferenceOption[],
    labelKey = 'organization_name'
  ): void {
    if (!usesApplicationGrantContext.value && embeddedOrganizationField.value === undefined) return
    const organizations = applications
      .map((option) => organizationOptionFromApplication(option.row, labelKey))
      .filter((option): option is ReferenceOption => option !== null)
    referenceOptions.organization_id = organizations.filter(
      (option, index) =>
        organizations.findIndex((candidate) => candidate.value === option.value) === index
    )
    const selected = applications.find(
      (option) => option.value === normalizeReferenceValue(form.application_id)
    )
    const organization =
      selected === undefined ? null : organizationOptionFromApplication(selected.row, labelKey)
    if (organization !== null) form.organization_id = organization.value
  }

  function embeddedReferenceReadOnly(field: SandIamFormField): boolean {
    const editableKey = field.embeddedReferenceEditableKey
    return editableKey !== undefined && props.row !== null && props.row[editableKey] === false
  }

  async function loadEmbeddedOrganizationSummary(
    field: SandIamFormField,
    session?: number
  ): Promise<void> {
    if (!isActiveEditorSession(session)) return
    const labelKey = field.embeddedReferenceLabelKey
    if (labelKey === undefined) return
    const response = await listSandIamResource('application', { page: 1, limit: 100 })
    if (!isActiveEditorSession(session)) return
    const applications = listRows(response)
      .map((row) => referenceOption(row))
      .filter((option): option is ReferenceOption => option !== null)
    referenceOptions.application_id = applications
    syncApplicationGrantOrganization(applications, labelKey)
    const id = normalizeReferenceValue(form[field.key])
    const label = props.row?.[labelKey]
    if (id !== null && typeof label === 'string' && label.trim() !== '') {
      appendReferenceOption(field.key, {
        value: id,
        label,
        row: { id, name: label }
      })
    }
  }

  function referenceParams(field: SandIamFormField, keywords = ''): SandIamListParams | null {
    if (field.applicationGrantContext === 'organization') return null
    if (field.applicationGrantContext === 'application') {
      return { page: 1, limit: 100, keywords: keywords.trim() || undefined }
    }
    const dependency = field.dependency
    if (dependency === undefined)
      return { page: 1, limit: 100, keywords: keywords.trim() || undefined }
    if (!visibleFields.value.some((item) => item.key === dependency.sourceKey)) {
      return { page: 1, limit: 100, keywords: keywords.trim() || undefined }
    }
    const source = form[dependency.sourceKey]
    let value = normalizeReferenceValue(source)
    if (value !== null && dependency.sourceEndpoint !== undefined) {
      const option = referenceOptions[dependency.sourceKey]?.find((item) => item.value === value)
      const related = option?.row[dependency.sourceValueKey ?? dependency.targetParam]
      value = normalizeReferenceValue(related)
    }
    if (value === null) return null
    return {
      page: 1,
      limit: 100,
      keywords: keywords.trim() || undefined,
      [dependency.targetParam]: value
    }
  }

  function missingDependencyMessage(field: SandIamFormField): string | null {
    if (field.dependency === undefined) return null
    const sourceField = props.fields.find((item) => item.key === field.dependency?.sourceKey)
    return `请先选择${sourceField?.label ?? '上级对象'}，再选择${field.label}`
  }

  function referenceLoadError(field: SandIamFormField, error: unknown): string {
    const described = describeSandIamError(error)
    if (described.http === 401) return '登录已失效，请重新登录后再选择。'
    if (described.http === 403)
      return `无权查看${field.label}，请联系平台管理员确认权限或管理范围。`
    if (described.code !== null) return `${described.code}：${described.detail}`
    return `无法加载${field.label}：${described.detail}`
  }

  function appendReferenceOption(key: string, option: ReferenceOption | null): void {
    if (option === null) return
    const existing = referenceOptions[key] ?? []
    if (existing.some((item) => item.value === option.value)) return
    referenceOptions[key] = [option, ...existing]
  }

  function readReferenceRow(value: unknown): SandIamResourceRow | null {
    return (
      normalizeReferenceRow(value) ?? (isRecord(value) ? normalizeReferenceRow(value.data) : null)
    )
  }

  async function hydrateSelectedReference(
    field: SandIamFormField,
    session?: number
  ): Promise<void> {
    if (!isActiveEditorSession(session)) return
    if (field.referenceEndpoint === undefined) return
    const id = normalizeReferenceValue(form[field.key])
    if (id === null || referenceOptions[field.key]?.some((option) => option.value === id)) {
      return
    }
    // The granted-application list normally contains the selected parent. Only
    // restore an edit value that falls outside that page through its own scoped read.
    try {
      const row = readReferenceRow(await readSandIamResource(field.referenceEndpoint, id))
      if (!isActiveEditorSession(session)) return
      appendReferenceOption(field.key, row === null ? null : referenceOption(row, field))
    } catch (error: unknown) {
      if (!isActiveEditorSession(session)) return
      referenceError[field.key] = referenceLoadError(field, error)
    }
  }

  async function loadReferenceField(
    field: SandIamFormField,
    keywords = '',
    session?: number
  ): Promise<void> {
    if (!isActiveEditorSession(session)) return
    if (field.kind !== 'reference' || field.referenceEndpoint === undefined) return
    if (embeddedReferenceReadOnly(field)) {
      try {
        await loadEmbeddedOrganizationSummary(field, session)
        if (!isActiveEditorSession(session)) return
        referenceError[field.key] = ''
      } catch (error: unknown) {
        if (!isActiveEditorSession(session)) return
        referenceOptions[field.key] = []
        referenceError[field.key] = referenceLoadError(field, error)
      }
      return
    }
    if (field.applicationGrantContext === 'organization') {
      syncApplicationGrantOrganization(referenceOptions.application_id ?? [])
      referenceError[field.key] = ''
      return
    }
    const params = referenceParams(field, keywords)
    if (params === null) {
      referenceOptions[field.key] = []
      referenceLoading[field.key] = false
      referenceError[field.key] = missingDependencyMessage(field) ?? ''
      return
    }
    const requestId = (referenceRequestId[field.key] ?? 0) + 1
    referenceRequestId[field.key] = requestId
    referenceLoading[field.key] = true
    referenceError[field.key] = ''
    try {
      const response = await listSandIamResource(field.referenceEndpoint, params)
      if (!isActiveEditorSession(session) || referenceRequestId[field.key] !== requestId) {
        return
      }
      const options = listRows(response)
        .map((row) => referenceOption(row, field))
        .filter((option): option is ReferenceOption => option !== null)
      referenceOptions[field.key] = options
      if (field.applicationGrantContext === 'application') {
        syncApplicationGrantOrganization(options)
      }
      if (referenceOptions[field.key].length === 0) {
        referenceError[field.key] = `没有可用的${field.label}。请先创建或启用上级对象后重试。`
      }
      await hydrateSelectedReference(field, session)
    } catch (error: unknown) {
      if (!isActiveEditorSession(session) || referenceRequestId[field.key] !== requestId) {
        return
      }
      referenceOptions[field.key] = []
      referenceError[field.key] = referenceLoadError(field, error)
    } finally {
      if (isActiveEditorSession(session) && referenceRequestId[field.key] === requestId) {
        referenceLoading[field.key] = false
      }
    }
  }

  async function loadReferenceOptions(session?: number): Promise<void> {
    for (const field of visibleFields.value) {
      if (!isActiveEditorSession(session)) return
      await loadReferenceField(field, '', session)
      if (!isActiveEditorSession(session)) return
    }
  }

  async function referenceRow(
    key: string,
    endpoint: NonNullable<SandIamFormField['referenceEndpoint']>,
    session: number
  ): Promise<SandIamResourceRow | null> {
    if (!isActiveEditorSession(session)) return null
    const id = normalizeReferenceValue(form[key])
    if (id === null) return null
    try {
      const row = readReferenceRow(await readSandIamResource(endpoint, id))
      return isActiveEditorSession(session) ? row : null
    } catch {
      return null
    }
  }

  function setReferenceId(key: string, value: unknown, session: number): void {
    if (!isActiveEditorSession(session)) return
    if (form[key] !== '') return
    const id = normalizeReferenceValue(value)
    if (id === null) return
    form[key] = id
  }

  async function hydrateReferenceContext(session: number): Promise<void> {
    if (!isActiveEditorSession(session)) return
    hydratingContext.value = true
    hydratingSession = session
    try {
      const client = await referenceRow('workload_client_id', 'client', session)
      if (!isActiveEditorSession(session)) return
      setReferenceId('environment_id', client?.environment_id, session)

      const identity = await referenceRow('identity_id', 'identity', session)
      if (!isActiveEditorSession(session)) return
      setReferenceId('application_id', identity?.application_id, session)

      const action = await referenceRow('service_action_id', 'action', session)
      if (!isActiveEditorSession(session)) return
      setReferenceId('service_id', action?.service_id, session)

      const environment = await referenceRow('environment_id', 'environment', session)
      if (!isActiveEditorSession(session)) return
      setReferenceId('application_id', environment?.application_id, session)

      if (isActiveEditorSession(session) && !usesApplicationGrantContext.value) {
        const application = await referenceRow('application_id', 'application', session)
        if (!isActiveEditorSession(session)) return
        setReferenceId('organization_id', application?.organization_id, session)
      }
    } finally {
      const finishPlan = planSandIamEditorHydrationFinish(
        hydratingSession,
        session,
        isActiveEditorSession(session)
      )
      if (finishPlan.captureDependencyValues) lastDependencyValues = dependencyValues()
      if (finishPlan.clearHydration) {
        hydratingContext.value = false
        hydratingSession = null
      }
    }
  }

  async function prepareEditor(session: number): Promise<void> {
    if (!isActiveEditorSession(session)) return
    resetForm()
    await hydrateReferenceContext(session)
    if (!isActiveEditorSession(session)) return
    await loadReferenceOptions(session)
  }

  function handleEditorOpen(modelValue: boolean): void {
    editorSession = editorSessions.next()
    if (modelValue) void prepareEditor(editorSession)
  }

  function searchReference(field: SandIamFormField, keywords: string): void {
    if (!props.modelValue) return
    void loadReferenceField(field, keywords, editorSession)
  }

  function optionsFor(field: SandIamFormField): readonly ReferenceOption[] {
    const options = referenceOptions[field.key] ?? []
    const selected = normalizeReferenceValue(form[field.key])
    if (selected === null) return options
    return resolvedReferenceOptions(options, selected, {
      value: selected,
      label: '当前已关联对象（名称暂不可用）',
      row: {}
    })
  }

  function textFieldValue(key: string): string {
    return sandIamTextFieldValue(form[key])
  }

  function setTextFieldValue(key: string, value: unknown): void {
    form[key] = sandIamTextFieldValue(value)
  }

  function referenceSelectKey(field: SandIamFormField): string {
    return sandIamReferenceSelectKey(field.key, form[field.key], referenceOptions[field.key] ?? [])
  }

  watch(() => props.modelValue, handleEditorOpen, { immediate: true, flush: 'post' })

  watch(
    () => dependencyValues(),
    (values) => {
      if (hydratingContext.value) return
      if (
        values.length === lastDependencyValues.length &&
        values.every((value, index) => value === lastDependencyValues[index])
      ) {
        return
      }
      for (const [index, field] of visibleFields.value.entries()) {
        if (field.dependency !== undefined && values[index] !== lastDependencyValues[index]) {
          if (field.applicationGrantContext === 'application') continue
          form[field.key] = ''
        }
      }
      lastDependencyValues = values
      if (!props.modelValue) return
      void loadReferenceOptions(editorSession)
    }
  )

  function parseNumber(raw: unknown, label: string, required: boolean): number | undefined {
    const value = typeof raw === 'number' ? String(raw) : raw
    if (typeof value !== 'string' || value.trim() === '') {
      if (required) throw new Error(`请填写${label}`)
      return undefined
    }
    const parsed = Number(value)
    if (!Number.isInteger(parsed) || parsed < 0) {
      throw new Error(`${label}必须是正整数`)
    }
    return parsed
  }

  function referenceDisabled(field: SandIamFormField): boolean {
    if (embeddedReferenceReadOnly(field)) return true
    if (field.applicationGrantContext === 'organization') return true
    if (field.applicationGrantContext === 'application') return false
    const sourceKey = field.dependency?.sourceKey
    if (sourceKey === undefined) return false
    if (!visibleFields.value.some((item) => item.key === sourceKey)) return false
    return normalizeReferenceValue(form[sourceKey]) === null
  }

  function policySubjectFieldVisible(field: SandIamFormField): boolean {
    if (props.writeMode !== 'policy') return true
    if (field.key === 'role_id') return policySubjectMode.value === 'role'
    if (field.key === 'identity_id') return policySubjectMode.value === 'identity'
    return true
  }

  watch(policySubjectMode, (mode) => {
    if (props.writeMode !== 'policy') return
    if (mode === 'role') form.identity_id = ''
    else form.role_id = ''
  })

  watch(
    () => [form.role_id ?? '', form.identity_id ?? ''] as const,
    ([roleId, identityValue], [previousRoleId, previousIdentityValue]) => {
      if (props.writeMode !== 'policy') return
      const role = String(roleId)
      const identity = String(identityValue)
      if (role !== '' && roleId !== previousRoleId && identity !== '') {
        const subject = choosePolicySubject('role', role, role, identity)
        form.identity_id = normalizeReferenceValue(subject.identityId) ?? ''
      }
      if (identity !== '' && identityValue !== previousIdentityValue && role !== '') {
        const subject = choosePolicySubject('identity', identity, role, identity)
        form.role_id = normalizeReferenceValue(subject.roleId) ?? ''
      }
    }
  )

  function buildPayload(): Readonly<Record<string, unknown>> {
    const payload: Record<string, unknown> = {}
    if (!props.creating && props.row !== null) {
      const id = normalizeReferenceValue(props.row.id)
      if (id !== null) payload.id = id
    }
    for (const field of visibleFields.value) {
      if (embeddedReferenceReadOnly(field)) continue
      if (!shouldSubmitSandIamField(field, showAdvancedConfig.value)) continue
      const raw = form[field.key] ?? ''
      if (field.kind === 'status') {
        payload[field.key] = raw === '2' ? 2 : 1
        continue
      }
      if (field.kind === 'number') {
        const parsed = parseNumber(raw, field.label, field.required === true)
        if (parsed !== undefined) payload[field.key] = parsed
        continue
      }
      if (field.kind === 'reference') {
        const value = normalizeReferenceValue(raw)
        if (value === null && field.required === true) {
          throw new Error(`请选择${field.label}`)
        }
        if (value !== null) payload[field.key] = value
        continue
      }
      if (field.kind === 'json' || field.kind === 'condition') {
        if (field.key === 'condition' || field.key === 'scope') {
          payload[field.key] = parseConditionOrScope(String(raw), field.label)
        } else if (field.jsonArray === true) {
          payload[field.key] = lineItems(String(raw), field.label)
        } else {
          payload[field.key] = parseJsonObject(String(raw), field.label)
        }
        continue
      }
      if (field.kind === 'select') {
        if (field.multiple === true) {
          const values = Array.isArray(raw) ? raw : []
          if (field.required === true && values.length === 0) {
            throw new Error(`请至少选择一项${field.label}`)
          }
          payload[field.key] = values
          continue
        }
        const value = String(raw).trim()
        if (field.required === true && value === '') {
          throw new Error(`请选择${field.label}`)
        }
        if (value === '') continue
        const numericOptions = field.options?.every((option) => /^-?\d+$/.test(option.value))
        payload[field.key] = numericOptions === true ? Number(value) : value
        continue
      }
      if (field.systemObjectCode === true) {
        const codeError = describeSandIamObjectCodeError(String(raw))
        if (codeError !== null) throw new Error(codeError)
      }
      const value = String(raw).trim()
      if (field.required === true && value === '') {
        throw new Error(`请填写${field.label}`)
      }
      if (value !== '') payload[field.key] = value
    }

    if (props.writeMode === 'policy') {
      const roleId = typeof payload.role_id === 'number' ? payload.role_id : 0
      const identityId = typeof payload.identity_id === 'number' ? payload.identity_id : 0
      if (roleId > 0 === identityId > 0) {
        throw new Error('请在角色和应用身份中二选一作为策略主体')
      }
    }
    if (props.writeMode === 'binding' && !props.creating) {
      return { id: payload.id, status: payload.status }
    }
    if (visibleFields.value.some((field) => field.key === 'password_min_length')) {
      const policyError = describeAuthPolicyPayloadError(payload)
      if (policyError !== null) throw new Error(policyError)
    }
    if (visibleFields.value.some((field) => field.key === 'scope_type')) {
      const providerError = describeIdentityProviderPayloadError(payload, props.creating)
      if (providerError !== null) throw new Error(providerError)
    }
    if (visibleFields.value.some((field) => field.key === 'client_type')) {
      const oauthError = describeOAuthClientPayloadError(payload, props.creating)
      if (oauthError !== null) throw new Error(oauthError)
    }
    if (
      visibleFields.value.some((field) => field.key === 'operation') &&
      visibleFields.value.some((field) => field.key === 'risk_level')
    ) {
      const apiError = describeApiResourcePayloadError(payload, props.creating)
      if (apiError !== null) throw new Error(apiError)
    }
    if (
      props.writeMode === 'business-action' ||
      (visibleFields.value.some((field) => field.key === 'description') &&
        visibleFields.value.some((field) => field.key === 'state') &&
        !visibleFields.value.some((field) => field.key === 'effect'))
    ) {
      const actionError = describeBusinessActionPayloadError(payload, props.creating)
      if (actionError !== null) throw new Error(actionError)
    }
    if (visibleFields.value.some((field) => field.key === 'route_template')) {
      const routeError = describeRouteBindingPayloadError(payload, props.creating)
      if (routeError !== null) throw new Error(routeError)
    }
    return payload
  }

  function submit(): void {
    try {
      emit('submit', buildPayload())
    } catch (error: unknown) {
      ElMessage.error(error instanceof Error ? error.message : '表单填写有误，请检查后重试')
    }
  }
</script>

<template>
  <ElDialog
    :model-value="modelValue"
    :title="title"
    width="640px"
    destroy-on-close
    @close="emit('update:modelValue', false)"
  >
    <ElForm label-width="160px">
      <ElFormItem v-if="writeMode === 'policy'" label="策略主体">
        <ElRadioGroup v-model="policySubjectMode">
          <ElRadioButton label="role">按角色授权</ElRadioButton>
          <ElRadioButton label="identity">指定应用身份</ElRadioButton>
        </ElRadioGroup>
        <p class="mb-0 mt-1 text-xs text-gray-500">
          二选一：角色适合一组相同职责的人；指定身份只对单个应用身份生效。
        </p>
      </ElFormItem>
      <ElFormItem v-if="hasAdvancedFields" label="高级可选配置">
        <ElButton text type="primary" @click="showAdvancedConfig = !showAdvancedConfig">
          {{ showAdvancedConfig ? '收起高级配置' : '展开高级配置' }}
        </ElButton>
        <p class="mb-0 mt-1 text-xs text-gray-500">
          基础授权不需要填写额度或网络限制；只有熟悉这些规则的管理员才需要展开。
        </p>
      </ElFormItem>
      <ElFormItem
        v-for="field in visibleFields"
        :key="field.key"
        :label="field.label"
        v-show="policySubjectFieldVisible(field) && (!field.advanced || showAdvancedConfig)"
      >
        <ElSelect v-if="field.kind === 'status'" v-model="form[field.key]" style="width: 100%">
          <ElOption label="已启用" value="1" />
          <ElOption label="已停用" value="2" />
        </ElSelect>
        <ElSelect
          v-else-if="field.kind === 'select' && field.options"
          v-model="form[field.key]"
          :multiple="field.multiple === true"
          :collapse-tags="field.multiple === true"
          :collapse-tags-tooltip="field.multiple === true"
          clearable
          style="width: 100%"
        >
          <ElOption
            v-for="option in field.options"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </ElSelect>
        <ElSelect
          v-else-if="field.kind === 'reference'"
          :key="referenceSelectKey(field)"
          v-model="form[field.key]"
          clearable
          filterable
          remote
          :remote-method="(keywords: string) => searchReference(field, keywords)"
          :disabled="referenceDisabled(field)"
          :loading="referenceLoading[field.key] === true"
          :placeholder="
            referenceLoading[field.key] === true
              ? '正在加载可选项'
              : referenceError[field.key] || `请选择${field.label}`
          "
          style="width: 100%"
        >
          <ElOption
            v-for="option in optionsFor(field)"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </ElSelect>
        <ConditionEditor
          v-else-if="field.kind === 'condition'"
          :model-value="textFieldValue(field.key)"
          @update:model-value="(value: unknown) => setTextFieldValue(field.key, value)"
          :label="field.label"
        />
        <ElDatePicker
          v-else-if="field.kind === 'datetime'"
          :model-value="textFieldValue(field.key)"
          @update:model-value="(value: unknown) => setTextFieldValue(field.key, value)"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm:ss"
          placeholder="请选择日期和时间"
          style="width: 100%"
        />
        <ElInput
          v-else-if="field.kind === 'json'"
          :model-value="textFieldValue(field.key)"
          @update:model-value="(value: unknown) => setTextFieldValue(field.key, value)"
          type="textarea"
          :rows="field.jsonArray === true ? 5 : 4"
          :placeholder="
            field.jsonArray === true
              ? '每行填写一项，例如一条回调地址或一个权限范围'
              : '高级设置：请按接入说明填写'
          "
        />
        <p
          v-if="field.kind === 'json' && field.jsonArray === true"
          class="mb-0 mt-1 text-xs text-gray-500"
        >
          一行一项，系统会自动整理；不需要输入方括号、逗号或引号。
        </p>
        <p v-else-if="field.kind === 'json'" class="mb-0 mt-1 text-xs text-gray-500">
          这是高级设置，基础流程可以留空。复杂规则请由熟悉接入约束的管理员填写。
        </p>
        <ElInput
          v-else
          :model-value="textFieldValue(field.key)"
          @update:model-value="(value: unknown) => setTextFieldValue(field.key, value)"
          :placeholder="
            field.placeholder ??
            (field.kind === 'number'
              ? `请输入${field.label}`
              : field.key === 'code'
                ? '创建后不可修改'
                : '')
          "
        />
        <p v-if="field.help" class="mb-0 mt-1 text-xs text-gray-500">
          {{ field.help }}
        </p>
        <p
          v-if="field.kind === 'reference' && referenceError[field.key]"
          class="mb-0 mt-1 text-xs text-warning"
        >
          {{ referenceError[field.key] }}
        </p>
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="emit('update:modelValue', false)">取消</ElButton>
      <ElButton type="primary" @click="submit">提交</ElButton>
    </template>
  </ElDialog>
</template>
