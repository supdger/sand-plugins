// behavior-test-gate: static-rule -- release-source shape and forbidden-token policy
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";
import { build } from "esbuild";

const root = resolve(fileURLToPath(new URL("..", import.meta.url)));
const read = (path) => readFile(resolve(root, path), "utf8");
const [app, runtime, oauth, cas, experience, index] = await Promise.all([
  read("src/app.ts"),
  read("src/runtime.ts"),
  read("src/oauthContracts.ts"),
  read("src/casContracts.ts"),
  read("src/experienceContracts.ts"),
  read("index.html"),
]);

for (const required of [
  "submitLogin",
  "startTotp",
  "registerPasskey",
  "submitOAuthDecision",
  "submitCasReject",
  "void loadOAuth();",
  "state.organizationCode = result.data.organizationCode",
  "registrationFieldLabel(field)",
  "defaultPasswordExperience",
  "isInteractionExperienceUnavailable",
  "http === 404",
  "http === 503",
  "submitPasskeyLogin",
  "startExternalLogin",
  "completeFederationCallback",
  "usingDefaultExperience",
  "brand-logo",
  "服务协议",
  "隐私政策",
  "登录凭据只留在内存",
]) {
  assert.ok(app.includes(required), `app is missing ${required}`);
}
for (const forbidden of ["id=\"access-token\"", "localStorage", "check_admin"]) {
  assert.ok(!app.includes(forbidden), `app must not contain ${forbidden}`);
}
for (const required of [
  "SAND_IAM_PORTAL_OAUTH_BIND",
  "SAND_IAM_PORTAL_OAUTH_CONFIRM",
  "SAND_IAM_PORTAL_CAS_REJECT",
  "credentials: \"omit\"",
]) {
  assert.ok(runtime.includes(required), `runtime is missing ${required}`);
}
assert.ok(oauth.includes("csrfToken"), "OAuth contract must retain CSRF only in memory");
assert.ok(oauth.includes("organizationCode"), "OAuth interaction must identify the application login context");
assert.ok(cas.includes("organizationCode"), "CAS interaction must identify the application login context");
assert.ok(experience.includes('field === "username"'), "registration fields must use a fixed allow-list");
assert.ok(experience.includes("registrationFieldLabel"), "registration fields must use safe fixed labels");
assert.ok(experience.includes("defaultPasswordExperience"), "OAuth/CAS must have the safe password-login fallback");
assert.ok(experience.includes("externalLoginMethods"), "mounted external login methods must remain actionable");
assert.ok(runtime.includes("startPortalPasskeyLogin"), "portal must start a primary passkey login");
assert.ok(runtime.includes("startPortalFederationLogin"), "portal must start an existing federation flow");
assert.ok(runtime.includes("exchangePortalFederationHandoff"), "portal must exchange the server-issued federation handoff");
assert.ok(index.includes('src="./src/app.ts"'), "source index must point to the TypeScript entrypoint before build");
console.log("SandIAM portal source contracts passed");

// Execute the production portal with a minimal DOM and controlled fetch responses.
const bundled = await build({
  stdin: {
    contents: `${app}\nexport { state, submitRegister, submitLogin, loadExperience, render, submitForgot, submitReset, startTotp, regenerateRecoveryCodes, registerPasskey, changePassword, submitPasskeyLogin, submitMfaLogin, submitPasskeyMfaLogin, startExternalLogin, submitCasConfirm, submitCasReject, submitOAuthDecision, submitAcceptInvitation, revokeSession, submitLogout };\nexport { loadPortalCaptchaConfiguration } from "./runtime";`,
    resolveDir: resolve(root, "src"),
    loader: "ts",
  },
  bundle: true,
  format: "cjs",
  platform: "node",
  write: false,
});
const nodes = new Map();
let dataNodes = [];
class Element {
  constructor(value = "", attributes = {}) {
    this.value = value; this.attributes = attributes; this.listeners = {}; this.isConnected = true;
  }
  addEventListener(event, callback) { this.listeners[event] = callback; }
  getAttribute(name) { return this.attributes[name] ?? null; }
}
class Input extends Element {}
class Select extends Element {}
const panel = {
  dataset: {},
  querySelectorAll: (selector) => selector === "[data-revoke-factor]"
    ? dataNodes.filter((node) => node.isConnected)
    : [],
  set innerHTML(html) {
    this.html = html;
    for (const node of nodes.values()) node.isConnected = false;
    for (const node of dataNodes) node.isConnected = false;
    nodes.clear();
    dataNodes = [];
    for (const match of html.matchAll(/<(input|button|div|p|select)[^>]*id="([^"]+)"[^>]*>/g)) {
      const value = match[0].match(/value="([^"]*)"/)?.[1] ?? "";
      nodes.set(match[2], match[1] === "select" ? new Select(value) : new Input(value));
      nodes.get(match[2]).disabled = /\sdisabled(?:\s|>)/.test(match[0]);
    }
    for (const match of html.matchAll(/<select[^>]*id="([^"]+)"[^>]*>([\s\S]*?)<\/select>/g)) {
      nodes.get(match[1]).value = match[2].match(/<option value="([^"]+)" selected/)?.[1] ?? "";
    }
    for (const match of html.matchAll(/<button[^>]*data-revoke-factor="([^"]+)"[^>]*data-factor-type="([^"]+)"[^>]*>/g)) {
      dataNodes.push(new Input("", {
        "data-revoke-factor": match[1],
        "data-factor-type": match[2],
      }));
    }
  },
};
let responseBody = { data: { verification_required: true } };
let responseStatus = 200;
let delayResponse = null;
let networkError = false;
let configurationTesting = false;
let captchaResponse = { required: false };
let sessionResponses = false;
let factorResponses = null;
const sdkOptions = [];
const calls = [];
const context = vm.createContext({
  module: { exports: {} }, exports: {}, URLSearchParams, URL, Error,
  HTMLInputElement: Input, HTMLButtonElement: Input, HTMLSelectElement: Select, setTimeout, clearTimeout,
  turnstile: {
    ready(callback) { callback(); },
    render(_container, options) { sdkOptions.push(options); return `widget-${sdkOptions.length}`; },
    remove() {},
  },
  crypto: { randomUUID: () => "test-request" },
  window: { location: { search: "", pathname: "/account/", hash: "" } },
  document: {
    getElementById: (id) => id === "app" ? panel : nodes.get(id) ?? null,
    documentElement: { style: { setProperty() {} } },
  },
  fetch: async (url, init) => {
    if (networkError) throw new Error("offline");
    calls.push({ url, ...init, body: init.body === undefined ? null : JSON.parse(init.body) });
    if (factorResponses !== null && url.endsWith("/auth/mfa/factors") && init.method === "GET") {
      const data = factorResponses.shift() ?? [];
      return { ok: true, status: 200, json: async () => ({ data }) };
    }
    if (sessionResponses && init.method === "GET" && init.headers.Authorization) {
      const data = url.endsWith("/profile") ? { display_name: "Authenticated Alice",
        organization: { name: "Org", code: "org" }, application: { name: "App", code: "app" } }
        : url.endsWith("/security") ? { password_enabled: true, active_sessions: 1,
          totp_factors: 1, passkeys: 1, connected_accounts: 0 } : [];
      return { ok: true, status: 200, json: async () => ({ data }) };
    }
    if (url.includes("/captcha/config?") && !configurationTesting) {
      const data = captchaResponse.available === true
        ? { ...captchaResponse, widget: { ...captchaResponse.widget,
          action: new URL(url, "https://portal.example").searchParams.get("action") } }
        : captchaResponse;
      return { ok: true, status: 200, json: async () => ({ data }) };
    }
    const body = responseBody;
    const status = responseStatus;
    const delay = delayResponse;
    delayResponse = null;
    if (delay !== null) await delay;
    return { ok: status === 200, status, json: async () => body };
  },
});
vm.runInContext(bundled.outputFiles[0].text, context);
const portal = context.module.exports;
Object.assign(portal.state, {
  organizationCode: "org-a", applicationCode: "app-a",
  experience: {
    registrationMode: "open", registrationFields: ["username"], loginMethods: ["password"],
    brandName: "Example", primaryColor: "#1677ff", logoUrl: "", termsUrl: "", privacyUrl: "",
  },
});
const settle = () => new Promise((done) => setImmediate(done));
portal.render();
await settle();
nodes.set("register-username", new Input("alice"));
nodes.set("register-password", new Input("not-persisted"));
await portal.submitRegister();
assert.match(panel.html, /id="verification-confirm"/, "registration must lead to verification");
assert.equal(portal.state.accessToken, "", "pending verification must not create a session");
assert.ok(!panel.html.includes("not-persisted"), "password must not survive rendering");

