import {
  parsePublicExperience,
  type SandIamPortalAuthOutcome,
  parsePortalAuthResult,
  type SandIamPublicExperience,
  type SandIamPortalSessionTokens,
} from "./experienceContracts";
import {
  parseInvitationAcceptResult,
  type SandIamInvitationAcceptResult,
} from "./invitationContracts";
import {
  parseCasConfirm,
  parseCasInteraction,
  type SandIamCasConfirm,
  type SandIamCasInteraction,
} from "./casContracts";
import {
  oauthRequestLooksValid,
  parseOAuthBoundInteraction,
  parseOAuthDecision,
  parseOAuthInteraction,
  type SandIamOAuthBoundInteraction,
  type SandIamOAuthDecision,
  type SandIamOAuthInteraction,
} from "./oauthContracts";
import {
  parsePasskeyOptions,
  parsePasskeyAssertionOptions,
  parseRecoveryCodes,
  parseTotpStart,
  type SandIamWebAuthnOptions,
} from "./mfaContracts";
import {
  parsePortalConnections,
  parsePortalFactors,
  parsePortalProfile,
  parsePortalSecurity,
  parsePortalSessions,
  portalRequestHeaders,
  type SandIamPortalConnection,
  type SandIamPortalFactor,
  type SandIamPortalProfile,
  type SandIamPortalSecurity,
  type SandIamPortalSession,
} from "./meContracts";

export const SAND_IAM_PORTAL_AUTH_PREFIX = "/api/sand-iam/v1/auth";
export const SAND_IAM_PORTAL_ME_PREFIX = "/api/sand-iam/v1/me";
export const SAND_IAM_PORTAL_EXPERIENCE = "/api/sand-iam/v1/experience";
export const SAND_IAM_PORTAL_INVITATION_ACCEPT = "/api/sand-iam/v1/invitations/accept";
export const SAND_IAM_PORTAL_CAS_INTERACTION = "/api/sand-iam/v1/cas/interaction";
export const SAND_IAM_PORTAL_CAS_CONFIRM = "/api/sand-iam/v1/cas/interaction/confirm";
export const SAND_IAM_PORTAL_CAS_REJECT = "/api/sand-iam/v1/cas/interaction/reject";
export const SAND_IAM_PORTAL_OAUTH_INTERACTION = "/api/sand-iam/v1/oauth/interaction";
export const SAND_IAM_PORTAL_OAUTH_BIND = "/api/sand-iam/v1/oauth/interaction/session";
export const SAND_IAM_PORTAL_OAUTH_CONFIRM = "/api/sand-iam/v1/oauth/interaction/confirm";
export const SAND_IAM_PORTAL_FEDERATION_PREFIX = "/api/sand-iam/v1/federation";

export class SandIamPortalTransportError extends Error {
  readonly http: number | null;

  constructor(message: string, http: number | null) {
    super(message);
    this.name = "SandIamPortalTransportError";
    this.http = http;
  }
}

