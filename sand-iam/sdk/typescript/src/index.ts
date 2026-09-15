import { validBaseUrl } from './baseUrl.js'

export type SandIamScope = Record<string, unknown>

export * from './management.js'

export interface SandIamDecision {
  allowed: boolean
  code: string
  policy_ids: number[]
  scope: SandIamScope
  application_id: number
  identity_id: number
  api_code: string
  api_version: string
  resource_code: string
  action: string
  operation: 'list' | 'read' | 'create' | 'update' | 'delete' | 'export' | 'batch'
  risk_level: 'low' | 'medium' | 'high' | 'critical'
}

export interface DecideInput {
  apiCode: string
  apiVersion?: string
  attributes?: Record<string, unknown>
  requestId?: string
}

export interface IssueContextInput {
  credential: string
  serviceCode: string
  audience: string
  actions: readonly string[]
  subjectScope?: Record<string, unknown> | undefined
  requestId?: string | undefined
}

export interface VerifyContextInput {
  context: string
  serviceCode: string
  audience: string
  actions: readonly string[]
  /** 仅供服务端适配器保留；公开 HTTP 路由从可信连接对端解析，绝不序列化该值。 */
  sourceIp?: string | undefined
  requestId?: string | undefined
}

export interface SandIamWorkloadContext {
  context?: string | undefined
  context_id: string
  expire_time?: string | undefined
  service_code?: string | undefined
  audience?: string | undefined
  actions?: string[] | undefined
  [claim: string]: unknown
}

export type SandIamWorkloadErrorCode =
  | 'SAND_IAM_SERVICE_NETWORK_FORBIDDEN'
  | 'SAND_IAM_INVOCATION_FACTS_UNVERIFIED'
  | 'SAND_IAM_DATA_CLASS_FORBIDDEN'
  | 'SAND_IAM_SERVICE_QUOTA_EXCEEDED'
  | 'SAND_IAM_IDEMPOTENCY_CONFLICT'

export interface RegisterInput {
  captchaToken?: string
  username: string
  password: string
  displayName?: string
  email?: string
  phone?: string
  userAgent?: string
  requestId?: string
}

export interface LoginInput {
  captchaToken?: string
  identifier: string
  password: string
  userAgent?: string
  requestId?: string
}

export interface SandIamIdentitySummary {
  id: number
  code?: string | undefined
  display_name: string
}

export interface SandIamAuthResult {
  step_up?: boolean | undefined
  methods?: string[] | undefined
  expires_in?: number | undefined
  public_key?: Record<string, unknown> | undefined
  identity?: SandIamIdentitySummary | undefined
  access_token?: string | undefined
  refresh_token?: string | undefined
  session_id?: number | undefined
  access_expire_time?: string | undefined
  refresh_expire_time?: string | undefined
  verification_required?: boolean | undefined
  mfa_required?: boolean | undefined
  challenge_token?: string | undefined
}

export interface SandIamPasskeyOptions {
  challenge_token: string
  public_key: Record<string, unknown>
}
export interface SandIamCaptchaWidget {
  kind: 'turnstile'
  site_key: string
  action: 'login' | 'register'
  application_binding: string
}
export type SandIamCaptchaConfiguration =
  | { required: false }
  | { required: true; available: false }
  | { required: true; available: true; widget: SandIamCaptchaWidget }
export interface PasskeyRegistrationResponse {
  clientDataJSON: string
  attestationObject: string
}
export interface PasskeyAuthenticationResponse {
  clientDataJSON: string
  authenticatorData: string
  signature: string
  userHandle: string
}

export interface SandIamMfaFactor {
  id: number
  type: 'totp' | 'passkey'
  name: string
  status: number
  create_time: string | null
  last_used_time: string | null
}
export interface SandIamTotpSetup {
  factor_id: number
  secret?: string
  otpauth_uri?: string
  secret_available?: boolean
}
export interface SandIamRecoveryCodes { recovery_codes?: string[]; secret_available?: boolean }
export interface SandIamTotpConfirmation extends SandIamRecoveryCodes { enabled: true }

export type VerifyMfaChallengeInput = {
  challengeToken: string
  requestId?: string
  userAgent?: string
} & ({
  method: 'totp' | 'recovery_code'
  code: string
} | {
  method: 'passkey'
  rawId: string
  response: {
    clientDataJSON: string
    authenticatorData: string
    signature: string
    userHandle?: string | null
  }
})