async function click(id) {
  assert.ok(nodes.has(id), `missing action ${id}`);
  nodes.get(id).listeners.click();
  await settle();
}
responseBody = { data: null };
await click("verification-email");
assert.deepEqual(calls.at(-1).body, {
  organization_code: "org-a", application_code: "app-a", identifier: "alice",
  channel: "email", purpose: "email_verify",
});
assert.equal(calls.at(-1).url, "/api/sand-iam/v1/auth/verification/request");
assert.equal(calls.at(-1).credentials, "omit");
assert.equal(calls.at(-1).headers.Authorization, undefined);
assert.match(panel.html, /如账号已登记所选联系方式且通道可用/);
assert.ok(!panel.html.includes("验证码已发送"), "request acceptance must not claim delivery");
await click("verification-phone");
assert.equal(calls.at(-1).body.purpose, "phone_verify");
assert.equal(calls.at(-1).body.channel, "phone");

nodes.get("verification-code").value = "123456";
responseStatus = 400;
responseBody = { message: "SAND_IAM_AUTH_VERIFICATION_INVALID" };
await click("verification-confirm");
assert.equal(calls.at(-1).url, "/api/sand-iam/v1/auth/verification/confirm");
assert.equal(calls.at(-1).body.code, "123456");
assert.equal(calls.at(-1).body.purpose, "phone_verify");
assert.match(panel.html, /SAND_IAM_AUTH_VERIFICATION_INVALID/);
assert.ok(nodes.has("verification-confirm"), "failed confirmation must remain retryable");
assert.equal(portal.state.verification.identifier, "alice");
assert.equal(portal.state.verification.busy, false);
assert.ok(!JSON.stringify(portal.state).includes("123456"), "code must not enter state");
nodes.get("verification-code").value = "654321";
responseStatus = 200;
responseBody = { data: null };
await click("verification-confirm");
assert.ok(nodes.has("login-btn"), "confirmation must return to login");
assert.equal(portal.state.verification, null);
assert.equal(portal.state.accessToken, "");
assert.match(panel.html, /若应用还要求其他验证/);

nodes.get("login-identifier").value = "alice";
nodes.get("login-password").value = "login-secret";
responseStatus = 403;
responseBody = { message: "SAND_IAM_AUTH_VERIFICATION_REQUIRED" };
await portal.submitLogin();
assert.ok(nodes.has("verification-confirm"), "login requirement must reopen verification");
assert.equal(portal.state.verification.identifier, "alice");
assert.ok(!panel.html.includes("login-secret"));
responseStatus = 200;
responseBody = { data: null };
let releaseResponse;
delayResponse = new Promise((resolveResponse) => { releaseResponse = resolveResponse; });
nodes.get("verification-email").listeners.click();
assert.equal(portal.state.verification.busy, true);
nodes.set("organization-code", new Input("org-b"));
nodes.set("application-code", new Input("app-b"));
await portal.loadExperience();
const newApplicationHtml = panel.html;
releaseResponse();
await settle();
assert.equal(portal.state.verification, null);
assert.equal(portal.state.applicationCode, "app-b");
assert.equal(panel.html, newApplicationHtml, "old verification response cannot render into another app");
assert.equal(calls.at(-2).body.application_code, "app-a", "in-flight verification must retain its original app");
console.log("SandIAM portal verification production behavior passed");

const loadCaptcha = (action = "login") =>
  portal.loadPortalCaptchaConfiguration("org & a", "app/b", action);
responseStatus = 200;
configurationTesting = true;
responseBody = { data: { required: false } };
assert.equal((await loadCaptcha()).data.required, false);
const captchaRequest = calls.at(-1);
const captchaUrl = new URL(captchaRequest.url, "https://portal.example");
assert.equal(captchaUrl.pathname, "/api/sand-iam/v1/auth/captcha/config");
assert.equal(captchaUrl.searchParams.get("organization_code"), "org & a");
assert.equal(captchaUrl.searchParams.get("application_code"), "app/b");
assert.equal(captchaUrl.searchParams.get("action"), "login");
assert.equal(captchaRequest.method, "GET");
assert.equal(captchaRequest.credentials, "omit");
assert.equal(captchaRequest.cache, "no-store");
assert.equal(captchaRequest.headers.Authorization, undefined);
responseBody = { data: { required: true, available: false } };
assert.equal((await loadCaptcha()).data.available, false);
const widget = { kind: "turnstile", site_key: "public-key", action: "register",
  application_binding: "app_binding" };
responseBody = { data: { required: true, available: true, widget } };
assert.equal((await loadCaptcha("register")).data.widget.application_binding, "app_binding");
await assert.rejects(loadCaptcha(), /格式/);
for (const data of [null, {}, { required: "false" }, { required: true },
  { required: false, available: true }, { required: true, available: true, widget: {} }]) {
  responseBody = { data };
  await assert.rejects(loadCaptcha(), /格式/);
}
responseStatus = 503;
responseBody = { message: "SAND_IAM_CAPTCHA_UNAVAILABLE" };
await assert.rejects(loadCaptcha(), (error) => error.http === 503
  && error.message === "SAND_IAM_CAPTCHA_UNAVAILABLE");
networkError = true;
await assert.rejects(loadCaptcha(), (error) => error.http === null && /连接/.test(error.message));
networkError = false;
console.log("SandIAM portal captcha configuration fetch behavior passed");

