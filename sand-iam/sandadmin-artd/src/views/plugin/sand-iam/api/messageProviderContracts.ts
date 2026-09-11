/**
 * MessageProviderController::safeProvider / options / mounts 已冻结字段。
 * 不解析 encrypted_config、config 原值或测试 destination。
 */

export interface SandIamMessageProviderRow {
  readonly id: number
  readonly organization_id: number
  readonly code: string
  readonly name: string
  readonly provider_type: string
  readonly driver_code: string
  readonly config_version: number
  readonly config_configured: boolean
  readonly status: number
}

export interface SandIamMessageProviderOption {
  readonly id: number
  readonly name: string
  readonly provider_type: string
  readonly config_configured: boolean
}

export interface SandIamMessageProviderMount {
  readonly id: number
  readonly message_provider_id: number
  readonly message_provider_name: string
  readonly provider_type: string
  readonly purposes: readonly string[]
  readonly template_codes: Readonly<Record<string, string>>
  readonly priority: number
  readonly status: number
}

export const SAND_IAM_MESSAGE_TYPES = [
  { value: 'email', label: '邮件' },
  { value: 'sms', label: '短信' },
  { value: 'captcha', label: '人机验证' },
  { value: 'notification', label: '站外安全通知' }
] as const

export const SAND_IAM_MESSAGE_PURPOSES = [
  { value: 'verification', label: '验证码' },
  { value: 'invitation', label: '邀请' },
  { value: 'register', label: '注册' },
  { value: 'login', label: '登录' },
  { value: 'password_reset', label: '找回密码' },
  { value: 'security_alert', label: '安全告警' },
  { value: 'account_notice', label: '账号通知' }
] as const

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function unwrapList(value: unknown): unknown[] {
  if (Array.isArray(value)) return value
  if (isRecord(value) && Array.isArray(value.data)) return value.data
  if (isRecord(value) && isRecord(value.data) && Array.isArray(value.data.data)) {
    return value.data.data
  }
  return []
}

function readPositiveInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function readString(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

function readConfigured(value: unknown): boolean {
  return value === true || value === 1
}

export function parseMessageProvider(value: unknown): SandIamMessageProviderRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const organizationId = readPositiveInt(value.organization_id)
  const code = readString(value.code)
  const name = readString(value.name)
  const providerType = readString(value.provider_type)
  const driverCode = readString(value.driver_code)
  const configVersion =
    typeof value.config_version === 'number' &&
    Number.isInteger(value.config_version) &&
    value.config_version >= 0
      ? value.config_version
      : null
  const status = value.status
  if (
    id === null ||
    organizationId === null ||
    code === null ||
    name === null ||
    providerType === null ||
    driverCode === null ||
    configVersion === null ||
    typeof status !== 'number'
  ) {
    return null
  }
  return {
    id,
    organization_id: organizationId,
    code,
    name,
    provider_type: providerType,
    driver_code: driverCode,
    config_version: configVersion,
    config_configured: readConfigured(value.config_configured),
    status
  }
}

export function parseMessageProviders(value: unknown): SandIamMessageProviderRow[] {
  return unwrapList(value)
    .map((item) => parseMessageProvider(item))
    .filter((item): item is SandIamMessageProviderRow => item !== null)
}

export function parseMessageProviderOption(value: unknown): SandIamMessageProviderOption | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const name = readString(value.name)
  const providerType = readString(value.provider_type)
  if (id === null || name === null || providerType === null) return null
  return {
    id,
    name,
    provider_type: providerType,
    config_configured: readConfigured(value.config_configured)
  }
}

export function parseMessageProviderOptions(value: unknown): SandIamMessageProviderOption[] {
  return unwrapList(value)
    .map((item) => parseMessageProviderOption(item))
    .filter((item): item is SandIamMessageProviderOption => item !== null)
}

export function parseMessageProviderMount(value: unknown): SandIamMessageProviderMount | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const providerId = readPositiveInt(value.message_provider_id)
  const name = readString(value.message_provider_name)
  const providerType = readString(value.provider_type) ?? ''
  const priority = value.priority
  const status = value.status
  if (
    id === null ||
    providerId === null ||
    name === null ||
    typeof priority !== 'number' ||
    typeof status !== 'number'
  ) {
    return null
  }
  const purposes = Array.isArray(value.purposes)
    ? value.purposes.filter((item): item is string => typeof item === 'string')
    : []
  const templateCodes: Record<string, string> = {}
  if (isRecord(value.template_codes)) {
    for (const [key, code] of Object.entries(value.template_codes)) {
      if (typeof code === 'string') templateCodes[key] = code
    }
  }
  return {
    id,
    message_provider_id: providerId,
    message_provider_name: name,
    provider_type: providerType,
    purposes,
    template_codes: templateCodes,
    priority,
    status
  }
}

export function parseMessageProviderMounts(value: unknown): SandIamMessageProviderMount[] {
  return unwrapList(value)
    .map((item) => parseMessageProviderMount(item))
    .filter((item): item is SandIamMessageProviderMount => item !== null)
}

export function messageTypeLabel(type: string): string {
  return SAND_IAM_MESSAGE_TYPES.find((item) => item.value === type)?.label ?? type
}
