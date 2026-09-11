import type { SandIamFormField, SandIamListParams } from './types'

export {
  describeFederationConfigError,
  describeIdentityProviderCodeError,
  describeIdentityProviderPayloadError,
  SAND_IAM_IDENTITY_PROVIDER_CODE_RULE
} from './federationContracts'
export {
  describeApiGovernanceCodeError,
  describeApiResourcePayloadError,
  describeBusinessActionPayloadError,
  describeOAuthClientPayloadError,
  describeOnboardingManifestError,
  describeRouteBindingPayloadError,
  parseAuthorizationDecision,
  parseOidcSigningStatus,
  parseOnboardingPreview,
  parsePolicySimulation,
  summarizeStringList,
  SAND_IAM_API_CODE_RULE,
  SAND_IAM_ONBOARDING_FORMAT
} from './governanceContracts'

/**
 * 客户主体、接入应用、应用环境、服务调用身份的系统代码规则。
 * 页面提示和提交失败必须回显同一条文，不能只返回 invalid code。
 */
export const SAND_IAM_OBJECT_CODE_RULE =
  '系统代码（用于接口配置）只用于接口配置、日志追踪和授权引用，不是给人辨识对象的名称。创建后不可修改。只能使用 2–64 位小写英文字母、数字、短横线（-）和下划线（_），且必须以字母或数字开头。不要把日期、环境、版本号或一次性验收编号写进长期对象代码。'

const OBJECT_CODE_PATTERN = /^[a-z0-9][a-z0-9_-]{1,63}$/

/**
 * 生成系统代码字段的用途、格式、示例和唯一范围说明。
 */
export function sandIamObjectCodeGuidance(
  example: string,
  uniqueness: string
): Pick<SandIamFormField, 'help' | 'placeholder'> {
  return {
    help: `${SAND_IAM_OBJECT_CODE_RULE} 示例：${example}。${uniqueness}`,
    placeholder: example
  }
}

/**
 * 校验系统代码。空值或格式错误都返回同一条可读规则；合法时返回 null。
 */
export function describeSandIamObjectCodeError(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') {
    return `请填写系统代码（用于接口配置）。${SAND_IAM_OBJECT_CODE_RULE}`
  }
  if (!OBJECT_CODE_PATTERN.test(trimmed)) {
    return SAND_IAM_OBJECT_CODE_RULE
  }
  return null
}

export interface SandIamReferenceSelections {
  readonly organizationId: string
  readonly applicationId: string
  readonly environmentId: string
}