configurationTesting = false;
Object.assign(portal.state, {
  verification: null, organizationCode: "org-a", applicationCode: "app-a",
  experience: { registrationMode: "open", registrationFields: ["username"], loginMethods: ["password"],
    brandName: "Example", primaryColor: "#1677ff", logoUrl: "", termsUrl: "", privacyUrl: "" },
});
captchaResponse = { required: true, available: true,
  widget: { kind: "turnstile", site_key: "public-key", application_binding: "app_a" } };
portal.render();
await settle();
assert.ok(!panel.html.includes('id="login-captcha"'), "manual tokens must be removed");
assert.equal(nodes.get("login-btn").disabled, true);
const beforeBlocked = calls.length;
await portal.submitLogin();
assert.equal(calls.length, beforeBlocked, "unsolved challenge must block login");
const loginWidget = sdkOptions.findLast((options) => options.action === "login");
const passwordInput = nodes.get("login-password");
passwordInput.value = "keep-until-submit";
loginWidget.callback("login-token");
assert.equal(nodes.get("login-password"), passwordInput, "challenge callback must not replace form DOM");
assert.equal(nodes.get("login-btn").disabled, false);
loginWidget["expired-callback"]();
assert.equal(nodes.get("login-btn").disabled, true);
assert.equal(nodes.get("login-captcha-retry").hidden, false);
nodes.get("login-captcha-retry").onclick();
await settle();
sdkOptions.findLast((options) => options.action === "login").callback("fresh-token");
nodes.get("login-identifier").value = "alice";
responseStatus = 401;
responseBody = { message: "SAND_IAM_AUTHENTICATION_FAILED" };
let releaseLogin;
delayResponse = new Promise((resolveResponse) => { releaseLogin = resolveResponse; });
const pendingLogin = portal.submitLogin();
await portal.submitLogin();
await portal.submitRegister();
const requests = calls.filter((call) => call.url === "/api/sand-iam/v1/auth/login");
assert.equal(requests.at(-1).body.captcha_token, "fresh-token");
assert.equal(nodes.get("register-btn").disabled, true);
const requestCount = calls.length;
releaseLogin();
await pendingLogin;
await settle();
assert.equal(calls.filter((call) => call.url === "/api/sand-iam/v1/auth/login").length, requests.length);
assert.ok(calls.length >= requestCount);
assert.equal(nodes.get("login-btn").disabled, true, "failed login requires a fresh challenge");
captchaResponse = { required: true, available: false };
portal.render();
await settle();
assert.equal(nodes.get("login-btn").disabled, true);
assert.match(nodes.get("login-captcha-status").textContent, /暂不可用/);
console.log("SandIAM portal captcha login wiring behavior passed");

networkError = true;
portal.render();
await settle();
assert.equal(nodes.get("login-btn").disabled, true);
assert.match(nodes.get("login-captcha-status").textContent, /连接/);
networkError = false;
captchaResponse = { required: true, available: true,
  widget: { kind: "turnstile", site_key: "public-key", application_binding: "app_a" } };
portal.render();
await settle();
sdkOptions.findLast((options) => options.action === "register").callback("registration-token");
nodes.get("register-username").value = "alice";
nodes.get("register-password").value = "registration-secret";
responseStatus = 200;
responseBody = { data: { verification_required: true } };
await portal.submitRegister();
assert.equal(calls.filter((call) => call.url.endsWith("/register")).at(-1).body.captcha_token,
  "registration-token");
assert.equal(portal.state.verification.identifier, "alice");
portal.state.verification = null;
portal.render();
await settle();
const staleWidget = sdkOptions.findLast((options) => options.action === "login");
staleWidget.callback("before-switch-token");
nodes.get("login-identifier").value = "alice";
nodes.get("login-password").value = "login-secret";
let releaseStaleLogin;
delayResponse = new Promise((resolveResponse) => { releaseStaleLogin = resolveResponse; });
responseBody = { data: { access_token: "siam_at_stale", token_type: "Bearer",
  identity: { display_name: "Old user" } } };
const staleLogin = portal.submitLogin();
nodes.get("organization-code").value = "org-b";
nodes.get("application-code").value = "app-b";
responseBody = { data: { organization_code: "org-b", application_code: "app-b",
  registration_mode: "open", registration_fields: ["username"], login_methods: ["password"],
  brand_name: "Application B" } };
await portal.loadExperience();
await settle();
staleWidget.callback("late-app-a-token");
releaseStaleLogin();
await staleLogin;
assert.equal(portal.state.accessToken, "", "stale successful login cannot enter the new app");
assert.equal(portal.state.applicationCode, "app-b");
assert.equal(nodes.get("login-btn").disabled, true);
assert.equal(portal.state.profile, null);
sdkOptions.findLast((options) => options.action === "login").callback("app-b-token");
const beforeContextChange = calls.length;
portal.state.applicationCode = "app-c";
await portal.submitLogin();
assert.equal(calls.length, beforeContextChange, "a rendered challenge cannot authorize another application");
console.log("SandIAM portal captcha registration and application-switch behavior passed");

captchaResponse = { required: false };
portal.render();
await settle();
nodes.get("reset-identifier").value = "alice";
nodes.get("reset-channel").value = "phone";
responseStatus = 200;
responseBody = { data: null };
await portal.submitForgot();
await settle();
assert.equal(nodes.get("reset-identifier").value, "alice", "sending must retain the recovery account");
assert.equal(nodes.get("reset-channel").value, "phone", "sending must retain the selected channel");
assert.match(panel.html, /如账号存在/);
const forgotCalls = () => calls.filter((call) => call.url.endsWith("/password/forgot"));
let releaseForgot;
delayResponse = new Promise((resolveResponse) => { releaseForgot = resolveResponse; });
const sending = portal.submitForgot();
assert.equal(nodes.get("forgot-btn").disabled, true);
assert.equal(nodes.get("reset-btn").disabled, true);
const beforeRepeatedRecovery = forgotCalls().length;
await portal.submitForgot();
await portal.submitReset();
assert.equal(forgotCalls().length, beforeRepeatedRecovery);
releaseForgot();
await sending;
assert.equal(nodes.get("forgot-btn").disabled, false);
nodes.get("reset-code").value = "12345678";
nodes.get("reset-password").value = "new-secret-password";
let releaseReset;
delayResponse = new Promise((resolveResponse) => { releaseReset = resolveResponse; });
const resetting = portal.submitReset();
await portal.submitReset();
assert.equal(nodes.get("reset-btn").disabled, true);
const resetRequest = calls.filter((call) => call.url.endsWith("/password/reset")).at(-1);
assert.equal(resetRequest.body.identifier, "alice");
assert.equal(resetRequest.body.channel, "phone");
assert.equal(resetRequest.body.code, "12345678");
assert.equal(resetRequest.body.password, "new-secret-password");
assert.ok(!JSON.stringify(portal.state).includes("new-secret-password"));
releaseReset();
await resetting;
assert.equal(nodes.get("reset-code").value, "");
assert.equal(nodes.get("reset-password").value, "");
assert.equal(nodes.get("reset-identifier").value, "alice");
assert.match(panel.html, /重新登录/);
nodes.get("reset-channel").value = "unrecognized";
await portal.submitForgot();
assert.equal(forgotCalls().length, beforeRepeatedRecovery);
nodes.get("reset-channel").value = "email";
let releaseOldRecovery;
delayResponse = new Promise((resolveResponse) => { releaseOldRecovery = resolveResponse; });
responseStatus = 400;
responseBody = { message: "OLD_APPLICATION_RECOVERY_ERROR" };
const oldRecovery = portal.submitForgot();
nodes.get("organization-code").value = "org-next";
nodes.get("application-code").value = "app-next";
responseStatus = 200;
responseBody = { data: { organization_code: "org-next", application_code: "app-next",
  registration_mode: "open", registration_fields: ["username"], login_methods: ["password"],
  brand_name: "Next application" } };
