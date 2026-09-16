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
import { CaptchaWidget, type CaptchaStatus } from "./captchaWidget";
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
  logoutPortalSession,
  loadPortalCaptchaConfiguration,
  portalAcceptInvitation,
  portalForgotPassword,
  portalLogin,
  portalRegister,
  portalIdentityVerification,
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
  verification: {
    readonly organizationCode: string;
    readonly applicationCode: string;
    readonly identifier: string;
    channel: "email" | "phone";
    busy: boolean;
  } | null;
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

interface RecoveryDraft {
  organization: string;
  application: string;
  identifier: string;
  channel: "email" | "phone";
  busy: boolean;
}
let recovery: RecoveryDraft | null = null;
interface SecurityOperation { token: string; submission: number }
let securityOperation: SecurityOperation | null = null;
let pendingTotp: (SecurityOperation & { factorId: number }) | null = null;

function securityCurrent(operation: SecurityOperation): boolean {
  return operation.token === state.accessToken && operation.submission === authSubmission;
}

function beginSecurityOperation(): SecurityOperation | null {
  if (state.accessToken === "" || (securityOperation !== null && securityCurrent(securityOperation))) return null;
  securityOperation = { token: state.accessToken, submission: authSubmission };
  updateDecisionButtons();
  return securityOperation;
}

function updateDecisionButtons(): void {
  const busy = securityOperation !== null && securityCurrent(securityOperation);
  for (const id of ["confirm-cas", "reject-cas", "approve-oauth", "deny-oauth", "logout-btn"]) {
    const button = document.getElementById(id);
    if (button instanceof HTMLButtonElement) button.disabled = busy;
  }
}

function finishSecurityOperation(operation: SecurityOperation): void {
  if (securityOperation === operation) securityOperation = null;
  if (securityCurrent(operation)) render();
}

function recoveryDraft(): RecoveryDraft {
  if (recovery === null || recovery.organization !== state.organizationCode
    || recovery.application !== state.applicationCode) {
    recovery = { organization: state.organizationCode, application: state.applicationCode,
      identifier: "", channel: "email", busy: false };
  }
  return recovery;
}

type CaptchaAction = "login" | "register";
interface PortalCaptcha {
  widget: CaptchaWidget | null;
  ready: boolean;
  optional: boolean;
  attempt: number;
  organization: string;
  application: string;
}
const captchas: Record<CaptchaAction, PortalCaptcha> = {
  login: { widget: null, ready: false, optional: false, attempt: 0, organization: "", application: "" },
  register: { widget: null, ready: false, optional: false, attempt: 0, organization: "", application: "" },
};
let captchaGeneration = 0;
let authBusy = false;
let authSubmission = 0;
interface LoginAttempt { organization: string; application: string; submission: number }

function loginCurrent(attempt: LoginAttempt): boolean {
  return attempt.submission === authSubmission && attempt.organization === state.organizationCode
    && attempt.application === state.applicationCode;
}

function beginAlternativeLogin(): LoginAttempt | null {
  if (authBusy) return null;
  authBusy = true;
  const attempt = { organization: state.organizationCode, application: state.applicationCode,
    submission: ++authSubmission };
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  return attempt;
}

function finishAlternativeLogin(attempt: LoginAttempt): void {
  if (attempt.submission !== authSubmission) return;
  authBusy = false;
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  if (loginCurrent(attempt)) render();
}

function updateCaptchaButton(action: CaptchaAction): void {
  const button = document.getElementById(`${action}-btn`);
  if (button instanceof HTMLButtonElement) button.disabled = authBusy || !captchas[action].ready;
  const retry = document.getElementById(`${action}-captcha-retry`);
  if (retry instanceof HTMLButtonElement) retry.disabled = authBusy;
  if (action === "login") {
    for (const id of ["passkey-login-btn", "mfa-login-btn", "mfa-passkey-btn"]) {
      const button = document.getElementById(id);
      if (button instanceof HTMLButtonElement) button.disabled = authBusy;
    }
  }
}

function clearCaptchas(): void {
  captchaGeneration += 1;
  for (const action of ["login", "register"] as const) {
    captchas[action].widget?.destroy();
    captchas[action] = { widget: null, ready: false, optional: false, attempt: 0, organization: "", application: "" };
  }
}

function renderCaptcha(action: CaptchaAction): string {
  return `<div id="${action}-captcha-widget"></div>
    <p id="${action}-captcha-status" role="status" aria-live="polite">正在读取人机验证配置…</p>
    <button type="button" id="${action}-captcha-retry" hidden>重新验证</button>`;
}

