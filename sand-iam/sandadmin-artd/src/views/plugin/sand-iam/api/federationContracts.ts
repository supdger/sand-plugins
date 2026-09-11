/**
 * 联合身份源 configure 只接受 FederationService::validateConfig 已冻结的协议与字段。
 * Kerberos 只提交 SPN、keytab 引用和允许 Realm；三项安全要求固定为开启。
 */
export const SAND_IAM_FEDERATION_TYPES = [
  'oidc',
  'oauth2',
  'saml',
  'ldap',
  'scim',
  'kerberos'
] as const

export type SandIamFederationType = (typeof SAND_IAM_FEDERATION_TYPES)[number]

export const SAND_IAM_FEDERATION_MAPPING_KEYS = [
  'subject',
  'username',
  'display_name',
  'email',
  'active'
] as const

export const SAND_IAM_FEDERATION_SECRET_KEYS = [
  'client_secret',
  'bind_password',
  'idp_x509cert',
  'sp_private_key'
] as const

export const SAND_IAM_IDENTITY_PROVIDER_CODE_RULE =
  '身份源标识创建后不可修改。须以小写字母开头，最长 64 位，只能包含小写字母、数字、点、下划线、冒号或短横线。'

const PROVIDER_CODE_PATTERN = /^[a-z][a-z0-9_.:-]{0,63}$/
const MAPPING_SOURCE_PATTERN = /^[A-Za-z][A-Za-z0-9_.-]{0,127}$/

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * 校验身份源创建标识。与客户主体系统代码不同，这里允许点号和冒号。
 */
export function describeIdentityProviderCodeError(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') {
    return `请填写身份源标识。${SAND_IAM_IDENTITY_PROVIDER_CODE_RULE}`
  }
  if (!PROVIDER_CODE_PATTERN.test(trimmed)) {
    return SAND_IAM_IDENTITY_PROVIDER_CODE_RULE
  }
  return null
}

/**
 * 组织级不得携带接入应用；应用级必须选择接入应用。
 */
export function describeIdentityProviderPayloadError(
  payload: Readonly<Record<string, unknown>>,
  creating: boolean
): string | null {
  if (creating) {
    const codeError =
      typeof payload.code === 'string'
        ? describeIdentityProviderCodeError(payload.code)
        : '请填写身份源标识。'
    if (codeError !== null) return codeError
  }
  const scope = payload.scope_type
  if (scope !== 'application' && scope !== 'organization') {
    return creating ? '请选择身份源范围：仅本应用，或整个客户主体可挂载。' : null
  }
  const organizationId = payload.organization_id
  if (
    typeof organizationId !== 'number' ||
    !Number.isInteger(organizationId) ||
    organizationId <= 0
  ) {
    return creating ? '请选择所属客户主体。' : null
  }
  const applicationId = payload.application_id
  if (scope === 'organization' && applicationId !== undefined) {
    return '组织级身份源不要选择接入应用；需要给某个应用使用时，请在配置页挂载。'
  }
  if (
    scope === 'application' &&
    (typeof applicationId !== 'number' || !Number.isInteger(applicationId) || applicationId <= 0)
  ) {
    return '应用级身份源必须选择所属接入应用。'
  }
  return null
}

function readString(value: unknown): string {
  return typeof value === 'string' ? value.trim() : ''
}

function readStringArray(value: unknown): string[] | null {
  if (!Array.isArray(value)) return null
  const items: string[] = []
  for (const item of value) {
    if (typeof item !== 'string' || item.trim() === '') return null
    items.push(item.trim())
  }
  return items
}

/**
 * 按已冻结协议检查配置对象；密钥字段只要求本次填写，不回读旧值。
 */