await portal.loadExperience();
await settle();
const nextApplicationPage = panel.html;
releaseOldRecovery();
await oldRecovery;
assert.equal(panel.html, nextApplicationPage);
assert.equal(nodes.get("reset-identifier").value, "");
assert.equal(nodes.get("reset-channel").value, "email");
assert.equal(nodes.get("forgot-btn").disabled, false);
console.log("SandIAM portal password recovery production behavior passed");

async function switchSecurityApplication(target = portal) {
  nodes.get("organization-code").value = "security-next-org";
  nodes.get("application-code").value = "security-next-app";
  responseStatus = 200;
  responseBody = { data: { organization_code: "security-next-org", application_code: "security-next-app",
    registration_mode: "open", registration_fields: ["username"], login_methods: ["password"],
    brand_name: "Security next" } };
  await target.loadExperience();
  await settle();
}
for (const [action, payload, secret] of [
  ["startTotp", { factor_id: 71, secret: "OLD-TOTP-SECRET", otpauth_uri: "otpauth://old" }, "OLD-TOTP-SECRET"],
  ["regenerateRecoveryCodes", { recovery_codes: ["OLD-RECOVERY-CODE"] }, "OLD-RECOVERY-CODE"],
]) {
  portal.state.accessToken = "old-session";
  responseBody = { data: payload };
  let releaseSecurity;
  delayResponse = new Promise((resolveResponse) => { releaseSecurity = resolveResponse; });
  const oldSecurity = portal[action]();
  await switchSecurityApplication();
  releaseSecurity();
  await oldSecurity;
  assert.ok(!JSON.stringify(portal.state).includes(secret), `${action} must ignore old-session secrets`);
  assert.ok(!panel.html.includes(secret));
  assert.equal(panel.dataset.totpFactorId, undefined);
}
class Attestation {
  clientDataJSON = new ArrayBuffer(1);
  attestationObject = new ArrayBuffer(1);
  getTransports() { return []; }
}
class Credential {
  id = "credential";
  rawId = new ArrayBuffer(1);
  type = "public-key";
  response = new Attestation();
}
context.PublicKeyCredential = Credential;
context.AuthenticatorAttestationResponse = Attestation;
context.window.PublicKeyCredential = Credential;
context.window.isSecureContext = true;
context.atob = atob;
context.btoa = btoa;
let releaseCredential;
context.navigator = { credentials: { create: () => new Promise((resolveCredential) => {
  releaseCredential = resolveCredential;
}) } };
portal.state.accessToken = "old-passkey-session";
responseBody = { data: { challenge_token: "challenge", public_key: {
  challenge: "YQ", user: { id: "Yg", name: "alice", displayName: "Alice" },
} } };
const finishRequestsBefore = calls.filter((call) => call.url.endsWith("/passkeys/registration/finish")).length;
const pendingPasskey = portal.registerPasskey();
await settle();
assert.equal(typeof releaseCredential, "function");
await switchSecurityApplication();
portal.state.accessToken = "new-passkey-session";
releaseCredential(new Credential());
await pendingPasskey;
assert.equal(calls.filter((call) => call.url.endsWith("/passkeys/registration/finish")).length,
  finishRequestsBefore, "old passkey ceremony must not finish with a new session");
console.log("SandIAM portal security session isolation behavior passed");

portal.state.accessToken = "current-security-session";
responseBody = { data: { recovery_codes: ["CURRENT-RECOVERY-CODE"] } };
let releaseCurrentRecovery;
delayResponse = new Promise((resolveResponse) => { releaseCurrentRecovery = resolveResponse; });
const currentRecovery = portal.regenerateRecoveryCodes();
const recoveryRequestCount = calls.filter((call) => call.url.endsWith("/mfa/recovery/regenerate")).length;
await portal.regenerateRecoveryCodes();
assert.equal(calls.filter((call) => call.url.endsWith("/mfa/recovery/regenerate")).length, recoveryRequestCount);
releaseCurrentRecovery();
await currentRecovery;
assert.equal(portal.state.recoveryCodes[0], "CURRENT-RECOVERY-CODE");
responseBody = { data: null };
await portal.changePassword();
assert.equal(portal.state.accessToken, "");
assert.equal(portal.state.recoveryCodes.length, 0);
assert.ok(!panel.html.includes("CURRENT-RECOVERY-CODE"));
console.log("SandIAM portal security current-session and password-change behavior passed");

portal.state.pendingMfa = { challengeToken: "old-mfa", methods: ["totp", "recovery_code"],
  passkeyOptions: null, mfaRequired: true, verificationRequired: false, accessToken: "", displayName: null };
portal.render();
await settle();
nodes.get("mfa-login-method").value = "recovery_code";
nodes.get("mfa-login-code").value = "recovery-value";
responseBody = { data: { access_token: "OLD_MFA_TOKEN" } };
let releaseMfa;
delayResponse = new Promise((resolveResponse) => { releaseMfa = resolveResponse; });
const oldMfaLogin = portal.submitMfaLogin();
assert.equal(calls.filter((call) => call.url.endsWith("/mfa/challenge/verify")).at(-1).body.method,
  "recovery_code", "the actual select element must choose recovery-code verification");
await switchSecurityApplication();
portal.state.pendingMfa = { challengeToken: "new-mfa", methods: ["totp"],
  passkeyOptions: null, mfaRequired: true, verificationRequired: false, accessToken: "", displayName: null };
releaseMfa();
await oldMfaLogin;
assert.equal(portal.state.accessToken, "");
assert.equal(portal.state.pendingMfa.challengeToken, "new-mfa");

class Assertion {
  clientDataJSON = new ArrayBuffer(1);
  authenticatorData = new ArrayBuffer(1);
  signature = new ArrayBuffer(1);
  userHandle = null;
}
context.AuthenticatorAssertionResponse = Assertion;
let releaseAssertion;
context.navigator.credentials.get = () => new Promise((resolveAssertion) => { releaseAssertion = resolveAssertion; });
portal.state.experience.loginMethods = ["password", "passkey"];
responseBody = { data: { challenge_token: "primary-challenge",
  public_key: { challenge: "YQ", rpId: "portal.example" } } };
