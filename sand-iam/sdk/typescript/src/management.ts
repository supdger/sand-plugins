import { validBaseUrl } from './baseUrl.js'

/** SandAdmin management-plane SDK. This client never accepts workload credentials. */

export type SandIamManagementJson = Record<string, unknown>

export interface SandIamManagementClientOptions {
  baseUrl: string
  administratorToken: () => string | Promise<string>
  fetch?: typeof globalThis.fetch
}

export interface OnboardingApplyInput {
  manifest: SandIamManagementJson & { operation_id: string }
  previewHash: string
  requestId: string
}

export interface RouteSyncApplyInput {
  manifest: SandIamManagementJson
  previewHash: string
  requestId: string
  disableMissing?: boolean | undefined
}

export interface CredentialIssueInput {
  workloadClientId: number
  name: string
  expireTime?: string | null | undefined
  requestId: string
}

export interface CredentialRotateInput {
  credentialId: number
  name: string
  expireTime?: string | null | undefined
  requestId: string
}

export interface CredentialRevokeInput {
  credentialId: number
  requestId: string
}

export interface ProviderPresetDraftInput {
  code: string
  clientId: string
  redirectUri: string
  handoffReturnUris: readonly string[]
  tenantId?: string | undefined
  requestId?: string | undefined
}

export class SandIamManagementError extends Error {
  constructor(public readonly code: string, message: string, public readonly status: number) {
    super(message)
    this.name = 'SandIamManagementError'
  }
}

/** One-time secret that is intentionally neither printable nor JSON serializable. */
export class SandIamOneTimeSecret {
  #value: string | undefined

  constructor(value: string) {
    this.#value = value
  }

  get secretAvailable(): boolean {
    return this.#value !== undefined
  }

  revealOnce(): string {
    const value = this.#value
    if (value === undefined) throw new SandIamManagementError('SAND_IAM_SDK_SECRET_UNAVAILABLE', '一次性密钥已读取或当前响应不含密钥', 0)
    this.#value = undefined
    return value
  }

  toString(): never {
    throw new TypeError('SandIAM 一次性密钥禁止转换为字符串或写入日志')
  }

  toJSON(): { secret_available: boolean } {
    return { secret_available: this.secretAvailable }
  }
}

export class SandIamCredentialResult {
  constructor(
    public readonly metadata: SandIamManagementJson,
    public readonly secretAvailable: boolean,
    private readonly secret: SandIamOneTimeSecret | undefined,
    public readonly replayed: boolean,
  ) {}

  revealSecretOnce(): string {
    if (this.secret === undefined) throw new SandIamManagementError('SAND_IAM_SDK_SECRET_UNAVAILABLE', '本次响应不含一次性调用凭证', 0)
    return this.secret.revealOnce()
  }

  toString(): never {
    throw new TypeError('SandIAM 一次性凭证结果禁止转换为字符串或写入日志')
  }

  toJSON(): { metadata: SandIamManagementJson; secret_available: boolean; replayed: boolean } {
    return { metadata: this.metadata, secret_available: this.secretAvailable, replayed: this.replayed }
  }
}

export class SandIamManagementClient {
  private readonly baseUrl: string
  private readonly fetcher: typeof globalThis.fetch

  constructor(private readonly options: SandIamManagementClientOptions) {
    const baseUrl = options.baseUrl.replace(/\/+$/, '')
    if (!validBaseUrl(baseUrl)) {
      throw new SandIamManagementError('SAND_IAM_SDK_INVALID_CONFIGURATION', '管理 API 地址必须使用 HTTPS；仅本机开发允许 HTTP', 0)
    }
    this.baseUrl = baseUrl
    this.fetcher = options.fetch ?? globalThis.fetch
    if (typeof this.fetcher !== 'function') throw new SandIamManagementError('SAND_IAM_SDK_FETCH_UNAVAILABLE', '当前运行环境没有可用的 fetch', 0)
  }