export interface ForgotPasswordInput {
  identifier: string
  channel: 'email' | 'phone'
  requestId?: string
}

export interface ResetPasswordInput extends ForgotPasswordInput {
  code: string
  password: string
}

export interface VerificationInput {
  identifier: string
  channel: 'email' | 'phone'
  requestId?: string
}

export interface ConfirmVerificationInput extends VerificationInput {
  code: string
}

export interface SandIamProfile {
  identity_id: number
  display_name: string
  organization: { code: string; name: string }
  application: { code: string; name: string }
  create_time?: string | null
}

export interface SandIamSecurityOverview {
  password_enabled: boolean
  active_sessions: number
  totp_factors: number
  passkeys: number
  connected_accounts: number
}

export interface SandIamConnection {
  binding_id: number
  provider_name: string
  provider_type: string
  account_hint: string
  source_state: string
  linked_time?: string | null
}

export interface SandIamSession {
  id: number
  create_time?: string | null
  last_used_time?: string | null
  access_expire_time?: string | null
  refresh_expire_time?: string | null
  current: boolean
}

export interface SandIamClientOptions {
  baseUrl: string
  organizationCode: string
  applicationCode: string
  accessToken: () => string | Promise<string>
  fetch?: typeof globalThis.fetch
}

export class SandIamError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
  ) {
    super(message)
    this.name = 'SandIamError'
  }
}

export class SandIamDeniedError extends SandIamError {
  constructor(public readonly decision: SandIamDecision) {
    super(decision.code, '当前账号没有执行此操作的权限', 403)
    this.name = 'SandIamDeniedError'
  }
}

export class SandIamClient {
  private readonly fetcher: typeof globalThis.fetch
  private readonly baseUrl: string

  constructor(private readonly options: SandIamClientOptions) {
    this.fetcher = options.fetch ?? globalThis.fetch
    if (typeof this.fetcher !== 'function') {
      throw new SandIamError('SAND_IAM_SDK_FETCH_UNAVAILABLE', '当前运行环境没有可用的 fetch', 0)
    }
    const stableCode = /^[a-z0-9][a-z0-9_-]{1,63}$/
    if (!stableCode.test(options.organizationCode) || !stableCode.test(options.applicationCode)) {
      throw new SandIamError('SAND_IAM_SDK_INVALID_CONFIGURATION', '客户主体代码和接入应用代码不能为空', 0)
    }
    const baseUrl = options.baseUrl.replace(/\/+$/, '')
    if (baseUrl !== '' && !validBaseUrl(baseUrl)) {
      throw new SandIamError('SAND_IAM_SDK_INVALID_CONFIGURATION', 'SandIAM 地址必须使用 HTTPS；仅本机开发允许 HTTP', 0)
    }
    this.baseUrl = baseUrl
  }

  async decide(input: DecideInput): Promise<SandIamDecision> {
    const data = await this.request({
      method: 'POST',
      path: '/api/sand-iam/v1/authorization/decide',
      authenticated: true,
      requestId: input.requestId,
      body: {
        organization_code: this.options.organizationCode,
        application_code: this.options.applicationCode,
        api_code: input.apiCode,
        api_version: input.apiVersion ?? 'v1',
        attributes: input.attributes ?? {},
      },
    })
    if (!isDecision(data)) {
      throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回了无法识别的授权结果', 200)
    }
    return data
  }

  async authorize(input: DecideInput): Promise<SandIamDecision> {
    const decision = await this.decide(input)
    if (!decision.allowed) {
      throw new SandIamDeniedError(decision)
    }
    return decision
  }

  async issueContext(input: IssueContextInput): Promise<SandIamWorkloadContext> {
    assertWorkloadInput(input.credential, input.serviceCode, input.audience, input.actions)
    const data = await this.request({
      method: 'POST', path: '/app/sand-iam/runtime/context/issue', authenticated: false,
      bearerToken: input.credential, noStore: true, requestId: input.requestId,
      body: { service_code: input.serviceCode, audience: input.audience, actions: [...input.actions], subject_scope: input.subjectScope },
    })
    if (!isRecord(data) || !stringValue(data.context) || !stringValue(data.context_id) || !stringValue(data.expire_time)) {
      throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的工作负载上下文不完整', 200)
    }
    return { ...data, context: data.context, context_id: data.context_id, expire_time: data.expire_time }
  }

