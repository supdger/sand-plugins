/**
 * 独立门户只消费 SelfServiceService 与 HumanAuthService 已冻结字段。
 * 解析结果不包含 identity_id、token、secret、完整 subject。
 */

export interface SandIamPortalProfile {
  readonly displayName: string;
  readonly organizationName: string;
  readonly organizationCode: string;
  readonly applicationName: string;
  readonly applicationCode: string;
  readonly createTime: string | null;
}

export interface SandIamPortalSecurity {
  readonly passwordEnabled: boolean;
  readonly activeSessions: number;
  readonly totpFactors: number;
  readonly passkeys: number;
  readonly connectedAccounts: number;
}

export interface SandIamPortalConnection {
  readonly bindingId: number;
  readonly providerName: string;
  readonly providerType: string;
  readonly accountHint: string;
  readonly sourceState: string;
  readonly linkedTime: string | null;
}

export interface SandIamPortalSession {
  readonly id: number;
  readonly createTime: string;
  readonly lastUsedTime: string;
  readonly accessExpireTime: string;
  readonly refreshExpireTime: string;
  readonly current: boolean;
}

export interface SandIamPortalFactor {
  readonly id: number;
  readonly type: "totp" | "passkey";
  readonly name: string;
  readonly status: number;
  readonly createTime: string;
  readonly lastUsedTime: string | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function readString(value: unknown): string | null {
  return typeof value === "string" && value.trim() !== "" ? value : null;
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && "data" in value ? value.data : value;
}

/**
 * 品牌名只取当前应用；不写死产品名。identity_id 即使返回也不进入展示对象。
 */
export function parsePortalProfile(value: unknown): SandIamPortalProfile | null {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  const displayName = readString(payload.display_name);
  const organization = isRecord(payload.organization) ? payload.organization : null;
  const application = isRecord(payload.application) ? payload.application : null;
  const organizationName = organization === null ? null : readString(organization.name);
  const organizationCode = organization === null ? null : readString(organization.code);
  const applicationName = application === null ? null : readString(application.name);
  const applicationCode = application === null ? null : readString(application.code);
  if (
    displayName === null ||
    organizationName === null ||
    organizationCode === null ||
    applicationName === null ||
    applicationCode === null
  ) {
    return null;
  }
  return {
    displayName,
    organizationName,
    organizationCode,
    applicationName,
    applicationCode,
    createTime: readString(payload.create_time),
  };
}

export function parsePortalSecurity(value: unknown): SandIamPortalSecurity | null {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  const passwordEnabled = payload.password_enabled;
  const activeSessions = payload.active_sessions;
  const totpFactors = payload.totp_factors;
  const passkeys = payload.passkeys;
  const connectedAccounts = payload.connected_accounts;
  if (
    typeof passwordEnabled !== "boolean" ||
    typeof activeSessions !== "number" ||
    typeof totpFactors !== "number" ||
    typeof passkeys !== "number" ||
    typeof connectedAccounts !== "number"
  ) {
    return null;
  }
  return {
    passwordEnabled,
    activeSessions,
    totpFactors,
    passkeys,
    connectedAccounts,
  };
}

/**
 * 外部连接只保留掩码提示；停用或卸载由 source_state 标明不可用。
 */
export function parsePortalConnection(
  value: unknown,
): SandIamPortalConnection | null {
  if (!isRecord(value)) return null;
  const bindingId = value.binding_id;
  const providerName = readString(value.provider_name);
  const providerType = readString(value.provider_type);
  const accountHint = readString(value.account_hint);
  const sourceState = readString(value.source_state);
  if (
    typeof bindingId !== "number" ||
    !Number.isInteger(bindingId) ||
    bindingId <= 0 ||
    providerName === null ||
    providerType === null ||
    accountHint === null ||
    sourceState === null
  ) {
    return null;
  }
  return {
    bindingId,
    providerName,
    providerType,
    accountHint,
    sourceState,
    linkedTime: readString(value.linked_time),
  };
}

export function parsePortalConnections(value: unknown): SandIamPortalConnection[] {
  const payload = unwrap(value);
  if (!Array.isArray(payload)) {
    throw new Error("外部连接返回格式不符合已冻结约定");
  }
  return payload
    .map((item) => parsePortalConnection(item))
    .filter((item): item is SandIamPortalConnection => item !== null);
}

export function connectionAvailable(state: string): boolean {
  return state === "active";
}

export function parsePortalSession(value: unknown): SandIamPortalSession | null {
  if (!isRecord(value)) return null;
  const id = value.id;
  const createTime = readString(value.create_time);
  const lastUsedTime = readString(value.last_used_time);
  const accessExpireTime = readString(value.access_expire_time);
  const refreshExpireTime = readString(value.refresh_expire_time);
  if (
    typeof id !== "number" ||
    !Number.isInteger(id) ||
    id <= 0 ||
    createTime === null ||
    lastUsedTime === null ||
    accessExpireTime === null ||
    refreshExpireTime === null
  ) {
    return null;
  }
  return {
    id,
    createTime,
    lastUsedTime,
    accessExpireTime,
    refreshExpireTime,
    current: value.current === true || value.current === 1,
  };
}

export function parsePortalSessions(value: unknown): SandIamPortalSession[] {
  const payload = unwrap(value);
  if (!Array.isArray(payload)) {
    throw new Error("会话列表返回格式不符合已冻结约定");
  }
  return payload
    .map((item) => parsePortalSession(item))
    .filter((item): item is SandIamPortalSession => item !== null);
}

export function parsePortalFactor(value: unknown): SandIamPortalFactor | null {
  if (!isRecord(value)) return null;
  const id = value.id;
  const type = value.type;
  const name = readString(value.name);
  const status = value.status;
  const createTime = readString(value.create_time);
  if (
    typeof id !== "number" ||
    !Number.isInteger(id) ||
    id <= 0 ||
    (type !== "totp" && type !== "passkey") ||
    name === null ||
    typeof status !== "number" ||
    createTime === null
  ) {
    return null;
  }
  return {
    id,
    type,
    name,
    status,
    createTime,
    lastUsedTime: readString(value.last_used_time),
  };
}

export function parsePortalFactors(value: unknown): SandIamPortalFactor[] {
  const payload = unwrap(value);
  if (!Array.isArray(payload)) {
    throw new Error("认证方式列表返回格式不符合已冻结约定");
  }
  return payload
    .map((item) => parsePortalFactor(item))
    .filter((item): item is SandIamPortalFactor => item !== null);
}

/**
 * 门户请求头只允许 Accept / Authorization / X-Request-Id / Content-Type。
 * 测试用此函数证明不会带上 check_admin。
 */
export function portalRequestHeaders(
  accessToken: string,
  requestId: string,
  hasBody: boolean,
): Readonly<Record<string, string>> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    Authorization: `Bearer ${accessToken}`,
    "X-Request-Id": requestId,
  };
  if (hasBody) headers["Content-Type"] = "application/json";
  return headers;
}

export function isPortalAdminHeader(name: string): boolean {
  return name.toLowerCase() === "check_admin";
}
