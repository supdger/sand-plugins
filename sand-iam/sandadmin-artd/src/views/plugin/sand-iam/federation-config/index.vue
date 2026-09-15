<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { describeSandIamError } from '../api/errors'
  import {
    describeFederationConfigError,
    type SandIamFederationType
  } from '../api/federationContracts'
  import { sandIamValueLabel } from '../api/presentation'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import { useAuth } from '@/hooks/core/useAuth'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canConfigure = computed(() => hasAuth('sand_iam:federation:configure'))
  const canMount = computed(() => hasAuth('sand_iam:federation:mount'))
  const canSync = computed(() => hasAuth('sand_iam:federation:sync'))
  const canReadPresets = computed(() => hasAuth('sand_iam:identity_provider:read'))
  const presets = ref<SandIamResourceRow[]>([])
  const presetCode = ref('')
  const presetClientId = ref('')
  const presetRedirectUri = ref('')
  const presetReturnUris = ref('')
  const presetTenantId = ref('')
  const presetLoading = ref(false)
  const presetBusy = ref(false)
  const presetError = ref('')
  const selectedPreset = computed(() => presets.value.find(row => row.code === presetCode.value) ?? null)
  const canGeneratePreset = computed(() => canReadPresets.value && canConfigure.value &&
    selectedProvider.value !== null && selectedPreset.value?.compatibility === 'compatible' &&
    (selectedProvider.value.provider_type === 'local' || selectedProvider.value.provider_type === providerType.value) &&
    selectedPreset.value.protocol === providerType.value)
  let presetListVersion = 0

  const loading = ref(false)
  const saving = ref(false)
  const providers = ref<SandIamResourceRow[]>([])
  const applications = ref<SandIamResourceRow[]>([])
  const selectedProviderId = ref('')
  const mountApplicationId = ref('')
  const providerType = ref<SandIamFederationType>('oidc')
  const conflictPolicy = ref('reject')
  const requestError = ref<SandIamRequestError | null>(null)
  const lastRequestHint = ref('')

  const clientId = ref('')
  const clientSecret = ref('')
  const oauthScope = ref('')
  const issuer = ref('')
  const discoveryUrl = ref('')
  const redirectUri = ref('')
  const authorizationEndpoint = ref('')
  const tokenEndpoint = ref('')
  const userinfoEndpoint = ref('')
  const handoffReturnUris = ref<string[]>(['https://app.example.com/callback'])
  const entityId = ref('')
  const ssoUrl = ref('')
  const acsUrl = ref('')
  const idpCert = ref('')
  const spCert = ref('')
  const spPrivateKey = ref('')
  const ldapUri = ref('')
  const ldapBaseDn = ref('')
  const ldapBindDn = ref('')
  const ldapBindPassword = ref('')
  const kerberosPrincipal = ref('')
  const kerberosKeytabRef = ref('')
  const kerberosRealms = ref<string[]>(['EXAMPLE.COM'])
  const mappingSubject = ref('sub')
  const mappingUsername = ref('preferred_username')
  const mappingDisplayName = ref('name')
  const mappingEmail = ref('email')
  const mappingActive = ref('active')

  const selectedProvider = computed(
    () => providers.value.find((row) => String(row.id) === selectedProviderId.value) ?? null
  )
  let disposed = false
  let contextVersion = 0
  let optionsVersion = 0
  const providerLoading = ref(false)
  watch([selectedProviderId, providerType], () => {
    contextVersion++
    clearSecrets()
    presetCode.value = ''
    presetClientId.value = ''
    presetRedirectUri.value = ''
    presetReturnUris.value = ''
    presetTenantId.value = ''
    presetError.value = ''
    oauthScope.value = ''
    for (const field of [clientId, issuer, discoveryUrl, redirectUri, authorizationEndpoint,
      tokenEndpoint, userinfoEndpoint, entityId, ssoUrl, acsUrl, spCert, ldapUri, ldapBaseDn,
      ldapBindDn, kerberosPrincipal, kerberosKeytabRef]) field.value = ''
    handoffReturnUris.value = []
    kerberosRealms.value = []
    mappingSubject.value = 'sub'
    mappingUsername.value = 'preferred_username'
    mappingDisplayName.value = 'name'
    mappingEmail.value = 'email'
    mappingActive.value = 'active'
    conflictPolicy.value = 'reject'
    mountApplicationId.value = ''
    requestError.value = null
    lastRequestHint.value = ''
  }, { flush: 'sync' })
  watch(selectedProviderId, () => {
    const type = selectedProvider.value?.provider_type
    if (type === 'oidc' || type === 'oauth2' || type === 'saml' ||
      type === 'ldap' || type === 'scim' || type === 'kerberos') providerType.value = type
  }, { flush: 'sync' })
  watch(() => JSON.stringify([buildConfig(), buildMapping(), conflictPolicy.value, mountApplicationId.value]),
    () => { contextVersion++ }, { flush: 'sync' })
  watch([presetCode, presetClientId, presetRedirectUri, presetReturnUris, presetTenantId],
    () => { contextVersion++; presetError.value = '' }, { flush: 'sync' })
  onScopeDispose(() => { disposed = true; optionsVersion++; contextVersion++; presetListVersion++; clearSecrets(); presets.value = [] })
  function captureContext(): () => boolean {
    const version = contextVersion
    const provider = selectedProvider.value
    return () => !disposed && version === contextVersion && provider !== null && selectedProvider.value === provider
  }
  function validMount(): boolean {
    const provider = selectedProvider.value
    const application = applications.value.find(row => String(row.id) === mountApplicationId.value)
    return provider !== null && application !== undefined && provider.status === 1 && application.status === 1 &&
      provider.organization_id === application.organization_id &&
      (provider.scope_type !== 'application' || provider.application_id === application.id)
  }

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  /**
   * 兼容宿主拦截器解包一层或两层后的列表：数组、`{data:[]}`、`{data:{data:[]}}`。
   */
  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    if (isRecord(value.data) && Array.isArray(value.data.data)) {
      return value.data.data.filter(isRecord)
    }
    return []
  }

  function providerLabel(row: SandIamResourceRow): string {
    const name = typeof row.name === 'string' ? row.name : '未命名身份源'
    const protocol = sandIamValueLabel(
      'provider_type',
      typeof row.provider_type === 'string' ? row.provider_type : 'local'
    )
    return `${name} · ${protocol}`
  }

  function applicationLabel(row: SandIamResourceRow): string {
    return typeof row.name === 'string' ? row.name : '未命名接入应用'
  }

  function selectedProviderIdNumber(): number | null {
    const parsed = Number(selectedProviderId.value)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function mountApplicationIdNumber(): number | null {
    const parsed = Number(mountApplicationId.value)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function buildConfig(): Record<string, unknown> {
    if (providerType.value === 'oidc') {
      return {
        client_id: clientId.value.trim(),
        client_secret: clientSecret.value,
        ...(oauthScope.value.trim() === '' ? {} : { scope: oauthScope.value.trim() }),
        issuer: issuer.value.trim(),
        discovery_url: discoveryUrl.value.trim(),
        redirect_uri: redirectUri.value.trim(),
        handoff_return_uris: handoffReturnUris.value
      }
    }
    if (providerType.value === 'oauth2') {
      return {
        client_id: clientId.value.trim(),
        client_secret: clientSecret.value,
        ...(oauthScope.value.trim() === '' ? {} : { scope: oauthScope.value.trim() }),
        authorization_endpoint: authorizationEndpoint.value.trim(),
        token_endpoint: tokenEndpoint.value.trim(),
        userinfo_endpoint: userinfoEndpoint.value.trim(),
        redirect_uri: redirectUri.value.trim(),
        handoff_return_uris: handoffReturnUris.value
      }
    }
    if (providerType.value === 'saml') {
      return {
        entity_id: entityId.value.trim(),
        sso_url: ssoUrl.value.trim(),
        acs_url: acsUrl.value.trim(),
        idp_x509cert: idpCert.value,
        sp_x509cert: spCert.value,
        sp_private_key: spPrivateKey.value,
        handoff_return_uris: handoffReturnUris.value
      }
    }
    if (providerType.value === 'ldap') {
      return {
        uri: ldapUri.value.trim(),
        base_dn: ldapBaseDn.value.trim(),
        bind_dn: ldapBindDn.value.trim(),
        bind_password: ldapBindPassword.value
      }
    }
    // Kerberos 三项安全要求固定提交为 true，页面不提供关闭开关。
    if (providerType.value === 'kerberos') {
      return {
        service_principal: kerberosPrincipal.value.trim(),
        keytab_ref: kerberosKeytabRef.value.trim(),
        allowed_realms: kerberosRealms.value,
        require_channel_binding: true,
        require_replay_cache: true,
        require_mutual_auth: true
      }
    }
    return {}
  }

  function buildMapping(): Record<string, string> {
    const mapping: Record<string, string> = {}
    if (mappingSubject.value.trim() !== '') mapping.subject = mappingSubject.value.trim()
    if (mappingUsername.value.trim() !== '') mapping.username = mappingUsername.value.trim()
    if (mappingDisplayName.value.trim() !== '')
      mapping.display_name = mappingDisplayName.value.trim()
    if (mappingEmail.value.trim() !== '') mapping.email = mappingEmail.value.trim()
    if (mappingActive.value.trim() !== '') mapping.active = mappingActive.value.trim()
    return mapping
  }

  function clearSecrets(): void {
    clientSecret.value = ''
    ldapBindPassword.value = ''
    idpCert.value = ''
    spPrivateKey.value = ''
  }

  async function loadPresets(): Promise<void> {
    const version = ++presetListVersion
    presets.value = []
    presetCode.value = ''
    if (disposed || !canReadPresets.value) return
    presetLoading.value = true
    presetError.value = ''
    try {
      const result = await getSandIamAdmin('identity-provider-preset/index')
      if (disposed || version !== presetListVersion || !canReadPresets.value) return
      presets.value = listRows(result).filter(row => typeof row.code === 'string' &&
        typeof row.name === 'string' && typeof row.protocol === 'string' && typeof row.compatibility === 'string')
    } catch (error: unknown) {
      if (!disposed && version === presetListVersion) presetError.value = describeSandIamError(error).detail
    } finally {
      if (!disposed && version === presetListVersion) presetLoading.value = false
    }
  }

  async function generatePresetDraft(): Promise<void> {
    if (disposed || saving.value || presetBusy.value || !canGeneratePreset.value) return
    const preset = selectedPreset.value
    if (preset === null) return
    const currentContext = captureContext()
    const current = () => currentContext() && canGeneratePreset.value && selectedPreset.value === preset
    const body = {
      code: presetCode.value,
      client_id: presetClientId.value.trim(),
      redirect_uri: presetRedirectUri.value.trim(),
      handoff_return_uris: presetReturnUris.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean),
      ...(preset.tenant_required === true ? { tenant_id: presetTenantId.value.trim() } : {}),
    }
    if (body.client_id === '' || body.redirect_uri === '' || body.handoff_return_uris.length === 0 ||
      (preset.tenant_required === true && presetTenantId.value.trim() === '')) {
      presetError.value = '请填写客户端标识、HTTPS 回调及应用回跳地址；Entra 还需租户标识。'
      return
    }
    presetBusy.value = true
    try {
      // Includes secret inputs in the overwrite warning, never in the request.
      const hasDraft = Object.values(buildConfig()).some(value =>
        typeof value === 'string' ? value !== '' : Array.isArray(value) && value.length > 0) ||
        JSON.stringify(buildMapping()) !== JSON.stringify({ subject: 'sub', username: 'preferred_username', display_name: 'name', email: 'email', active: 'active' })
      if (hasDraft) {
        try {
          await ElMessageBox.confirm('生成的草稿将替换当前协议参数和声明映射，并清空已填密钥。尚不会保存到身份源。', '替换未保存草稿',
            { type: 'warning', confirmButtonText: '替换草稿', cancelButtonText: '取消' })
        } catch { return }
      }
      if (!current()) return
      presetError.value = ''
      const result = await postSandIamAction('identity-provider-preset/draft', body)
      if (!current()) return
      const draft = isRecord(result) && isRecord(result.data) ? result.data : result
      if (!isRecord(draft) || draft.draft_only !== true || draft.save_performed !== false ||
        draft.provider_type !== providerType.value || draft.preset_code !== preset.code ||
        !isRecord(draft.config) || !isRecord(draft.attribute_mapping)) throw new Error('预设草稿格式无效，请继续手工配置。')
      const config = draft.config, mapping = draft.attribute_mapping
      const keys = providerType.value === 'oidc'
        ? ['client_id', 'redirect_uri', 'scope', 'issuer', 'discovery_url']
        : ['client_id', 'redirect_uri', 'scope', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint']
      if (keys.some(key => typeof config[key] !== 'string') ||
        !Array.isArray(config.handoff_return_uris) || !config.handoff_return_uris.every((value: unknown) => typeof value === 'string') ||
        Object.values(mapping).some(value => typeof value !== 'string')) throw new Error('预设草稿字段无效。')
      clearSecrets()
      clientId.value = String(config.client_id)
      redirectUri.value = String(config.redirect_uri)
      oauthScope.value = String(config.scope)
      handoffReturnUris.value = config.handoff_return_uris.filter((value: unknown): value is string => typeof value === 'string')
      issuer.value = typeof config.issuer === 'string' ? config.issuer : ''
      discoveryUrl.value = typeof config.discovery_url === 'string' ? config.discovery_url : ''
      authorizationEndpoint.value = typeof config.authorization_endpoint === 'string' ? config.authorization_endpoint : ''
      tokenEndpoint.value = typeof config.token_endpoint === 'string' ? config.token_endpoint : ''
      userinfoEndpoint.value = typeof config.userinfo_endpoint === 'string' ? config.userinfo_endpoint : ''
      mappingSubject.value = typeof mapping.subject === 'string' ? mapping.subject : ''
      mappingUsername.value = typeof mapping.username === 'string' ? mapping.username : ''
      mappingDisplayName.value = typeof mapping.display_name === 'string' ? mapping.display_name : ''
      mappingEmail.value = typeof mapping.email === 'string' ? mapping.email : ''
      mappingActive.value = typeof mapping.active === 'string' ? mapping.active : ''
      lastRequestHint.value = '预设已填入未保存草稿。请补充客户端密钥并核对参数，再点击“保存敏感配置”。'
    } catch (error: unknown) {
      if (current()) presetError.value = describeSandIamError(error).detail
    } finally { presetBusy.value = false }
  }

  const applicationLoading = ref(false)
  const applicationError = ref('')
  const applicationKeywords = ref('')
  let applicationRequest = 0

  async function loadApplications(keywords = ''): Promise<void> {
    const attempt = ++applicationRequest
    if (disposed) return
    applicationKeywords.value = keywords
    applicationLoading.value = true
    applicationError.value = ''
    try {
      const result = await listSandIamResource('application', { page: 1, limit: 100, keywords })
      if (disposed || attempt !== applicationRequest) return
      const selected = applications.value.find(row => String(row.id) === mountApplicationId.value)
      const rows = listRows(result)
      applications.value = selected && !rows.some(row => row.id === selected.id) ? [selected, ...rows] : rows
    } catch (error: unknown) {
      if (!disposed && attempt === applicationRequest) applicationError.value = describeSandIamError(error).detail
    } finally {
      if (!disposed && attempt === applicationRequest) applicationLoading.value = false
    }
  }

  async function loadOptions(): Promise<void> {
    void loadApplications()
    const version = ++optionsVersion
    loading.value = true
    requestError.value = null
    try {
      const providerResult = await listSandIamResource('identity-provider', { page: 1, limit: 100 })
      if (disposed || version !== optionsVersion) return
      providers.value = listRows(providerResult)
    } catch (error: unknown) {
      if (!disposed && version === optionsVersion) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === optionsVersion) loading.value = false
    }
  }

  async function searchProviders(keywords: string): Promise<void> {
    const version = ++optionsVersion
    selectedProviderId.value = ''
    providers.value = []
    loading.value = false
    if (disposed || !hasAuth('sand_iam:identity_provider:index')) return
    providerLoading.value = true
    try {
      const result = await listSandIamResource('identity-provider', { page: 1, limit: 100, keywords: keywords.trim() })
      if (!disposed && version === optionsVersion) providers.value = listRows(result)
    } catch (error: unknown) {
      if (!disposed && version === optionsVersion) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === optionsVersion) providerLoading.value = false
    }
  }

  async function saveConfig(): Promise<void> {
    if (disposed || saving.value || presetBusy.value || !canConfigure.value || selectedProvider.value === null) return
    if (selectedProvider.value.provider_type !== 'local' &&
      selectedProvider.value.provider_type !== providerType.value) {
      requestError.value = describeSandIamError(new Error('已配置的身份源不能更换协议，请登记新的身份源。'))
      return
    }
    const current = captureContext()
    const providerId = selectedProviderIdNumber()
    if (providerId === null) {
      requestError.value = describeSandIamError(new Error('请先选择要配置的身份源。'))
      return
    }
    let config: Record<string, unknown>
    try {
      config = buildConfig()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      return
    }
    const mapping = buildMapping()
    const configError = describeFederationConfigError(
      providerType.value,
      config,
      mapping,
      conflictPolicy.value
    )
    if (configError !== null) {
      requestError.value = describeSandIamError(new Error(configError))
      return
    }
    saving.value = true
    requestError.value = null
    try {
      await postSandIamAction('federation/configure', {
        provider_id: providerId,
        provider_type: providerType.value,
        config,
        attribute_mapping: mapping,
        conflict_policy: conflictPolicy.value
      })
      if (!current()) return
      ElMessage.success('已保存')
      lastRequestHint.value = '协议配置已写入。密钥不会回显，重新打开本页必须再次填写。'
      clearSecrets()
      await loadOptions()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function mountProvider(): Promise<void> {
    if (disposed || saving.value || presetBusy.value || !canMount.value || !validMount()) return
    const current = captureContext()
    const providerId = selectedProviderIdNumber()
    const applicationId = mountApplicationIdNumber()
    if (providerId === null || applicationId === null) {
      requestError.value = describeSandIamError(new Error('挂载前请选择身份源和接入应用名称。'))
      return
    }
    saving.value = true
    requestError.value = null
    try {
      await postSandIamAction('federation/mount', {
        provider_id: providerId,
        application_id: applicationId
      })
      if (!current()) return
      ElMessage.success('已保存')
      lastRequestHint.value = '身份源已挂到所选接入应用。'
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  async function syncDirectory(): Promise<void> {
    if (disposed || saving.value || presetBusy.value || !canSync.value || !validMount() ||
      selectedProvider.value?.provider_type !== 'ldap') return
    const current = captureContext()
    const providerId = selectedProviderIdNumber()
    const applicationId = mountApplicationIdNumber()
    if (providerId === null || applicationId === null) {
      requestError.value = describeSandIamError(
        new Error('同步前请选择 LDAP 身份源和已挂载的接入应用。')
      )
      return
    }
    saving.value = true
    try {
      await ElMessageBox.confirm(
        '确认立即同步该目录吗？同步失败或只完成一部分时，页面不会显示“已连接生产目录”。',
        '同步目录',
        { type: 'warning', confirmButtonText: '开始同步', cancelButtonText: '取消' }
      )
    } catch {
      saving.value = false
      return
    }
    if (!current() || !canSync.value || !validMount()) {
      saving.value = false
      return
    }
    requestError.value = null
    try {
      const result = await postSandIamAction('federation/sync', {
        provider_id: providerId,
        application_id: applicationId
      })
      if (!current()) return
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const state = isRecord(payload) && typeof payload.state === 'string' ? payload.state : ''
      if (state === 'succeeded') {
        ElMessage.success('已保存')
        lastRequestHint.value = '本次目录同步已完整完成。'
      } else {
        requestError.value = describeSandIamError(
          new Error('本次同步没有完整完成，请检查目录配置后重试。')
        )
        lastRequestHint.value = '请根据失败提示检查目录配置后重试。'
      }
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      saving.value = false
    }
  }

  onMounted(() => {
    void loadOptions()
    void loadPresets()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never" v-loading="loading || saving">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">联合身份源配置</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          给已登记的身份源填写登录协议参数。客户端密钥、目录密码和 SAML
          私钥只在保存时填写，重新打开页面不会显示。保存后请用测试账号验证登录是否正常。
        </p>
      </div>

      <ElAlert
        v-if="!canConfigure"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号暂时不能配置联合身份源。请联系平台管理员开通联合身份源管理范围。"
      />
      <ElAlert
        v-else-if="!loading && providers.length === 0"
        class="mb-4"
        type="info"
        :closable="false"
        title="还没有身份源"
        description="请先到「身份源」按名称登记一条记录，再回到本页填写协议。这与没有权限不同。"
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
        v-else-if="lastRequestHint !== ''"
        class="mb-4"
        type="success"
        :closable="false"
        title="已保存"
        :description="lastRequestHint"
      />

      <ElForm label-width="170px">
        <ElFormItem label="身份源">
          <ElSelect
            v-model="selectedProviderId"
            filterable
            remote
            :remote-method="searchProviders"
            :loading="providerLoading"
            clearable
            placeholder="按名称选择身份源"
            style="width: 100%"
          >
            <ElOption
              v-for="row in providers"
              :key="String(row.id)"
              :label="providerLabel(row)"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="selectedProvider" label="当前状态">
          <span>
            {{
              sandIamValueLabel('provider_type', String(selectedProvider.provider_type ?? 'local'))
            }}
            ·
            {{
              selectedProvider.secret_configured === true ||
              selectedProvider.secret_configured === 1
                ? '密钥已配置'
                : '密钥未配置'
            }}
          </span>
        </ElFormItem>
        <ElFormItem label="协议">
          <ElSelect v-model="providerType" :disabled="selectedProvider !== null && selectedProvider.provider_type !== 'local'" style="width: 100%">
            <ElOption label="OpenID Connect" value="oidc" />
            <ElOption label="OAuth 2.0" value="oauth2" />
            <ElOption label="SAML" value="saml" />
            <ElOption label="LDAP 目录" value="ldap" />
            <ElOption label="SCIM 供给" value="scim" />
            <ElOption label="Kerberos" value="kerberos" />
          </ElSelect>
          <p class="mb-0 mt-1 text-xs text-gray-500">
            Kerberos
            只保存服务名称、密钥引用和允许的域。运行环境未准备好时，登录会被拒绝；请完成测试登录后再投入使用。
          </p>
        </ElFormItem>
        <ElFormItem label="冲突策略">
          <ElSelect v-model="conflictPolicy" style="width: 100%">
            <ElOption label="已存在则拒绝" value="reject" />
            <ElOption label="允许新建应用用户" value="create" />
          </ElSelect>
        </ElFormItem>

        <ElFormItem label="身份源预设">
          <ElSelect v-model="presetCode" clearable :loading="presetLoading" placeholder="可选，或继续手工配置">
            <ElOption v-for="preset in presets" :key="String(preset.code)" :value="String(preset.code)"
              :label="`${String(preset.name)} · ${preset.compatibility === 'compatible' ? '可生成草稿' : '需人工配置'}`" />
          </ElSelect>
          <ElButton :disabled="!canReadPresets || presetLoading" @click="loadPresets">刷新预设</ElButton>
        </ElFormItem>
        <ElAlert v-if="presetError" type="error" :closable="false" :title="presetError" class="mb-4" />
        <template v-if="selectedPreset">
          <ElAlert class="mb-4" :closable="false" type="info"
            :title="selectedPreset.compatibility === 'compatible' ? '预设只生成未保存草稿，不包含密钥' : '此预设需要人工配置'"
            :description="typeof selectedPreset.manual_reason === 'string' ? selectedPreset.manual_reason : '请核对身份源协议和实际租户参数，生成后仍需补充密钥并主动保存。'" />
          <ElAlert v-if="selectedPreset.protocol !== providerType" class="mb-4" :closable="false" type="warning"
            title="预设协议与当前身份源不匹配，请选择匹配预设或身份源。" />
          <template v-if="selectedPreset.compatibility === 'compatible' && selectedPreset.protocol === providerType">
            <ElFormItem label="预设客户端标识"><ElInput v-model="presetClientId" /></ElFormItem>
            <ElFormItem v-if="selectedPreset.tenant_required === true" label="Entra 租户标识"><ElInput v-model="presetTenantId" /></ElFormItem>
            <ElFormItem label="预设 HTTPS 回调"><ElInput v-model="presetRedirectUri" /></ElFormItem>
            <ElFormItem label="预设应用回跳地址"><ElInput v-model="presetReturnUris" type="textarea" placeholder="每行一个 HTTPS 地址" /></ElFormItem>
            <ElFormItem>
              <ElButton :disabled="!canGeneratePreset || saving || presetBusy" :loading="presetBusy" @click="generatePresetDraft">生成未保存草稿</ElButton>
            </ElFormItem>
          </template>
        </template>

        <template v-if="providerType === 'oidc' || providerType === 'oauth2'">
          <ElFormItem label="客户端标识">
            <ElInput v-model="clientId" placeholder="由身份源颁发，不是密钥" />
          </ElFormItem>
          <ElFormItem label="协议 Scope"><ElInput v-model="oauthScope" placeholder="留空使用协议默认值；多个范围以空格分隔" /></ElFormItem>
          <ElFormItem label="客户端密钥">
            <ElInput
              v-model="clientSecret"
              type="password"
              show-password
              autocomplete="new-password"
              placeholder="只写不读，重新打开必须再填"
            />
          </ElFormItem>
          <ElFormItem v-if="providerType === 'oidc'" label="签发方">
            <ElInput v-model="issuer" placeholder="https://accounts.example.com" />
          </ElFormItem>
          <ElFormItem v-if="providerType === 'oidc'" label="发现地址">
            <ElInput
              v-model="discoveryUrl"
              placeholder="https://accounts.example.com/.well-known/openid-configuration"
            />
          </ElFormItem>
          <ElFormItem v-if="providerType === 'oauth2'" label="授权地址">
            <ElInput v-model="authorizationEndpoint" />
          </ElFormItem>
          <ElFormItem v-if="providerType === 'oauth2'" label="令牌地址">
            <ElInput v-model="tokenEndpoint" />
          </ElFormItem>
          <ElFormItem v-if="providerType === 'oauth2'" label="用户资料地址">
            <ElInput v-model="userinfoEndpoint" />
          </ElFormItem>
          <ElFormItem label="回调地址">
            <ElInput v-model="redirectUri" placeholder="https://login.example.com/callback" />
          </ElFormItem>
          <ElFormItem label="应用回跳地址">
            <ElSelect
              v-model="handoffReturnUris"
              multiple
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              placeholder="逐条输入后按回车添加"
            >
              <ElOption v-for="uri in handoffReturnUris" :key="uri" :label="uri" :value="uri" />
            </ElSelect>
            <p class="mb-0 mt-1 text-xs text-gray-500"
              >每条地址单独添加，必须是精确的 HTTPS 地址，不能带登录过程参数。</p
            >
          </ElFormItem>
        </template>

        <template v-if="providerType === 'saml'">
          <ElFormItem label="实体标识">
            <ElInput v-model="entityId" />
          </ElFormItem>
          <ElFormItem label="单点登录地址">
            <ElInput v-model="ssoUrl" />
          </ElFormItem>
          <ElFormItem label="接收地址">
            <ElInput v-model="acsUrl" />
          </ElFormItem>
          <ElFormItem label="对方证书">
            <ElInput v-model="idpCert" type="textarea" :rows="3" placeholder="只写不读" />
          </ElFormItem>
          <ElFormItem label="本方证书">
            <ElInput v-model="spCert" type="textarea" :rows="3" />
          </ElFormItem>
          <ElFormItem label="本方私钥">
            <ElInput
              v-model="spPrivateKey"
              type="textarea"
              :rows="3"
              placeholder="只写不读，不要粘贴到备注"
            />
          </ElFormItem>
          <ElFormItem label="应用回跳地址">
            <ElSelect
              v-model="handoffReturnUris"
              multiple
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              placeholder="逐条输入后按回车添加"
            >
              <ElOption v-for="uri in handoffReturnUris" :key="uri" :label="uri" :value="uri" />
            </ElSelect>
          </ElFormItem>
        </template>

        <template v-if="providerType === 'ldap'">
          <ElFormItem label="目录地址">
            <ElInput v-model="ldapUri" placeholder="ldaps://directory.example.com" />
          </ElFormItem>
          <ElFormItem label="搜索起点">
            <ElInput v-model="ldapBaseDn" />
          </ElFormItem>
          <ElFormItem label="绑定账号">
            <ElInput v-model="ldapBindDn" />
          </ElFormItem>
          <ElFormItem label="绑定密码">
            <ElInput
              v-model="ldapBindPassword"
              type="password"
              show-password
              autocomplete="new-password"
              placeholder="只写不读"
            />
          </ElFormItem>
        </template>

        <template v-if="providerType === 'kerberos'">
          <ElFormItem label="服务主体名称">
            <ElInput v-model="kerberosPrincipal" placeholder="HTTP/app.example.com@EXAMPLE.COM" />
            <p class="mb-0 mt-1 text-xs text-gray-500">只填写服务名称。不要粘贴任何密钥材料。</p>
          </ElFormItem>
          <ElFormItem label="keytab 引用">
            <ElInput v-model="kerberosKeytabRef" placeholder="prod-http-keytab" />
            <p class="mb-0 mt-1 text-xs text-gray-500"
              >只填写引用标识。不要粘贴 keytab 内容或服务器路径。</p
            >
          </ElFormItem>
          <ElFormItem label="允许的域">
            <ElSelect
              v-model="kerberosRealms"
              multiple
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              placeholder="逐条输入后按回车添加"
            >
              <ElOption
                v-for="realm in kerberosRealms"
                :key="realm"
                :label="realm"
                :value="realm"
              />
            </ElSelect>
            <p class="mb-0 mt-1 text-xs text-gray-500">一个域一项，不需要输入方括号或逗号。</p>
          </ElFormItem>
          <ElAlert
            class="mb-4"
            type="warning"
            :closable="false"
            title="三项安全要求不可关闭"
            description="通道绑定、重放缓存和双向认证会随配置固定提交为开启。当前环境若缺少 GSSAPI、TLS 上下文或 replay cache，后端会拒绝，本页不会伪造成功。"
          />
        </template>

        <template v-if="providerType !== 'scim' && providerType !== 'kerberos'">
          <ElFormItem label="主体声明">
            <ElInput v-model="mappingSubject" />
          </ElFormItem>
          <ElFormItem label="用户名声明">
            <ElInput v-model="mappingUsername" />
          </ElFormItem>
          <ElFormItem label="显示名称声明">
            <ElInput v-model="mappingDisplayName" />
          </ElFormItem>
          <ElFormItem label="邮箱声明">
            <ElInput v-model="mappingEmail" />
          </ElFormItem>
          <ElFormItem label="启用状态声明">
            <ElInput v-model="mappingActive" />
          </ElFormItem>
        </template>

        <ElFormItem>
          <ElButton type="primary" :disabled="!canConfigure" @click="saveConfig"
            >保存协议配置</ElButton
          >
        </ElFormItem>

        <ElFormItem v-if="applicationError" label="应用搜索失败">
          <span role="alert">{{ applicationError }}</span>
          <ElButton :loading="applicationLoading" @click="loadApplications(applicationKeywords)">重试搜索</ElButton>
        </ElFormItem>
        <ElFormItem label="挂载到接入应用">
          <ElSelect
            v-model="mountApplicationId"
            remote :remote-method="loadApplications" :loading="applicationLoading"
            filterable
            clearable
            placeholder="按名称选择接入应用"
            style="width: 100%"
          >
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="applicationLabel(row)"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElSpace>
            <ElButton :disabled="!canMount" @click="mountProvider">挂载</ElButton>
            <ElButton :disabled="!canSync || providerType !== 'ldap'" @click="syncDirectory">
              同步目录
            </ElButton>
          </ElSpace>
        </ElFormItem>
      </ElForm>
    </ElCard>
  </div>
</template>