const primaryFinishBefore = calls.filter((call) => call.url.endsWith("/passkeys/authentication/finish")).length;
const oldPrimaryPasskey = portal.submitPasskeyLogin();
await settle();
assert.equal(typeof releaseAssertion, "function");
await switchSecurityApplication();
const assertionCredential = new Credential();
assertionCredential.response = new Assertion();
releaseAssertion(assertionCredential);
await oldPrimaryPasskey;
assert.equal(calls.filter((call) => call.url.endsWith("/passkeys/authentication/finish")).length,
  primaryFinishBefore, "application switch during system assertion must prevent finish");
console.log("SandIAM portal MFA method and stale login behavior passed");

sessionResponses = true;
portal.state.pendingMfa = { challengeToken: "current-mfa", methods: ["recovery_code"],
  passkeyOptions: null, mfaRequired: true, verificationRequired: false, accessToken: "", displayName: null };
portal.render();
await settle();
nodes.get("mfa-login-method").value = "recovery_code";
nodes.get("mfa-login-code").value = "current-recovery-code";
responseBody = { data: { access_token: "CURRENT_MFA_TOKEN" } };
await portal.submitMfaLogin();
assert.equal(portal.state.accessToken, "CURRENT_MFA_TOKEN");
assert.equal(portal.state.pendingMfa, null);
assert.equal(portal.state.profile.displayName, "Authenticated Alice");
assert.equal(portal.state.errorTitle, "");
assert.equal(calls.filter((call) => call.url.endsWith("/profile")).at(-1).headers.Authorization,
  "Bearer CURRENT_MFA_TOKEN");

portal.state.experience.loginMethods = ["password", "passkey"];
responseBody = { data: { challenge_token: "normal-primary",
  public_key: { challenge: "YQ", rpId: "portal.example" } } };
const normalPrimary = portal.submitPasskeyLogin();
await settle();
const primaryOptionsCount = calls.filter((call) => call.url.endsWith("/passkeys/authentication/options")).length;
await portal.submitPasskeyLogin();
await portal.submitLogin();
assert.equal(calls.filter((call) => call.url.endsWith("/passkeys/authentication/options")).length, primaryOptionsCount);
responseBody = { data: { access_token: "CURRENT_PASSKEY_TOKEN" } };
releaseAssertion(assertionCredential);
await normalPrimary;
assert.equal(portal.state.accessToken, "CURRENT_PASSKEY_TOKEN");
assert.equal(portal.state.profile.displayName, "Authenticated Alice");
assert.equal(portal.state.errorTitle, "");
const primaryFinish = calls.filter((call) => call.url.endsWith("/passkeys/authentication/finish")).at(-1);
assert.equal(primaryFinish.body.application_code, portal.state.applicationCode);
assert.equal(primaryFinish.body.challenge_token, "normal-primary");

const passkeyMfa = () => ({ challengeToken: "passkey-mfa", methods: ["passkey"],
  passkeyOptions: { challenge: "YQ", rpId: "portal.example" },
  mfaRequired: true, verificationRequired: false, accessToken: "", displayName: null });
portal.state.pendingMfa = passkeyMfa();
const replacedPasskeyMfa = portal.submitPasskeyMfaLogin();
await settle();
const beforeReplacedMfa = calls.filter((call) => call.url.endsWith("/mfa/challenge/verify")).length;
const newChallenge = { ...passkeyMfa(), challengeToken: "replacement" };
portal.state.pendingMfa = newChallenge;
releaseAssertion(assertionCredential);
await replacedPasskeyMfa;
assert.equal(portal.state.pendingMfa, newChallenge);
assert.equal(calls.filter((call) => call.url.endsWith("/mfa/challenge/verify")).length, beforeReplacedMfa);
const normalPasskeyMfa = portal.submitPasskeyMfaLogin();
await settle();
responseBody = { data: { access_token: "CURRENT_PASSKEY_MFA_TOKEN" } };
releaseAssertion(assertionCredential);
await normalPasskeyMfa;
assert.equal(portal.state.accessToken, "CURRENT_PASSKEY_MFA_TOKEN");
assert.equal(portal.state.pendingMfa, null);
assert.equal(portal.state.profile.displayName, "Authenticated Alice");
assert.equal(calls.filter((call) => call.url.endsWith("/mfa/challenge/verify")).at(-1).body.challenge_token, "replacement");
console.log("SandIAM portal current MFA and passkey completion behavior passed");

const temporaryContexts = new Map();
context.sessionStorage = {
  setItem(key, value) { temporaryContexts.set(key, value); },
  getItem(key) { return temporaryContexts.get(key) ?? null; },
  removeItem(key) { temporaryContexts.delete(key); },
};
context.TextEncoder = TextEncoder;
context.window.location.origin = "https://portal.example";
const redirects = [];
context.window.location.assign = (url) => redirects.push(url);
let randomCounter = 0;
context.crypto.getRandomValues = (bytes) => { bytes.fill(++randomCounter); return bytes; };
let releaseDigest;
context.crypto.subtle = { digest: () => new Promise((resolveDigest) => { releaseDigest = resolveDigest; }) };
portal.state.experience.loginMethods = ["oidc:example"];
const startsBeforeDigest = calls.filter((call) => call.url.includes("/oidc/start?")).length;
const delayedDigestStart = portal.startExternalLogin("oidc", "example");
await switchSecurityApplication();
releaseDigest(new ArrayBuffer(32));
await delayedDigestStart;
assert.equal(calls.filter((call) => call.url.includes("/oidc/start?")).length, startsBeforeDigest,
  "a stale PKCE digest must not start federation for the newly selected application");
assert.equal(temporaryContexts.size, 0);

context.crypto.subtle.digest = async () => new ArrayBuffer(32);
temporaryContexts.set("unrelated-attempt", "preserve");
responseBody = { redirect_uri: "https://identity.example/authorize" };
let releaseFederationStart;
delayResponse = new Promise((resolveResponse) => { releaseFederationStart = resolveResponse; });
const delayedFederationStart = portal.startExternalLogin("oidc", "example");
await settle();
assert.equal(temporaryContexts.size, 2);
await switchSecurityApplication();
releaseFederationStart();
await delayedFederationStart;
assert.equal(redirects.length, 0);
assert.equal(temporaryContexts.size, 1);
assert.equal(temporaryContexts.get("unrelated-attempt"), "preserve");
responseBody = { redirect_uri: "https://identity.example/authorize" };
await portal.startExternalLogin("oidc", "example");
assert.equal(redirects.at(-1), "https://identity.example/authorize");
const normalStart = calls.findLast((call) => call.url.includes("/oidc/start?"));
const normalStartQuery = new URL(normalStart.url, "https://portal.example").searchParams;
const normalStored = JSON.parse(temporaryContexts.get(`sand-iam.portal.federation.${normalStartQuery.get("state")}`));
assert.equal(normalStored.applicationCode, normalStartQuery.get("application"));
assert.equal(normalStored.organizationCode, portal.state.organizationCode);
assert.match(normalStored.verifier, /^[A-Za-z0-9_-]{43}$/);
assert.equal(normalStartQuery.get("code_challenge"), "A".repeat(43));

