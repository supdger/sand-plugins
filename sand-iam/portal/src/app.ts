import {
  defaultPasswordExperience,
  experienceAllowsPasskey,
  experienceAllowsPassword,
  experienceAllowsRegister,
  externalLoginMethods,
  registrationFieldLabel,
  type SandIamPublicExperience,
} from "./experienceContracts";
import { connectionAvailable } from "./meContracts";
import { casRequestLooksValid } from "./casContracts";
import type { SandIamCasInteraction } from "./casContracts";
import { oauthRequestLooksValid, type SandIamOAuthBoundInteraction, type SandIamOAuthInteraction } from "./oauthContracts";
import { createPasskeyCredential, getPasskeyAssertion, parsePasskeyAssertionOptions } from "./mfaContracts";
import { invitationTokenLooksValid } from "./invitationContracts";
import {
  changePortalPassword,
  bindOAuthInteraction,
  confirmPortalTotp,
  confirmCasInteraction,
  decideOAuthInteraction,
  describePortalError,
  exchangePortalFederationHandoff,
  finishPortalPasskeyLogin,
  loadCasInteraction,
  loadPortalConnections,
  loadPortalFactors,
  loadPortalProfile,
  loadPortalSecurity,
  loadPortalSessions,
  loadOAuthInteraction,
  loadPublicExperience,
  portalAcceptInvitation,
  portalForgotPassword,
  portalLogin,
  portalRegister,
  verifyPortalMfaChallenge,
  verifyPortalPasskeyChallenge,
  portalResetPassword,
  regeneratePortalRecoveryCodes,
  rejectCasInteraction,
  revokePortalFactor,
  revokePortalSession,
  startPortalPasskey,
  startPortalFederationLogin,
  startPortalPasskeyLogin,
  startPortalTotp,
  finishPortalPasskey,
  updatePortalProfile,
} from "./runtime";
import type {
  SandIamPortalConnection,
  SandIamPortalFactor,
  SandIamPortalProfile,
  SandIamPortalSecurity,
  SandIamPortalSession,
} from "./meContracts";
import type { SandIamPortalMfaChallenge } from "./experienceContracts";

interface PortalState {
  accessToken: string;
  requestId: string;
  organizationCode: string;
  applicationCode: string;
  experience: SandIamPublicExperience | null;
  profile: SandIamPortalProfile | null;
  security: SandIamPortalSecurity | null;
  sessions: SandIamPortalSession[];
  factors: SandIamPortalFactor[];
  connections: SandIamPortalConnection[];
  cas: SandIamCasInteraction | null;
  oauth: SandIamOAuthInteraction | null;
  oauthBound: SandIamOAuthBoundInteraction | null;
  recoveryCodes: readonly string[];
  pendingMfa: SandIamPortalMfaChallenge | null;
  errorTitle: string;
  errorDetail: string;
  usingDefaultExperience: boolean;
}

const params = new URLSearchParams(window.location.search);

/**
 * 邀请 token 只留内存。从 URL 读出后立刻去掉 query，避免进入历史和后续日志。
 */
function takeInvitationToken(): string {
  const token = params.get("token") ?? "";
  if (token === "") return "";
  params.delete("token");
  const query = params.toString();
  window.history.replaceState(
    {},
    "",
    `${window.location.pathname}${query === "" ? "" : `?${query}`}${window.location.hash}`,
  );
  return token;
}

/**
 * CAS request 只留内存。从 URL 读出后立刻去掉 query，避免进入历史和后续日志。
 */
function takeSensitiveQuery(name: string): string {
  const request = params.get(name) ?? "";
  if (request === "") return "";
  params.delete(name);
  const query = params.toString();
  window.history.replaceState(
    {},
    "",
    `${window.location.pathname}${query === "" ? "" : `?${query}`}${window.location.hash}`,
  );
  return request;
}

const federationCallback = takeFederationCallback();
let invitationToken = takeInvitationToken();
let invitationAcceptedName = "";
let casRequest = takeSensitiveQuery("cas_request") || takeSensitiveQuery("request");
let oauthRequest = takeSensitiveQuery("oauth_request");

const state: PortalState = {
  accessToken: "",
  requestId: "",
  organizationCode: params.get("organization_code") ?? "",
  applicationCode: params.get("application_code") ?? "",
  experience: null,
  profile: null,
  security: null,
  sessions: [],
  factors: [],
  connections: [],
  cas: null,
  oauth: null,
  oauthBound: null,
  recoveryCodes: [],
  pendingMfa: null,
  errorTitle: "",
  errorDetail: "",
  usingDefaultExperience: false,
};

interface FederationReturnContext {
  readonly providerCode: string;
  readonly applicationCode: string;
  readonly returnUri: string;
  readonly verifier: string;
  readonly oauthRequest: string;
  readonly casRequest: string;
}

const federationStoragePrefix = "sand-iam.portal.federation.";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isInteractionExperienceUnavailable(error: unknown): boolean {
  const http = describePortalError(error).http;
  return http === 404 || http === 503;
}

function useDefaultExperience(): void {
  state.experience = defaultPasswordExperience(state.organizationCode, state.applicationCode);
  state.usingDefaultExperience = true;
}

function clearDefaultExperience(): void {
  state.usingDefaultExperience = false;
}

function currentPortalReturnUri(): string {
  return `${window.location.origin}${window.location.pathname}`;
}

function randomBase64Url(bytes: number): string {
  if (typeof crypto === "undefined" || typeof crypto.getRandomValues !== "function") {
    throw new Error("当前浏览器无法安全发起外部登录。");
  }
  const value = crypto.getRandomValues(new Uint8Array(bytes));
  let binary = "";
  for (const item of value) binary += String.fromCharCode(item);
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/u, "");
}