  async verifyContext(input: VerifyContextInput): Promise<SandIamWorkloadContext> {
    assertWorkloadInput(input.context, input.serviceCode, input.audience, input.actions)
    if (input.sourceIp !== undefined && !isIpAddress(input.sourceIp)) throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', 'sourceIp 必须是服务器已验证的 IP 地址', 0)
    const baseRequestId = input.requestId ?? createRequestId()
    let claims: Record<string, unknown> | undefined
    for (const [index, action] of input.actions.entries()) {
      const data = await this.request({
        method: 'POST', path: '/app/sand-iam/runtime/context/verify', authenticated: false, noStore: true,
        requestId: derivedRequestId(baseRequestId, index), body: { context: input.context, audience: input.audience, action },
      })
      if (!isRecord(data) || data.service_code !== input.serviceCode || !Array.isArray(data.actions) || !data.actions.includes(action) || !stringValue(data.context_id)) {
        throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的上下文与请求的服务或动作不一致', 200)
      }
      claims = data
    }
    return claims as SandIamWorkloadContext
  }

  async register(input: RegisterInput): Promise<SandIamAuthResult> {
    const data = await this.request({
      method: 'POST',
      path: '/api/sand-iam/v1/auth/register',
      authenticated: false,
      requestId: input.requestId,
      body: this.applicationPayload({
        username: input.username,
        password: input.password,
        display_name: input.displayName ?? input.username,
        email: input.email ?? '',
        phone: input.phone ?? '',
        user_agent: input.userAgent ?? '',
        captcha_token: input.captchaToken ?? '',
      }),
    })
    return authResult(data)
  }

  /** Accepting an invitation does not start a session. */
  async acceptInvitation(input: { token: string; username: string; password: string; displayName?: string; requestId?: string }): Promise<{ id: number; display_name: string }> {
    if (input.token.trim() === '' || input.username.trim() === '' || input.password === '') {
      throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', '邀请令牌、账号和密码不能为空', 0)
    }
    const data = await this.request({
      method: 'POST', path: '/api/sand-iam/v1/invitations/accept', authenticated: false,
      noStore: true, requestId: input.requestId,
      body: { token: input.token, username: input.username, password: input.password, display_name: input.displayName ?? '' },
    })
    if (!isRecord(data) || typeof data.id !== 'number' || !Number.isSafeInteger(data.id) || data.id <= 0 || typeof data.display_name !== 'string') {
      throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的邀请用户信息不正确', 200)
    }
    return { id: data.id, display_name: data.display_name }
  }

  async login(input: LoginInput): Promise<SandIamAuthResult> {
    const data = await this.request({
      method: 'POST',
      path: '/api/sand-iam/v1/auth/login',
      authenticated: false,
      requestId: input.requestId,
      body: this.applicationPayload({ identifier: input.identifier, password: input.password, user_agent: input.userAgent ?? '', captcha_token: input.captchaToken ?? '' }),
    })
    return authResult(data)
  }

  async verifyMfaChallenge(input: VerifyMfaChallengeInput): Promise<SandIamAuthResult> {
    if (input.challengeToken.trim() === '' || !['totp', 'recovery_code', 'passkey'].includes(input.method)) {
      throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', 'MFA 挑战和验证方式不能为空', 0)
    }
    const proof = input.method === 'passkey'
      ? { rawId: input.rawId, response: input.response }
      : { code: input.code }
    return authResult(await this.request({
      method: 'POST', path: '/api/sand-iam/v1/auth/mfa/challenge/verify',
      authenticated: false, requestId: input.requestId,
      body: this.applicationPayload({
        ...proof, challenge_token: input.challengeToken, method: input.method,
        user_agent: input.userAgent ?? '',
      }),
    }))
  }