export interface SandIamPortalResult<T> {
  readonly requestId: string;
  readonly data: T;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readMessage(body: Record<string, unknown> | null): string {
  if (body === null) return "";
  const message = body.msg ?? body.message ?? body.code;
  return typeof message === "string" ? message : "";
}

function createRequestId(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `sand-iam-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

/**
 * 只调用公开 /auth/*、/me/*、/invitations/accept 与 /cas/interaction，credentials 省略，不发送 check_admin。
 */
async function portalRequest(
  method: "GET" | "POST" | "PATCH",
  url: string,
  accessToken: string,
  body?: Readonly<Record<string, unknown>>,
): Promise<{ readonly requestId: string; readonly data: unknown }> {
  const token = accessToken.trim();
  if (token === "") {
    throw new SandIamPortalTransportError("请先登录当前应用账号。", 401);
  }
  const requestId = createRequestId();
  let response: Response;
  try {
    const init: RequestInit = {
      method,
      credentials: "omit",
      headers: portalRequestHeaders(token, requestId, body !== undefined),
    };
    if (body !== undefined) init.body = JSON.stringify(body);
    response = await fetch(url, init);
  } catch {
    throw new SandIamPortalTransportError(
      "网络不可用，请检查连接后重试；持续失败时联系应用管理员。",
      null,
    );
  }
  let parsed: unknown = null;
  try {
    parsed = (await response.json()) as unknown;
  } catch {
    parsed = null;
  }
  const record = isRecord(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  return { requestId, data: record !== null && "data" in record ? record.data : parsed };
}

/**
 * 公开登录体验不带令牌、不带 check_admin；503/404 必须显示为未配置，不能当成登录成功。
 */
export async function loadPublicExperience(
  organizationCode: string,
  applicationCode: string,
): Promise<SandIamPortalResult<SandIamPublicExperience>> {
  const requestId = createRequestId();
  let response: Response;
  try {
    const query = new URLSearchParams({
      organization_code: organizationCode,
      application_code: applicationCode,
    });
    response = await fetch(`${SAND_IAM_PORTAL_EXPERIENCE}?${query.toString()}`, {
      method: "GET",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "X-Request-Id": requestId,
      },
    });
  } catch {
    throw new SandIamPortalTransportError("网络不可用，请检查连接后重试。", null);
  }
  let parsed: unknown = null;
  try {
    parsed = (await response.json()) as unknown;
  } catch {
    parsed = null;
  }
  if (!response.ok) {
    const record = isRecord(parsed) ? parsed : null;
    throw new SandIamPortalTransportError(
      readMessage(record) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  const experience = parsePublicExperience(parsed);
  if (experience === null) {
    throw new SandIamPortalTransportError("登录外观返回格式不符合已冻结约定", null);
  }
  return { requestId, data: experience };
}

async function portalPublicAuth(
  path: string,
  body: Readonly<Record<string, unknown>>,
): Promise<SandIamPortalResult<unknown>> {
  const requestId = createRequestId();
  let response: Response;
  try {
    response = await fetch(`${SAND_IAM_PORTAL_AUTH_PREFIX}${path}`, {
      method: "POST",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Request-Id": requestId,
      },
      body: JSON.stringify(body),
    });
  } catch {
    throw new SandIamPortalTransportError("网络不可用，请检查连接后重试。", null);
  }
  let parsed: unknown = null;
  try {
    parsed = (await response.json()) as unknown;
  } catch {
    parsed = null;
  }
  const record = isRecord(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  return { requestId, data: record !== null && "data" in record ? record.data : parsed };
}

export async function portalLogin(
  organizationCode: string,
  applicationCode: string,
  identifier: string,
  password: string,
  captchaToken: string,
): Promise<SandIamPortalResult<SandIamPortalAuthOutcome>> {
  const body: Record<string, unknown> = {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    password,
  };
  if (captchaToken.trim() !== "") body.captcha_token = captchaToken.trim();
  const result = await portalPublicAuth("/login", body);
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null) {
    throw new SandIamPortalTransportError("登录响应不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: tokens };
}

export async function portalRegister(
  organizationCode: string,
  applicationCode: string,
  fields: Readonly<Record<string, string>>,
): Promise<SandIamPortalResult<SandIamPortalAuthOutcome>> {
  const result = await portalPublicAuth("/register", {
    organization_code: organizationCode,
    application_code: applicationCode,
    ...fields,
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null) {
    throw new SandIamPortalTransportError("注册响应不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: tokens };
}

export async function portalIdentityVerification(
  organizationCode: string,
  applicationCode: string,
  identifier: string,
  channel: "email" | "phone",
  code?: string,
): Promise<SandIamPortalResult<unknown>> {
  return portalPublicAuth(code === undefined ? "/verification/request" : "/verification/confirm", {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    channel,
    purpose: channel === "email" ? "email_verify" : "phone_verify",
    ...(code === undefined ? {} : { code }),
  });
}

export async function verifyPortalMfaChallenge(
  organizationCode: string,
  applicationCode: string,
  challengeToken: string,
  method: "totp" | "recovery_code",
  code: string,
): Promise<SandIamPortalResult<SandIamPortalSessionTokens>> {
  const result = await portalPublicAuth("/mfa/challenge/verify", {
    organization_code: organizationCode,
    application_code: applicationCode,
    challenge_token: challengeToken,
    method,
    code,
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null || tokens.mfaRequired || tokens.verificationRequired || tokens.accessToken === "") {
    throw new SandIamPortalTransportError("多重验证响应不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: tokens };
}

export async function verifyPortalPasskeyChallenge(
  organizationCode: string,
  applicationCode: string,
  challengeToken: string,
  optionsPayload: unknown,
  assertion: Readonly<Record<string, unknown>>,
): Promise<SandIamPortalResult<SandIamPortalSessionTokens>> {
  const options = parsePasskeyAssertionOptions(optionsPayload);
  if (options === null) throw new SandIamPortalTransportError("通行密钥登录信息不完整。", null);
  const result = await portalPublicAuth("/mfa/challenge/verify", {
    organization_code: organizationCode,
    application_code: applicationCode,
    challenge_token: challengeToken,
    method: "passkey",
    ...assertion,
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null || tokens.mfaRequired || tokens.verificationRequired || tokens.accessToken === "") {
    throw new SandIamPortalTransportError("通行密钥登录响应不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: tokens };
}

export async function startPortalPasskeyLogin(
  organizationCode: string,
  applicationCode: string,
): Promise<SandIamPortalResult<{ readonly challengeToken: string; readonly publicKey: unknown }>> {
  const result = await portalPublicAuth("/passkeys/authentication/options", {
    organization_code: organizationCode,
    application_code: applicationCode,
  });
  const payload = isRecord(result.data) ? result.data : null;
  const challengeToken = payload === null || typeof payload.challenge_token !== "string"
    ? ""
    : payload.challenge_token.trim();
  const publicKey = payload === null ? null : payload.public_key;
  if (challengeToken === "" || !isRecord(publicKey)) {
    throw new SandIamPortalTransportError("通行密钥登录信息不完整。", null);
  }
  return { requestId: result.requestId, data: { challengeToken, publicKey } };
}

export async function finishPortalPasskeyLogin(
  organizationCode: string,
  applicationCode: string,
  challengeToken: string,
  assertion: Readonly<Record<string, unknown>>,
): Promise<SandIamPortalResult<SandIamPortalAuthOutcome>> {
  const result = await portalPublicAuth("/passkeys/authentication/finish", {
    organization_code: organizationCode,
    application_code: applicationCode,
    challenge_token: challengeToken,
    ...assertion,
  });
  const tokens = parsePortalAuthResult(result.data);
  if (tokens === null) {
    throw new SandIamPortalTransportError("通行密钥登录响应不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: tokens };
}

function federationStartEndpoint(protocol: "oidc" | "oauth2" | "saml"): string {
  return `${SAND_IAM_PORTAL_FEDERATION_PREFIX}/${protocol}/start`;
}

function readRedirectUri(value: unknown): string | null {
  if (!isRecord(value) || typeof value.redirect_uri !== "string") return null;
  const redirectUri = value.redirect_uri.trim();
  return redirectUri === "" ? null : redirectUri;
}

/**
 * 外部身份源的跳转地址由服务端根据已挂载身份源签发，门户不拼接供应商地址。
 */
export async function startPortalFederationLogin(
  protocol: "oidc" | "oauth2" | "saml",
  providerCode: string,
  applicationCode: string,
  returnUri: string,
  state: string,
  verifier: string,
): Promise<SandIamPortalResult<{ readonly redirectUri: string }>> {
  const requestId = createRequestId();
  const query = new URLSearchParams({
    provider: providerCode,
    application: applicationCode,
    return_uri: returnUri,
    state,
    code_challenge: verifier,
    purpose: "login",
  });
  let response: Response;
  try {
    response = await fetch(`${federationStartEndpoint(protocol)}?${query.toString()}`, {
      method: "GET",
      credentials: "omit",
      headers: { Accept: "application/json", "X-Request-Id": requestId },
    });
  } catch {
    throw new SandIamPortalTransportError("网络不可用，请检查连接后重试。", null);
  }
  let payload: unknown = null;
  try {
    payload = (await response.json()) as unknown;
  } catch {
    payload = null;
  }
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(isRecord(payload) ? payload : null) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  const redirectUri = readRedirectUri(payload);
  if (redirectUri === null) throw new SandIamPortalTransportError("外部登录没有返回跳转地址。", null);
  return { requestId, data: { redirectUri } };
}

export async function exchangePortalFederationHandoff(
  providerCode: string,
  applicationCode: string,
  code: string,
  returnUri: string,
  verifier: string,
): Promise<SandIamPortalResult<SandIamPortalAuthOutcome>> {
  const requestId = createRequestId();
  let response: Response;
  try {
    response = await fetch(`${SAND_IAM_PORTAL_FEDERATION_PREFIX}/handoff/exchange`, {
      method: "POST",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Request-Id": requestId,
      },
      body: JSON.stringify({
        provider: providerCode,
        application: applicationCode,
        code,
        return_uri: returnUri,
        verifier,
      }),
    });
  } catch {
    throw new SandIamPortalTransportError("网络不可用，请检查连接后重试。", null);
  }
  let payload: unknown = null;
  try {
    payload = (await response.json()) as unknown;
  } catch {
    payload = null;
  }
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(isRecord(payload) ? payload : null) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  const outcome = parsePortalAuthResult(payload);
  if (outcome === null) throw new SandIamPortalTransportError("外部登录响应不符合已冻结约定", null);
  return { requestId, data: outcome };
}

export async function portalForgotPassword(
  organizationCode: string,
  applicationCode: string,
  identifier: string,
  channel: "email" | "phone",
): Promise<SandIamPortalResult<null>> {
  const result = await portalPublicAuth("/password/forgot", {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    channel,
  });
  return { requestId: result.requestId, data: null };
}

/**
 * 接受邀请不带管理会话，也不签发应用登录凭据；成功后必须再走登录。
 * token 只在本次请求体中发送，不写入 URL 以外的存储或日志。
 */
export async function portalAcceptInvitation(
  token: string,
  username: string,
  displayName: string,
  password: string,
): Promise<SandIamPortalResult<SandIamInvitationAcceptResult>> {
  const requestId = createRequestId();
  let response: Response;
  try {
    response = await fetch(SAND_IAM_PORTAL_INVITATION_ACCEPT, {
      method: "POST",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Request-Id": requestId,
      },
      body: JSON.stringify({
        token,
        username,
        display_name: displayName,
        password,
      }),
    });
  } catch {
    throw new SandIamPortalTransportError("网络不可用，请检查连接后重试。", null);
  }
  let parsed: unknown = null;
  try {
    parsed = (await response.json()) as unknown;
  } catch {
    parsed = null;
  }
  const record = isRecord(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  const accepted = parseInvitationAcceptResult(record !== null && "data" in record ? record.data : parsed);
  if (accepted === null) {
    throw new SandIamPortalTransportError("接受邀请响应不符合已冻结约定", null);
  }
  return { requestId, data: accepted };
}

export async function portalResetPassword(
  organizationCode: string,
  applicationCode: string,
  identifier: string,
  channel: "email" | "phone",
  code: string,
  password: string,
): Promise<SandIamPortalResult<null>> {
  const result = await portalPublicAuth("/password/reset", {
    organization_code: organizationCode,
    application_code: applicationCode,
    identifier,
    channel,
    code,
    password,
  });
  return { requestId: result.requestId, data: null };
}

export async function loadPortalProfile(
  accessToken: string,
): Promise<SandIamPortalResult<SandIamPortalProfile>> {
  const result = await portalRequest("GET", `${SAND_IAM_PORTAL_ME_PREFIX}/profile`, accessToken);
  const profile = parsePortalProfile(result.data);
  if (profile === null) {
    throw new SandIamPortalTransportError("资料返回格式不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: profile };
}

export async function updatePortalProfile(
  accessToken: string,
  displayName: string,
): Promise<SandIamPortalResult<SandIamPortalProfile>> {
  const result = await portalRequest(
    "PATCH",
    `${SAND_IAM_PORTAL_ME_PREFIX}/profile`,
    accessToken,
    { display_name: displayName },
  );
  const profile = parsePortalProfile(result.data);
  if (profile === null) {
    throw new SandIamPortalTransportError("资料更新返回格式不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: profile };
}

export async function loadPortalSecurity(
  accessToken: string,
): Promise<SandIamPortalResult<SandIamPortalSecurity>> {
  const result = await portalRequest("GET", `${SAND_IAM_PORTAL_ME_PREFIX}/security`, accessToken);
  const security = parsePortalSecurity(result.data);
  if (security === null) {
    throw new SandIamPortalTransportError("安全概况返回格式不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: security };
}

export async function loadPortalConnections(
  accessToken: string,
): Promise<SandIamPortalResult<SandIamPortalConnection[]>> {
  const result = await portalRequest(
    "GET",
    `${SAND_IAM_PORTAL_ME_PREFIX}/connections`,
    accessToken,
  );
  return {
    requestId: result.requestId,
    data: parsePortalConnections(result.data),
  };
}

export async function loadPortalSessions(
  accessToken: string,
): Promise<SandIamPortalResult<SandIamPortalSession[]>> {
  const result = await portalRequest("GET", `${SAND_IAM_PORTAL_AUTH_PREFIX}/sessions`, accessToken);
  return { requestId: result.requestId, data: parsePortalSessions(result.data) };
}

export async function revokePortalSession(
  accessToken: string,
  sessionId: number,
): Promise<SandIamPortalResult<null>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/sessions/revoke`,
    accessToken,
    { id: sessionId },
  );
  return { requestId: result.requestId, data: null };
}