async function mountCaptcha(action: CaptchaAction): Promise<void> {
  const container = document.getElementById(`${action}-captcha-widget`);
  const status = document.getElementById(`${action}-captcha-status`);
  const retry = document.getElementById(`${action}-captcha-retry`);
  if (container === null || status === null || !(retry instanceof HTMLButtonElement)) return;
  const generation = captchaGeneration;
  const attempt = ++captchas[action].attempt;
  const organization = state.organizationCode;
  const application = state.applicationCode;
  const current = (): boolean => generation === captchaGeneration
    && attempt === captchas[action].attempt
    && organization === state.organizationCode && application === state.applicationCode;
  const show = (text: string, ready: boolean, retryable: boolean): void => {
    if (!current()) return;
    status.textContent = text;
    captchas[action].ready = ready;
    retry.hidden = !retryable;
    retry.disabled = authBusy;
    updateCaptchaButton(action);
  };
  const messages: Record<CaptchaStatus, string> = {
    loading: "正在加载人机验证…", waiting: "请完成人机验证", verified: "人机验证已完成",
    expired: "验证已过期，请重新验证。", error: "人机验证失败，请重试。",
    destroyed: "请重新完成人机验证。",
  };
  captchas[action].widget?.destroy();
  captchas[action] = { widget: null, ready: false, optional: false, attempt, organization, application };
  show("正在读取人机验证配置…", false, false);
  retry.onclick = () => { if (!authBusy) void mountCaptcha(action); };
  try {
    const result = await loadPortalCaptchaConfiguration(organization, application, action);
    if (!current()) return;
    if (!result.data.required) {
      captchas[action].optional = true;
      show("当前无需人机验证。", true, false);
    } else if (!result.data.available) {
      show("人机验证服务暂不可用，请稍后重试或联系管理员。", false, true);
    } else {
      const widget = new CaptchaWidget(container, (value) => {
        show(messages[value], value === "verified", value === "expired" || value === "error" || value === "destroyed");
      });
      captchas[action].widget = widget;
      await widget.mount(result.data.widget);
    }
  } catch (error: unknown) {
    show(error instanceof Error ? error.message : "人机验证配置加载失败，请重试。", false, true);
  }
}

function takeCaptcha(action: CaptchaAction): string | null {
  if (authBusy || !captchas[action].ready) return null;
  if (captchas[action].organization !== state.organizationCode
    || captchas[action].application !== state.applicationCode) return null;
  authBusy = true;
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  if (captchas[action].optional) return "";
  const token = captchas[action].widget?.takeToken() ?? "";
  if (token !== "") return token;
  authBusy = false;
  updateCaptchaButton("login");
  updateCaptchaButton("register");
  return null;
}

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
let invitationUsername = "";
let invitationDisplayName = "";
interface InvitationAttempt extends LoginAttempt { token: string; accessToken: string }
let acceptingInvitation: InvitationAttempt | null = null;

function invitationCurrent(attempt: InvitationAttempt): boolean {
  return loginCurrent(attempt) && attempt.token === invitationToken && attempt.accessToken === state.accessToken;
}
let casRequest = takeSensitiveQuery("cas_request") || takeSensitiveQuery("request");
let oauthRequest = takeSensitiveQuery("oauth_request");