  /** Platform code performs the WebAuthn ceremony and serializes binary fields. */
  async passkeyRegistrationOptions(input: { name?: string; currentPassword: string; requestId?: string }): Promise<SandIamPasskeyOptions> {
    if (input.currentPassword === '') throw invalidMfaInput()
    return passkeyOptions(await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/passkeys/registration/options',
      authenticated: true, requestId: input.requestId,
      body: { name: input.name ?? '', current_password: input.currentPassword } }))
  }

  async passkeyRegistrationFinish(input: { challengeToken: string; rawId: string; response: PasskeyRegistrationResponse; requestId?: string }): Promise<void> {
    const response = passkeyProof(input.challengeToken, input.rawId, input.response, ['clientDataJSON', 'attestationObject'])
    await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/passkeys/registration/finish',
      authenticated: true, requestId: input.requestId,
      body: { challenge_token: input.challengeToken, rawId: input.rawId, response } })
  }

  async passkeyAuthenticationOptions(requestId?: string): Promise<SandIamPasskeyOptions> {
    return passkeyOptions(await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/passkeys/authentication/options',
      authenticated: false, requestId, body: this.applicationPayload({}) }))
  }

  async passkeyAuthenticationFinish(input: { challengeToken: string; rawId: string; response: PasskeyAuthenticationResponse; userAgent?: string; requestId?: string }): Promise<SandIamAuthResult> {
    const response = passkeyProof(input.challengeToken, input.rawId, input.response, ['clientDataJSON', 'authenticatorData', 'signature', 'userHandle'])
    return authResult(await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/passkeys/authentication/finish',
      authenticated: false, requestId: input.requestId,
      body: this.applicationPayload({ challenge_token: input.challengeToken, rawId: input.rawId, response, user_agent: input.userAgent ?? '' }) }))
  }

  async captchaConfiguration(action: 'login' | 'register', requestId?: string): Promise<SandIamCaptchaConfiguration> {
    if (!['login', 'register'].includes(action)) throw invalidMfaInput()
    const query = new URLSearchParams({ organization_code: this.options.organizationCode,
      application_code: this.options.applicationCode, action })
    const data = await this.request({ method: 'GET', path: `/api/sand-iam/v1/auth/captcha/config?${query}`,
      authenticated: false, noStore: true, requestId })
    if (!isRecord(data)) throw invalidMfaResponse()
    if (data.required === false) return { required: false }
    if (data.required !== true) throw invalidMfaResponse()
    if (data.available === false) return { required: true, available: false }
    const widget = data.widget
    const validKey = (value: unknown): value is string => typeof value === 'string' && value.trim() === value && /^[A-Za-z0-9_-]{1,255}$/.test(value)
    if (data.available !== true || !isRecord(widget) || widget.kind !== 'turnstile' ||
      widget.action !== action || !validKey(widget.site_key) || !validKey(widget.application_binding)) throw invalidMfaResponse()
    return { required: true, available: true, widget: {
      kind: 'turnstile', site_key: widget.site_key, action, application_binding: widget.application_binding } }
  }

  async stepUpPassword(password: string, requestId?: string): Promise<SandIamAuthResult> {
    if (password === '') throw invalidMfaInput()
    const result = authResult(await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/step-up/password',
      authenticated: true, requestId, body: { password } }))
    if (result.step_up !== true || result.expires_in === undefined) throw invalidMfaResponse()
    return result
  }

  async startMfaStepUp(requestId?: string): Promise<SandIamAuthResult> {
    const result = authResult(await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/step-up/mfa/start',
      authenticated: true, requestId, body: {} }))
    if (result.mfa_required !== true || !result.challenge_token?.trim() ||
      !result.methods?.length || result.expires_in === undefined) throw invalidMfaResponse()
    return result
  }

  async unlinkFederation(bindingId: number, requestId?: string): Promise<void> {
    if (!Number.isSafeInteger(bindingId) || bindingId <= 0) throw invalidMfaInput()
    await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/federation/unlink',
      authenticated: true, requestId, body: { binding_id: bindingId } })
  }

  async mfaFactors(requestId?: string): Promise<SandIamMfaFactor[]> {
    const data = await this.request({ method: 'GET', path: '/api/sand-iam/v1/auth/mfa/factors', authenticated: true, requestId })
    if (!Array.isArray(data)) throw invalidMfaResponse()
    return data.map((row: unknown) => {
      if (!isRecord(row) || !Number.isSafeInteger(row.id) || Number(row.id) <= 0 ||
        (row.type !== 'totp' && row.type !== 'passkey') || typeof row.name !== 'string' ||
        !Number.isInteger(row.status) || !nullableMfaTime(row.create_time) || !nullableMfaTime(row.last_used_time)) throw invalidMfaResponse()
      return { id: Number(row.id), type: row.type, name: row.name, status: Number(row.status),
        create_time: typeof row.create_time === 'string' ? row.create_time : null,
        last_used_time: typeof row.last_used_time === 'string' ? row.last_used_time : null }
    })
  }

  async startTotp(input: { name?: string; currentPassword: string; requestId?: string }): Promise<SandIamTotpSetup> {
    if (input.currentPassword === '') throw invalidMfaInput()
    const data = await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/mfa/totp/start', authenticated: true,
      requestId: input.requestId, body: { name: input.name ?? '', current_password: input.currentPassword } })
    if (!isRecord(data) || !Number.isSafeInteger(data.factor_id) || Number(data.factor_id) <= 0) throw invalidMfaResponse()
    if (data.secret_available === false) return { factor_id: Number(data.factor_id), secret_available: false }
    if (!stringValue(data.secret) || !stringValue(data.otpauth_uri)) throw invalidMfaResponse()
    return { factor_id: Number(data.factor_id), secret: data.secret, otpauth_uri: data.otpauth_uri }
  }

  async confirmTotp(input: { factorId: number; code: string; requestId?: string }): Promise<SandIamTotpConfirmation> {
    validateMfaFactor(input.factorId, 'totp')
    if (input.code.trim() === '') throw invalidMfaInput()
    const data = await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/mfa/totp/confirm', authenticated: true,
      requestId: input.requestId, body: { factor_id: input.factorId, code: input.code } })
    if (!isRecord(data) || data.enabled !== true) throw invalidMfaResponse()
    return { enabled: true, ...mfaRecoveryCodes(data) }
  }

  async renameMfaFactor(input: { factorId: number; type: 'totp' | 'passkey'; name: string; requestId?: string }): Promise<void> {
    validateMfaFactor(input.factorId, input.type)
    await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/mfa/factors/rename', authenticated: true,
      requestId: input.requestId, body: { factor_id: input.factorId, type: input.type, name: input.name } })
  }

  async revokeMfaFactor(input: { factorId: number; type: 'totp' | 'passkey'; password: string; requestId?: string }): Promise<void> {
    validateMfaFactor(input.factorId, input.type)
    if (input.password === '') throw invalidMfaInput()
    await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/mfa/factors/revoke', authenticated: true,
      requestId: input.requestId, body: { factor_id: input.factorId, type: input.type, password: input.password } })
  }

  async regenerateRecoveryCodes(input: { password: string; requestId?: string }): Promise<SandIamRecoveryCodes> {
    if (input.password === '') throw invalidMfaInput()
    return mfaRecoveryCodes(await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/mfa/recovery/regenerate',
      authenticated: true, requestId: input.requestId, body: { password: input.password } }))
  }

  async requestVerification(input: VerificationInput): Promise<void> {
    validateRecoveryInput(input)
    await this.request({
      method: 'POST', path: '/api/sand-iam/v1/auth/verification/request',
      authenticated: false, requestId: input.requestId,
      body: this.applicationPayload({
        identifier: input.identifier, channel: input.channel,
        purpose: input.channel === 'email' ? 'email_verify' : 'phone_verify',
      }),
    })
  }

  async confirmVerification(input: ConfirmVerificationInput): Promise<void> {
    validateRecoveryInput(input)
    if (input.code.trim() === '') throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', '验证码不能为空', 0)
    await this.request({
      method: 'POST', path: '/api/sand-iam/v1/auth/verification/confirm',
      authenticated: false, requestId: input.requestId,
      body: this.applicationPayload({
        identifier: input.identifier, channel: input.channel, code: input.code,
        purpose: input.channel === 'email' ? 'email_verify' : 'phone_verify',
      }),
    })
  }

  async forgotPassword(input: ForgotPasswordInput): Promise<void> {
    validateRecoveryInput(input)
    await this.request({
      method: 'POST', path: '/api/sand-iam/v1/auth/password/forgot',
      authenticated: false, requestId: input.requestId,
      body: this.applicationPayload({ identifier: input.identifier, channel: input.channel }),
    })
  }

  async resetPassword(input: ResetPasswordInput): Promise<void> {
    validateRecoveryInput(input)
    if (input.code.trim() === '' || input.password === '') {
      throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', '验证码和新密码不能为空', 0)
    }
    await this.request({
      method: 'POST', path: '/api/sand-iam/v1/auth/password/reset',
      authenticated: false, requestId: input.requestId,
      body: this.applicationPayload({
        identifier: input.identifier, channel: input.channel, code: input.code, password: input.password,
      }),
    })
  }

  async refresh(refreshToken: string, requestId?: string): Promise<SandIamAuthResult> {
    const data = await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/refresh', authenticated: false, requestId, body: { refresh_token: refreshToken } })
    return authResult(data)
  }

  async profile(requestId?: string): Promise<SandIamProfile> {
    return profileData(await this.request({ method: 'GET', path: '/api/sand-iam/v1/me/profile', authenticated: true, requestId }))
  }

  async updateProfile(displayName: string, requestId?: string): Promise<SandIamProfile> {
    return profileData(await this.request({ method: 'PATCH', path: '/api/sand-iam/v1/me/profile', authenticated: true, requestId, body: { display_name: displayName } }))
  }

  async securityOverview(requestId?: string): Promise<SandIamSecurityOverview> {
    return securityData(await this.request({ method: 'GET', path: '/api/sand-iam/v1/me/security', authenticated: true, requestId }))
  }

  async connections(requestId?: string): Promise<SandIamConnection[]> {
    return connectionData(await this.request({ method: 'GET', path: '/api/sand-iam/v1/me/connections', authenticated: true, requestId }))
  }

  async sessions(requestId?: string): Promise<SandIamSession[]> {
    return sessionData(await this.request({ method: 'GET', path: '/api/sand-iam/v1/auth/sessions', authenticated: true, requestId }))
  }

  async revokeSession(sessionId: number, requestId?: string): Promise<void> {
    if (!Number.isInteger(sessionId) || sessionId <= 0) throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', '会话编号无效', 0)
    await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/sessions/revoke', authenticated: true, requestId, body: { id: sessionId } })
  }

  async changePassword(currentPassword: string, newPassword: string, requestId?: string): Promise<void> {
    if (currentPassword === '' || newPassword === '') throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', '当前密码和新密码不能为空', 0)
    await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/password/change', authenticated: true, requestId, body: { current_password: currentPassword, new_password: newPassword } })
  }

  async logout(requestId?: string): Promise<void> {
    await this.request({ method: 'POST', path: '/api/sand-iam/v1/auth/logout', authenticated: true, requestId, body: {} })
  }

  private applicationPayload(payload: Record<string, unknown>): Record<string, unknown> {
    return { organization_code: this.options.organizationCode, application_code: this.options.applicationCode, ...payload }
  }

  private async request(input: {
    method: 'GET' | 'POST' | 'PATCH'
    path: string
    authenticated: boolean
    bearerToken?: string | undefined
    noStore?: boolean | undefined
    requestId?: string | undefined
    body?: Record<string, unknown>
  }): Promise<unknown> {
    const headers: Record<string, string> = { 'X-Request-Id': input.requestId ?? createRequestId() }
    if (input.bearerToken !== undefined) {
      headers.Authorization = `Bearer ${input.bearerToken}`
    } else if (input.authenticated) {
      const token = (await this.options.accessToken()).trim()
      if (token === '') throw new SandIamError('SAND_IAM_AUTHENTICATION_FAILED', '尚未登录或登录凭证已丢失', 401)
      headers.Authorization = `Bearer ${token}`
    }
    if (input.body !== undefined) headers['Content-Type'] = 'application/json'
    if (input.noStore) headers['Cache-Control'] = 'no-store'
    const requestInit: RequestInit = {
      method: input.method,
      headers,
      redirect: 'error',
    }
    if (input.body !== undefined) requestInit.body = JSON.stringify(input.body)
    const response = await this.fetcher(`${this.baseUrl}${input.path}`, requestInit)
    const payload: unknown = await response.json().catch(() => null)
    if (!response.ok) {
      const error = errorPayload(payload)
      throw new SandIamError(error.code, error.message, response.status)
    }
    return responseData(payload)
  }
}

