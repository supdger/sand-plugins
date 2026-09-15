<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    messageTypeLabel,
    parseMessageProviderMounts,
    parseMessageProviderOptions,
    parseMessageProviders,
    SAND_IAM_MESSAGE_PURPOSES,
    SAND_IAM_MESSAGE_TYPES,
    type SandIamMessageProviderMount,
    type SandIamMessageProviderOption,
    type SandIamMessageProviderRow
  } from '../api/messageProviderContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:message_provider:index'))
  const canSave = computed(() => hasAuth('sand_iam:message_provider:save'))
  const canDisable = computed(() => hasAuth('sand_iam:message_provider:disable'))
  const canConfigure = computed(() => hasAuth('sand_iam:message_provider:configure'))
  const canTest = computed(() => hasAuth('sand_iam:message_provider:test'))
  const canMountIndex = computed(() => hasAuth('sand_iam:message_provider_mount:index'))
  const canMountSave = computed(() => hasAuth('sand_iam:message_provider_mount:save'))
  const canUnmount = computed(() => hasAuth('sand_iam:message_provider_mount:disable'))

  const loading = ref(false)
  const saving = ref(false)
  const mountLoading = ref(false)
  const organizationLoading = ref(false)
  const applicationLoading = ref(false)
  const page = ref(1)
  const pageSize = ref(20)
  const total = ref(0)
  let disposed = false
  let providerScope = 0
  let mountScope = 0
  let providerVersion = 0
  let mountVersion = 0
  const searchVersions = { organization: 0, application: 0 }
  const organizations = ref<SandIamResourceRow[]>([])
  const applications = ref<SandIamResourceRow[]>([])
  const providers = ref<SandIamMessageProviderRow[]>([])
  const options = ref<SandIamMessageProviderOption[]>([])
  const mounts = ref<SandIamMessageProviderMount[]>([])
  const organizationId = ref('')
  const applicationId = ref('')
  const name = ref('')
  const code = ref('')
  const providerType = ref('sms')
  const driverCode = ref('')
  const configJson = ref('')
  const showAdvancedProviderConfig = ref(false)
  const configEntries = ref<ProviderConfigEntry[]>([{ key: '', value: '' }])
  const testDestination = ref('')
  const selectedProviderId = ref('')
  const mountProviderId = ref('')
  const purposes = ref<string[]>(['verification'])
  const templateCodes = ref('')
  const showAdvancedTemplateCodes = ref(false)
  const templateEntries = ref<ProviderConfigEntry[]>([])
  const priority = ref(100)
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  watch(organizationId, () => {
    providerVersion++
    providers.value = []
    selectedProviderId.value = ''
    name.value = ''
    code.value = ''
    driverCode.value = ''
    applicationId.value = ''
    page.value = 1
    total.value = 0
    loading.value = false
    viewState.value = 'idle'
  }, { flush: 'sync' })
  watch(applicationId, () => {
    mountVersion++
    options.value = []
    mounts.value = []
    mountProviderId.value = ''
    mountLoading.value = false
  }, { flush: 'sync' })
  watch([organizationId, applicationId, selectedProviderId, mountProviderId], () => {
    requestError.value = null
  }, { flush: 'sync' })
  watch([organizationId, selectedProviderId], () => {
    providerScope++
    clearSecrets()
  }, { flush: 'sync' })
  watch([organizationId, applicationId, mountProviderId], () => {
    mountScope++
    templateEntries.value = []
    templateCodes.value = ''
    purposes.value = ['verification']
    priority.value = 100
  }, { flush: 'sync' })
  watch([page, pageSize], () => { void loadProviders() })

  function beginWrite(allowed: boolean, forMount = false) {
    if (disposed || saving.value || !allowed) return null
    const scope = forMount ? mountScope : providerScope
    saving.value = true
    requestError.value = null
    return () => !disposed && scope === (forMount ? mountScope : providerScope)
  }

  function currentProvider() {
    if (loading.value) return undefined
    return providers.value.find(row => row.id === selectedId(selectedProviderId.value) &&
      (organizationId.value === '' || row.organization_id === selectedId(organizationId.value)))
  }

  function validApplication(): boolean {
    return !applicationLoading.value && applications.value.some(row => row.id === selectedId(applicationId.value) &&
      (organizationId.value === '' || row.organization_id === selectedId(organizationId.value)))
  }

  interface ProviderConfigEntry {
    key: string
    value: string
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

  function organizationName(id: number): string {
    const row = organizations.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联客户主体名称暂不可用'
  }

  /**
   * 供应商配置只写不读；提交后立即清空输入，避免留在页面状态。
   */
  function clearSecrets(): void {
    configJson.value = ''
    configEntries.value = [{ key: '', value: '' }]
    testDestination.value = ''
  }

  function entryObject(
    entries: readonly ProviderConfigEntry[],
    label: string
  ): Record<string, string> | null {
    const result: Record<string, string> = {}
    for (const entry of entries) {
      const key = entry.key.trim()
      const value = entry.value.trim()
      if (key === '' && value === '') continue
      if (key === '' || value === '') {
        requestError.value = describeSandIamError(new Error(`${label}的每一行都要填写名称和值。`))
        return null
      }
      if (Object.prototype.hasOwnProperty.call(result, key)) {
        requestError.value = describeSandIamError(new Error(`${label}不能重复填写同一个名称。`))
        return null
      }
      result[key] = value
    }
    return result
  }

  function addConfigEntry(): void {
    configEntries.value.push({ key: '', value: '' })
  }

  function removeConfigEntry(index: number): void {
    configEntries.value.splice(index, 1)
    if (configEntries.value.length === 0) addConfigEntry()
  }

  function addTemplateEntry(): void {
    templateEntries.value.push({ key: '', value: '' })
  }

  function removeTemplateEntry(index: number): void {
    templateEntries.value.splice(index, 1)
  }

  async function searchOptions(kind: 'organization' | 'application', keywords = ''): Promise<void> {
    if (disposed || !(canIndex.value || canSave.value || canMountIndex.value || canMountSave.value)) return
    const version = ++searchVersions[kind]
    const list = kind === 'organization' ? organizations : applications
    const busy = kind === 'organization' ? organizationLoading : applicationLoading
    if (kind === 'organization') organizationId.value = ''
    else applicationId.value = ''
    list.value = []
    busy.value = true
    try {
      const result = await listSandIamResource(kind, { page: 1, limit: 100, keywords: keywords.trim() })
      if (!disposed && version === searchVersions[kind]) list.value = listRows(result)
    } catch (error: unknown) {
      if (!disposed && version === searchVersions[kind]) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === searchVersions[kind]) busy.value = false
    }
  }

  async function loadProviders(): Promise<void> {
    if (disposed || !canIndex.value) return
    const version = ++providerVersion
    const organization = selectedId(organizationId.value)
    const current = () => !disposed && version === providerVersion && organization === selectedId(organizationId.value)
    selectedProviderId.value = ''
    loading.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin(
        'message-provider/index',
        { ...(organization === null ? {} : { organization_id: organization }), page: page.value, limit: pageSize.value }
      )
      const rows = parseMessageProviders(result)
      if (!current()) return
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      total.value = isRecord(payload) && typeof payload.total === 'number' ? payload.total : rows.length
      providers.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (!current()) return
      requestError.value = describeSandIamError(error)
      providers.value = []
    } finally {
      if (current()) loading.value = false
    }
  }

  async function createProvider(): Promise<void> {
    if (!canSave.value || saving.value || disposed || organizationLoading.value ||
      !organizations.value.some(row => row.id === selectedId(organizationId.value))) return
    const organization = selectedId(organizationId.value)
    if (organization === null || name.value.trim() === '' || code.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择客户主体并填写服务名称和系统代码')
      )
      return
    }
    const current = beginWrite(canSave.value)
    if (!current) return
    const draft = JSON.stringify([name.value, code.value, driverCode.value, providerType.value])
    try {
      await postSandIamAction('message-provider/save', {
        organization_id: organization,
        name: name.value.trim(),
        code: code.value.trim(),
        provider_type: providerType.value,
        driver_code: driverCode.value.trim()
      })
      if (!current()) return
      ElMessage.success('已保存')
      if (draft === JSON.stringify([name.value, code.value, driverCode.value, providerType.value])) {
        name.value = ''
        code.value = ''
      }
      await loadProviders()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function configureProvider(): Promise<void> {
    if (!canConfigure.value || saving.value || !currentProvider()) return
    const id = selectedId(selectedProviderId.value)
    if (id === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择要配置的消息服务')
      )
      return
    }
    const structured = entryObject(configEntries.value, '配置项')
    if (structured === null) return
    let config: Record<string, unknown> = structured
    try {
      if (configJson.value.trim() !== '') {
        const parsed: unknown = JSON.parse(configJson.value)
        if (!isRecord(parsed)) throw new Error('not object')
        config = { ...config, ...parsed }
      }
    } catch {
      requestError.value = describeSandIamError(new Error('高级配置格式不正确，请检查后重试。'))
      return
    }
    const current = beginWrite(canConfigure.value)
    if (!current) return
    const draft = JSON.stringify([configEntries.value, configJson.value])
    try {
      const result = await postSandIamAction('message-provider/configure', { id, config })
      if (!current()) return
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      if (isRecord(payload) && typeof payload.config_version === 'number') {
        const version = payload.config_version
        providers.value = providers.value.map(row => row.id === id
          ? { ...row, config_version: version, config_configured: true } : row)
      }
      ElMessage.success('已保存')
      if (draft === JSON.stringify([configEntries.value, configJson.value])) clearSecrets()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function testProvider(): Promise<void> {
    if (!canTest.value || saving.value || !currentProvider()) return
    const id = selectedId(selectedProviderId.value)
    const destination = testDestination.value.trim()
    if (id === null || destination === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择消息服务并填写测试接收地址或挑战令牌')
      )
      return
    }
    const current = beginWrite(canTest.value)
    if (!current) return
    try {
      await postSandIamAction('message-provider/test', {
        id,
        destination_or_token: destination
      })
      if (!current()) return
      ElMessage.success('已保存')
      if (testDestination.value.trim() === destination) testDestination.value = ''
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function disableProvider(row: SandIamMessageProviderRow): Promise<void> {
    if (!providers.value.includes(row) || row.status !== 1 || loading.value) return
    const scope = beginWrite(canDisable.value)
    if (!scope) return
    const version = providerVersion
    const current = () => scope() && version === providerVersion && providers.value.includes(row)
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？停用后其全部应用挂载会一起停用。`,
        '停用消息服务',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      saving.value = false
      return
    }
    if (!current() || !canDisable.value || row.status !== 1) { saving.value = false; return }
    requestError.value = null
    try {
      await postSandIamAction('message-provider/disable', { id: row.id })
      if (!current()) return
      ElMessage.success('已停用')
      await loadProviders()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function loadMounts(): Promise<void> {
    if (disposed || !validApplication() || (!canIndex.value && !canMountIndex.value)) return
    const version = ++mountVersion
    const scope = mountScope
    const current = () => !disposed && version === mountVersion && scope === mountScope
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    mountLoading.value = true
    requestError.value = null
    try {
      if (canIndex.value) {
        const result = await getSandIamAdmin('message-provider/options', { application_id: application })
        if (!current()) return
        options.value = parseMessageProviderOptions(result)
      }
      if (canMountIndex.value) {
        const result = await getSandIamAdmin('message-provider/mounts', { application_id: application })
        if (!current()) return
        mounts.value = parseMessageProviderMounts(result)
      }
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === mountVersion) mountLoading.value = false
    }
  }

  async function saveMount(): Promise<void> {
    if (!canMountSave.value || saving.value || mountLoading.value || !validApplication() ||
      !options.value.some(row => row.id === selectedId(mountProviderId.value))) return
    const application = selectedId(applicationId.value)
    const provider = selectedId(mountProviderId.value)
    if (application === null || provider === null || purposes.value.length === 0) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择接入应用、消息服务和使用场景')
      )
      return
    }
    const structured = entryObject(templateEntries.value, '模板对应关系')
    if (structured === null) return
    let templates: Record<string, string> = structured
    if (templateCodes.value.trim() !== '') {
      try {
        const parsed: unknown = JSON.parse(templateCodes.value)
        if (!isRecord(parsed)) throw new Error('not object')
        for (const [key, value] of Object.entries(parsed)) {
          if (typeof value === 'string') templates[key] = value
        }
      } catch {
        requestError.value = describeSandIamError(
          new Error('高级模板配置格式不正确，请检查后重试。')
        )
        return
      }
    }
    const current = beginWrite(canMountSave.value, true)
    if (!current) return
    try {
      await postSandIamAction('message-provider/mount', {
        application_id: application,
        message_provider_id: provider,
        purposes: [...purposes.value],
        template_codes: templates,
        priority: priority.value
      })
      if (!current()) return
      ElMessage.success('已保存')
      await loadMounts()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function unmount(row: SandIamMessageProviderMount): Promise<void> {
    if (!validApplication() || mountLoading.value || !mounts.value.includes(row) || row.status !== 1) return
    const scope = beginWrite(canUnmount.value, true)
    if (!scope) return
    const version = mountVersion
    const current = () => scope() && version === mountVersion && mounts.value.includes(row)
    try {
      await postSandIamAction('message-provider/unmount', { id: row.id })
      if (!current()) return
      ElMessage.success('已停用')
      await loadMounts()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  onMounted(() => {
    void searchOptions('organization')
    void searchOptions('application')
    void loadProviders()
  })
  onScopeDispose(() => { disposed = true; clearSecrets() })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">消息服务</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          为客户主体登记邮件、短信、人机验证或站外通知。默认列表只显示名称、类型、驱动、配置状态和版本；密文和测试接收地址不会回显、写入
          URL 或日志。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看消息服务。请联系平台管理员开通消息服务管理范围。"
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
        description="该客户主体还没有消息服务。这与没有权限不同。"
      />

      <ElForm label-width="180px" class="mb-4">
        <ElFormItem label="客户主体">
          <ElSelect v-model="organizationId" filterable remote :remote-method="(q: string) => searchOptions('organization', q)" :loading="organizationLoading" clearable placeholder="按名称搜索">
            <ElOption
              v-for="row in organizations"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名客户主体'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadProviders"
            >加载服务</ElButton
          >
        </ElFormItem>
        <ElFormItem label="服务名称">
          <ElInput v-model="name" />
        </ElFormItem>
        <ElFormItem label="系统代码">
          <ElInput v-model="code" placeholder="创建后用于接口配置" />
        </ElFormItem>
        <ElFormItem label="服务类型">
          <ElSelect v-model="providerType">
            <ElOption
              v-for="item in SAND_IAM_MESSAGE_TYPES"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="驱动代码">
          <ElInput v-model="driverCode" placeholder="必须是部署环境已登记的驱动" />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canSave || saving" :loading="saving" @click="createProvider">
            创建服务
          </ElButton>
        </ElFormItem>
        <ElFormItem label="要配置的服务">
          <ElSelect v-model="selectedProviderId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in providers"
              :key="String(row.id)"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="配置项">
          <div class="w-full space-y-2">
            <p>保存将完整替换供应商配置，请填写全部必需项；留空不会保留旧值。</p>
            <div v-for="(entry, index) in configEntries" :key="index" class="flex gap-2">
              <ElInput v-model="entry.key" placeholder="配置名称，例如 access_key" />
              <ElInput
                v-model="entry.value"
                type="password"
                show-password
                autocomplete="new-password"
                placeholder="配置值，只写不回显"
              />
              <ElButton text type="danger" @click="removeConfigEntry(index)">删除</ElButton>
            </div>
            <ElButton text type="primary" @click="addConfigEntry">添加配置项</ElButton>
          </div>
        </ElFormItem>
        <ElFormItem label="高级设置">
          <ElButton
            text
            type="primary"
            @click="showAdvancedProviderConfig = !showAdvancedProviderConfig"
          >
            {{ showAdvancedProviderConfig ? '收起高级设置' : '展开高级设置' }}
          </ElButton>
          <p class="mb-0 mt-1 text-xs text-gray-500"
            >只有部署说明要求嵌套配置时才需要使用，普通邮件、短信和通知服务按上面的逐项填写即可。</p
          >
        </ElFormItem>
        <ElFormItem v-if="showAdvancedProviderConfig" label="高级配置">
          <ElInput
            v-model="configJson"
            type="textarea"
            :rows="4"
            placeholder="仅按部署说明填写复杂配置"
          />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canConfigure || saving" :loading="saving" @click="configureProvider">
            保存配置
          </ElButton>
        </ElFormItem>
        <ElFormItem label="测试接收地址或挑战">
          <ElInput v-model="testDestination" type="password" autocomplete="off" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canTest || saving" :loading="saving" @click="testProvider">测试</ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="providers" border stripe empty-text="暂无可见服务">
        <ElTableColumn label="服务名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属客户主体" min-width="160">
          <template #default="scope">{{ organizationName(scope.row.organization_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="类型" min-width="120">
          <template #default="scope">{{ messageTypeLabel(scope.row.provider_type) }}</template>
        </ElTableColumn>
        <ElTableColumn label="驱动" min-width="140">
          <template #default="scope">{{ scope.row.driver_code }}</template>
        </ElTableColumn>
        <ElTableColumn label="配置状态" min-width="100">
          <template #default="scope">{{
            scope.row.config_configured ? '已配置' : '未配置'
          }}</template>
        </ElTableColumn>
        <ElTableColumn label="配置版本" min-width="90">
          <template #default="scope">{{ scope.row.config_version }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="90">
          <template #default="scope">{{ scope.row.status === 1 ? '正常' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="100" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable || saving"
              @click="disableProvider(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination v-model:current-page="page" v-model:page-size="pageSize" :total="total"
        :page-sizes="[20, 50, 100]" layout="total, sizes, prev, pager, next" @size-change="page = 1" />

      <h3 class="mt-8 text-base">应用挂载</h3>
      <p class="text-sm text-gray-500"
        >应用管理员按名称选择本客户主体下已启用的消息服务，不能读取组织级密钥。</p
      >
      <ElForm label-width="180px" class="mb-4">
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable remote :remote-method="(q: string) => searchOptions('application', q)" :loading="applicationLoading" clearable placeholder="按名称搜索">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canIndex && !canMountIndex" :loading="mountLoading" @click="loadMounts"
            >加载可选项</ElButton
          >
        </ElFormItem>
        <ElFormItem label="消息服务">
          <ElSelect v-model="mountProviderId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in options"
              :key="String(row.id)"
              :label="`${row.name}（${row.config_configured ? '已配置' : '未配置'}）`"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="使用场景">
          <ElSelect v-model="purposes" multiple>
            <ElOption
              v-for="item in SAND_IAM_MESSAGE_PURPOSES"
              :key="item.value"
              :label="item.label"
              :value="item.value"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="场景与模板">
          <div class="w-full space-y-2">
            <div v-for="(entry, index) in templateEntries" :key="index" class="flex gap-2">
              <ElSelect
                v-model="entry.key"
                filterable
                allow-create
                default-first-option
                placeholder="选择或填写场景"
              >
                <ElOption
                  v-for="item in SAND_IAM_MESSAGE_PURPOSES"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value"
                />
              </ElSelect>
              <ElInput v-model="entry.value" placeholder="供应商模板名称或代码" />
              <ElButton text type="danger" @click="removeTemplateEntry(index)">删除</ElButton>
            </div>
            <ElButton text type="primary" @click="addTemplateEntry">添加模板对应关系</ElButton>
          </div>
        </ElFormItem>
        <ElFormItem label="高级模板设置">
          <ElButton
            text
            type="primary"
            @click="showAdvancedTemplateCodes = !showAdvancedTemplateCodes"
          >
            {{ showAdvancedTemplateCodes ? '收起高级设置' : '展开高级设置' }}
          </ElButton>
        </ElFormItem>
        <ElFormItem v-if="showAdvancedTemplateCodes" label="高级模板配置">
          <ElInput v-model="templateCodes" placeholder="仅按供应商文档填写复杂模板配置" />
        </ElFormItem>
        <ElFormItem label="优先级">
          <ElInputNumber v-model="priority" :min="1" :max="1000" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canMountSave || saving" :loading="saving" @click="saveMount"
            >保存挂载</ElButton
          >
        </ElFormItem>
      </ElForm>

      <ElTable :data="mounts" border stripe empty-text="该应用尚未挂载消息服务">
        <ElTableColumn label="消息服务" min-width="160">
          <template #default="scope">{{ scope.row.message_provider_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="类型" min-width="120">
          <template #default="scope">{{ messageTypeLabel(scope.row.provider_type) }}</template>
        </ElTableColumn>
        <ElTableColumn label="使用场景" min-width="180">
          <template #default="scope">{{ scope.row.purposes.join('、') || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="优先级" min-width="80">
          <template #default="scope">{{ scope.row.priority }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="90">
          <template #default="scope">{{ scope.row.status === 1 ? '正常' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="100" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              :disabled="!canUnmount || saving"
              @click="unmount(scope.row)"
            >
              解绑
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>
