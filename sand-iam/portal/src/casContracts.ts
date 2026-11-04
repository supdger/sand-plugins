/**
 * 门户 CAS 确认只消费 CasProtocolService::interaction / confirm 已返回字段。
 * Ticket、request HMAC 和用户身份编号即使误回也不进入展示对象。
 */

export interface SandIamCasInteraction {
  readonly organizationCode: string;
  readonly applicationCode: string;
  readonly applicationName: string;
  readonly serviceName: string;
  readonly serviceUrl: string;
  readonly expiresIn: number;
}

export interface SandIamCasConfirm {
  readonly redirectUri: string;
  readonly expiresIn: number;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && "data" in value ? value.data : value;
}

/**
 * 确认页只展示应用名称、服务名称和精确地址。
 */
export function parseCasInteraction(value: unknown): SandIamCasInteraction | null {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  const applicationName = payload.application_name;
  const organizationCode = payload.organization_code;
  const applicationCode = payload.application_code;
  const serviceName = payload.service_name;
  const serviceUrl = payload.service_url;
  const expiresIn = payload.expires_in;
  if (
    typeof applicationName !== "string" ||
    applicationName.trim() === "" ||
    typeof organizationCode !== "string" ||
    organizationCode.trim() === "" ||
    typeof applicationCode !== "string" ||
    applicationCode.trim() === "" ||
    typeof serviceName !== "string" ||
    serviceName.trim() === "" ||
    typeof serviceUrl !== "string" ||
    serviceUrl.trim() === "" ||
    typeof expiresIn !== "number"
  ) {
    return null;
  }
  return {
    organizationCode: organizationCode.trim(),
    applicationCode: applicationCode.trim(),
    applicationName,
    serviceName,
    serviceUrl,
    expiresIn,
  };
}

/**
 * 确认结果只保留后端给出的跳转地址，页面不得自行拼接 Ticket。
 */
export function parseCasConfirm(value: unknown): SandIamCasConfirm | null {
  const payload = unwrap(value);
  if (!isRecord(payload) || typeof payload.redirect_uri !== "string" || payload.redirect_uri === "") {
    return null;
  }
  return {
    redirectUri: payload.redirect_uri,
    expiresIn: typeof payload.expires_in === "number" ? payload.expires_in : 0,
  };
}

/**
 * 只接受后端签发的 CRT- 确认请求，拒绝手填或截断值。
 */
export function casRequestLooksValid(request: string): boolean {
  return /^CRT-[A-Za-z0-9_-]{48}$/.test(request);
}