const callbackState = `fhr_${"A".repeat(43)}`;
const callbackKey = `sand-iam.portal.federation.${callbackState}`;
function startCallbackContext(saved = {}, extraFetch = null) {
  temporaryContexts.set(callbackKey, JSON.stringify({
    providerCode: "example", organizationCode: "callback-org", applicationCode: "callback-app",
    returnUri: "https://portal.example/account/", verifier: "V".repeat(43),
    oauthRequest: "", casRequest: "", ...saved,
  }));
  const callbackContext = vm.createContext({
    ...context, module: { exports: {} }, exports: {},
    window: { ...context.window, history: { replaceState() {} },
      location: { ...context.window.location, search: `?state=${callbackState}&code=fh_${"a".repeat(64)}` } },
    fetch: extraFetch ?? context.fetch,
  });
  vm.runInContext(bundled.outputFiles[0].text, callbackContext);
  return callbackContext.module.exports;
}
responseStatus = 200;
responseBody = { data: { access_token: "OLD_CALLBACK_TOKEN" } };
let releaseExchange;
delayResponse = new Promise((resolveResponse) => { releaseExchange = resolveResponse; });
const delayedCallback = startCallbackContext();
assert.equal(temporaryContexts.has(callbackKey), false, "handoff context must be consumed immediately");
await switchSecurityApplication(delayedCallback);
releaseExchange();
await settle();
assert.equal(delayedCallback.state.accessToken, "");
assert.equal(delayedCallback.state.applicationCode, "security-next-app");

responseBody = { data: { access_token: "NORMAL_CALLBACK_TOKEN" } };
const normalCallback = startCallbackContext();
await settle();
assert.equal(normalCallback.state.accessToken, "NORMAL_CALLBACK_TOKEN");
assert.equal(normalCallback.state.profile.displayName, "Authenticated Alice");
assert.equal(normalCallback.state.applicationCode, "callback-app");
assert.equal(temporaryContexts.has(callbackKey), false);
console.log("SandIAM portal federation start and callback isolation behavior passed");

const casInteraction = { data: { organization_code: "callback-org", application_code: "callback-app",
  application_name: "Callback app", service_name: "Service", service_url: "https://service.example/",
  expires_in: 60 } };
const oauthInteraction = { data: { organization_code: "callback-org", application_code: "callback-app",
  client_name: "Client", client_id: "client", expires_in: 60, scope: ["openid"] } };
const interactionFetch = async (url, init) => {
  if (url.includes("/cas/interaction?")) return { ok: true, status: 200, json: async () => casInteraction };
  if (url.includes("/oauth/interaction?")) return { ok: true, status: 200, json: async () => oauthInteraction };
  if (url.includes("/experience?") && url.includes("application_code=callback-app")) {
    return { ok: false, status: 404, json: async () => ({ message: "no experience" }) };
  }
  if (url.endsWith("/oauth/interaction/session")) {
    return { ok: true, status: 200, json: async () => ({ data: {
      csrf_token: `siam_oac_${"b".repeat(64)}`, client_name: "Client",
      consent_required: true, expires_in: 60, scope: ["openid"],
    } }) };
  }
  return context.fetch(url, init);
};
responseBody = { data: { access_token: "RESTORED_CALLBACK_TOKEN" } };
const restoredCallback = startCallbackContext({
  organizationCode: "", casRequest: `CRT-${"A".repeat(48)}`, oauthRequest: `siam_oar_${"a".repeat(64)}`,
}, interactionFetch);
await settle();
assert.equal(restoredCallback.state.organizationCode, "callback-org");
assert.equal(restoredCallback.state.applicationCode, "callback-app");
assert.equal(restoredCallback.state.accessToken, "RESTORED_CALLBACK_TOKEN");
assert.equal(restoredCallback.state.usingDefaultExperience, true);
assert.equal(restoredCallback.state.oauthBound.clientName, "Client");
assert.equal(restoredCallback.state.cas.serviceName, "Service");
let releaseInteraction;
const staleInteractionFetch = async (url, init) => {
  if (url.includes("/cas/interaction?")) {
    await new Promise((done) => { releaseInteraction = done; });
    return { ok: true, status: 200, json: async () => casInteraction };
  }
  return interactionFetch(url, init);
};
const staleInteractionCallback = startCallbackContext({
  organizationCode: "", casRequest: `CRT-${"A".repeat(48)}`,
}, staleInteractionFetch);
await settle();
assert.equal(typeof releaseInteraction, "function");
await switchSecurityApplication(staleInteractionCallback);
releaseInteraction();
await settle();
assert.equal(staleInteractionCallback.state.organizationCode, "security-next-org");
assert.equal(staleInteractionCallback.state.accessToken, "");
assert.equal(staleInteractionCallback.state.cas, null);
console.log("SandIAM portal federation interaction restoration behavior passed");

responseBody = { data: { access_token: "DECISION_TOKEN" } };
const decisionPortal = startCallbackContext({
  organizationCode: "", casRequest: `CRT-${"A".repeat(48)}`, oauthRequest: `siam_oar_${"a".repeat(64)}`,
}, interactionFetch);
await settle();
responseBody = { data: { redirect_uri: "https://service.example/?ticket=backend-ticket", expires_in: 60 } };
let releaseCasDecision;
delayResponse = new Promise((done) => { releaseCasDecision = done; });
const beforeDecisions = calls.filter((call) => /\/cas\/interaction\/(confirm|reject)$/.test(call.url)).length;
const oldCasDecision = decisionPortal.submitCasConfirm();
await decisionPortal.submitCasConfirm();
await decisionPortal.submitCasReject();
assert.equal(calls.filter((call) => /\/cas\/interaction\/(confirm|reject)$/.test(call.url)).length,
  beforeDecisions + 1, "CAS confirm and reject must share one in-flight decision");
const redirectsBeforeDecision = redirects.length;
await switchSecurityApplication(decisionPortal);
releaseCasDecision();
await oldCasDecision;
assert.equal(redirects.length, redirectsBeforeDecision, "old session must not redirect after CAS completes");

async function newDecisionPortal() {
  responseStatus = 200;
  responseBody = { data: { access_token: "CURRENT_DECISION_TOKEN" } };
  const target = startCallbackContext({
    organizationCode: "", casRequest: `CRT-${"A".repeat(48)}`, oauthRequest: `siam_oar_${"a".repeat(64)}`,
  }, interactionFetch);
  await settle();
  return target;
}
for (const [method, decision, button] of [
  ["submitCasConfirm", undefined, "confirm-cas"],
  ["submitCasReject", undefined, "reject-cas"],
  ["submitOAuthDecision", "approve", "approve-oauth"],
  ["submitOAuthDecision", "deny", "deny-oauth"],
]) {
  const target = await newDecisionPortal();
  responseStatus = 400;
  responseBody = { message: "RETRYABLE_DECISION_FAILURE" };
  const beforeFailure = redirects.length;
  await target[method](decision);
  assert.equal(redirects.length, beforeFailure);
  assert.match(panel.html, /RETRYABLE_DECISION_FAILURE/);
  assert.equal(nodes.get(button).disabled, false, `${method} failure must release its busy state`);
  responseStatus = 200;
  const redirectUri = `https://service.example/result?decision=${decision ?? method}&opaque=backend-value`;
  responseBody = { data: { redirect_uri: redirectUri, expires_in: 60 } };
  await target[method](decision);
  assert.equal(redirects.at(-1), redirectUri);
  assert.equal(calls.at(-1).headers.Authorization, "Bearer CURRENT_DECISION_TOKEN");
  if (decision !== undefined) assert.equal(calls.at(-1).body.decision, decision);
  const completedRequestCount = calls.length;
  await target[method](decision);
  assert.equal(calls.length, completedRequestCount, "navigation in progress must not resubmit the decision");
}