export async function loadPortalFactors(
  accessToken: string,
): Promise<SandIamPortalResult<SandIamPortalFactor[]>> {
  const result = await portalRequest(
    "GET",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/factors`,
    accessToken,
  );
  return { requestId: result.requestId, data: parsePortalFactors(result.data) };
}

export async function revokePortalFactor(
  accessToken: string,
  factorId: number,
  type: "totp" | "passkey",
  password: string,
): Promise<SandIamPortalResult<null>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/factors/revoke`,
    accessToken,
    { factor_id: factorId, type, password },
  );
  return { requestId: result.requestId, data: null };
}

export async function startPortalTotp(
  accessToken: string,
  name: string,
  currentPassword: string,
): Promise<SandIamPortalResult<{ readonly factorId: number; readonly secret: string; readonly otpAuthUri: string }>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/totp/start`,
    accessToken,
    { name, current_password: currentPassword },
  );
  const started = parseTotpStart(result.data);
  if (started === null) throw new SandIamPortalTransportError("验证器初始化响应不符合已冻结约定", null);
  return { requestId: result.requestId, data: started };
}

export async function confirmPortalTotp(
  accessToken: string,
  factorId: number,
  code: string,
): Promise<SandIamPortalResult<readonly string[]>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/totp/confirm`,
    accessToken,
    { factor_id: factorId, code },
  );
  const codes = parseRecoveryCodes(result.data);
  if (codes === null) throw new SandIamPortalTransportError("恢复码响应不符合已冻结约定", null);
  return { requestId: result.requestId, data: codes };
}