export function positiveReferenceId(value: string): number | null {
  if (value.trim() === '') return null
  const parsed = Number(value)
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

export function cascadedReferenceParams(
  key: string,
  activeFilterKeys: readonly string[],
  selections: SandIamReferenceSelections
): SandIamListParams | null {
  const base = { page: 1, limit: 100 } as const
  if (key === 'application_id' && activeFilterKeys.includes('organization_id')) {
    const organizationId = positiveReferenceId(selections.organizationId)
    return organizationId === null ? null : { ...base, organization_id: organizationId }
  }
  if (key === 'environment_id' && activeFilterKeys.includes('application_id')) {
    const applicationId = positiveReferenceId(selections.applicationId)
    return applicationId === null ? null : { ...base, application_id: applicationId }
  }
  if (key === 'workload_client_id' && activeFilterKeys.includes('environment_id')) {
    const environmentId = positiveReferenceId(selections.environmentId)
    return environmentId === null ? null : { ...base, environment_id: environmentId }
  }
  return base
}

export function choosePolicySubject(
  changed: 'role' | 'identity',
  nextValue: string,
  currentRoleId: string,
  currentIdentityId: string
): Readonly<{ roleId: string; identityId: string }> {
  if (changed === 'role') {
    return {
      roleId: nextValue,
      identityId: nextValue === '' ? currentIdentityId : ''
    }
  }
  return {
    roleId: nextValue === '' ? currentRoleId : '',
    identityId: nextValue
  }
}

export function shouldSubmitSandIamField(
  field: Pick<SandIamFormField, 'advanced' | 'omitFromPayload'>,
  advancedExpanded: boolean
): boolean {
  if (field.omitFromPayload === true) return false
  return field.advanced !== true || advancedExpanded
}

export function sandIamReferenceLabel(
  row: Readonly<Record<string, unknown>>,
  parentLabel = ''
): string {
  const name =
    typeof row.name === 'string'
      ? row.name
      : typeof row.display_name === 'string'
        ? row.display_name
        : typeof row.code === 'string'
          ? row.code
          : '未命名记录'
  const code = typeof row.code === 'string' && row.code !== name ? ` · ${row.code}` : ''
  const parent = parentLabel.trim() === '' ? '' : ` · 所属：${parentLabel}`
  return `${name}${code}${parent}`
}

export type SandIamActionKind =
  | 'publish-policy'
  | 'revoke-policy'
  | 'revoke-grant'
  | 'revoke-relation'
  | 'rotate-credential'
  | 'revoke-credential'
  | 'rotate-oauth-secret'
  | 'publish-business-action'

const ACTION_IMPACTS: Readonly<Record<SandIamActionKind, string>> = {
  'publish-policy': '发布后，该策略会参与运行时授权判断。需要停止生效时可以撤销策略。',
  'revoke-policy':
    '撤销后，该策略不再参与新的授权判断，审计记录会保留；原发布记录不能恢复，需要时请复制配置并重新发布。',
  'revoke-grant':
    '撤销后，该服务调用身份立即失去这项服务动作权限；原授权不能恢复，需要时请重新创建授权。',
  'revoke-relation': '撤销后，该身份将不再拥有此角色或用户类型；需要恢复时可以重新授予关系。',
  'rotate-credential': '轮换后旧凭证立即失效且不能恢复；新明文只展示一次，请在关闭前完成安全交付。',
  'revoke-credential':
    '撤销后，该凭证永久失效且不能恢复，审计记录会保留；需要继续调用时请重新签发凭证。',
  'rotate-oauth-secret':
    '轮换后旧客户端密钥立即失效且不能恢复；新明文只展示一次，请在关闭前完成安全交付。',
  'publish-business-action':
    '发布后，该业务动作成为可审计声明；代码从创建起就不能改，停用后相关接口授权按拒绝处理。'
}

export function sandIamActionImpact(kind: SandIamActionKind): string {
  return ACTION_IMPACTS[kind]
}

const AUTH_POLICY_RANGES: Readonly<Record<string, readonly [number, number]>> = {
  password_min_length: [12, 128],
  password_max_length: [12, 128],
  access_token_ttl_seconds: [60, 3600],
  refresh_token_ttl_seconds: [300, 7776000],
  verification_ttl_seconds: [60, 3600],
  max_login_failures: [3, 20],
  lock_seconds: [60, 86400],
  rate_limit_per_minute: [1, 1000]
}

/**
 * 认证策略提交前按已冻结后端范围做可读校验，避免把 1/2 开关或超范围数字直接交给用户。
 */
export function describeAuthPolicyPayloadError(
  payload: Readonly<Record<string, unknown>>
): string | null {
  for (const [field, range] of Object.entries(AUTH_POLICY_RANGES)) {
    const value = payload[field]
    if (typeof value !== 'number') continue
    if (value < range[0] || value > range[1]) {
      return `SAND_IAM_VALIDATION_ERROR: 认证策略参数超出允许范围`
    }
  }
  const minimumLength = payload.password_min_length
  const maximumLength = payload.password_max_length
  if (
    typeof minimumLength === 'number' &&
    typeof maximumLength === 'number' &&
    minimumLength > maximumLength
  ) {
    return 'SAND_IAM_VALIDATION_ERROR: 密码最小长度不能大于最大长度'
  }
  const rpId = typeof payload.webauthn_rp_id === 'string' ? payload.webauthn_rp_id.trim() : ''
  const origins = payload.webauthn_allowed_origins
  if (Array.isArray(origins) && (rpId === '') !== (origins.length === 0)) {
    return 'SAND_IAM_VALIDATION_ERROR: 通行密钥 RP ID 和允许来源必须同时配置或同时留空'
  }
  return null
}