function assertWorkloadInput(secretOrContext: string, serviceCode: string, audience: string, actions: readonly string[]): void {
  const actionCode = /^[a-z0-9][a-z0-9._-]{1,95}$/
  if (secretOrContext === '' || !/^[a-z0-9][a-z0-9._-]{1,63}$/.test(serviceCode) || audience.trim() === '' || actions.length === 0 || actions.some((action) => !actionCode.test(action))) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', '工作负载服务、受众和动作必须使用已声明的稳定代码', 0)
  }
}

function derivedRequestId(requestId: string, index: number): string {
  return `${requestId.slice(0, 88)}-v${index + 1}`
}

function isIpAddress(value: string): boolean {
  return /^\d{1,3}(?:\.\d{1,3}){3}$/.test(value) || value.includes(':')
}

function createRequestId(): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return globalThis.crypto.randomUUID()
  }
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('')
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function stringValue(value: unknown): value is string {
  return typeof value === 'string'
}

function optionalString(value: unknown): boolean {
  return value === undefined || value === null || typeof value === 'string'
}

function invalidMfaInput(): SandIamError { return new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', 'MFA 参数不正确', 0) }
function invalidMfaResponse(): SandIamError { return new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'MFA 返回结果不正确', 200) }
function validateMfaFactor(id: number, type: string): void {
  if (!Number.isSafeInteger(id) || id <= 0 || !['totp', 'passkey'].includes(type)) throw invalidMfaInput()
}
function nullableMfaTime(value: unknown): boolean { return value === null || typeof value === 'string' }
function mfaRecoveryCodes(value: unknown): SandIamRecoveryCodes {
  if (isRecord(value) && value.secret_available === false) return { secret_available: false }
  if (!isRecord(value) || !Array.isArray(value.recovery_codes) || value.recovery_codes.length === 0 ||
    !value.recovery_codes.every((code: unknown) => typeof code === 'string' && code !== '')) throw invalidMfaResponse()
  return { recovery_codes: value.recovery_codes.filter((code: unknown): code is string => typeof code === 'string') }
}

