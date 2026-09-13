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
    contents: `${app}\nexport { state, submitRegister, submitLogin, loadExperience };`,
    resolveDir: resolve(root, "src"),
    loader: "ts",
  },
  bundle: true,
  format: "cjs",
  platform: "node",
  write: false,
});
const nodes = new Map();
class Input {
  constructor(value = "") { this.value = value; this.listeners = {}; }
  addEventListener(event, callback) { this.listeners[event] = callback; }
}
const panel = {
  dataset: {},
  querySelectorAll: () => [],
  set innerHTML(html) {
    this.html = html;
    nodes.clear();
    for (const match of html.matchAll(/<(input|button)[^>]*id="([^"]+)"[^>]*>/g)) {
      const value = match[0].match(/value="([^"]*)"/)?.[1] ?? "";
      nodes.set(match[2], new Input(value));
    }
  },
};
let responseBody = { data: { verification_required: true } };
let responseStatus = 200;
let delayResponse = null;
const calls = [];
const context = vm.createContext({
  module: { exports: {} }, exports: {}, URLSearchParams, URL, Error,
  HTMLInputElement: Input,
  crypto: { randomUUID: () => "test-request" },
  window: { location: { search: "", pathname: "/account/", hash: "" } },
  document: {
    getElementById: (id) => id === "app" ? panel : nodes.get(id) ?? null,
    documentElement: { style: { setProperty() {} } },
  },
  fetch: async (url, init) => {
    calls.push({ url, ...init, body: init.body === undefined ? null : JSON.parse(init.body) });
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
nodes.set("register-username", new Input("alice"));
nodes.set("register-password", new Input("not-persisted"));
await portal.submitRegister();
assert.match(panel.html, /id="verification-confirm"/, "registration must lead to verification");
assert.equal(portal.state.accessToken, "", "pending verification must not create a session");
assert.ok(!panel.html.includes("not-persisted"), "password must not survive rendering");

const settle = () => new Promise((done) => setImmediate(done));
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