  onboardingPreview(manifest: SandIamManagementJson, requestId?: string): Promise<SandIamManagementJson> {
    return this.objectRequest('POST', '/developer/onboarding/preview', { manifest }, requestId, false)
  }

  onboardingApply(input: OnboardingApplyInput): Promise<SandIamManagementJson> {
    this.assertOperation(input.manifest, input.requestId)
    if (input.previewHash.trim() === '') throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '应用接入草稿必须提供预检哈希', 0)
    return this.objectRequest('POST', '/developer/onboarding/apply', { manifest: input.manifest, preview_hash: input.previewHash, apply: true }, input.requestId, true)
  }

  routeSyncPreview(manifest: SandIamManagementJson, disableMissing = false, requestId?: string): Promise<SandIamManagementJson> {
    return this.objectRequest('POST', '/developer/route-manifest/preview', { manifest, disable_missing: disableMissing }, requestId, false)
  }

  routeSyncApply(input: RouteSyncApplyInput): Promise<SandIamManagementJson> {
    if (!/^[a-f0-9]{64}$/.test(input.previewHash)) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '路由清单必须提供有效预检哈希', 0)
    return this.objectRequest('POST', '/developer/route-manifest/apply', {
      manifest: input.manifest,
      disable_missing: input.disableMissing ?? false,
      preview_hash: input.previewHash,
      apply: true
    }, input.requestId, true)
  }

  policySimulate(input: SandIamManagementJson, requestId?: string): Promise<SandIamManagementJson> {
    return this.objectRequest('POST', '/policy/simulate', input, requestId, false)
  }

  policyRollback(policyId: number, versionId: number, requestId: string): Promise<SandIamManagementJson> {
    if (!Number.isInteger(policyId) || policyId <= 0 || !Number.isInteger(versionId) || versionId <= 0) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '策略和版本编号必须为正整数', 0)
    return this.objectRequest('POST', '/policy/rollback', { id: policyId, version_id: versionId }, requestId, true)
  }

  async credentialIssue(input: CredentialIssueInput): Promise<SandIamCredentialResult> {
    if (!Number.isInteger(input.workloadClientId) || input.workloadClientId <= 0 || input.name.trim() === '') throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '服务调用身份和凭证名称不能为空', 0)
    return this.credentialResult(await this.objectRequest('POST', '/credential/issue', { workload_client_id: input.workloadClientId, name: input.name, expire_time: input.expireTime ?? null }, input.requestId, true))
  }

  async credentialRotate(input: CredentialRotateInput): Promise<SandIamCredentialResult> {
    if (!Number.isInteger(input.credentialId) || input.credentialId <= 0 || input.name.trim() === '') throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '调用凭证编号和名称不能为空', 0)
    return this.credentialResult(await this.objectRequest('POST', '/credential/rotate', { id: input.credentialId, name: input.name, expire_time: input.expireTime ?? null }, input.requestId, true))
  }

  credentialRevoke(input: CredentialRevokeInput): Promise<SandIamManagementJson> {
    if (!Number.isInteger(input.credentialId) || input.credentialId <= 0) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '调用凭证编号必须为正整数', 0)
    return this.objectRequest('POST', '/credential/revoke', { id: input.credentialId }, input.requestId, true)
  }

  presetList(requestId?: string): Promise<SandIamManagementJson[]> {
    return this.listRequest('GET', '/identity-provider-preset/index', undefined, requestId, false)
  }

  presetDraft(input: ProviderPresetDraftInput): Promise<SandIamManagementJson> {
    if (input.code.trim() === '' || input.clientId.trim() === '' || input.redirectUri.trim() === '' || input.handoffReturnUris.length === 0) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '预设代码、客户端编号、回调地址和回跳白名单不能为空', 0)
    const body: SandIamManagementJson = { code: input.code, client_id: input.clientId, redirect_uri: input.redirectUri, handoff_return_uris: [...input.handoffReturnUris] }
    if (input.tenantId !== undefined) body.tenant_id = input.tenantId
    return this.objectRequest('POST', '/identity-provider-preset/draft', body, input.requestId, false)
  }

  private async objectRequest(method: 'GET' | 'POST', path: string, body: SandIamManagementJson | undefined, requestId: string | undefined, write: boolean): Promise<SandIamManagementJson> {
    const data = await this.request(method, path, body, requestId, write)
    if (!isRecord(data)) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的数据结构不正确', 200)
    return data
  }

  private async listRequest(method: 'GET' | 'POST', path: string, body: SandIamManagementJson | undefined, requestId: string | undefined, write: boolean): Promise<SandIamManagementJson[]> {
    const data = await this.request(method, path, body, requestId, write)
    if (!Array.isArray(data) || data.some((item) => !isRecord(item))) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的列表不正确', 200)
    return data
  }

  private async request(method: 'GET' | 'POST', path: string, body: SandIamManagementJson | undefined, requestId: string | undefined, write: boolean): Promise<unknown> {
    if (write) this.assertRequestId(requestId)
    const token = (await this.options.administratorToken()).trim()
    if (token === '') throw new SandIamManagementError('SAND_IAM_AUTHENTICATION_FAILED', '管理员登录态或 Bearer 令牌不能为空', 401)
    const headers: Record<string, string> = { Authorization: `Bearer ${token}`, 'X-Request-Id': requestId ?? createRequestId(), 'Cache-Control': 'no-store', Accept: 'application/json' }
    const init: RequestInit = { method, headers, redirect: 'error' }
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json'
      init.body = JSON.stringify(body)
    }
    const response = await this.fetcher(`${this.baseUrl}/app/sand-iam/admin${path}`, init)
    const payload: unknown = await response.json().catch(() => null)
    if (!response.ok) {
      const error = remoteError(payload)
      throw new SandIamManagementError(error.code, error.message, response.status)
    }
    if (!isRecord(payload) || !('data' in payload)) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的数据结构不正确', response.status)
    return payload.data
  }

  private assertOperation(manifest: SandIamManagementJson, requestId: string): void {
    if (typeof manifest.operation_id !== 'string' || manifest.operation_id.trim() === '') throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '应用或路由同步必须在 manifest 中提供 operation_id', 0)
    this.assertRequestId(requestId)
  }

  private assertRequestId(requestId: string | undefined): asserts requestId is string {
    if (requestId === undefined || !/^[A-Za-z0-9_.:-]{8,96}$/.test(requestId)) throw new SandIamManagementError('SAND_IAM_SDK_REQUEST_ID_REQUIRED', '写操作必须显式提供 8–96 位 request_id', 0)
  }

  private credentialResult(data: SandIamManagementJson): SandIamCredentialResult {
    const replayed = data.replayed === true
    const value = data.credential
    if (value !== undefined && (typeof value !== 'string' || value === '' || replayed)) throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', '重放响应不得包含一次性调用凭证', 200)
    const { credential: _credential, ...metadata } = data
    return new SandIamCredentialResult(metadata, value !== undefined, typeof value === 'string' ? new SandIamOneTimeSecret(value) : undefined, replayed)
  }
}

function isRecord(value: unknown): value is SandIamManagementJson {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function remoteError(value: unknown): { code: string; message: string } {
  const message = isRecord(value) && typeof value.msg === 'string'
    ? value.msg
    : isRecord(value) && typeof value.message === 'string'
      ? value.message
      : 'SandIAM 管理 API 请求失败'
  return { code: message.match(/^(SAND_IAM_[A-Z0-9_]+)/)?.[1] ?? 'SAND_IAM_REQUEST_FAILED', message }
}

function createRequestId(): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID()
  const bytes = new Uint8Array(16)
  globalThis.crypto.getRandomValues(bytes)
  return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('')
}
