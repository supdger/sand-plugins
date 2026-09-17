import { validBaseUrl } from './baseUrl.js';
export class SandIamManagementError extends Error {
    code;
    status;
    constructor(code, message, status) {
        super(message);
        this.code = code;
        this.status = status;
        this.name = 'SandIamManagementError';
    }
}
/** One-time secret that is intentionally neither printable nor JSON serializable. */
export class SandIamOneTimeSecret {
    #value;
    constructor(value) {
        this.#value = value;
    }
    get secretAvailable() {
        return this.#value !== undefined;
    }
    revealOnce() {
        const value = this.#value;
        if (value === undefined)
            throw new SandIamManagementError('SAND_IAM_SDK_SECRET_UNAVAILABLE', '一次性密钥已读取或当前响应不含密钥', 0);
        this.#value = undefined;
        return value;
    }
    toString() {
        throw new TypeError('SandIAM 一次性密钥禁止转换为字符串或写入日志');
    }
    toJSON() {
        return { secret_available: this.secretAvailable };
    }
}
export class SandIamCredentialResult {
    metadata;
    secretAvailable;
    secret;
    replayed;
    constructor(metadata, secretAvailable, secret, replayed) {
        this.metadata = metadata;
        this.secretAvailable = secretAvailable;
        this.secret = secret;
        this.replayed = replayed;
    }
    revealSecretOnce() {
        if (this.secret === undefined)
            throw new SandIamManagementError('SAND_IAM_SDK_SECRET_UNAVAILABLE', '本次响应不含一次性调用凭证', 0);
        return this.secret.revealOnce();
    }
    toString() {
        throw new TypeError('SandIAM 一次性凭证结果禁止转换为字符串或写入日志');
    }
    toJSON() {
        return { metadata: this.metadata, secret_available: this.secretAvailable, replayed: this.replayed };
    }
}
export class SandIamManagementClient {
    options;
    baseUrl;
    fetcher;
    constructor(options) {
        this.options = options;
        const baseUrl = options.baseUrl.replace(/\/+$/, '');
        if (!validBaseUrl(baseUrl)) {
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_CONFIGURATION', '管理 API 地址必须使用 HTTPS；仅本机开发允许 HTTP', 0);
        }
        this.baseUrl = baseUrl;
        this.fetcher = options.fetch ?? globalThis.fetch;
        if (typeof this.fetcher !== 'function')
            throw new SandIamManagementError('SAND_IAM_SDK_FETCH_UNAVAILABLE', '当前运行环境没有可用的 fetch', 0);
    }
    onboardingPreview(manifest, requestId) {
        return this.objectRequest('POST', '/developer/onboarding/preview', { manifest }, requestId, false);
    }
    onboardingApply(input) {
        this.assertOperation(input.manifest, input.requestId);
        if (input.previewHash.trim() === '')
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '应用接入草稿必须提供预检哈希', 0);
        return this.objectRequest('POST', '/developer/onboarding/apply', { manifest: input.manifest, preview_hash: input.previewHash, apply: true }, input.requestId, true);
    }
    routeSyncPreview(manifest, disableMissing = false, requestId) {
        return this.objectRequest('POST', '/developer/route-manifest/preview', { manifest, disable_missing: disableMissing }, requestId, false);
    }
    routeSyncApply(input) {
        if (!/^[a-f0-9]{64}$/.test(input.previewHash))
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '路由清单必须提供有效预检哈希', 0);
        return this.objectRequest('POST', '/developer/route-manifest/apply', {
            manifest: input.manifest,
            disable_missing: input.disableMissing ?? false,
            preview_hash: input.previewHash,
            apply: true
        }, input.requestId, true);
    }
    openApiImportPreview(input, requestId) {
        return this.objectRequest('POST', '/developer/openapi-import/preview', { import: input }, requestId, false);
    }
    openApiImportApply(input) {
        if (!/^[a-f0-9]{64}$/.test(input.previewHash))
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', 'OpenAPI 导入必须提供有效预检哈希', 0);
        return this.objectRequest('POST', '/developer/openapi-import/apply', {
            import: input.input,
            preview_hash: input.previewHash,
            apply: true
        }, input.requestId, true);
    }
    policySimulate(input, requestId) {
        return this.objectRequest('POST', '/policy/simulate', input, requestId, false);
    }
    policyRollback(policyId, versionId, requestId) {
        if (!Number.isInteger(policyId) || policyId <= 0 || !Number.isInteger(versionId) || versionId <= 0)
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '策略和版本编号必须为正整数', 0);
        return this.objectRequest('POST', '/policy/rollback', { id: policyId, version_id: versionId }, requestId, true);
    }
    async credentialIssue(input) {
        if (!Number.isInteger(input.workloadClientId) || input.workloadClientId <= 0 || input.name.trim() === '')
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '服务调用身份和凭证名称不能为空', 0);
        return this.credentialResult(await this.objectRequest('POST', '/credential/issue', { workload_client_id: input.workloadClientId, name: input.name, expire_time: input.expireTime ?? null }, input.requestId, true));
    }
    async credentialRotate(input) {
        if (!Number.isInteger(input.credentialId) || input.credentialId <= 0 || input.name.trim() === '')
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '调用凭证编号和名称不能为空', 0);
        return this.credentialResult(await this.objectRequest('POST', '/credential/rotate', { id: input.credentialId, name: input.name, expire_time: input.expireTime ?? null }, input.requestId, true));
    }
    credentialRevoke(input) {
        if (!Number.isInteger(input.credentialId) || input.credentialId <= 0)
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '调用凭证编号必须为正整数', 0);
        return this.objectRequest('POST', '/credential/revoke', { id: input.credentialId }, input.requestId, true);
    }
    presetList(requestId) {
        return this.listRequest('GET', '/identity-provider-preset/index', undefined, requestId, false);
    }
    presetDraft(input) {
        if (input.code.trim() === '' || input.clientId.trim() === '' || input.redirectUri.trim() === '' || input.handoffReturnUris.length === 0)
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '预设代码、客户端编号、回调地址和回跳白名单不能为空', 0);
        const body = { code: input.code, client_id: input.clientId, redirect_uri: input.redirectUri, handoff_return_uris: [...input.handoffReturnUris] };
        if (input.tenantId !== undefined)
            body.tenant_id = input.tenantId;
        return this.objectRequest('POST', '/identity-provider-preset/draft', body, input.requestId, false);
    }
    async objectRequest(method, path, body, requestId, write) {
        const data = await this.request(method, path, body, requestId, write);
        if (!isRecord(data))
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的数据结构不正确', 200);
        return data;
    }
    async listRequest(method, path, body, requestId, write) {
        const data = await this.request(method, path, body, requestId, write);
        if (!Array.isArray(data) || data.some((item) => !isRecord(item)))
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的列表不正确', 200);
        return data;
    }
    async request(method, path, body, requestId, write) {
        if (write)
            this.assertRequestId(requestId);
        const token = (await this.options.administratorToken()).trim();
        if (token === '')
            throw new SandIamManagementError('SAND_IAM_AUTHENTICATION_FAILED', '管理员登录态或 Bearer 令牌不能为空', 401);
        const headers = { Authorization: `Bearer ${token}`, 'X-Request-Id': requestId ?? createRequestId(), 'Cache-Control': 'no-store', Accept: 'application/json' };
        const init = { method, headers, redirect: 'error' };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }
        const response = await this.fetcher(`${this.baseUrl}/app/sand-iam/admin${path}`, init);
        const payload = await response.json().catch(() => null);
        if (!response.ok) {
            const error = remoteError(payload);
            throw new SandIamManagementError(error.code, error.message, response.status);
        }
        if (!isRecord(payload) || !('data' in payload))
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', 'SandIAM 管理 API 返回的数据结构不正确', response.status);
        return payload.data;
    }
    assertOperation(manifest, requestId) {
        if (typeof manifest.operation_id !== 'string' || manifest.operation_id.trim() === '')
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_ARGUMENT', '应用或路由同步必须在 manifest 中提供 operation_id', 0);
        this.assertRequestId(requestId);
    }
    assertRequestId(requestId) {
        if (requestId === undefined || !/^[A-Za-z0-9_.:-]{8,96}$/.test(requestId))
            throw new SandIamManagementError('SAND_IAM_SDK_REQUEST_ID_REQUIRED', '写操作必须显式提供 8–96 位 request_id', 0);
    }
    credentialResult(data) {
        const replayed = data.replayed === true;
        const value = data.credential;
        if (value !== undefined && (typeof value !== 'string' || value === '' || replayed))
            throw new SandIamManagementError('SAND_IAM_SDK_INVALID_RESPONSE', '重放响应不得包含一次性调用凭证', 200);
        const { credential: _credential, ...metadata } = data;
        return new SandIamCredentialResult(metadata, value !== undefined, typeof value === 'string' ? new SandIamOneTimeSecret(value) : undefined, replayed);
    }
}
function isRecord(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
function remoteError(value) {
    const message = isRecord(value) && typeof value.msg === 'string'
        ? value.msg
        : isRecord(value) && typeof value.message === 'string'
            ? value.message
            : 'SandIAM 管理 API 请求失败';
    return { code: message.match(/^(SAND_IAM_[A-Z0-9_]+)/)?.[1] ?? 'SAND_IAM_REQUEST_FAILED', message };
}
function createRequestId() {
    if (typeof globalThis.crypto?.randomUUID === 'function')
        return globalThis.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    return Array.from(bytes, (value) => value.toString(16).padStart(2, '0')).join('');
}
