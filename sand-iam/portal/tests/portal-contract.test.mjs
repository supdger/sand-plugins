import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

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