async function handoffChallenge(verifier: string): Promise<string> {
  if (typeof crypto === "undefined" || crypto.subtle === undefined) {
    throw new Error("当前浏览器无法安全发起外部登录。");
  }
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(verifier));
  const bytes = new Uint8Array(digest);
  let binary = "";
  for (const item of bytes) binary += String.fromCharCode(item);
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/u, "");
}

function saveFederationContext(stateKey: string, context: FederationReturnContext): void {
  try {
    sessionStorage.setItem(`${federationStoragePrefix}${stateKey}`, JSON.stringify(context));
  } catch {
    throw new Error("浏览器拒绝保存本次外部登录确认信息，请允许此站点使用临时会话存储后重试。");
  }
}

function takeFederationContext(stateKey: string): FederationReturnContext | null {
  try {
    const stored = sessionStorage.getItem(`${federationStoragePrefix}${stateKey}`);
    sessionStorage.removeItem(`${federationStoragePrefix}${stateKey}`);
    if (stored === null) return null;
    const parsed: unknown = JSON.parse(stored);
    if (
      !isRecord(parsed) ||
      typeof parsed.providerCode !== "string" ||
      typeof parsed.applicationCode !== "string" ||
      typeof parsed.returnUri !== "string" ||
      typeof parsed.verifier !== "string" ||
      typeof parsed.oauthRequest !== "string" ||
      typeof parsed.casRequest !== "string"
    ) {
      return null;
    }
    return {
      providerCode: parsed.providerCode,
      applicationCode: parsed.applicationCode,
      returnUri: parsed.returnUri,
      verifier: parsed.verifier,
      oauthRequest: parsed.oauthRequest,
      casRequest: parsed.casRequest,
    };
  } catch {
    return null;
  }
}

function takeFederationCallback(): { readonly code: string; readonly state: string } | null {
  const code = params.get("code") ?? "";
  const stateKey = params.get("state") ?? "";
  if (code === "" && stateKey === "") return null;
  params.delete("code");
  params.delete("state");
  const query = params.toString();
  window.history.replaceState(
    {},
    "",
    `${window.location.pathname}${query === "" ? "" : `?${query}`}${window.location.hash}`,
  );
  if (!/^fh_[a-f0-9]{64}$/.test(code) || !/^fhr_[A-Za-z0-9_-]{43}$/.test(stateKey)) return null;
  return { code, state: stateKey };
}

function root(): HTMLElement {
  const element = document.getElementById("app");
  if (element === null) throw new Error("缺少 #app");
  return element;
}

function inputValue(id: string): string {
  const element = document.getElementById(id);
  return element instanceof HTMLInputElement ? element.value : "";
}

function setError(error: unknown): void {
  const described = describePortalError(error);
  state.errorTitle = described.title;
  state.errorDetail = described.detail;
}

function clearError(): void {
  state.errorTitle = "";
  state.errorDetail = "";
}

function rememberRequest(requestId: string): void {
  state.requestId = requestId;
}

/**
 * 先读后端 interaction，再允许确认。管理端登录不能代替应用用户 Bearer。
 */
async function loadCas(): Promise<void> {
  if (!casRequestLooksValid(casRequest)) {
    state.errorTitle = "CAS 请求未被接受";
    state.errorDetail = "缺少有效确认请求。请从应用重新发起，不要手填 request。";
    state.cas = null;
    render();
    return;
  }
  clearError();
  try {
    const result = await loadCasInteraction(casRequest);
    rememberRequest(result.requestId);
    state.cas = result.data;
    state.organizationCode = result.data.organizationCode;
    state.applicationCode = result.data.applicationCode;
    try {
      const experience = await loadPublicExperience(state.organizationCode, state.applicationCode);
      rememberRequest(experience.requestId);
      state.experience = experience.data;
      clearDefaultExperience();
    } catch (experienceError: unknown) {
      if (isInteractionExperienceUnavailable(experienceError)) {
        useDefaultExperience();
      } else {
        setError(experienceError);
        state.experience = null;
        clearDefaultExperience();
      }
    }
  } catch (error: unknown) {
    setError(error);
    state.cas = null;
  }
  render();
}

/**
 * 确认成功后只跳转后端返回的 redirect_uri，页面不拼接 Ticket。
 */