export function describeFederationConfigError(
  providerType: string,
  config: unknown,
  mapping: unknown,
  conflictPolicy: string
): string | null {
  if (!SAND_IAM_FEDERATION_TYPES.includes(providerType as SandIamFederationType)) {
    return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请选择已支持的协议；通行密钥不在本页配置。'
  }
  if (conflictPolicy !== 'reject' && conflictPolicy !== 'create') {
    return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 冲突策略只能选择拒绝或新建。'
  }
  if (!isRecord(mapping)) {
    return 'SAND_IAM_FEDERATION_MAPPING_INVALID: 属性映射必须是对象。'
  }
  for (const [name, source] of Object.entries(mapping)) {
    if (
      !SAND_IAM_FEDERATION_MAPPING_KEYS.includes(
        name as (typeof SAND_IAM_FEDERATION_MAPPING_KEYS)[number]
      ) ||
      typeof source !== 'string' ||
      !MAPPING_SOURCE_PATTERN.test(source)
    ) {
      return 'SAND_IAM_FEDERATION_MAPPING_INVALID: 只能映射主体、用户名、显示名称、邮箱和是否启用。'
    }
  }
  if (!isRecord(config) || Object.keys(config).length > 32) {
    return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 协议配置超出允许范围。'
  }
  if (providerType === 'oidc') {
    if (readString(config.client_id) === '') {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写客户端标识。'
    }
    if (readString(config.client_secret) === '') {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写客户端密钥。重新保存会覆盖整份配置，旧密钥不会回显。'
    }
    if (readString(config.discovery_url) === '' && readString(config.issuer) === '') {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写发现地址或签发方。'
    }
    if (readStringArray(config.handoff_return_uris) === null) {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写 1 至 32 条精确 HTTPS 回跳地址。'
    }
  }
  if (providerType === 'oauth2') {
    for (const key of [
      'authorization_endpoint',
      'token_endpoint',
      'userinfo_endpoint',
      'client_id',
      'redirect_uri'
    ]) {
      if (readString(config[key]) === '') {
        return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: OAuth 配置缺少必填地址或客户端标识。'
      }
    }
    if (readString(config.client_secret) === '') {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写客户端密钥。重新保存会覆盖整份配置，旧密钥不会回显。'
    }
    if (readStringArray(config.handoff_return_uris) === null) {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写 1 至 32 条精确 HTTPS 回跳地址。'
    }
  }
  if (providerType === 'saml') {
    for (const key of [
      'entity_id',
      'sso_url',
      'acs_url',
      'idp_x509cert',
      'sp_x509cert',
      'sp_private_key'
    ]) {
      if (readString(config[key]) === '') {
        return 'SAND_IAM_SAML_CONFIGURATION_INVALID: SAML 配置缺少必填项；证书和私钥只写不读。'
      }
    }
    if (readStringArray(config.handoff_return_uris) === null) {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写 1 至 32 条精确 HTTPS 回跳地址。'
    }
  }
  if (providerType === 'ldap') {
    if (readString(config.uri) === '' || readString(config.base_dn) === '') {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: LDAP 必须填写 ldaps 地址和搜索起点。'
    }
    if (readString(config.bind_password) === '') {
      return 'SAND_IAM_FEDERATION_PROVIDER_CONFIGURATION_INVALID: 请填写绑定密码。重新保存会覆盖整份配置，旧密码不会回显。'
    }
  }
  if (providerType === 'kerberos') {
    const principal = readString(config.service_principal)
    if (!/^HTTP\/[A-Za-z0-9.-]+@[A-Z0-9][A-Z0-9.-]{1,127}$/.test(principal)) {
      return 'SAND_IAM_KERBEROS_CONFIGURATION_INVALID: Kerberos SPN 必须是 HTTP/主机@REALM，不要填写 principal 以外的原值。'
    }
    if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/.test(readString(config.keytab_ref))) {
      return 'SAND_IAM_KERBEROS_CONFIGURATION_INVALID: 请填写 keytab 引用标识，不要粘贴 keytab 内容或路径。'
    }
    const realms = readStringArray(config.allowed_realms)
    if (realms === null || realms.length === 0 || realms.length > 20) {
      return 'SAND_IAM_KERBEROS_CONFIGURATION_INVALID: 请填写 1 至 20 个允许 Realm。'
    }
    if (
      config.require_channel_binding !== true ||
      config.require_replay_cache !== true ||
      config.require_mutual_auth !== true
    ) {
      return 'SAND_IAM_KERBEROS_CONFIGURATION_INVALID: 通道绑定、重放缓存和双向认证三项安全要求不可关闭。'
    }
  }
  return null
}

export function isFederationSecretKey(key: string): boolean {
  return SAND_IAM_FEDERATION_SECRET_KEYS.includes(
    key as (typeof SAND_IAM_FEDERATION_SECRET_KEYS)[number]
  )
}