function validateRecoveryInput(input: ForgotPasswordInput): void {
  if (input.identifier.trim() === '' || !['email', 'phone'].includes(input.channel)) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_ARGUMENT', '账号和验证通道不正确', 0)
  }
}

function authResult(value: unknown): SandIamAuthResult {
  if (!isRecord(value)) throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的认证结果不正确', 200)
  const methods = value.methods
  if (methods !== undefined && (!Array.isArray(methods) || !methods.every((method: unknown) => typeof method === 'string' && method !== ''))) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的 MFA 方式不正确', 200)
  }
  if (value.expires_in !== undefined && (!Number.isInteger(value.expires_in) || Number(value.expires_in) <= 0)) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的挑战有效期不正确', 200)
  }
  if (value.public_key !== undefined && !isRecord(value.public_key)) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的 Passkey 参数不正确', 200)
  }
  const identity = value.identity
  if (identity !== undefined && (!isRecord(identity) || !Number.isInteger(identity.id) || !stringValue(identity.display_name))) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的身份信息不正确', 200)
  }
  for (const field of ['access_token', 'refresh_token', 'access_expire_time', 'refresh_expire_time', 'challenge_token']) {
    if (!optionalString(value[field])) throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的认证字段不正确', 200)
  }
  if (value.session_id !== undefined && !Number.isInteger(value.session_id)) throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的会话信息不正确', 200)
  if (value.verification_required !== undefined && typeof value.verification_required !== 'boolean') throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的验证状态不正确', 200)
  if (value.mfa_required !== undefined && typeof value.mfa_required !== 'boolean') throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的 MFA 状态不正确', 200)
  if (value.step_up !== undefined && typeof value.step_up !== 'boolean') throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的升级状态不正确', 200)
  return {
    step_up: typeof value.step_up === 'boolean' ? value.step_up : undefined,
    methods: Array.isArray(methods) ? methods.filter((method: unknown): method is string => typeof method === 'string') : undefined,
    expires_in: typeof value.expires_in === 'number' ? value.expires_in : undefined,
    public_key: isRecord(value.public_key) ? value.public_key : undefined,
    identity: identity === undefined ? undefined : { id: Number(identity.id), code: stringValue(identity.code) ? identity.code : undefined, display_name: String(identity.display_name) },
    access_token: stringValue(value.access_token) ? value.access_token : undefined,
    refresh_token: stringValue(value.refresh_token) ? value.refresh_token : undefined,
    session_id: Number.isInteger(value.session_id) ? Number(value.session_id) : undefined,
    access_expire_time: stringValue(value.access_expire_time) ? value.access_expire_time : undefined,
    refresh_expire_time: stringValue(value.refresh_expire_time) ? value.refresh_expire_time : undefined,
    verification_required: typeof value.verification_required === 'boolean' ? value.verification_required : undefined,
    mfa_required: typeof value.mfa_required === 'boolean' ? value.mfa_required : undefined,
    challenge_token: stringValue(value.challenge_token) ? value.challenge_token : undefined,
  }
}

