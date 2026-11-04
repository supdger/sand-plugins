/**
 * OAuth/OIDC 人工确认页只消费 OAuthOidcService 已冻结的交互字段。
 * 授权请求、CSRF 和授权码不进入页面展示、URL 历史或持久化存储。外部登录跳转所需的
 * 一次性确认信息只可保留在当前标签页，回跳后立即删除。
 */

export interface SandIamOAuthInteraction {
  readonly clientName: string;
  readonly clientId: string;
  readonly organizationCode: string;
  readonly applicationCode: string;
  readonly scopes: readonly string[];
  readonly expiresIn: number;
  readonly bound: boolean;
}

export interface SandIamOAuthBoundInteraction {
  readonly csrfToken: string;
  readonly clientName: string;
  readonly scopes: readonly string[];
  readonly consentRequired: boolean;
  readonly expiresIn: number;
}

export interface SandIamOAuthDecision {
  readonly redirectUri: string;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && "data" in value ? value.data : value;
}

function readStringList(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string" && item.trim() !== "")
    : [];
}

export function oauthRequestLooksValid(request: string): boolean {
  return /^siam_oar_[a-f0-9]{64}$/.test(request);
}

export function parseOAuthInteraction(value: unknown): SandIamOAuthInteraction | null {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  const clientName = payload.client_name;
  const clientId = payload.client_id;
  const organizationCode = payload.organization_code;
  const applicationCode = payload.application_code;
  const expiresIn = payload.expires_in;
  if (
    typeof clientName !== "string" ||
    clientName.trim() === "" ||
    typeof clientId !== "string" ||
    clientId.trim() === "" ||
    typeof organizationCode !== "string" ||
    organizationCode.trim() === "" ||
    typeof applicationCode !== "string" ||
    applicationCode.trim() === "" ||
    typeof expiresIn !== "number" ||
    !Number.isFinite(expiresIn)
  ) {
    return null;
  }
  return {
    clientName: clientName.trim(),
    clientId: clientId.trim(),
    organizationCode: organizationCode.trim(),
    applicationCode: applicationCode.trim(),
    scopes: readStringList(payload.scope),
    expiresIn,
    bound: payload.bound === true,
  };
}

export function parseOAuthBoundInteraction(
  value: unknown,
): SandIamOAuthBoundInteraction | null {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  const csrfToken = payload.csrf_token;
  const clientName = payload.client_name;
  const consentRequired = payload.consent_required;
  const expiresIn = payload.expires_in;
  if (
    typeof csrfToken !== "string" ||
    !/^siam_oac_[a-f0-9]{64}$/.test(csrfToken) ||
    typeof clientName !== "string" ||
    clientName.trim() === "" ||
    typeof consentRequired !== "boolean" ||
    typeof expiresIn !== "number" ||
    !Number.isFinite(expiresIn)
  ) {
    return null;
  }
  return {
    csrfToken,
    clientName: clientName.trim(),
    scopes: readStringList(payload.scope),
    consentRequired,
    expiresIn,
  };
}

export function parseOAuthDecision(value: unknown): SandIamOAuthDecision | null {
  const payload = unwrap(value);
  if (!isRecord(payload) || typeof payload.redirect_uri !== "string") return null;
  const redirectUri = payload.redirect_uri.trim();
  return redirectUri === "" ? null : { redirectUri };
}
