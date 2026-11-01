/**
 * ExperienceController::read 与登录/注册公开响应。
 * 不解析数据库 ID、驱动、模板、密文或 identity.id。
 */

export interface SandIamPublicExperience {
  readonly organizationCode: string;
  readonly applicationCode: string;
  readonly brandName: string;
  readonly logoUrl: string;
  readonly primaryColor: string;
  readonly themeMode: string;
  readonly defaultLocale: string;
  readonly termsUrl: string;
  readonly privacyUrl: string;
  readonly registrationMode: "open" | "invite" | "disabled";
  readonly loginMethods: readonly string[];
  readonly registrationFields: readonly SandIamRegistrationField[];
}

export type SandIamExternalLoginMethod =
  | { readonly protocol: "oidc" | "oauth2" | "saml"; readonly providerCode: string };

export type SandIamRegistrationField = "username" | "display_name" | "email" | "phone";

const REGISTRATION_FIELD_LABELS: Readonly<Record<SandIamRegistrationField, string>> = {
  username: "用户名",
  display_name: "显示名称",
  email: "邮箱",
  phone: "手机号",
};

export interface SandIamPortalSessionTokens {
  readonly accessToken: string;
  readonly verificationRequired: boolean;
  readonly mfaRequired: false;
  readonly displayName: string | null;
}

export interface SandIamPortalMfaChallenge {
  readonly accessToken: "";
  readonly verificationRequired: false;
  readonly mfaRequired: true;
  readonly challengeToken: string;
  readonly methods: readonly ("totp" | "recovery_code" | "passkey")[];
  readonly passkeyOptions: unknown | null;
  readonly displayName: string | null;
}

export type SandIamPortalAuthOutcome = SandIamPortalSessionTokens | SandIamPortalMfaChallenge;

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function unwrap(value: unknown): unknown {
  return isRecord(value) && "data" in value ? value.data : value;
}

function readString(value: unknown): string {
  return typeof value === "string" ? value : "";
}

function readStringList(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string" && item !== "")
    : [];
}

function readRegistrationFields(value: unknown): SandIamRegistrationField[] {
  return readStringList(value).filter(
    (field): field is SandIamRegistrationField =>
      field === "username" || field === "display_name" || field === "email" || field === "phone",
  );
}

export function registrationFieldLabel(field: SandIamRegistrationField): string {
  return REGISTRATION_FIELD_LABELS[field];
}

export function parsePublicExperience(
  value: unknown,
): SandIamPublicExperience | null {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  const organizationCode = readString(payload.organization_code).trim();
  const applicationCode = readString(payload.application_code).trim();
  const brandName = readString(payload.brand_name).trim();
  const registrationMode = payload.registration_mode;
  if (
    organizationCode === "" ||
    applicationCode === "" ||
    brandName === "" ||
    (registrationMode !== "open" &&
      registrationMode !== "invite" &&
      registrationMode !== "disabled")
  ) {
    return null;
  }
  return {
    organizationCode,
    applicationCode,
    brandName,
    logoUrl: readString(payload.logo_url),
    primaryColor: readString(payload.primary_color) || "#1677ff",
    themeMode: readString(payload.theme_mode) || "system",
    defaultLocale: readString(payload.default_locale) || "zh-CN",
    termsUrl: readString(payload.terms_url),
    privacyUrl: readString(payload.privacy_url),
    registrationMode,
    loginMethods: readStringList(payload.login_methods),
    registrationFields: readRegistrationFields(payload.registration_fields),
  };
}

export function experienceAllowsPassword(
  experience: SandIamPublicExperience,
): boolean {
  return experience.loginMethods.includes("password");
}

export function experienceAllowsRegister(
  experience: SandIamPublicExperience,
): boolean {
  return experience.registrationMode === "open";
}

/**
 * 登录外观是可选的展示层。协议确认页已经由后端确认了应用归属时，
 * 外观接口不可用不能把用户挡在密码登录之外；注册仍保持关闭。
 */
export function defaultPasswordExperience(
  organizationCode: string,
  applicationCode: string,
): SandIamPublicExperience {
  return {
    organizationCode,
    applicationCode,
    brandName: "当前应用",
    logoUrl: "",
    primaryColor: "#1677ff",
    themeMode: "system",
    defaultLocale: "zh-CN",
    termsUrl: "",
    privacyUrl: "",
    registrationMode: "disabled",
    loginMethods: ["password"],
    registrationFields: ["username", "display_name", "email"],
  };
}

export function experienceAllowsPasskey(
  experience: SandIamPublicExperience,
): boolean {
  return experience.loginMethods.includes("passkey");
}

export function externalLoginMethods(
  experience: SandIamPublicExperience,
): readonly SandIamExternalLoginMethod[] {
  const methods: SandIamExternalLoginMethod[] = [];
  for (const method of experience.loginMethods) {
    const match = /^(oidc|oauth2|saml):([A-Za-z0-9_-]{20,128})$/u.exec(method);
    if (match === null) continue;
    const protocol = match[1];
    if (protocol !== "oidc" && protocol !== "oauth2" && protocol !== "saml") continue;
    methods.push({ protocol, providerCode: match[2] });
  }
  return methods;
}

/**
 * 登录/注册成功只把 access_token 留在内存；refresh_token 与 identity.id 不进入展示对象。
 */
export function parsePortalAuthResult(value: unknown): SandIamPortalAuthOutcome | null {
  const payload = unwrap(value);
  if (!isRecord(payload)) return null;
  if (payload.verification_required === true) {
    return {
      accessToken: "",
      verificationRequired: true,
      mfaRequired: false,
      displayName:
        isRecord(payload.identity) && typeof payload.identity.display_name === "string"
          ? payload.identity.display_name
          : null,
    };
  }
  if (payload.mfa_required === true) {
    const challengeToken = readString(payload.challenge_token).trim();
    const methods = readStringList(payload.methods).filter(
      (method): method is "totp" | "recovery_code" | "passkey" =>
        method === "totp" || method === "recovery_code" || method === "passkey",
    );
    if (!/^siam_mc_[a-f0-9]{64}$/.test(challengeToken) || methods.length === 0) return null;
    return {
      accessToken: "",
      verificationRequired: false,
      mfaRequired: true,
      challengeToken,
      methods,
      passkeyOptions: isRecord(payload.public_key) ? payload.public_key : null,
      displayName:
        isRecord(payload.identity) && typeof payload.identity.display_name === "string"
          ? payload.identity.display_name
          : null,
    };
  }
  const accessToken = readString(payload.access_token).trim();
  if (accessToken === "") return null;
  return {
    accessToken,
    verificationRequired: false,
    mfaRequired: false,
    displayName:
      isRecord(payload.identity) && typeof payload.identity.display_name === "string"
        ? payload.identity.display_name
        : null,
  };
}