for (const failed of [false, true]) {
  const target = await newDecisionPortal();
  const originalBound = target.state.oauthBound;
  responseStatus = failed ? 400 : 200;
  responseBody = failed ? { message: "OLD_BOUND_ERROR" }
    : { data: { redirect_uri: "https://service.example/stale", expires_in: 60 } };
  let releaseBound;
  delayResponse = new Promise((done) => { releaseBound = done; });
  const beforeBoundRedirects = redirects.length;
  const pendingBound = target.submitOAuthDecision("approve");
  const pendingRequestCount = calls.length;
  await target.submitOAuthDecision("deny");
  await target.submitCasConfirm();
  assert.equal(calls.length, pendingRequestCount, "all decisions must remain mutually exclusive");
  assert.equal(nodes.get("approve-oauth").disabled, true);
  assert.equal(nodes.get("deny-oauth").disabled, true);
  target.state.oauthBound = { ...originalBound, csrfToken: `siam_oac_${"c".repeat(64)}` };
  target.state.errorTitle = "Current page";
  target.state.errorDetail = "Current bound";
  releaseBound();
  await pendingBound;
  assert.equal(redirects.length, beforeBoundRedirects);
  assert.equal(target.state.errorTitle, "Current page");
  assert.equal(target.state.oauthBound.csrfToken, `siam_oac_${"c".repeat(64)}`);
  assert.ok(!panel.html.includes("OLD_BOUND_ERROR"));
  assert.equal(nodes.get("approve-oauth").disabled, false);
}
console.log("SandIAM portal authorization decision isolation and retry behavior passed");

function invitationPortal() {
  const targetContext = vm.createContext({
    ...context, module: { exports: {} }, exports: {},
    window: { ...context.window, history: { replaceState() {} },
      location: { ...context.window.location, search: `?token=siam_inv_${"I".repeat(43)}` } },
  });
  vm.runInContext(bundled.outputFiles[0].text, targetContext);
  return targetContext.module.exports;
}
const acceptingPortal = invitationPortal();
acceptingPortal.state.accessToken = "old-invitation-session";
nodes.get("invite-username").value = "invited-user";
nodes.get("invite-display-name").value = "Invited Name";
nodes.get("invite-password").value = "invitation-secret";
responseStatus = 200;
responseBody = { data: { display_name: "Invited Name" } };
let releaseInvitation;
delayResponse = new Promise((done) => { releaseInvitation = done; });
const pendingInvitation = acceptingPortal.submitAcceptInvitation();
const invitationRequestsBeforeRepeat = calls.filter((call) => call.url.endsWith("/invitations/accept")).length;
await acceptingPortal.submitAcceptInvitation();
assert.equal(calls.filter((call) => call.url.endsWith("/invitations/accept")).length,
  invitationRequestsBeforeRepeat, "invitation acceptance must not submit twice");
acceptingPortal.state.accessToken = "new-invitation-session";
releaseInvitation();
await pendingInvitation;
assert.equal(acceptingPortal.state.accessToken, "new-invitation-session", "old invitation success cannot clear a new login");

const retryInvitationPortal = invitationPortal();
nodes.get("invite-username").value = "retry-user";
nodes.get("invite-display-name").value = "Retry Name";
nodes.get("invite-password").value = "first-password";
responseStatus = 400;
responseBody = { message: "INVITATION_PASSWORD_REJECTED" };
await retryInvitationPortal.submitAcceptInvitation();
assert.equal(nodes.get("invite-username").value, "retry-user");
assert.equal(nodes.get("invite-display-name").value, "Retry Name");
assert.equal(nodes.get("invite-password").value, "");
assert.equal(nodes.get("accept-invite-btn").disabled, false);
assert.match(panel.html, /INVITATION_PASSWORD_REJECTED/);
nodes.get("invite-password").value = "second-password";
responseStatus = 200;
responseBody = { data: { display_name: "Retry Name" } };
retryInvitationPortal.state.accessToken = "prior-account-token";
retryInvitationPortal.state.profile = { displayName: "Prior user", organizationName: "Org", applicationName: "App" };
retryInvitationPortal.state.recoveryCodes = ["prior-recovery-code"];
retryInvitationPortal.state.pendingMfa = { challengeToken: "prior-challenge", methods: ["totp"] };
let releaseAccepted;
delayResponse = new Promise((done) => { releaseAccepted = done; });
const accepted = retryInvitationPortal.submitAcceptInvitation();
assert.equal(nodes.get("accept-invite-btn").disabled, true);
const acceptedRequest = calls.filter((call) => call.url.endsWith("/invitations/accept")).at(-1);
assert.equal(acceptedRequest.body.username, "retry-user");
assert.equal(acceptedRequest.body.display_name, "Retry Name");
assert.equal(acceptedRequest.body.password, "second-password");
assert.ok(!JSON.stringify(retryInvitationPortal.state).includes("second-password"));
releaseAccepted();
await accepted;
assert.equal(retryInvitationPortal.state.accessToken, "");
assert.equal(retryInvitationPortal.state.profile, null);
assert.equal(retryInvitationPortal.state.pendingMfa, null);
assert.equal(retryInvitationPortal.state.recoveryCodes.length, 0);
assert.match(panel.html, /邀请已接受，请使用新账号登录/);
assert.ok(!nodes.has("invite-password"));
const afterAccepted = calls.filter((call) => call.url.endsWith("/invitations/accept")).length;
await retryInvitationPortal.submitAcceptInvitation();
assert.equal(calls.filter((call) => call.url.endsWith("/invitations/accept")).length, afterAccepted);
console.log("SandIAM portal invitation acceptance isolation and retry behavior passed");

const sessionPortal = invitationPortal();
sessionPortal.state.accessToken = "current-revoked-token";
sessionPortal.state.sessions = [{ id: 42, current: true, createTime: "", lastUsedTime: "",
  accessExpireTime: "", refreshExpireTime: "" }];
responseStatus = 200;
responseBody = { data: null };
const sessionsRefreshBefore = calls.filter((call) => call.url.endsWith("/auth/sessions")).length;
await sessionPortal.revokeSession(42);
assert.equal(calls.filter((call) => call.url.endsWith("/auth/sessions")).length, sessionsRefreshBefore,
  "revoking the current session must not refresh with its revoked token");