function namedCode(value: unknown): value is { code: string; name: string } {
  return isRecord(value) && stringValue(value.code) && stringValue(value.name)
}

function profileData(value: unknown): SandIamProfile {
  if (!isRecord(value) || !Number.isInteger(value.identity_id) || !stringValue(value.display_name) || !namedCode(value.organization) || !namedCode(value.application) || !optionalString(value.create_time)) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的个人资料不正确', 200)
  }
  return { identity_id: Number(value.identity_id), display_name: value.display_name, organization: value.organization, application: value.application, create_time: stringValue(value.create_time) ? value.create_time : null }
}

function securityData(value: unknown): SandIamSecurityOverview {
  const counters = ['active_sessions', 'totp_factors', 'passkeys', 'connected_accounts']
  if (!isRecord(value) || typeof value.password_enabled !== 'boolean' || counters.some((field) => !Number.isInteger(value[field]))) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的安全概况不正确', 200)
  }
  return { password_enabled: value.password_enabled, active_sessions: Number(value.active_sessions), totp_factors: Number(value.totp_factors), passkeys: Number(value.passkeys), connected_accounts: Number(value.connected_accounts) }
}

function connectionData(value: unknown): SandIamConnection[] {
  if (!Array.isArray(value) || value.some((item) => !isRecord(item) || !Number.isInteger(item.binding_id) || !stringValue(item.provider_name) || !stringValue(item.provider_type) || !stringValue(item.account_hint) || !stringValue(item.source_state) || !optionalString(item.linked_time))) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的外部账号连接不正确', 200)
  }
  return value.map((item) => ({ binding_id: Number(item.binding_id), provider_name: String(item.provider_name), provider_type: String(item.provider_type), account_hint: String(item.account_hint), source_state: String(item.source_state), linked_time: stringValue(item.linked_time) ? item.linked_time : null }))
}