async function submitCasConfirm(): Promise<void> {
  if (state.accessToken === "") {
    state.errorTitle = "还没有应用用户会话";
    state.errorDetail = "请先用当前接入应用的账号登录。管理端登录不能代替确认。";
    render();
    return;
  }
  if (!casRequestLooksValid(casRequest)) {
    state.errorTitle = "CAS 请求未被接受";
    state.errorDetail = "确认请求已失效，请从应用重新发起。";
    render();
    return;
  }
  clearError();
  try {
    const result = await confirmCasInteraction(state.accessToken, casRequest);
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function submitCasReject(): Promise<void> {
  if (state.accessToken === "" || !casRequestLooksValid(casRequest)) {
    state.errorTitle = "无法拒绝此请求";
    state.errorDetail = "请先登录，并从应用重新发起 CAS 登录。";
    render();
    return;
  }
  clearError();
  try {
    const result = await rejectCasInteraction(state.accessToken, casRequest);
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function loadOAuth(): Promise<void> {
  if (!oauthRequestLooksValid(oauthRequest)) {
    state.oauth = null;
    return;
  }
  clearError();
  try {
    const result = await loadOAuthInteraction(oauthRequest);
    rememberRequest(result.requestId);
    state.oauth = result.data;
    state.organizationCode = result.data.organizationCode;
    state.applicationCode = result.data.applicationCode;
    try {
      const experience = await loadPublicExperience(state.organizationCode, state.applicationCode);
      rememberRequest(experience.requestId);
      state.experience = experience.data;
      clearDefaultExperience();
    } catch (experienceError: unknown) {
      if (isInteractionExperienceUnavailable(experienceError)) {
        useDefaultExperience();
      } else {
        setError(experienceError);
        state.experience = null;
        clearDefaultExperience();
      }
    }
  } catch (error: unknown) {
    setError(error);
    state.oauth = null;
  }
  render();
}

async function bindOAuthAfterLogin(): Promise<void> {
  if (state.accessToken === "" || !oauthRequestLooksValid(oauthRequest)) return;
  try {
    const result = await bindOAuthInteraction(state.accessToken, oauthRequest);
    rememberRequest(result.requestId);
    state.oauthBound = result.data;
  } catch (error: unknown) {
    setError(error);
    state.oauthBound = null;
  }
}

async function submitOAuthDecision(decision: "approve" | "deny"): Promise<void> {
  if (state.accessToken === "" || state.oauthBound === null || !oauthRequestLooksValid(oauthRequest)) {
    state.errorTitle = "还不能完成授权";
    state.errorDetail = "请先使用该应用的账号登录。";
    render();
    return;
  }
  clearError();
  try {
    const result = await decideOAuthInteraction(
      state.accessToken,
      oauthRequest,
      state.oauthBound.csrfToken,
      decision,
    );
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function loadExperience(): Promise<void> {
  state.organizationCode = inputValue("organization-code") || state.organizationCode;
  state.applicationCode = inputValue("application-code") || state.applicationCode;
  clearError();
  try {
    const result = await loadPublicExperience(
      state.organizationCode,
      state.applicationCode,
    );
    rememberRequest(result.requestId);
    state.experience = result.data;
    clearDefaultExperience();
  } catch (error: unknown) {
    setError(error);
    state.experience = null;
    clearDefaultExperience();
  }
  render();
}

async function submitLogin(): Promise<void> {
  if (state.experience === null || !experienceAllowsPassword(state.experience)) {
    state.errorTitle = "该登录方式已关闭";
    state.errorDetail = "当前外观未启用密码登录，页面不会提交。";
    render();
    return;
  }
  clearError();
  try {
    const result = await portalLogin(
      state.organizationCode,
      state.applicationCode,
      inputValue("login-identifier"),
      inputValue("login-password"),
      inputValue("login-captcha"),
    );
    rememberRequest(result.requestId);
    if (result.data.verificationRequired) {
      state.errorTitle = "还需要完成验证";
      state.errorDetail = "账号已识别，但还不能签发会话。请先完成邮箱或手机验证。";
      render();
      return;
    }
    if (result.data.mfaRequired) {
      state.pendingMfa = result.data;
      state.errorTitle = "需要进行安全验证";
      state.errorDetail = "请选择已经设置的验证方式后继续。";
      render();
      return;
    }
    state.accessToken = result.data.accessToken;
    await loadAll();
    await bindOAuthAfterLogin();
    render();
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function completePrimaryLogin(
  outcome: Awaited<ReturnType<typeof finishPortalPasskeyLogin>>["data"],
): Promise<void> {
  if (outcome.verificationRequired) {
    state.errorTitle = "还需要完成验证";
    state.errorDetail = "账号已识别，但还不能签发会话。请先完成邮箱或手机验证。";
    return;
  }
  if (outcome.mfaRequired) {
    state.pendingMfa = outcome;
    state.errorTitle = "需要进行安全验证";
    state.errorDetail = "请选择已经设置的验证方式后继续。";
    return;
  }
  state.accessToken = outcome.accessToken;
  await loadAll();
  await bindOAuthAfterLogin();
}

async function submitPasskeyLogin(): Promise<void> {
  if (state.experience === null || !experienceAllowsPasskey(state.experience)) {
    state.errorTitle = "该登录方式已关闭";
    state.errorDetail = "当前应用没有启用通行密钥登录。";
    render();
    return;
  }
  clearError();
  try {
    const started = await startPortalPasskeyLogin(state.organizationCode, state.applicationCode);
    rememberRequest(started.requestId);
    const options = parsePasskeyAssertionOptions(started.data.publicKey);
    if (options === null) throw new Error("通行密钥登录信息不完整。");
    const assertion = await getPasskeyAssertion(options);
    const completed = await finishPortalPasskeyLogin(
      state.organizationCode,
      state.applicationCode,
      started.data.challengeToken,
      assertion,
    );
    rememberRequest(completed.requestId);
    await completePrimaryLogin(completed.data);
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function startExternalLogin(
  protocol: "oidc" | "oauth2" | "saml",
  providerCode: string,
): Promise<void> {
  if (state.experience === null) return;
  clearError();
  try {
    const returnUri = currentPortalReturnUri();
    const stateKey = `fhr_${randomBase64Url(32)}`;
    const verifier = randomBase64Url(32);
    const challenge = await handoffChallenge(verifier);
    saveFederationContext(stateKey, {
      providerCode,
      applicationCode: state.applicationCode,
      returnUri,
      verifier,
      oauthRequest,
      casRequest,
    });
    const started = await startPortalFederationLogin(
      protocol,
      providerCode,
      state.applicationCode,
      returnUri,
      stateKey,
      challenge,
    );
    rememberRequest(started.requestId);
    window.location.assign(started.data.redirectUri);
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function completeFederationCallback(): Promise<void> {
  if (federationCallback === null) return;
  const context = takeFederationContext(federationCallback.state);
  if (context === null) {
    state.errorTitle = "外部登录未完成";
    state.errorDetail = "本次登录确认信息已失效。请回到应用重新选择登录方式。";
    render();
    return;
  }
  clearError();
  try {
    const completed = await exchangePortalFederationHandoff(
      context.providerCode,
      context.applicationCode,
      federationCallback.code,
      context.returnUri,
      context.verifier,
    );
    rememberRequest(completed.requestId);
    oauthRequest = context.oauthRequest;
    casRequest = context.casRequest;
    state.applicationCode = context.applicationCode;
    if (oauthRequestLooksValid(oauthRequest)) await loadOAuth();
    if (casRequestLooksValid(casRequest)) await loadCas();
    await completePrimaryLogin(completed.data);
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function submitRegister(): Promise<void> {
  if (state.experience === null || !experienceAllowsRegister(state.experience)) {
    state.errorTitle = "注册已关闭";
    state.errorDetail = "当前外观未开放注册。邀请注册请走邀请链接。";
    render();
    return;
  }
  clearError();
  const fields: Record<string, string> = {
    username: inputValue("register-username"),
    password: inputValue("register-password"),
  };
  for (const field of state.experience.registrationFields) {
    if (field !== "username") fields[field] = inputValue(`register-${field}`);
  }
  const captcha = inputValue("register-captcha");
  if (captcha !== "") fields.captcha_token = captcha;
  try {
    const result = await portalRegister(
      state.organizationCode,
      state.applicationCode,
      fields,
    );
    rememberRequest(result.requestId);
    if (result.data.verificationRequired) {
      state.errorTitle = "还需要完成验证";
      state.errorDetail = "注册已接受，但还不能签发会话。";
      render();
      return;
    }
    if (result.data.mfaRequired) {
      state.pendingMfa = result.data;
      state.errorTitle = "需要进行安全验证";
      state.errorDetail = "请选择已经设置的验证方式后继续。";
      render();
      return;
    }
    state.accessToken = result.data.accessToken;
    await loadAll();
    await bindOAuthAfterLogin();
    render();
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function submitMfaLogin(): Promise<void> {
  if (state.pendingMfa === null) return;
  const method = inputValue("mfa-login-method") === "recovery_code" ? "recovery_code" : "totp";
  if (!state.pendingMfa.methods.includes(method)) {
    state.errorTitle = "该验证方式不可用";
    state.errorDetail = "请选择本账号已经设置的验证方式。";
    render();
    return;
  }
  clearError();
  try {
    const result = await verifyPortalMfaChallenge(
      state.organizationCode,
      state.applicationCode,
      state.pendingMfa.challengeToken,
      method,
      inputValue("mfa-login-code"),
    );
    rememberRequest(result.requestId);
    state.pendingMfa = null;
    state.accessToken = result.data.accessToken;
    await loadAll();
    await bindOAuthAfterLogin();
    render();
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function submitPasskeyMfaLogin(): Promise<void> {
  if (state.pendingMfa === null || !state.pendingMfa.methods.includes("passkey") || state.pendingMfa.passkeyOptions === null) {
    state.errorTitle = "通行密钥不可用";
    state.errorDetail = "请改用身份验证器或恢复码，或重新发起登录。";
    render();
    return;
  }
  clearError();
  try {
    const options = parsePasskeyAssertionOptions(state.pendingMfa.passkeyOptions);
    if (options === null) throw new Error("通行密钥登录信息不完整。");
    const assertion = await getPasskeyAssertion(options);
    const result = await verifyPortalPasskeyChallenge(
      state.organizationCode,
      state.applicationCode,
      state.pendingMfa.challengeToken,
      state.pendingMfa.passkeyOptions,
      assertion,
    );
    rememberRequest(result.requestId);
    state.pendingMfa = null;
    state.accessToken = result.data.accessToken;
    await loadAll();
    await bindOAuthAfterLogin();
    render();
  } catch (error: unknown) {
    setError(error);
    render();
  }
}

async function submitAcceptInvitation(): Promise<void> {
  if (!invitationTokenLooksValid(invitationToken)) {
    state.errorTitle = "邀请无效";
    state.errorDetail = "缺少有效邀请令牌。请使用邮件或短信中的链接，不要手填 token。";
    render();
    return;
  }
  clearError();
  try {
    const result = await portalAcceptInvitation(
      invitationToken,
      inputValue("invite-username"),
      inputValue("invite-display-name"),
      inputValue("invite-password"),
    );
    rememberRequest(result.requestId);
    invitationToken = "";
    invitationAcceptedName = result.data.displayName;
    state.accessToken = "";
    state.errorTitle = "已保存";
    state.errorDetail = "邀请已接受，请使用新账号登录。本页不会自动取得后台或应用会话。";
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function submitForgot(): Promise<void> {
  clearError();
  try {
    const result = await portalForgotPassword(
      state.organizationCode,
      state.applicationCode,
      inputValue("reset-identifier"),
      inputValue("reset-channel") === "phone" ? "phone" : "email",
    );
    rememberRequest(result.requestId);
    state.errorTitle = "已保存";
    state.errorDetail = "如账号存在，重置验证码已发送。";
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function submitReset(): Promise<void> {
  clearError();
  try {
    const result = await portalResetPassword(
      state.organizationCode,
      state.applicationCode,
      inputValue("reset-identifier"),
      inputValue("reset-channel") === "phone" ? "phone" : "email",
      inputValue("reset-code"),
      inputValue("reset-password"),
    );
    rememberRequest(result.requestId);
    state.errorTitle = "已保存";
    state.errorDetail = "密码已重置，请使用新密码登录。";
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

/**
 * 登录凭据只留在内存；登录成功后加载账户安全页。
 */
async function loadAll(): Promise<void> {
  if (state.accessToken === "") {
    state.errorTitle = "请先登录";
    state.errorDetail = "登录成功后会自动打开账户安全设置。";
    render();
    return;
  }
  clearError();
  try {
    const [profile, security, sessions, factors, connections] = await Promise.all([
      loadPortalProfile(state.accessToken),
      loadPortalSecurity(state.accessToken),
      loadPortalSessions(state.accessToken),
      loadPortalFactors(state.accessToken),
      loadPortalConnections(state.accessToken),
    ]);
    rememberRequest(profile.requestId);
    state.profile = profile.data;
    state.security = security.data;
    state.sessions = sessions.data;
    state.factors = factors.data;
    state.connections = connections.data;
  } catch (error: unknown) {
    setError(error);
    state.profile = null;
    state.security = null;
    state.sessions = [];
    state.factors = [];
    state.connections = [];
  }
  render();
}

async function startTotp(): Promise<void> {
  clearError();
  try {
    const result = await startPortalTotp(
      state.accessToken,
      inputValue("totp-name"),
      inputValue("totp-password"),
    );
    rememberRequest(result.requestId);
    state.errorTitle = "请完成验证器绑定";
    state.errorDetail = `请在验证器中添加密钥 ${result.data.secret}，再输入六位验证码完成确认。`;
    root().dataset.totpFactorId = String(result.data.factorId);
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function confirmTotp(): Promise<void> {
  const factorId = Number(root().dataset.totpFactorId);
  if (!Number.isInteger(factorId) || factorId <= 0) {
    state.errorTitle = "请先开始绑定";
    state.errorDetail = "请先生成验证器密钥，再输入验证码。";
    render();
    return;
  }
  clearError();
  try {
    const result = await confirmPortalTotp(state.accessToken, factorId, inputValue("totp-code"));
    rememberRequest(result.requestId);
    state.recoveryCodes = result.data;
    delete root().dataset.totpFactorId;
    state.factors = (await loadPortalFactors(state.accessToken)).data;
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function regenerateRecoveryCodes(): Promise<void> {
  clearError();
  try {
    const result = await regeneratePortalRecoveryCodes(state.accessToken, inputValue("recovery-password"));
    rememberRequest(result.requestId);
    state.recoveryCodes = result.data;
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function registerPasskey(): Promise<void> {
  clearError();
  try {
    const started = await startPortalPasskey(
      state.accessToken,
      inputValue("passkey-name"),
      inputValue("passkey-password"),
    );
    rememberRequest(started.requestId);
    const credential = await createPasskeyCredential(started.data.options);
    const result = await finishPortalPasskey(state.accessToken, {
      ...credential,
      challenge_token: started.data.challengeToken,
    });
    rememberRequest(result.requestId);
    state.factors = (await loadPortalFactors(state.accessToken)).data;
    state.errorTitle = "通行密钥已添加";
    state.errorDetail = "下次可以在支持的设备上使用它登录。";
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function saveDisplayName(): Promise<void> {
  clearError();
  try {
    const result = await updatePortalProfile(state.accessToken, inputValue("display-name"));
    rememberRequest(result.requestId);
    state.profile = result.data;
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function revokeSession(sessionId: number): Promise<void> {
  clearError();
  try {
    const result = await revokePortalSession(state.accessToken, sessionId);
    rememberRequest(result.requestId);
    const sessions = await loadPortalSessions(state.accessToken);
    state.sessions = sessions.data;
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function revokeFactor(factor: SandIamPortalFactor): Promise<void> {
  clearError();
  try {
    const result = await revokePortalFactor(
      state.accessToken,
      factor.id,
      factor.type,
      inputValue("factor-password"),
    );
    rememberRequest(result.requestId);
    const factors = await loadPortalFactors(state.accessToken);
    state.factors = factors.data;
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

async function changePassword(): Promise<void> {
  clearError();
  try {
    const result = await changePortalPassword(
      state.accessToken,
      inputValue("current-password"),
      inputValue("new-password"),
    );
    rememberRequest(result.requestId);
    state.accessToken = "";
    state.profile = null;
    state.security = null;
    state.sessions = [];
    state.factors = [];
    state.connections = [];
    state.errorTitle = "已保存";
    state.errorDetail = "密码已修改，所有设备需要重新登录。当前登录状态已失效。";
  } catch (error: unknown) {
    setError(error);
  }
  render();
}

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function render(): void {
  const brand =
    state.experience?.brandName ?? state.profile?.applicationName ?? "当前应用";
  if (state.experience !== null) {
    document.documentElement.style.setProperty(
      "--brand",
      state.experience.primaryColor,
    );
    document.documentElement.style.colorScheme = state.experience.themeMode === "dark"
      ? "dark"
      : state.experience.themeMode === "light"
        ? "light"
        : "light dark";
  }
  document.title = `${brand} · 账户安全`;
  const logo = state.experience?.logoUrl.trim() ?? "";
  const termsUrl = state.experience?.termsUrl.trim() ?? "";
  const privacyUrl = state.experience?.privacyUrl.trim() ?? "";
  root().innerHTML = `
    <main class="panel">
      <header class="portal-heading">
        ${logo === "" ? "" : `<img class="brand-logo" src="${escapeHtml(logo)}" alt="${escapeHtml(brand)}" />`}
        <h1>${escapeHtml(brand)}</h1>
      </header>
      <p class="hint">这是您的账户与登录安全设置。访问令牌只在本次页面使用，不会进入 SandAdmin 后台，也不会保存在浏览器中；外部登录仅在当前标签页临时保留一次确认信息。</p>
      ${state.usingDefaultExperience ? '<p class="hint">该应用尚未设置登录外观，正在使用基础密码登录。注册默认关闭。</p>' : ""}
      ${state.errorTitle === "" ? "" : `<section class="alert"><strong>${escapeHtml(state.errorTitle)}</strong><p>${escapeHtml(state.errorDetail)}</p></section>`}
      ${renderOAuth()}
      ${renderCas()}
      ${renderExperience()}
      ${renderInvitation()}
      ${renderLogin()}
      ${renderMfaLogin()}
      ${renderProfile()}
      ${renderSecurity()}
      ${renderSessions()}
      ${renderFactors()}
      ${renderConnections()}
      ${renderPassword()}
      ${termsUrl === "" && privacyUrl === "" ? "" : `<footer class="portal-links">${termsUrl === "" ? "" : `<a href="${escapeHtml(termsUrl)}" target="_blank" rel="noreferrer">服务协议</a>`}${termsUrl !== "" && privacyUrl !== "" ? " · " : ""}${privacyUrl === "" ? "" : `<a href="${escapeHtml(privacyUrl)}" target="_blank" rel="noreferrer">隐私政策</a>`}</footer>`}
    </main>
  `;
  document.getElementById("load-cas")?.addEventListener("click", () => {
    void loadCas();
  });
  document.getElementById("confirm-cas")?.addEventListener("click", () => {
    void submitCasConfirm();
  });
  document.getElementById("reject-cas")?.addEventListener("click", () => {
    void submitCasReject();
  });
  document.getElementById("approve-oauth")?.addEventListener("click", () => {
    void submitOAuthDecision("approve");
  });
  document.getElementById("deny-oauth")?.addEventListener("click", () => {
    void submitOAuthDecision("deny");
  });
  document.getElementById("load-experience")?.addEventListener("click", () => {
    void loadExperience();
  });
  document.getElementById("accept-invite-btn")?.addEventListener("click", () => {
    void submitAcceptInvitation();
  });
  document.getElementById("login-btn")?.addEventListener("click", () => {
    void submitLogin();
  });
  document.getElementById("passkey-login-btn")?.addEventListener("click", () => {
    void submitPasskeyLogin();
  });
  document.getElementById("mfa-login-btn")?.addEventListener("click", () => {
    void submitMfaLogin();
  });
  document.getElementById("mfa-passkey-btn")?.addEventListener("click", () => {
    void submitPasskeyMfaLogin();
  });
  document.getElementById("register-btn")?.addEventListener("click", () => {
    void submitRegister();
  });
  document.getElementById("forgot-btn")?.addEventListener("click", () => {
    void submitForgot();
  });
  document.getElementById("reset-btn")?.addEventListener("click", () => {
    void submitReset();
  });
  document.getElementById("save-name")?.addEventListener("click", () => {
    void saveDisplayName();
  });
  document.getElementById("change-password")?.addEventListener("click", () => {
    void changePassword();
  });
  document.getElementById("start-totp")?.addEventListener("click", () => {
    void startTotp();
  });
  document.getElementById("confirm-totp")?.addEventListener("click", () => {
    void confirmTotp();
  });
  document.getElementById("regenerate-recovery")?.addEventListener("click", () => {
    void regenerateRecoveryCodes();
  });
  document.getElementById("register-passkey")?.addEventListener("click", () => {
    void registerPasskey();
  });
  for (const button of Array.from(root().querySelectorAll("[data-external-login]"))) {
    button.addEventListener("click", () => {
      const protocol = button.getAttribute("data-external-protocol");
      const providerCode = button.getAttribute("data-external-provider");
      if (
        providerCode !== null &&
        (protocol === "oidc" || protocol === "oauth2" || protocol === "saml")
      ) {
        void startExternalLogin(protocol, providerCode);
      }
    });
  }
  for (const button of Array.from(root().querySelectorAll("[data-revoke-session]"))) {
    button.addEventListener("click", () => {
      const id = Number(button.getAttribute("data-revoke-session"));
      if (Number.isInteger(id) && id > 0) void revokeSession(id);
    });
  }
  for (const button of Array.from(root().querySelectorAll("[data-revoke-factor]"))) {
    button.addEventListener("click", () => {
      const id = Number(button.getAttribute("data-revoke-factor"));
      const type = button.getAttribute("data-factor-type");
      const factor = state.factors.find((item) => item.id === id);
      if (factor !== undefined && (type === "totp" || type === "passkey")) {
        void revokeFactor(factor);
      }
    });
  }
}

function renderOAuth(): string {
  if (oauthRequest === "") return "";
  if (state.oauth === null) {
    return `<section><h2>应用授权</h2><p class="empty">正在读取授权请求。请不要手动复制或修改链接。</p></section>`;
  }
  const scopes = state.oauth.scopes.length === 0 ? "基本登录信息" : state.oauth.scopes.map(escapeHtml).join("、");
  if (state.oauthBound === null) {
    return `
      <section>
        <h2>应用授权</h2>
        <p><strong>${escapeHtml(state.oauth.clientName)}</strong> 希望使用您的账户登录。</p>
        <p>需要访问：${scopes}</p>
        <p class="hint">请在下方使用此应用的账号登录。授权请求将在 ${String(state.oauth.expiresIn)} 秒后失效。</p>
      </section>
    `;
  }
  return `
    <section>
      <h2>确认授权</h2>
      <p><strong>${escapeHtml(state.oauthBound.clientName)}</strong> 将获得：${state.oauthBound.scopes.length === 0 ? "基本登录信息" : state.oauthBound.scopes.map(escapeHtml).join("、")}。</p>
      <p class="hint">${state.oauthBound.consentRequired ? "这是新的授权请求，请确认后继续。" : "您已经授权过这些范围，仍可选择继续或拒绝。"}</p>
      <button type="button" id="approve-oauth">确认并继续</button>
      <button type="button" id="deny-oauth">拒绝</button>
    </section>
  `;
}

/**
 * CAS 确认区只展示后端返回的应用名、服务名和精确地址。
 */
function renderCas(): string {
  if (casRequest === "") {
    return `<section><h2>CAS 确认</h2><p class="empty">没有确认请求。请从已登记的 CAS 服务发起登录；管理端登录不能代替应用用户确认。</p></section>`;
  }
  if (state.cas === null) {
    return `
      <section>
        <h2>CAS 确认</h2>
        <p class="hint">已从链接读取确认请求，页面不会回显 request 或 Ticket。</p>
        <button type="button" id="load-cas">读取目标服务</button>
      </section>
    `;
  }
  return `
    <section>
      <h2>CAS 确认</h2>
      <p>目标应用：${escapeHtml(state.cas.applicationName)}</p>
      <p>目标服务：${escapeHtml(state.cas.serviceName)}</p>
      <p>精确地址：${escapeHtml(state.cas.serviceUrl)}</p>
      <p class="hint">剩余 ${String(state.cas.expiresIn)} 秒。请先用该应用账号登录，再确认继续。</p>
      <button type="button" id="confirm-cas">确认并继续</button>
      <button type="button" id="reject-cas">拒绝并返回应用</button>
    </section>
  `;
}

function renderInvitation(): string {
  if (invitationAcceptedName !== "") {
    return `<section><h2>接受邀请</h2><p>账号「${escapeHtml(invitationAcceptedName)}」已激活。请在下方登录，不要期待自动进入管理后台。</p></section>`;
  }
  if (invitationToken === "") {
    return `<section><h2>接受邀请</h2><p class="empty">没有邀请令牌。请使用邮件或短信中的链接；撤销、过期和已接受的链接不能重放。</p></section>`;
  }
  return `
    <section>
      <h2>接受邀请</h2>
      <p class="hint">已从邀请链接读取令牌，页面不会回显或缓存 token。</p>
      <label>用户名<input id="invite-username" autocomplete="username" /></label>
      <label>显示名称<input id="invite-display-name" /></label>
      <label>密码<input id="invite-password" type="password" autocomplete="new-password" /></label>
      <button type="button" id="accept-invite-btn">接受邀请并去登录</button>
    </section>
  `;
}

function renderExperience(): string {
  return `
    <section>
      <h2>选择要登录的应用</h2>
      <label>客户主体代码<input id="organization-code" value="${escapeHtml(state.organizationCode)}" /></label>
      <label>接入应用代码<input id="application-code" value="${escapeHtml(state.applicationCode)}" /></label>
      <button type="button" id="load-experience">继续</button>
      ${
        state.experience === null
          ? `<p class="empty">请输入管理员提供的客户主体代码和应用代码。</p>`
          : `<p>品牌：${escapeHtml(state.experience.brandName)} · 注册：${state.experience.registrationMode === "open" ? "开放注册" : state.experience.registrationMode === "invite" ? "邀请注册" : "关闭注册"} · 登录方式：${escapeHtml(state.experience.loginMethods.join("、") || "无")}</p>`
      }
    </section>
  `;
}

function renderLogin(): string {
  if (state.experience === null) return "";
  const passwordEnabled = experienceAllowsPassword(state.experience);
  const passkeyEnabled = experienceAllowsPasskey(state.experience);
  const externalMethods = externalLoginMethods(state.experience);
  const registerEnabled = experienceAllowsRegister(state.experience);
  const extraFields = state.experience.registrationFields
    .filter((field) => field !== "username")
    .map(
      (field) =>
        `<label>${registrationFieldLabel(field)}<input id="register-${field}" /></label>`,
    )
    .join("");
  return `
    <section>
      <h2>登录</h2>
      ${
        passwordEnabled
          ? `<label>用户名/邮箱/手机<input id="login-identifier" /></label>
             <label>密码<input id="login-password" type="password" autocomplete="current-password" /></label>
             <label>人机验证令牌（若策略要求）<input id="login-captcha" autocomplete="off" /></label>
             <button type="button" id="login-btn">登录</button>`
          : `<p class="empty">密码登录已关闭，本页不展示登录表单。</p>`
      }
      ${passkeyEnabled ? '<button type="button" id="passkey-login-btn">使用通行密钥登录</button>' : ""}
      ${externalMethods.map((method, index) => `<button type="button" data-external-login data-external-protocol="${method.protocol}" data-external-provider="${escapeHtml(method.providerCode)}">使用外部身份源 ${String(index + 1)} 登录</button>`).join("")}
    </section>
    <section>
      <h2>注册</h2>
      ${
        registerEnabled
          ? `<label>用户名<input id="register-username" /></label>
             <label>密码<input id="register-password" type="password" autocomplete="new-password" /></label>
             ${extraFields}
             <label>人机验证令牌（若策略要求）<input id="register-captcha" autocomplete="off" /></label>
             <button type="button" id="register-btn">注册</button>`
          : `<p class="empty">${state.experience.registrationMode === "invite" ? "当前仅邀请注册，请使用邀请链接。" : "注册已关闭。"}</p>`
      }
    </section>
    ${
      passwordEnabled
        ? `<section>
             <h2>找回密码</h2>
             <label>账号标识<input id="reset-identifier" /></label>
             <label>通道<input id="reset-channel" value="email" /></label>
             <button type="button" id="forgot-btn">发送重置验证码</button>
             <label>验证码<input id="reset-code" autocomplete="off" /></label>
             <label>新密码<input id="reset-password" type="password" autocomplete="new-password" /></label>
             <button type="button" id="reset-btn">重置密码</button>
           </section>`
        : ""
    }
  `;
}

function renderMfaLogin(): string {
  if (state.pendingMfa === null) return "";
  const supportsTotp = state.pendingMfa.methods.includes("totp");
  const supportsRecovery = state.pendingMfa.methods.includes("recovery_code");
  const supportsPasskey = state.pendingMfa.methods.includes("passkey") && state.pendingMfa.passkeyOptions !== null;
  return `
    <section>
      <h2>安全验证</h2>
      <p class="hint">请输入验证器中的六码，或使用一组尚未使用的恢复码。此次请求只可使用一次。</p>
      <label>验证方式
        <select id="mfa-login-method">
          ${supportsTotp ? '<option value="totp">身份验证器</option>' : ""}
          ${supportsRecovery ? '<option value="recovery_code">恢复码</option>' : ""}
        </select>
      </label>
      <label>验证码或恢复码<input id="mfa-login-code" autocomplete="one-time-code" /></label>
      <button type="button" id="mfa-login-btn">验证并登录</button>
      ${supportsPasskey ? '<button type="button" id="mfa-passkey-btn">使用通行密钥登录</button>' : ""}
    </section>
  `;
}

function renderProfile(): string {
  if (state.profile === null) {
    return `<section><h2>个人资料</h2><p class="empty">尚未加载。过期 token 与没有数据会分开提示。</p></section>`;
  }
  return `
    <section>
      <h2>个人资料</h2>
      <p>所属产品：${escapeHtml(state.profile.applicationName)}</p>
      <p>所属客户主体：${escapeHtml(state.profile.organizationName)}</p>
      <label>显示名称<input id="display-name" value="${escapeHtml(state.profile.displayName)}" /></label>
      <button type="button" id="save-name">保存显示名称</button>
    </section>
  `;
}

function renderSecurity(): string {
  if (state.security === null) {
    return `<section><h2>安全概况</h2><p class="empty">尚未加载。</p></section>`;
  }
  return `
    <section>
      <h2>安全概况</h2>
      <p>本地密码：${state.security.passwordEnabled ? "已启用" : "未配置"}</p>
      <p>有效会话：${String(state.security.activeSessions)}</p>
      <p>验证器：${String(state.security.totpFactors)}</p>
      <p>通行密钥：${String(state.security.passkeys)}</p>
      <p>外部账号：${String(state.security.connectedAccounts)}</p>
    </section>
  `;
}

function renderSessions(): string {
  if (state.sessions.length === 0) {
    return `<section><h2>会话</h2><p class="empty">当前没有有效会话。这与没有权限不同。</p></section>`;
  }
  const items = state.sessions
    .map(
      (session) => `
        <li>
          ${session.current ? "当前会话" : "其他设备"} · 最近使用 ${escapeHtml(session.lastUsedTime)}
          <button type="button" data-revoke-session="${String(session.id)}">撤销</button>
        </li>
      `,
    )
    .join("");
  return `<section><h2>会话</h2><ul>${items}</ul></section>`;
}

function renderFactors(): string {
  const list =
    state.factors.length === 0
      ? `<p class="empty">还没有验证器或通行密钥。添加通行密钥仍走认证器流程。</p>`
      : `<ul>${state.factors
          .map(
            (factor) => `
              <li>
                ${factor.type === "passkey" ? "通行密钥" : "验证器"} · ${escapeHtml(factor.name)} · ${factor.status === 1 ? "正常" : "已停用"}
                <button type="button" data-revoke-factor="${String(factor.id)}" data-factor-type="${factor.type}">撤销</button>
              </li>
            `,
          )
          .join("")}</ul>`;
  return `
    <section>
      <h2>登录方式</h2>
      <p class="hint">添加、移除或重新生成恢复码时，需要再次确认当前密码。</p>
      <label>当前密码<input id="factor-password" type="password" autocomplete="current-password" /></label>
      ${list}
      <h3>添加验证器</h3>
      <label>设备名称<input id="totp-name" value="身份验证器" /></label>
      <label>当前密码<input id="totp-password" type="password" autocomplete="current-password" /></label>
      <button type="button" id="start-totp">生成验证器密钥</button>
      <label>六位验证码<input id="totp-code" inputmode="numeric" autocomplete="one-time-code" /></label>
      <button type="button" id="confirm-totp">确认绑定</button>
      <h3>添加通行密钥</h3>
      <label>设备名称<input id="passkey-name" value="此设备" /></label>
      <label>当前密码<input id="passkey-password" type="password" autocomplete="current-password" /></label>
      <button type="button" id="register-passkey">添加通行密钥</button>
      <h3>恢复码</h3>
      <label>当前密码<input id="recovery-password" type="password" autocomplete="current-password" /></label>
      <button type="button" id="regenerate-recovery">生成新的恢复码</button>
      ${state.recoveryCodes.length === 0 ? "" : `<p class="alert"><strong>请立即抄写恢复码</strong><br>${state.recoveryCodes.map(escapeHtml).join("<br>")}</p>`}
    </section>
  `;
}

function renderConnections(): string {
  if (state.connections.length === 0) {
    return `<section><h2>外部连接</h2><p class="empty">当前没有可用的外部账号连接。</p></section>`;
  }
  const items = state.connections
    .map((item) => {
      const available = connectionAvailable(item.sourceState);
      return `<li>${escapeHtml(item.providerName)} · ${escapeHtml(item.accountHint)} · ${available ? "可用" : "不可用（已停用或已卸载）"}</li>`;
    })
    .join("");
  return `<section><h2>外部连接</h2><ul>${items}</ul></section>`;
}

function renderPassword(): string {
  return `
    <section>
      <h2>修改密码</h2>
      <p class="hint">成功后全部设备都需重新登录，当前令牌立即失效。</p>
      <label>当前密码<input id="current-password" type="password" autocomplete="current-password" /></label>
      <label>新密码<input id="new-password" type="password" autocomplete="new-password" /></label>
      <button type="button" id="change-password">修改密码</button>
    </section>
  `;
}

render();
if (federationCallback !== null) {
  void completeFederationCallback();
}
if (oauthRequestLooksValid(oauthRequest)) {
  void loadOAuth();
}
if (casRequestLooksValid(casRequest)) {
  void loadCas();
}
if (federationCallback === null && oauthRequest === "" && casRequest === "" && state.organizationCode !== "" && state.applicationCode !== "") {
  void loadExperience();
}