export async function regeneratePortalRecoveryCodes(
  accessToken: string,
  password: string,
): Promise<SandIamPortalResult<readonly string[]>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/mfa/recovery/regenerate`,
    accessToken,
    { password },
  );
  const codes = parseRecoveryCodes(result.data);
  if (codes === null) throw new SandIamPortalTransportError("恢复码响应不符合已冻结约定", null);
  return { requestId: result.requestId, data: codes };
}

export async function startPortalPasskey(
  accessToken: string,
  name: string,
  currentPassword: string,
): Promise<SandIamPortalResult<{ readonly challengeToken: string; readonly options: SandIamWebAuthnOptions }>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/passkeys/registration/options`,
    accessToken,
    { name, current_password: currentPassword },
  );
  const payload = isRecord(result.data) ? result.data : null;
  const challengeToken = payload === null || typeof payload.challenge_token !== "string" ? "" : payload.challenge_token;
  const options = parsePasskeyOptions(result.data);
  if (challengeToken === "" || options === null) {
    throw new SandIamPortalTransportError("通行密钥初始化响应不符合已冻结约定", null);
  }
  return { requestId: result.requestId, data: { challengeToken, options } };
}

export async function finishPortalPasskey(
  accessToken: string,
  payload: Readonly<Record<string, unknown>>,
): Promise<SandIamPortalResult<null>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/passkeys/registration/finish`,
    accessToken,
    payload,
  );
  return { requestId: result.requestId, data: null };
}

/**
 * 改密成功后全部会话失效；调用方必须丢掉内存中的登录凭据。
 */
export async function changePortalPassword(
  accessToken: string,
  currentPassword: string,
  newPassword: string,
): Promise<SandIamPortalResult<null>> {
  const result = await portalRequest(
    "POST",
    `${SAND_IAM_PORTAL_AUTH_PREFIX}/password/change`,
    accessToken,
    { current_password: currentPassword, new_password: newPassword },
  );
  return { requestId: result.requestId, data: null };
}

/**
 * CAS 确认页读取 request 不带管理会话；失败由后端状态码决定，不能当成确认成功。
 */
export async function loadCasInteraction(
  requestToken: string,
): Promise<SandIamPortalResult<SandIamCasInteraction>> {
  const requestId = createRequestId();
  let response: Response;
  try {
    const query = new URLSearchParams({ request: requestToken });
    response = await fetch(`${SAND_IAM_PORTAL_CAS_INTERACTION}?${query.toString()}`, {
      method: "GET",
      credentials: "omit",
      headers: {
        Accept: "application/json",
        "X-Request-Id": requestId,
      },
    });
  } catch {
    throw new SandIamPortalTransportError("网络不可用，请检查连接后重试。", null);
  }
  let parsed: unknown = null;
  try {
    parsed = (await response.json()) as unknown;
  } catch {
    parsed = null;
  }
  if (!response.ok) {
    const record = isRecord(parsed) ? parsed : null;
    throw new SandIamPortalTransportError(
      readMessage(record) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  const interaction = parseCasInteraction(parsed);
  if (interaction === null) {
    throw new SandIamPortalTransportError("CAS 确认信息返回格式不符合已冻结约定", null);
  }
  return { requestId, data: interaction };
}

/**
 * 确认必须使用当前应用用户 Bearer。跳转地址只取后端 redirect_uri。
 */
export async function confirmCasInteraction(
  accessToken: string,
  requestToken: string,
): Promise<SandIamPortalResult<SandIamCasConfirm>> {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_CAS_CONFIRM,
    accessToken,
    { request: requestToken },
  );
  const confirm = parseCasConfirm(result.data);
  if (confirm === null) {
    throw new SandIamPortalTransportError("CAS 确认结果没有跳转地址，页面不会自行拼接 Ticket。", null);
  }
  return { requestId: result.requestId, data: confirm };
}

export async function rejectCasInteraction(
  accessToken: string,
  requestToken: string,
): Promise<SandIamPortalResult<SandIamCasConfirm>> {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_CAS_REJECT,
    accessToken,
    { request: requestToken },
  );
  const decision = parseCasConfirm(result.data);
  if (decision === null) throw new SandIamPortalTransportError("CAS 拒绝结果没有返回地址。", null);
  return { requestId: result.requestId, data: decision };
}

async function publicPortalRequest(
  url: string,
): Promise<SandIamPortalResult<unknown>> {
  const requestId = createRequestId();
  let response: Response;
  try {
    response = await fetch(url, {
      method: "GET",
      credentials: "omit",
      headers: { Accept: "application/json", "X-Request-Id": requestId },
    });
  } catch {
    throw new SandIamPortalTransportError("网络不可用，请检查连接后重试。", null);
  }
  let parsed: unknown = null;
  try {
    parsed = (await response.json()) as unknown;
  } catch {
    parsed = null;
  }
  const record = isRecord(parsed) ? parsed : null;
  if (!response.ok) {
    throw new SandIamPortalTransportError(
      readMessage(record) || `请求未完成（HTTP ${String(response.status)}）`,
      response.status,
    );
  }
  return { requestId, data: record !== null && "data" in record ? record.data : parsed };
}

export async function loadOAuthInteraction(
  requestToken: string,
): Promise<SandIamPortalResult<SandIamOAuthInteraction>> {
  if (!oauthRequestLooksValid(requestToken)) throw new SandIamPortalTransportError("授权确认请求无效。", 400);
  const result = await publicPortalRequest(
    `${SAND_IAM_PORTAL_OAUTH_INTERACTION}?${new URLSearchParams({ request: requestToken }).toString()}`,
  );
  const interaction = parseOAuthInteraction(result.data);
  if (interaction === null) throw new SandIamPortalTransportError("授权确认信息返回格式不符合已冻结约定", null);
  return { requestId: result.requestId, data: interaction };
}

export async function bindOAuthInteraction(
  accessToken: string,
  requestToken: string,
): Promise<SandIamPortalResult<SandIamOAuthBoundInteraction>> {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_OAUTH_BIND,
    accessToken,
    { authorization_request: requestToken },
  );
  const bound = parseOAuthBoundInteraction(result.data);
  if (bound === null) throw new SandIamPortalTransportError("授权确认会话未建立。", null);
  return { requestId: result.requestId, data: bound };
}

export async function decideOAuthInteraction(
  accessToken: string,
  requestToken: string,
  csrfToken: string,
  decision: "approve" | "deny",
): Promise<SandIamPortalResult<SandIamOAuthDecision>> {
  const result = await portalRequest(
    "POST",
    SAND_IAM_PORTAL_OAUTH_CONFIRM,
    accessToken,
    {
      authorization_request: requestToken,
      csrf_token: csrfToken,
      decision,
    },
  );
  const completed = parseOAuthDecision(result.data);
  if (completed === null) throw new SandIamPortalTransportError("授权结果没有返回地址。", null);
  return { requestId: result.requestId, data: completed };
}

export function describePortalError(error: unknown): {
  readonly title: string;
  readonly detail: string;
  readonly http: number | null;
} {
  const http = error instanceof SandIamPortalTransportError ? error.http : null;
  const detail = error instanceof Error ? error.message : "请求未完成";
  if (http === 401) {
    return {
      title: "登录已失效",
      detail: "请重新登录后再试。页面不会保存令牌明文。",
      http,
    };
  }
  if (http === 403) {
    return {
      title: "没有权限",
      detail: "当前应用用户无权执行此操作。这与没有数据不同。",
      http,
    };
  }
  if (http === 409) {
    return { title: "与现有配置冲突", detail, http };
  }
  if (http === 410) {
    return {
      title: "邀请已过期",
      detail: "该邀请链接已过期，不能重放。请联系应用管理员重发。",
      http,
    };
  }
  if (http === 423) {
    return { title: "账号已锁定", detail, http };
  }
  if (http === 404) {
    // CAS interaction 失败不能复用“登录外观未配置”，否则会把确认失败说成品牌未配置。
    if (detail.includes("CAS") || detail.includes("SAND_IAM_CAS_")) {
      return { title: "CAS 请求未被接受", detail, http };
    }
    return {
      title: "登录外观未配置",
      detail: "该应用还没有启用的登录外观。这不是登录成功。",
      http,
    };
  }
  if (http === 503) {
    return {
      title: "服务暂时不可用",
      detail: "请稍后重试；持续失败时联系应用管理员。",
      http,
    };
  }
  return { title: "请求未完成", detail, http };
}