function sessionData(value: unknown): SandIamSession[] {
  if (!Array.isArray(value) || value.some((item) => !isRecord(item) || !Number.isInteger(item.id) || typeof item.current !== 'boolean' || !optionalString(item.create_time) || !optionalString(item.last_used_time) || !optionalString(item.access_expire_time) || !optionalString(item.refresh_expire_time))) {
    throw new SandIamError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 返回的会话列表不正确', 200)
  }
  return value.map((item) => ({ id: Number(item.id), current: Boolean(item.current), create_time: stringValue(item.create_time) ? item.create_time : null, last_used_time: stringValue(item.last_used_time) ? item.last_used_time : null, access_expire_time: stringValue(item.access_expire_time) ? item.access_expire_time : null, refresh_expire_time: stringValue(item.refresh_expire_time) ? item.refresh_expire_time : null }))
}

function responseData(value: unknown): unknown {
  return isRecord(value) && 'data' in value ? value.data : null
}

function errorPayload(value: unknown): { code: string; message: string } {
  if (!isRecord(value)) {
    return { code: 'SAND_IAM_REQUEST_FAILED', message: 'SandIAM 请求失败' }
  }
  const rawMessage = typeof value.msg === 'string'
    ? value.msg
    : typeof value.message === 'string'
      ? value.message
      : 'SandIAM 请求失败'
  const match = rawMessage.match(/^(SAND_IAM_[A-Z0-9_]+)/)
  return { code: match?.[1] ?? 'SAND_IAM_REQUEST_FAILED', message: rawMessage }
}

function isDecision(value: unknown): value is SandIamDecision {
  if (!isRecord(value)) return false
  const operation = value.operation
  const riskLevel = value.risk_level
  return typeof value.allowed === 'boolean'
    && typeof value.code === 'string'
    && Array.isArray(value.policy_ids)
    && value.policy_ids.every((id) => Number.isInteger(id))
    && isRecord(value.scope)
    && Number.isInteger(value.application_id)
    && Number.isInteger(value.identity_id)
    && typeof value.api_code === 'string'
    && typeof value.api_version === 'string'
    && typeof value.resource_code === 'string'
    && typeof value.action === 'string'
    && typeof operation === 'string'
    && ['list', 'read', 'create', 'update', 'delete', 'export', 'batch'].includes(operation)
    && typeof riskLevel === 'string'
    && ['low', 'medium', 'high', 'critical'].includes(riskLevel)
}

function passkeyProof(challenge: string, rawId: string, response: unknown, fields: readonly string[]): Record<string, string> {
  if (challenge.trim() === '' || rawId.trim() === '' || !isRecord(response)) throw invalidMfaInput()
  const proof: Record<string, string> = {}
  for (const field of fields) {
    const value = response[field]
    if (typeof value !== 'string' || value.trim() === '') throw invalidMfaInput()
    proof[field] = value
  }
  return proof
}

function passkeyOptions(value: unknown): SandIamPasskeyOptions {
  if (!isRecord(value) || typeof value.challenge_token !== 'string' || value.challenge_token.trim() === '' || !isRecord(value.public_key)) throw invalidMfaResponse()
  return { challenge_token: value.challenge_token, public_key: value.public_key }
}