const state: PortalState = {
  verification: null,
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
  readonly organizationCode: string;
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
      organizationCode: typeof parsed.organizationCode === "string" ? parsed.organizationCode : "",
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
async function loadCas(attempt?: LoginAttempt): Promise<void> {
  const submission = authSubmission;
  const request = casRequest;
  const current = (): boolean => submission === authSubmission && request === casRequest;
  if (!casRequestLooksValid(casRequest)) {
    state.errorTitle = "CAS 请求未被接受";
    state.errorDetail = "缺少有效确认请求。请从应用重新发起，不要手填 request。";
    state.cas = null;
    render();
    return;
  }
  clearError();
  try {
    const result = await loadCasInteraction(request);
    if (!current()) return;
    if (attempt !== undefined && (attempt.application !== result.data.applicationCode
      || (attempt.organization !== "" && attempt.organization !== result.data.organizationCode))) {
      throw new Error("应用登录请求与本次外部登录不匹配。");
    }
    rememberRequest(result.requestId);
    state.cas = result.data;
    state.organizationCode = result.data.organizationCode;
    state.applicationCode = result.data.applicationCode;
    if (attempt !== undefined) attempt.organization = result.data.organizationCode;
    try {
      const experience = await loadPublicExperience(state.organizationCode, state.applicationCode);
      if (!current()) return;
      rememberRequest(experience.requestId);
      state.experience = experience.data;
      clearDefaultExperience();
    } catch (experienceError: unknown) {
      if (!current()) return;
      if (isInteractionExperienceUnavailable(experienceError)) {
        useDefaultExperience();
      } else {
        setError(experienceError);
        state.experience = null;
        clearDefaultExperience();
      }
    }
  } catch (error: unknown) {
    if (!current()) return;
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
  const operation = beginSecurityOperation();
  if (operation === null) return;
  const request = casRequest;
  const current = (): boolean => securityCurrent(operation) && request === casRequest;
  let redirected = false;
  clearError();
  try {
    const result = await confirmCasInteraction(operation.token, request);
    if (!current()) return;
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
    redirected = true;
  } catch (error: unknown) {
    if (!current()) return;
    setError(error);
  } finally {
    if (!redirected) finishSecurityOperation(operation);
  }
}

async function submitCasReject(): Promise<void> {
  if (state.accessToken === "" || !casRequestLooksValid(casRequest)) {
    state.errorTitle = "无法拒绝此请求";
    state.errorDetail = "请先登录，并从应用重新发起 CAS 登录。";
    render();
    return;
  }
  const operation = beginSecurityOperation();
  if (operation === null) return;
  const request = casRequest;
  const current = (): boolean => securityCurrent(operation) && request === casRequest;
  let redirected = false;
  clearError();
  try {
    const result = await rejectCasInteraction(operation.token, request);
    if (!current()) return;
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
    redirected = true;
  } catch (error: unknown) {
    if (!current()) return;
    setError(error);
  } finally {
    if (!redirected) finishSecurityOperation(operation);
  }
}

async function loadOAuth(attempt?: LoginAttempt): Promise<void> {
  const submission = authSubmission;
  const request = oauthRequest;
  const current = (): boolean => submission === authSubmission && request === oauthRequest;
  if (!oauthRequestLooksValid(oauthRequest)) {
    state.oauth = null;
    return;
  }
  clearError();
  try {
    const result = await loadOAuthInteraction(request);
    if (!current()) return;
    if (attempt !== undefined && (attempt.application !== result.data.applicationCode
      || (attempt.organization !== "" && attempt.organization !== result.data.organizationCode))) {
      throw new Error("应用授权请求与本次外部登录不匹配。");
    }
    rememberRequest(result.requestId);
    state.oauth = result.data;
    state.organizationCode = result.data.organizationCode;
    state.applicationCode = result.data.applicationCode;
    if (attempt !== undefined) attempt.organization = result.data.organizationCode;
    try {
      const experience = await loadPublicExperience(state.organizationCode, state.applicationCode);
      if (!current()) return;
      rememberRequest(experience.requestId);
      state.experience = experience.data;
      clearDefaultExperience();
    } catch (experienceError: unknown) {
      if (!current()) return;
      if (isInteractionExperienceUnavailable(experienceError)) {
        useDefaultExperience();
      } else {
        setError(experienceError);
        state.experience = null;
        clearDefaultExperience();
      }
    }
  } catch (error: unknown) {
    if (!current()) return;
    setError(error);
    state.oauth = null;
  }
  render();
}

async function bindOAuthAfterLogin(): Promise<void> {
  const submission = authSubmission;
  const accessToken = state.accessToken;
  if (state.accessToken === "" || !oauthRequestLooksValid(oauthRequest)) return;
  try {
    const result = await bindOAuthInteraction(state.accessToken, oauthRequest);
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
    rememberRequest(result.requestId);
    state.oauthBound = result.data;
  } catch (error: unknown) {
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
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
  const operation = beginSecurityOperation();
  if (operation === null) return;
  const request = oauthRequest;
  const bound = state.oauthBound;
  const current = (): boolean => securityCurrent(operation) && request === oauthRequest && bound === state.oauthBound;
  let redirected = false;
  clearError();
  try {
    const result = await decideOAuthInteraction(
      operation.token,
      request,
      bound.csrfToken,
      decision,
    );
    if (!current()) return;
    rememberRequest(result.requestId);
    window.location.assign(result.data.redirectUri);
    redirected = true;
  } catch (error: unknown) {
    if (!current()) return;
    setError(error);
  } finally {
    if (!redirected) finishSecurityOperation(operation);
  }
}

async function loadExperience(): Promise<void> {
  pendingTotp = null;
  securityOperation = null;
  recovery = null;
  authSubmission += 1;
  authBusy = false;
  clearCaptchas();
  state.verification = null;
  state.organizationCode = inputValue("organization-code") || state.organizationCode;
  state.applicationCode = inputValue("application-code") || state.applicationCode;
  state.accessToken = "";
  state.profile = null;
  state.security = null;
  state.sessions = [];
  state.factors = [];
  state.connections = [];
  state.pendingMfa = null;
  state.oauthBound = null;
  state.recoveryCodes = [];
  const submission = authSubmission;
  state.experience = null;
  clearError();
  render();
  try {
    const result = await loadPublicExperience(
      state.organizationCode,
      state.applicationCode,
    );
    if (submission !== authSubmission) return;
    rememberRequest(result.requestId);
    state.experience = result.data;
    clearDefaultExperience();
  } catch (error: unknown) {
    if (submission !== authSubmission) return;
    setError(error);
    state.experience = null;
    clearDefaultExperience();
  }
  render();
}

async function submitLogin(): Promise<void> {
  if (authBusy) return;
  if (state.experience === null || !experienceAllowsPassword(state.experience)) {
    state.errorTitle = "该登录方式已关闭";
    state.errorDetail = "当前外观未启用密码登录，页面不会提交。";
    render();
    return;
  }
  const captcha = takeCaptcha("login");
  if (captcha === null) return;
  const submission = ++authSubmission;
  clearError();
  const identifier = inputValue("login-identifier");
  const organization = state.organizationCode;
  const application = state.applicationCode;
  try {
    const result = await portalLogin(
      state.organizationCode,
      state.applicationCode,
      identifier,
      inputValue("login-password"),
      captcha,
    );
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    rememberRequest(result.requestId);
    if (result.data.verificationRequired) {
      openVerification(identifier, organization, application);
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
    if (submission !== authSubmission) return;
    await bindOAuthAfterLogin();
    if (submission !== authSubmission) return;
    render();
  } catch (error: unknown) {
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    if (error instanceof Error && error.message.includes("SAND_IAM_AUTH_VERIFICATION_REQUIRED")) {
      openVerification(identifier, organization, application);
    } else {
      setError(error);
    }
    render();
  } finally {
    if (submission === authSubmission) {
      authBusy = false;
      updateCaptchaButton("login");
      updateCaptchaButton("register");
    }
  }
}

async function completePrimaryLogin(
  outcome: Awaited<ReturnType<typeof finishPortalPasskeyLogin>>["data"],
  attempt: LoginAttempt = { organization: state.organizationCode, application: state.applicationCode,
    submission: authSubmission },
): Promise<void> {
  if (!loginCurrent(attempt)) return;
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
  state.pendingMfa = null;
  state.accessToken = outcome.accessToken;
  await loadAll();
  if (!loginCurrent(attempt)) return;
  await bindOAuthAfterLogin();
  if (!loginCurrent(attempt)) return;
}

async function submitPasskeyLogin(): Promise<void> {
  if (authBusy) return;
  if (state.experience === null || !experienceAllowsPasskey(state.experience)) {
    state.errorTitle = "该登录方式已关闭";
    state.errorDetail = "当前应用没有启用通行密钥登录。";
    render();
    return;
  }
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  clearError();
  try {
    const started = await startPortalPasskeyLogin(attempt.organization, attempt.application);
    if (!loginCurrent(attempt)) return;
    rememberRequest(started.requestId);
    const options = parsePasskeyAssertionOptions(started.data.publicKey);
    if (options === null) throw new Error("通行密钥登录信息不完整。");
    const assertion = await getPasskeyAssertion(options);
    if (!loginCurrent(attempt)) return;
    const completed = await finishPortalPasskeyLogin(
      attempt.organization,
      attempt.application,
      started.data.challengeToken,
      assertion,
    );
    if (!loginCurrent(attempt)) return;
    rememberRequest(completed.requestId);
    await completePrimaryLogin(completed.data, attempt);
  } catch (error: unknown) {
    if (!loginCurrent(attempt)) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}

async function startExternalLogin(
  protocol: "oidc" | "oauth2" | "saml",
  providerCode: string,
): Promise<void> {
  if (state.experience === null || authBusy) return;
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  const originalOAuthRequest = oauthRequest;
  const originalCasRequest = casRequest;
  let storedKey: string | null = null;
  let redirected = false;
  clearError();
  try {
    const returnUri = currentPortalReturnUri();
    const stateKey = `fhr_${randomBase64Url(32)}`;
    const verifier = randomBase64Url(32);
    const challenge = await handoffChallenge(verifier);
    if (!loginCurrent(attempt)) return;
    saveFederationContext(stateKey, {
      providerCode,
      organizationCode: attempt.organization,
      applicationCode: attempt.application,
      returnUri,
      verifier,
      oauthRequest: originalOAuthRequest,
      casRequest: originalCasRequest,
    });
    storedKey = stateKey;
    const started = await startPortalFederationLogin(
      protocol,
      providerCode,
      attempt.application,
      returnUri,
      stateKey,
      challenge,
    );
    if (!loginCurrent(attempt)) return;
    rememberRequest(started.requestId);
    window.location.assign(started.data.redirectUri);
    redirected = true;
  } catch (error: unknown) {
    if (!loginCurrent(attempt)) return;
    setError(error);
  } finally {
    if (!redirected) {
      if (storedKey !== null) {
        try { sessionStorage.removeItem(`${federationStoragePrefix}${storedKey}`); }
        catch {
          if (loginCurrent(attempt)) setError(new Error("浏览器未能清除本次外部登录确认信息，请关闭当前标签页后重试。"));
        }
      }
      finishAlternativeLogin(attempt);
    }
  }
}

async function completeFederationCallback(): Promise<void> {
  if (federationCallback === null || authBusy) return;
  const context = takeFederationContext(federationCallback.state);
  if (context === null) {
    state.errorTitle = "外部登录未完成";
    state.errorDetail = "本次登录确认信息已失效。请回到应用重新选择登录方式。";
    render();
    return;
  }
  state.organizationCode = context.organizationCode;
  state.applicationCode = context.applicationCode;
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  clearError();
  try {
    const completed = await exchangePortalFederationHandoff(
      context.providerCode,
      context.applicationCode,
      federationCallback.code,
      context.returnUri,
      context.verifier,
    );
    if (!loginCurrent(attempt)) return;
    rememberRequest(completed.requestId);
    oauthRequest = context.oauthRequest;
    casRequest = context.casRequest;
    if (oauthRequestLooksValid(oauthRequest)) {
      await loadOAuth(attempt);
      if (!loginCurrent(attempt)) return;
      if (state.oauth === null) throw new Error("应用授权请求无法恢复，请从应用重新发起登录。");
    }
    if (casRequestLooksValid(casRequest)) {
      await loadCas(attempt);
      if (!loginCurrent(attempt)) return;
      if (state.cas === null) throw new Error("应用登录请求无法恢复，请从应用重新发起登录。");
    }
    await completePrimaryLogin(completed.data, attempt);
  } catch (error: unknown) {
    if (!loginCurrent(attempt)) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}

async function submitRegister(): Promise<void> {
  if (authBusy) return;
  if (state.experience === null || !experienceAllowsRegister(state.experience)) {
    state.errorTitle = "注册已关闭";
    state.errorDetail = "当前外观未开放注册。邀请注册请走邀请链接。";
    render();
    return;
  }
  const captcha = takeCaptcha("register");
  if (captcha === null) return;
  const submission = ++authSubmission;
  clearError();
  const fields: Record<string, string> = {
    username: inputValue("register-username"),
    password: inputValue("register-password"),
  };
  for (const field of state.experience.registrationFields) {
    if (field !== "username") fields[field] = inputValue(`register-${field}`);
  }
  if (captcha !== "") fields.captcha_token = captcha;
  const organization = state.organizationCode;
  const application = state.applicationCode;
  try {
    const result = await portalRegister(
      state.organizationCode,
      state.applicationCode,
      fields,
    );
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    rememberRequest(result.requestId);
    if (result.data.verificationRequired) {
      openVerification(fields.username, organization, application);
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
    if (submission !== authSubmission) return;
    await bindOAuthAfterLogin();
    if (submission !== authSubmission) return;
    render();
  } catch (error: unknown) {
    if (submission !== authSubmission || organization !== state.organizationCode || application !== state.applicationCode) return;
    setError(error);
    render();
  } finally {
    if (submission === authSubmission) {
      authBusy = false;
      updateCaptchaButton("login");
      updateCaptchaButton("register");
    }
  }
}

function openVerification(identifier: string, organization: string, application: string): void {
  if (organization !== state.organizationCode || application !== state.applicationCode) return;
  state.verification = {
    organizationCode: organization, applicationCode: application, identifier,
    channel: "email", busy: false,
  };
  state.pendingMfa = null;
  state.errorTitle = "还需要完成验证";
  state.errorDetail = "请选择账号登记的邮箱或手机完成验证，再重新登录。";
}

async function submitVerification(channel?: "email" | "phone"): Promise<void> {
  const verification = state.verification;
  if (verification === null || verification.busy) return;
  const code = channel === undefined ? inputValue("verification-code").trim() : undefined;
  if (code === "") {
    state.errorTitle = "请填写验证码";
    state.errorDetail = "输入收到的验证码后再确认。";
    render();
    return;
  }
  if (channel !== undefined) verification.channel = channel;
  verification.busy = true;
  clearError();
  render();
  try {
    const result = await portalIdentityVerification(
      verification.organizationCode, verification.applicationCode, verification.identifier,
      verification.channel, code,
    );
    if (state.verification !== verification) return;
    rememberRequest(result.requestId);
    if (code === undefined) {
      state.errorTitle = "验证请求已受理";
      state.errorDetail = "如账号已登记所选联系方式且通道可用，您将收到验证码。未收到时请稍后重试或联系应用管理员。";
    } else {
      state.verification = null;
      state.errorTitle = "本次联系方式验证已完成";
      state.errorDetail = "请重新登录；若应用还要求其他验证，请按登录提示继续。";
      render();
    }
  } catch (error: unknown) {
    if (state.verification === verification) setError(error);
  } finally {
    verification.busy = false;
    if (state.verification === verification) render();
  }
}

async function submitMfaLogin(): Promise<void> {
  if (authBusy || state.pendingMfa === null) return;
  const pending = state.pendingMfa;
  const methodInput = document.getElementById("mfa-login-method");
  const method = methodInput instanceof HTMLSelectElement ? methodInput.value : "";
  if ((method !== "totp" && method !== "recovery_code") || !pending.methods.includes(method)) {
    state.errorTitle = "该验证方式不可用";
    state.errorDetail = "请选择本账号已经设置的验证方式。";
    render();
    return;
  }
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  const current = (): boolean => loginCurrent(attempt) && state.pendingMfa === pending;
  clearError();
  try {
    const result = await verifyPortalMfaChallenge(
      attempt.organization,
      attempt.application,
      pending.challengeToken,
      method,
      inputValue("mfa-login-code"),
    );
    if (!current()) return;
    rememberRequest(result.requestId);
    state.pendingMfa = null;
    await completePrimaryLogin(result.data, attempt);
  } catch (error: unknown) {
    if (!current()) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}

async function submitPasskeyMfaLogin(): Promise<void> {
  if (authBusy) return;
  if (state.pendingMfa === null || !state.pendingMfa.methods.includes("passkey") || state.pendingMfa.passkeyOptions === null) {
    state.errorTitle = "通行密钥不可用";
    state.errorDetail = "请改用身份验证器或恢复码，或重新发起登录。";
    render();
    return;
  }
  const pending = state.pendingMfa;
  const attempt = beginAlternativeLogin();
  if (attempt === null) return;
  const current = (): boolean => loginCurrent(attempt) && state.pendingMfa === pending;
  clearError();
  try {
    const options = parsePasskeyAssertionOptions(pending.passkeyOptions);
    if (options === null) throw new Error("通行密钥登录信息不完整。");
    const assertion = await getPasskeyAssertion(options);
    if (!current()) return;
    const result = await verifyPortalPasskeyChallenge(
      attempt.organization,
      attempt.application,
      pending.challengeToken,
      pending.passkeyOptions,
      assertion,
    );
    if (!current()) return;
    rememberRequest(result.requestId);
    state.pendingMfa = null;
    await completePrimaryLogin(result.data, attempt);
  } catch (error: unknown) {
    if (!current()) return;
    setError(error);
  } finally {
    finishAlternativeLogin(attempt);
  }
}

async function submitAcceptInvitation(): Promise<void> {
  if (acceptingInvitation !== null && invitationCurrent(acceptingInvitation)) return;
  if (!invitationTokenLooksValid(invitationToken)) {
    state.errorTitle = "邀请无效";
    state.errorDetail = "缺少有效邀请令牌。请使用邮件或短信中的链接，不要手填 token。";
    render();
    return;
  }
  const attempt: InvitationAttempt = { token: invitationToken, accessToken: state.accessToken,
    organization: state.organizationCode, application: state.applicationCode, submission: authSubmission };
  acceptingInvitation = attempt;
  invitationUsername = inputValue("invite-username");
  invitationDisplayName = inputValue("invite-display-name");
  const password = inputValue("invite-password");
  clearError();
  render();
  try {
    const result = await portalAcceptInvitation(
      attempt.token,
      invitationUsername,
      invitationDisplayName,
      password,
    );
    if (!invitationCurrent(attempt)) return;
    rememberRequest(result.requestId);
    invitationToken = "";
    acceptingInvitation = null;
    invitationUsername = "";
    invitationDisplayName = "";
    invitationAcceptedName = result.data.displayName;
    state.accessToken = "";
    state.profile = null;
    state.security = null;
    state.sessions = [];
    state.factors = [];
    state.connections = [];
    state.recoveryCodes = [];
    state.pendingMfa = null;
    state.oauthBound = null;
    pendingTotp = null;
    securityOperation = null;
    recovery = null;
    authSubmission += 1;
    authBusy = false;
    state.errorTitle = "已保存";
    state.errorDetail = "邀请已接受，请使用新账号登录。本页不会自动取得后台或应用会话。";
    render();
  } catch (error: unknown) {
    if (!invitationCurrent(attempt)) return;
    setError(error);
  } finally {
    if (acceptingInvitation === attempt) {
      acceptingInvitation = null;
      if (invitationCurrent(attempt)) render();
      else {
        const button = document.getElementById("accept-invite-btn");
        if (button instanceof HTMLButtonElement) button.disabled = false;
      }
    }
  }
}

async function submitForgot(): Promise<void> {
  await submitRecovery(false);
}

async function submitReset(): Promise<void> {
  await submitRecovery(true);
}

async function submitRecovery(resetPassword: boolean): Promise<void> {
  const draft = recoveryDraft();
  if (draft.busy) return;
  const channelInput = document.getElementById("reset-channel");
  const channel = channelInput instanceof HTMLSelectElement ? channelInput.value : "";
  draft.identifier = inputValue("reset-identifier").trim();
  if (draft.identifier === "" || (channel !== "email" && channel !== "phone")) {
    state.errorTitle = "请检查找回信息";
    state.errorDetail = "请输入账号，并选择邮箱或手机接收验证码。";
    render();
    return;
  }
  draft.channel = channel;
  const code = inputValue("reset-code");
  const password = inputValue("reset-password");
  const current = (): boolean => recovery === draft && draft.organization === state.organizationCode
    && draft.application === state.applicationCode;
  draft.busy = true;
  clearError();
  render();
  try {
    const result = resetPassword
      ? await portalResetPassword(draft.organization, draft.application, draft.identifier, draft.channel, code, password)
      : await portalForgotPassword(draft.organization, draft.application, draft.identifier, draft.channel);
    if (!current()) return;
    rememberRequest(result.requestId);
    state.errorTitle = resetPassword ? "密码已重置" : "请查看验证码";
    state.errorDetail = resetPassword ? "所有设备需要重新登录，请使用新密码登录。"
      : "如账号存在，重置验证码已发送。";
  } catch (error: unknown) {
    if (!current()) return;
    setError(error);
  } finally {
    if (current()) {
      draft.busy = false;
      render();
    }
  }
}

/**
 * 登录凭据只留在内存；登录成功后加载账户安全页。
 */
async function loadAll(): Promise<void> {
  const submission = authSubmission;
  const accessToken = state.accessToken;
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
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
    rememberRequest(profile.requestId);
    state.profile = profile.data;
    state.security = security.data;
    state.sessions = sessions.data;
    state.factors = factors.data;
    state.connections = connections.data;
  } catch (error: unknown) {
    if (submission !== authSubmission || accessToken !== state.accessToken) return;
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
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await startPortalTotp(
      operation.token,
      inputValue("totp-name"),
      inputValue("totp-password"),
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.errorTitle = "请完成验证器绑定";
    state.errorDetail = `请在验证器中添加密钥 ${result.data.secret}，再输入六位验证码完成确认。`;
    pendingTotp = { ...operation, factorId: result.data.factorId };
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

async function confirmTotp(): Promise<void> {
  if (pendingTotp === null || !securityCurrent(pendingTotp)) {
    state.errorTitle = "请先开始绑定";
    state.errorDetail = "请先生成验证器密钥，再输入验证码。";
    render();
    return;
  }
  const factorId = pendingTotp.factorId;
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await confirmPortalTotp(operation.token, factorId, inputValue("totp-code"));
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.recoveryCodes = result.data;
    pendingTotp = null;
    const factors = await loadPortalFactors(operation.token);
    if (!securityCurrent(operation)) return;
    state.factors = factors.data;
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

async function regenerateRecoveryCodes(): Promise<void> {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await regeneratePortalRecoveryCodes(operation.token, inputValue("recovery-password"));
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.recoveryCodes = result.data;
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

async function registerPasskey(): Promise<void> {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const started = await startPortalPasskey(
      operation.token,
      inputValue("passkey-name"),
      inputValue("passkey-password"),
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(started.requestId);
    const credential = await createPasskeyCredential(started.data.options);
    if (!securityCurrent(operation)) return;
    const result = await finishPortalPasskey(operation.token, {
      ...credential,
      challenge_token: started.data.challengeToken,
    });
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    const factors = await loadPortalFactors(operation.token);
    if (!securityCurrent(operation)) return;
    state.factors = factors.data;
    state.errorTitle = "通行密钥已添加";
    state.errorDetail = "下次可以在支持的设备上使用它登录。";
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

async function saveDisplayName(): Promise<void> {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await updatePortalProfile(operation.token, inputValue("display-name"));
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    state.profile = result.data;
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

async function revokeSession(sessionId: number): Promise<void> {
  const currentSession = state.sessions.some((session) => session.id === sessionId && session.current);
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await revokePortalSession(operation.token, sessionId);
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    if (currentSession) {
      clearLocalSession();
      state.errorTitle = "当前会话已撤销";
      state.errorDetail = "请重新登录后继续。";
      render();
      return;
    }
    const sessions = await loadPortalSessions(operation.token);
    if (!securityCurrent(operation)) return;
    state.sessions = sessions.data;
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

async function revokeFactor(factor: SandIamPortalFactor): Promise<void> {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await revokePortalFactor(
      operation.token,
      factor.id,
      factor.type,
      inputValue("factor-password"),
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    const factors = await loadPortalFactors(operation.token);
    if (!securityCurrent(operation)) return;
    state.factors = factors.data;
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

async function changePassword(): Promise<void> {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await changePortalPassword(
      operation.token,
      inputValue("current-password"),
      inputValue("new-password"),
    );
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    clearLocalSession();
    state.errorTitle = "已保存";
    state.errorDetail = "密码已修改，所有设备需要重新登录。当前登录状态已失效。";
    render();
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

function clearLocalSession(): void {
  state.accessToken = "";
  state.profile = null;
  state.security = null;
  state.sessions = [];
  state.factors = [];
  state.connections = [];
  state.pendingMfa = null;
  state.oauthBound = null;
  state.recoveryCodes = [];
  state.verification = null;
  pendingTotp = null;
  securityOperation = null;
  recovery = null;
  authSubmission += 1;
  authBusy = false;
}

async function submitLogout(): Promise<void> {
  const operation = beginSecurityOperation();
  if (operation === null) return;
  clearError();
  try {
    const result = await logoutPortalSession(operation.token);
    if (!securityCurrent(operation)) return;
    rememberRequest(result.requestId);
    clearLocalSession();
    state.errorTitle = "已退出登录";
    state.errorDetail = "当前会话已结束。";
    render();
  } catch (error: unknown) {
    if (!securityCurrent(operation)) return;
    setError(error);
  } finally {
    finishSecurityOperation(operation);
  }
}

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function render(): void {
  clearCaptchas();
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
      ${state.accessToken === "" ? "" : '<button type="button" id="logout-btn">退出登录</button>'}
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
  updateDecisionButtons();
  document.getElementById("logout-btn")?.addEventListener("click", () => { void submitLogout(); });
  void mountCaptcha("login");
  void mountCaptcha("register");
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
  document.getElementById("verification-email")?.addEventListener("click", () => {
    void submitVerification("email");
  });
  document.getElementById("verification-phone")?.addEventListener("click", () => {
    void submitVerification("phone");
  });
  document.getElementById("verification-confirm")?.addEventListener("click", () => {
    void submitVerification();
  });
  document.getElementById("verification-back")?.addEventListener("click", () => {
    state.verification = null;
    clearError();
    render();
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
      const factor = state.factors.find((item) => item.id === id && item.type === type);
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
    return `<section><h2>接受邀请</h2><p>账号「${escapeHtml(invitationAcceptedName)}」已激活。邀请已接受，请使用新账号登录。</p></section>`;
  }
  if (invitationToken === "") {
    return `<section><h2>接受邀请</h2><p class="empty">没有邀请令牌。请使用邮件或短信中的链接；撤销、过期和已接受的链接不能重放。</p></section>`;
  }
  const disabled = acceptingInvitation !== null && invitationCurrent(acceptingInvitation) ? "disabled" : "";
  return `
    <section>
      <h2>接受邀请</h2>
      <p class="hint">已从邀请链接读取令牌，页面不会回显或缓存 token。</p>
      <label>用户名<input id="invite-username" value="${escapeHtml(invitationUsername)}" autocomplete="username" ${disabled} /></label>
      <label>显示名称<input id="invite-display-name" value="${escapeHtml(invitationDisplayName)}" ${disabled} /></label>
      <label>密码<input id="invite-password" type="password" autocomplete="new-password" ${disabled} /></label>
      <button type="button" id="accept-invite-btn" ${disabled}>接受邀请并去登录</button>
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
  if (state.verification !== null) {
    const verification = state.verification;
    const disabled = verification.busy ? "disabled" : "";
    return `<section>
      <h2>验证账号联系方式</h2>
      <p>当前账号：${escapeHtml(verification.identifier)}</p>
      <p class="hint">验证码发送到该账号已登记的联系方式。请选择需要验证的邮箱或手机。</p>
      <button type="button" id="verification-email" ${disabled}>发送邮箱验证码</button>
      <button type="button" id="verification-phone" ${disabled}>发送手机验证码</button>
      <label>${verification.channel === "email" ? "邮箱" : "手机"}验证码
        <input id="verification-code" autocomplete="one-time-code" ${disabled} />
      </label>
      <button type="button" id="verification-confirm" ${disabled}>确认验证</button>
      <button type="button" id="verification-back" ${disabled}>返回登录</button>
    </section>`;
  }
  const passwordEnabled = experienceAllowsPassword(state.experience);
  const passkeyEnabled = experienceAllowsPasskey(state.experience);
  const externalMethods = externalLoginMethods(state.experience);
  const registerEnabled = experienceAllowsRegister(state.experience);
  const recoveryForm = recoveryDraft();
  const recoveryDisabled = recoveryForm.busy ? "disabled" : "";
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
             ${renderCaptcha("login")}
             <button type="button" id="login-btn" disabled>登录</button>`
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
             ${renderCaptcha("register")}
             <button type="button" id="register-btn" disabled>注册</button>`
          : `<p class="empty">${state.experience.registrationMode === "invite" ? "当前仅邀请注册，请使用邀请链接。" : "注册已关闭。"}</p>`
      }
    </section>
    ${
      passwordEnabled
        ? `<section>
             <h2>找回密码</h2>
             <label>账号标识<input id="reset-identifier" value="${escapeHtml(recoveryForm.identifier)}" ${recoveryDisabled} /></label>
             <label>接收方式<select id="reset-channel" ${recoveryDisabled}>
               <option value="email" ${recoveryForm.channel === "email" ? "selected" : ""}>邮箱</option>
               <option value="phone" ${recoveryForm.channel === "phone" ? "selected" : ""}>手机</option>
             </select></label>
             <button type="button" id="forgot-btn" ${recoveryDisabled}>发送重置验证码</button>
             <label>验证码<input id="reset-code" autocomplete="one-time-code" ${recoveryDisabled} /></label>
             <label>新密码<input id="reset-password" type="password" autocomplete="new-password" ${recoveryDisabled} /></label>
             <button type="button" id="reset-btn" ${recoveryDisabled}>重置密码</button>
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
if (federationCallback === null && oauthRequestLooksValid(oauthRequest)) {
  void loadOAuth();
}
if (federationCallback === null && casRequestLooksValid(casRequest)) {
  void loadCas();
}
if (federationCallback === null && oauthRequest === "" && casRequest === "" && state.organizationCode !== "" && state.applicationCode !== "") {
  void loadExperience();
}