assert.equal(sessionPortal.state.accessToken, "");

sessionPortal.state.accessToken = "current-still-valid";
sessionPortal.state.sessions = [{ id: 43, current: false, createTime: "", lastUsedTime: "",
  accessExpireTime: "", refreshExpireTime: "" }];
const otherRefreshBefore = calls.filter((call) => call.url.endsWith("/auth/sessions")).length;
await sessionPortal.revokeSession(43);
assert.equal(calls.filter((call) => call.url.endsWith("/auth/sessions")).length, otherRefreshBefore + 1);
assert.equal(sessionPortal.state.accessToken, "current-still-valid");
sessionPortal.render();
assert.ok(nodes.has("logout-btn"), "every signed-in page must offer logout");
responseStatus = 400;
responseBody = { message: "LOGOUT_RETRY_REQUIRED" };
await sessionPortal.submitLogout();
assert.equal(sessionPortal.state.accessToken, "current-still-valid");
assert.equal(nodes.get("logout-btn").disabled, false);
assert.match(panel.html, /LOGOUT_RETRY_REQUIRED/);
sessionPortal.state.recoveryCodes = ["logout-recovery-secret"];
sessionPortal.state.pendingMfa = { challengeToken: "logout-mfa", methods: ["totp"] };
responseStatus = 200;
responseBody = { data: null };
let releaseLogout;
delayResponse = new Promise((done) => { releaseLogout = done; });
const logoutPending = sessionPortal.submitLogout();
assert.equal(nodes.get("logout-btn").disabled, true);
const logoutRequests = calls.filter((call) => call.url.endsWith("/auth/logout"));
await sessionPortal.submitLogout();
assert.equal(calls.filter((call) => call.url.endsWith("/auth/logout")).length, logoutRequests.length);
assert.equal(logoutRequests.at(-1).method, "POST");
assert.equal(logoutRequests.at(-1).headers.Authorization, "Bearer current-still-valid");
assert.equal(logoutRequests.at(-1).credentials, "omit");
releaseLogout();
await logoutPending;
assert.equal(sessionPortal.state.accessToken, "");
assert.equal(sessionPortal.state.profile, null);
assert.equal(sessionPortal.state.security, null);
assert.equal(sessionPortal.state.pendingMfa, null);
assert.equal(sessionPortal.state.oauthBound, null);
assert.equal(sessionPortal.state.recoveryCodes.length, 0);
assert.equal(sessionPortal.state.sessions.length, 0);
assert.equal(sessionPortal.state.factors.length, 0);
assert.equal(sessionPortal.state.connections.length, 0);
assert.ok(!nodes.has("logout-btn"));
assert.match(panel.html, /已退出登录/);
sessionPortal.state.accessToken = "old-logout-session";
sessionPortal.render();
let releaseOldLogout;
delayResponse = new Promise((done) => { releaseOldLogout = done; });
const oldLogout = sessionPortal.submitLogout();
sessionPortal.state.accessToken = "new-logout-session";
sessionPortal.state.errorTitle = "New session";
releaseOldLogout();
await oldLogout;
assert.equal(sessionPortal.state.accessToken, "new-logout-session");
assert.equal(sessionPortal.state.errorTitle, "New session");
console.log("SandIAM portal logout and current-session revoke behavior passed");

const collidingFactorPortal = invitationPortal();
collidingFactorPortal.state.accessToken = "factor-token";
collidingFactorPortal.state.factors = [
  { id: 7, type: "totp", name: "TOTP", status: 1, createTime: "2026-09-16 20:00:00", lastUsedTime: null },
  { id: 7, type: "passkey", name: "Passkey", status: 1, createTime: "2026-09-16 20:00:01", lastUsedTime: null },
];
collidingFactorPortal.render();
nodes.get("factor-password").value = "current-password";
factorResponses = [[{
  id: 7, type: "totp", name: "TOTP", status: 1,
  create_time: "2026-09-16 20:00:00", last_used_time: null,
}]];
const passkeyButton = dataNodes.find((node) => node.getAttribute("data-factor-type") === "passkey");
assert.ok(passkeyButton, "same-id passkey revoke button must render");
passkeyButton.listeners.click();
await settle();
await settle();
const passkeyRevoke = calls.filter((call) => call.url.endsWith("/auth/mfa/factors/revoke")).at(-1);
assert.equal(passkeyRevoke.body.factor_id, 7);
assert.equal(passkeyRevoke.body.type, "passkey", "same-id Passkey click must not revoke TOTP");
assert.deepEqual(
  collidingFactorPortal.state.factors.map(({ id, type }) => ({ id, type })),
  [{ id: 7, type: "totp" }],
  "Passkey revoke refresh must preserve the same-id TOTP factor",
);

collidingFactorPortal.state.factors = [
  { id: 9, type: "passkey", name: "Passkey", status: 1, createTime: "2026-09-16 20:00:02", lastUsedTime: null },
  { id: 9, type: "totp", name: "TOTP", status: 1, createTime: "2026-09-16 20:00:03", lastUsedTime: null },
];
collidingFactorPortal.render();
nodes.get("factor-password").value = "current-password";
factorResponses = [[{
  id: 9, type: "passkey", name: "Passkey", status: 1,
  create_time: "2026-09-16 20:00:02", last_used_time: null,
}]];
const totpButton = dataNodes.find((node) => node.getAttribute("data-factor-type") === "totp");
assert.ok(totpButton, "same-id TOTP revoke button must render");
totpButton.listeners.click();
await settle();
await settle();
const totpRevoke = calls.filter((call) => call.url.endsWith("/auth/mfa/factors/revoke")).at(-1);
assert.equal(totpRevoke.body.factor_id, 9);
assert.equal(totpRevoke.body.type, "totp", "same-id TOTP click must not revoke Passkey");
assert.deepEqual(
  collidingFactorPortal.state.factors.map(({ id, type }) => ({ id, type })),
  [{ id: 9, type: "passkey" }],
  "TOTP revoke refresh must preserve the same-id Passkey",
);
collidingFactorPortal.state.factors = [];
const revokeCallCount = calls.filter((call) => call.url.endsWith("/auth/mfa/factors/revoke")).length;
totpButton.listeners.click();
await settle();
assert.equal(
  calls.filter((call) => call.url.endsWith("/auth/mfa/factors/revoke")).length,
  revokeCallCount,
  "a stale factor button without an exact id/type match must not revoke",
);
collidingFactorPortal.state.factors = [{
  id: 9, type: "unknown", name: "Invalid", status: 1,
  createTime: "2026-09-16 20:00:04", lastUsedTime: null,
}];
totpButton.attributes["data-factor-type"] = "unknown";
totpButton.listeners.click();
await settle();
assert.equal(
  calls.filter((call) => call.url.endsWith("/auth/mfa/factors/revoke")).length,
  revokeCallCount,
  "an invalid factor type must not revoke",
);
factorResponses = null;
console.log("SandIAM portal same-id factor revoke behavior passed");
