/**
 * Public helper contracts: imports and executes exported parsing, validation,
 * filtering and state helpers. This test never reads production source text
 * and does not mount a Vue component or use a browser.
 */
import assert from "node:assert/strict";
import {
  applicationFields,
  adminApplicationGrantFields,
  applicationExperienceFields,
  authPolicyFields,
  clientFields,
  environmentFields,
  grantFields,
  identityFields,
  identityProviderFields,
  oauthClientFields,
  casServiceFields,
  organizationFields,
  businessActionFields,
  apiResourceFields,
  serviceFields,
  actionFields,
  credentialFields,
  namedAppFields,
  policyFields,
  resourceFields,
} from "../src/views/plugin/sand-iam/api/fields";
import {
  auditActionLabel,
  auditActorLabel,
  auditOutcomeLabel,
  auditResourceTypeLabel,
  auditTimeLabel,
  describeAuditExportRangeError,
  parseWebhookSecretResult,
  SAND_IAM_AUDIT_DEFAULT_COLUMNS,
  SAND_IAM_AUDIT_HIDDEN_DEFAULT_COLUMNS,
  summarizeWebhookUrl,
  webhookDeliveryRetryable,
} from "../src/views/plugin/sand-iam/api/delegationContracts";
import {
  isPortalAdminHeader,
  parsePortalProfile,
  portalRequestHeaders,
} from "../../portal/src/meContracts";
import {
  experienceAllowsPassword,
  parsePublicExperience,
} from "../../portal/src/experienceContracts";
import {
  invitationTokenLooksValid,
  parseInvitationAcceptResult,
} from "../../portal/src/invitationContracts";
import { createSandIamEditorSessionTracker } from "../src/views/plugin/sand-iam/api/editorLifecycle";
import {
  hasNormalizedReferenceOption,
  normalizeReferenceRow,
  normalizeReferenceValue,
  resolvedReferenceOptions,
  sandIamReferenceSelectKey,
} from "../src/views/plugin/sand-iam/api/referenceValues";
import {
  auditApplicationOptions,
  auditFilterEndpoints,
  auditOrganizationOptionsFromApplications,
} from "../src/views/plugin/sand-iam/api/auditFilterOptions";
import { sandIamTextFieldValue } from "../src/views/plugin/sand-iam/api/formValues";
import {
  invitationCanResend,
  invitationStateLabel,
  parseSandIamInvitations,
} from "../src/views/plugin/sand-iam/api/invitationContracts";
import {
  importJobCanConfirm,
  parseSandIamImportJobs,
  parseSandIamImportPreview,
  sandIamImportTemplateCsv,
} from "../src/views/plugin/sand-iam/api/importContracts";
import {
  parseSandIamSyncConnectors,
  parseSandIamSyncOutboxRows,
  syncConfigLabel,
} from "../src/views/plugin/sand-iam/api/syncConnectorContracts";
import {
  describeRegistrationTokenIssueError,
  parseRegistrationTokenIssue,
  parseRegistrationTokenRows,
} from "../src/views/plugin/sand-iam/api/oauthRegistrationContracts";
import { parseRadiusNasRows } from "../src/views/plugin/sand-iam/api/radiusNasContracts";
import { parseSecurityAlertRows } from "../src/views/plugin/sand-iam/api/securityAlertContracts";
import { parseInitializationPreview } from "../src/views/plugin/sand-iam/api/initializationContracts";
import {
  casRequestLooksValid,
  parseCasConfirm,
  parseCasInteraction,
} from "../../portal/src/casContracts";
import { parseMessageProviders } from "../src/views/plugin/sand-iam/api/messageProviderContracts";
import {
  IDENTITY_LOGIN_IDENTIFIER_UNAVAILABLE,
  identityCanDisable,
  identityGroupNamesByMember,
  identityGroupSummaryLabel,
  lifecycleStateLabel,
  parseSandIamIdentities,
  parseSandIamIdentityGroupRoles,
  parseSandIamIdentityGroups,
  parseSandIamRoleOptions,
  selectableGroupRoles,
  selectableGroupIdentities,
} from "../src/views/plugin/sand-iam/api/identityLifecycleContracts";
import { parseConditionOrScope } from "../src/views/plugin/sand-iam/api/policyJson";
import {
  cascadedReferenceParams,
  choosePolicySubject,
  describeAuthPolicyPayloadError,
  describeApiGovernanceCodeError,
  describeFederationConfigError,
  describeIdentityProviderCodeError,
  describeIdentityProviderPayloadError,
  describeOAuthClientPayloadError,
  describeOnboardingManifestError,
  describeSandIamObjectCodeError,
  parsePolicySimulation,
  parseRouteManifestPreview,
  sandIamActionImpact,
  sandIamReferenceLabel,
  SAND_IAM_OBJECT_CODE_RULE,
  shouldSubmitSandIamField,
} from "../src/views/plugin/sand-iam/api/uxContracts";
import { parseJsonStringArray } from "../src/views/plugin/sand-iam/api/policyJson";
import {
  parseSandIamRuntimeSession,
  parseSandIamRuntimeSessions,
} from "../src/views/plugin/sand-iam/api/runtimeSessions";
import {
  parseSandIamRuntimeFactor,
  parseSandIamRuntimeFactors,
} from "../src/views/plugin/sand-iam/api/runtimeFactors";
import {
  recommendedSandIamTaskStep,
  SAND_IAM_APPLICATION_PORTAL_URL,
  sandIamTaskPathIsComplete,
  sandIamTaskPathCanOpen,
  sandIamTaskPaths,
  sandIamTaskStepCanOpen,
  sandIamTaskStepNeedsPageInspection,
  sandIamTaskStepNeedsRequest,
} from "../src/views/plugin/sand-iam/api/taskPaths";
import type { SandIamTaskStepSnapshot } from "../src/views/plugin/sand-iam/api/taskPaths";

const defaultListPages = [
  "action",
  "credential",
  "identity-binding",
  "identity-role",
  "identity-user-type",
  "policy",
  "resource",
  "role",
  "service",
  "service-grant",
  "user-type",
] as const;


const filterKeys = [
  "organization_id",
  "application_id",
  "environment_id",
] as const;

assert.equal(
  cascadedReferenceParams("application_id", filterKeys, {
    organizationId: "12",
    applicationId: "",
    environmentId: "",
  })?.organization_id,
  12,
);
assert.equal(
  cascadedReferenceParams("environment_id", filterKeys, {
    organizationId: "12",
    applicationId: "34",
    environmentId: "",
  })?.application_id,
  34,
);
assert.equal(
  cascadedReferenceParams("workload_client_id", filterKeys, {
    organizationId: "12",
    applicationId: "34",
    environmentId: "56",
  })?.environment_id,
  56,
);
assert.equal(
  cascadedReferenceParams("application_id", filterKeys, {
    organizationId: "",
    applicationId: "",
    environmentId: "",
  }),
  null,
);

const environmentApplication = environmentFields.find(
  (field) => field.key === "application_id",
);
assert.equal(environmentApplication?.dependency?.sourceKey, "organization_id");
assert.equal(environmentApplication?.dependency?.targetParam, "organization_id");

const clientEnvironment = clientFields.find(
  (field) => field.key === "environment_id",
);
assert.equal(clientEnvironment?.dependency?.sourceKey, "application_id");
assert.equal(clientEnvironment?.dependency?.targetParam, "application_id");

assert.equal(normalizeReferenceValue(42), 42);
assert.equal(normalizeReferenceValue("42"), 42);
assert.equal(normalizeReferenceValue(" 42 "), 42);
assert.equal(normalizeReferenceValue(null), null);
assert.equal(normalizeReferenceValue(""), null);
assert.equal(normalizeReferenceValue("4.2"), null);
assert.equal(normalizeReferenceValue("application-a"), null);
assert.equal(normalizeReferenceValue(0), null);
assert.equal(normalizeReferenceValue("-42"), null);
assert.deepEqual(normalizeReferenceRow({ id: "42", name: "环境 A" }), {
  id: 42,
  name: "环境 A",
});
assert.equal(normalizeReferenceRow({ id: "environment-a" }), null);
assert.equal(
  hasNormalizedReferenceOption([{ value: 42 }], "42"),
  true,
  "a closed Environment editor must recognize A from a string row id instead of rendering a placeholder",
);
assert.equal(
  hasNormalizedReferenceOption([{ value: 42 }], "environment-a"),
  false,
  "an invalid reference value must not select an option or trigger a fallback read",
);
const environmentReferenceOptions = [{ value: 42, label: "环境 A" }];
assert.equal(
  sandIamReferenceSelectKey("environment_id", null, environmentReferenceOptions),
  "environment_id:neutral",
);
assert.equal(
  sandIamReferenceSelectKey("environment_id", "42", []),
  "environment_id:missing",
);
assert.equal(
  sandIamReferenceSelectKey("environment_id", "42", environmentReferenceOptions),
  "environment_id:resolved",
);
assert.equal(
  sandIamReferenceSelectKey("environment_id", 42, environmentReferenceOptions),
  "environment_id:resolved",
  "42 and '42' must share one resolved Select key",
);
assert.equal(
  sandIamReferenceSelectKey("environment_id", 43, [{ value: 43, label: "环境 B" }]),
  "environment_id:resolved",
  "switching between real options must not recreate the Select",
);
const missingEnvironment = {
  value: 42,
  label: "当前已关联对象（名称暂不可用）",
};
assert.deepEqual(
  resolvedReferenceOptions(
    environmentReferenceOptions,
    "42",
    missingEnvironment,
  ),
  environmentReferenceOptions,
  "a newly loaded real Environment option must remove the fallback label",
);
assert.deepEqual(
  resolvedReferenceOptions([], "42", missingEnvironment),
  [missingEnvironment],
  "a genuinely missing reference must retain its fallback label",
);
assert.deepEqual(auditFilterEndpoints(false), ["application"]);
assert.deepEqual(auditFilterEndpoints(true), ["application", "organization"]);
const delegatedAuditApplications = [
  {
    id: "42",
    name: "应用 A",
    organization_id: "7",
    organization_name: "客户主体甲",
  },
  {
    id: 43,
    name: "应用 C",
    organization_id: 7,
    organization_name: "客户主体甲",
  },
];
assert.deepEqual(auditApplicationOptions(delegatedAuditApplications), [
  { id: 42, name: "应用 A" },
  { id: 43, name: "应用 C" },
]);
assert.equal(
  auditApplicationOptions(delegatedAuditApplications).some((option) => option.name === "应用 B"),
  false,
  "an application not returned by the granted application index must not become an audit filter option",
);
assert.deepEqual(
  auditOrganizationOptionsFromApplications(delegatedAuditApplications),
  [{ id: 7, name: "客户主体甲" }],
  "a pure application grant derives one read-only organization option from granted applications",
);
assert.equal(sandIamTextFieldValue("matter.read"), "matter.read");
assert.equal(
  sandIamTextFieldValue(42),
  "",
  "a normalized reference number must not be converted into a business code string for text inputs",
);


for (const fields of [
  environmentFields,
  clientFields,
  identityFields,
  namedAppFields,
  resourceFields,
  policyFields,
  authPolicyFields,
  applicationExperienceFields,
  oauthClientFields,
  casServiceFields,
  businessActionFields,
  apiResourceFields,
  grantFields,
]) {
  assert.equal(
    fields.find((field) => field.key === "organization_id")
      ?.applicationGrantContext,
    "organization",
    "application-scoped fields must derive the organization from granted applications",
  );
  assert.equal(
    fields.find((field) => field.key === "application_id")
      ?.applicationGrantContext,
    "application",
    "application-scoped fields must load granted applications directly",
  );
}

async function verifyEditorSessionRace(): Promise<void> {
  const editorSessions = createSandIamEditorSessionTracker();
  let editorOpen = false;
  let editorSession = 0;
  const referenceRequests: Array<{
    readonly session: number;
    readonly endpoint: "application" | "organization";
    resolve(): void;
  }> = [];
  const completedSessions: number[] = [];
  function isActiveEditorSession(session: number): boolean {
    return editorOpen && editorSessions.isCurrent(session) && editorSession === session;
  }
  async function prepareEditorSession(session: number): Promise<void> {
    if (!isActiveEditorSession(session)) return;
    let resolveRequest: (() => void) | undefined;
    const pending = new Promise<void>((resolve) => {
      resolveRequest = resolve;
    });
    referenceRequests.push({
      session,
      endpoint: "application",
      resolve(): void {
        resolveRequest?.();
      },
    });
    await pending;
    if (!isActiveEditorSession(session)) return;
    completedSessions.push(session);
  }
  function transitionEditor(modelValue: boolean): void {
    editorOpen = modelValue;
    editorSession = editorSessions.next();
    if (modelValue) void prepareEditorSession(editorSession);
  }

  transitionEditor(false);
  assert.equal(referenceRequests.length, 0, "an initially closed editor must not request references");
  transitionEditor(true);
  await Promise.resolve();
  assert.equal(referenceRequests.length, 1, "an editor mounted open must request application/index once");
  const staleSession = referenceRequests[0];
  if (staleSession === undefined) throw new Error("expected the first application request");
  transitionEditor(false);
  transitionEditor(true);
  await Promise.resolve();
  assert.equal(referenceRequests.length, 2, "reopening must start one new application/index request");
  assert.equal(
    referenceRequests.filter(
      (request) => request.session === editorSession && request.endpoint === "application",
    ).length,
    1,
    "the current open session must issue application/index once",
  );
  assert.equal(
    referenceRequests.filter((request) => request.endpoint === "organization").length,
    0,
    "application-grant initialization must not request organization/index",
  );
  staleSession.resolve();
  await Promise.resolve();
  assert.deepEqual(completedSessions, [], "a closed stale session must not continue or write options");
  const currentSession = referenceRequests[1];
  if (currentSession === undefined) throw new Error("expected the second application request");
  currentSession.resolve();
  await Promise.resolve();
  assert.deepEqual(completedSessions, [editorSession], "only the current session may write options");
}
void verifyEditorSessionRace().catch((error: unknown) => {
  throw error;
});

async function verifyEditorSearchSessionRace(): Promise<void> {
  const editorSessions = createSandIamEditorSessionTracker();
  let editorOpen = true;
  let editorSession = editorSessions.next();
  const searchRequests: Array<{
    readonly session: number;
    readonly keywords: string;
    resolve(): void;
  }> = [];
  const visibleSearchResults: string[] = [];
  function isActiveEditorSession(session: number): boolean {
    return editorOpen && editorSessions.isCurrent(session) && editorSession === session;
  }
  async function search(session: number, keywords: string): Promise<void> {
    if (!isActiveEditorSession(session)) return;
    let resolveRequest: (() => void) | undefined;
    const pending = new Promise<void>((resolve) => {
      resolveRequest = resolve;
    });
    searchRequests.push({
      session,
      keywords,
      resolve(): void {
        resolveRequest?.();
      },
    });
    await pending;
    if (!isActiveEditorSession(session)) return;
    visibleSearchResults.push(keywords);
  }
  function searchReference(keywords: string): void {
    if (!editorOpen) return;
    void search(editorSession, keywords);
  }
  function transitionEditor(modelValue: boolean): void {
    editorOpen = modelValue;
    editorSession = editorSessions.next();
  }

  searchReference("old");
  const staleSearch = searchRequests[0];
  if (staleSearch === undefined) throw new Error("expected the first search request");
  transitionEditor(false);
  transitionEditor(true);
  searchReference("current");
  const currentSearch = searchRequests[1];
  if (currentSearch === undefined) throw new Error("expected the second search request");
  staleSearch.resolve();
  await Promise.resolve();
  assert.deepEqual(visibleSearchResults, [], "a stale search must not restore old options after reopening");
  currentSearch.resolve();
  await Promise.resolve();
  assert.deepEqual(visibleSearchResults, ["current"], "the current session search must update options normally");
}
void verifyEditorSearchSessionRace().catch((error: unknown) => {
  throw error;
});

async function verifyEditorCascadeSessionRace(): Promise<void> {
  const editorSessions = createSandIamEditorSessionTracker();
  let editorOpen = true;
  let editorSession = editorSessions.next();
  let lastDependencyValue = "";
  const cascadeRequests: Array<{
    readonly session: number;
    readonly dependency: string;
    resolve(): void;
  }> = [];
  const visibleCascadeResults: string[] = [];
  function isActiveEditorSession(session: number): boolean {
    return editorOpen && editorSessions.isCurrent(session) && editorSession === session;
  }
  async function loadReferenceOptions(session: number, dependency: string): Promise<void> {
    if (!isActiveEditorSession(session)) return;
    let resolveRequest: (() => void) | undefined;
    const pending = new Promise<void>((resolve) => {
      resolveRequest = resolve;
    });
    cascadeRequests.push({
      session,
      dependency,
      resolve(): void {
        resolveRequest?.();
      },
    });
    await pending;
    if (!isActiveEditorSession(session)) return;
    visibleCascadeResults.push(dependency);
  }
  function dependencyChanged(value: string): void {
    lastDependencyValue = value;
    if (!editorOpen) return;
    void loadReferenceOptions(editorSession, value);
  }
  function transitionEditor(modelValue: boolean): void {
    editorOpen = modelValue;
    editorSession = editorSessions.next();
  }

  dependencyChanged("old");
  const staleCascade = cascadeRequests[0];
  if (staleCascade === undefined) throw new Error("expected the first cascade request");
  transitionEditor(false);
  dependencyChanged("closed");
  assert.equal(lastDependencyValue, "closed", "a closed dialog still records its latest dependency snapshot");
  assert.equal(cascadeRequests.length, 1, "a closed dialog must not issue a dependency request");
  transitionEditor(true);
  dependencyChanged("current");
  const currentCascade = cascadeRequests[1];
  if (currentCascade === undefined) throw new Error("expected the second cascade request");
  staleCascade.resolve();
  await Promise.resolve();
  assert.deepEqual(visibleCascadeResults, [], "a stale cascade must not restore old options after reopening");
  currentCascade.resolve();
  await Promise.resolve();
  assert.deepEqual(visibleCascadeResults, ["current"], "the current cascade must update options normally");
}
void verifyEditorCascadeSessionRace().catch((error: unknown) => {
  throw error;
});

assert.deepEqual(choosePolicySubject("role", "7", "", "9"), {
  roleId: "7",
  identityId: "",
});
assert.deepEqual(choosePolicySubject("identity", "9", "7", ""), {
  roleId: "",
  identityId: "9",
});

assert.deepEqual(
  parseConditionOrScope(
    '{"equals":{"owner_id":"42"},"in":{"organization_id":[1,2]}}',
    "策略生效条件",
  ),
  {
    equals: { owner_id: "42" },
    in: { organization_id: [1, 2] },
  },
);
assert.throws(
  () => parseConditionOrScope('{"contains":{}}', "策略生效条件"),
  /只允许 equals\/in/,
);

assert.equal(organizationFields[0]?.key, "name");
assert.equal(organizationFields[1]?.key, "code");
assert.equal(organizationFields[1]?.placeholder, "xinghe-group");
assert.match(organizationFields[1]?.help ?? "", /创建后不可修改/);
assert.match(organizationFields[0]?.help ?? "", /具体产品请在「接入应用」中登记/);
assert.equal(applicationFields.find((field) => field.key === "code")?.placeholder, "customer-portal");
assert.equal(
  applicationFields.find((field) => field.key === "organization_id")
    ?.applicationGrantContext,
  undefined,
  "application itself must not use the generic application-grant context",
);
assert.equal(
  applicationFields.find((field) => field.key === "organization_id")
    ?.embeddedReferenceLabelKey,
  "organization_name",
);
assert.equal(
  applicationFields.find((field) => field.key === "organization_id")
    ?.embeddedReferenceEditableKey,
  "organization_editable",
);
assert.match(
  applicationFields.find((field) => field.key === "name")?.help ?? "",
  /需要接入的产品或项目名称/,
);
assert.equal(environmentFields.find((field) => field.key === "code")?.placeholder, "production");
assert.equal(clientFields.find((field) => field.key === "code")?.placeholder, "backend");
assert.notEqual(serviceFields, organizationFields);

assert.equal(describeSandIamObjectCodeError("customer-portal"), null);
assert.equal(
  describeSandIamObjectCodeError("Invalid Code"),
  SAND_IAM_OBJECT_CODE_RULE,
);
assert.match(describeSandIamObjectCodeError("") ?? "", /请填写系统代码（用于接口配置）/);

const connectionSteps = sandIamTaskPaths.connection.steps.map((definition, index): SandIamTaskStepSnapshot => ({
  definition,
  state: index === 0 ? "ready" : "missing",
  total: index === 0 ? 1 : 0,
  detail: "test",
}));
assert.equal(recommendedSandIamTaskStep(connectionSteps)?.definition.key, "application");
assert.equal(sandIamTaskPaths.connection.steps[1]?.path, "/sand-iam/application");
assert.equal(sandIamTaskPaths.connection.entryPath, "/sand-iam/connection");
assert.deepEqual(
  Object.fromEntries(
    Object.entries(sandIamTaskPaths).map(([path, definition]) => [
      path,
      definition.entryPermission,
    ]),
  ),
  {
    connection: "sand_iam:organization:index",
    "people-access": "sand_iam:identity:index",
    "auth-session": "sand_iam:auth_policy:index",
    "api-governance": "sand_iam:api_resource:index",
    "admin-scope": undefined,
    "event-notification": "sand_iam:webhook:index",
    "audit-troubleshooting": "sand_iam:audit:index",
  },
  "每个总览入口必须与对应动态路由的父入口权限一致",
);
assert.equal(
  sandIamTaskPaths["audit-troubleshooting"].guides?.find(
    (guide) => guide.title === "服务受众不匹配",
  )?.path,
  "/sand-iam/service-grant",
);
assert.equal(
  sandIamTaskPaths["audit-troubleshooting"].guides?.find(
    (guide) => guide.title === "调用凭证已撤销",
  )?.permission,
  "sand_iam:credential:index",
);
assert.match(
  sandIamTaskPaths["audit-troubleshooting"].guides?.find(
    (guide) => guide.title === "调用凭证已撤销",
  )?.recovery ?? "",
  /重新签发凭证/,
);
assert.equal(
  sandIamTaskPathCanOpen(sandIamTaskPaths["admin-scope"], (permission) =>
    permission === "sand_iam:admin_application_grant:index",
  ),
  true,
  "应用委派管理员无需客户主体委派权限，也能进入管理范围",
);
assert.equal(
  sandIamTaskPathCanOpen(sandIamTaskPaths["admin-scope"], () => false), false);
assert.equal(sandIamTaskPaths.connection.steps[3]?.path, "/sand-iam/workload-client");
assert.equal(sandIamTaskPaths.connection.steps[3]?.permission, "sand_iam:client:index");
assert.equal(sandIamTaskPaths.connection.steps[4]?.permission, "sand_iam:grant:index");
assert.equal(
  recommendedSandIamTaskStep(
    sandIamTaskPaths.connection.steps.map((definition): SandIamTaskStepSnapshot => ({
      definition,
      state: "ready",
      total: 1,
      detail: "test",
    })),
  ),
  null,
);
assert.equal(
  recommendedSandIamTaskStep([
    { ...connectionSteps[0]!, state: "ready" },
    { ...connectionSteps[1]!, state: "error" },
    { ...connectionSteps[2]!, state: "forbidden" },
  ])?.state,
  "error",
);

const routeManifestStep = sandIamTaskPaths["api-governance"].steps.find(
  (step) => step.key === "route-manifest",
);
assert.ok(routeManifestStep);
assert.equal(sandIamTaskStepNeedsRequest(routeManifestStep), false);
assert.equal(sandIamTaskStepNeedsPageInspection(routeManifestStep), true);
const uncheckedCustomPage: SandIamTaskStepSnapshot = {
  definition: routeManifestStep,
  state: "inspection",
  total: null,
  detail: "test",
};
const noPermissionCustomPage: SandIamTaskStepSnapshot = {
  ...uncheckedCustomPage,
  state: "forbidden",
};
assert.equal(sandIamTaskStepCanOpen(uncheckedCustomPage), true);
assert.equal(sandIamTaskStepCanOpen(noPermissionCustomPage), false);
const failedApiPage: SandIamTaskStepSnapshot = {
  ...connectionSteps[0]!,
  state: "error",
  total: null,
};
const emptyApiPage: SandIamTaskStepSnapshot = {
  ...connectionSteps[0]!,
  state: "missing",
  total: 0,
};
for (const incompleteStep of [
  uncheckedCustomPage,
  noPermissionCustomPage,
  failedApiPage,
  emptyApiPage,
]) {
  assert.notEqual(recommendedSandIamTaskStep([incompleteStep]), null);
  assert.equal(sandIamTaskPathIsComplete([incompleteStep]), false);
}
assert.equal(
  sandIamTaskPathIsComplete(
    sandIamTaskPaths.connection.steps.map((definition): SandIamTaskStepSnapshot => ({
      definition,
      state: "ready",
      total: 1,
      detail: "test",
    })),
  ),
  true,
);
assert.match(
  clientFields.find((field) => field.key === "audience")?.help ?? "",
  /服务提供方/,
);
assert.match(
  grantFields.find((field) => field.key === "service_action_id")?.help ?? "",
  /不是 HTTP 地址/,
);
assert.equal(grantFields.find((field) => field.key === "expire_time")?.kind, "datetime");
assert.equal(credentialFields.find((field) => field.key === "expire_time")?.kind, "datetime");
assert.equal(grantFields.find((field) => field.key === "quota_policy")?.advanced, true);
assert.equal(grantFields.find((field) => field.key === "network_policy")?.advanced, true);
assert.equal(
  shouldSubmitSandIamField(
    grantFields.find((field) => field.key === "quota_policy")!,
    false,
  ),
  false,
);
assert.equal(
  shouldSubmitSandIamField(
    grantFields.find((field) => field.key === "quota_policy")!,
    true,
  ),
  true,
);
assert.equal(
  shouldSubmitSandIamField(
    grantFields.find((field) => field.key === "organization_id")!,
    true,
  ),
  false,
);
assert.equal(
  grantFields.find((field) => field.key === "application_id")?.dependency
    ?.sourceKey,
  "organization_id",
);
assert.equal(
  grantFields.find((field) => field.key === "workload_client_id")?.dependency
    ?.sourceKey,
  "environment_id",
);
assert.equal(
  grantFields.find((field) => field.key === "service_action_id")?.dependency
    ?.sourceKey,
  "service_id",
);
assert.equal(
  credentialFields.find((field) => field.key === "workload_client_id")
    ?.dependency?.sourceKey,
  "environment_id",
);
assert.equal(
  sandIamReferenceLabel(
    { id: 9, name: "生产环境", code: "production" },
    "律序 · lvxu · 所属：北京星河律师事务所",
  ),
  "生产环境 · production · 所属：律序 · lvxu · 所属：北京星河律师事务所",
);
assert.match(sandIamActionImpact("revoke-policy"), /不能恢复/);
assert.match(sandIamActionImpact("revoke-grant"), /重新创建授权/);
assert.match(sandIamActionImpact("revoke-relation"), /重新授予/);
assert.match(sandIamActionImpact("rotate-credential"), /旧凭证立即失效且不能恢复/);
assert.match(sandIamActionImpact("revoke-credential"), /重新签发凭证/);
assert.match(serviceFields.find((field) => field.key === "code")?.help ?? "", /下划线/);
assert.equal(
  actionFields.find((field) => field.key === "code")?.placeholder,
  "sand_ai.document_parse",
);

assert.equal(sandIamTaskPaths["api-governance"].steps[0]?.path, "/sand-iam/oauth-client");
assert.equal(sandIamTaskPaths["api-governance"].steps[0]?.contractStatus, "frozen");
assert.equal(
  sandIamTaskPaths.connection.description,
  "登记谁在使用 SandIAM，以及哪些系统需要接入。",
);
assert.equal(
  sandIamTaskPaths["people-access"].description,
  "管理用户从哪里来、能进入哪些应用、可以做什么。",
);
assert.equal(
  sandIamTaskPaths["auth-session"].description,
  "设置登录方式和安全要求，管理登录状态。",
);
assert.equal(
  sandIamTaskPaths["api-governance"].description,
  "控制系统之间如何连接，以及可以调用哪些能力。",
);
assert.equal(
  sandIamTaskPaths["admin-scope"].description,
  "把指定客户或应用的管理工作交给合适的管理员。",
);
assert.equal(
  sandIamTaskPaths["event-notification"].description,
  "把用户和权限变化通知给业务系统，并查看是否送达。",
);
assert.equal(
  sandIamTaskPaths["audit-troubleshooting"].description,
  "查询谁在什么时候做了什么，并处理异常。",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "oauth-registration-token")
    ?.path,
  "/sand-iam/oauth-registration-token",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "cas-service")
    ?.path,
  "/sand-iam/cas-service",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "business-action")
    ?.path,
  "/sand-iam/application-business-action",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "business-action")
    ?.permission,
  "sand_iam:api_resource:index",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps
    .find((step) => step.key === "business-action")
    ?.emptyHint,
  "先声明业务动作，再在接口目录中引用它。",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "api-resource")
    ?.path,
  "/sand-iam/api-resource",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "route-manifest")
    ?.path,
  "/sand-iam/route-manifest",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "route-manifest")
    ?.permission,
  "sand_iam:onboarding:preview",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "policy-simulate")
    ?.path,
  "/sand-iam/policy-simulate",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "policy-simulate")
    ?.permission,
  "sand_iam:policy:index",
);
assert.equal(
  sandIamTaskPaths["api-governance"].steps.find((step) => step.key === "developer-docs")
    ?.permission,
  "sand_iam:developer:openapi",
);
assert.equal(oauthClientFields.find((field) => field.key === "name") !== undefined, true);
assert.equal(
  oauthClientFields.find((field) => field.key === "redirect_uris")?.jsonArray,
  true,
);
assert.equal(
  oauthClientFields.find((field) => field.key === "frontchannel_logout_session_required")
    ?.advanced,
  true,
);
assert.equal(casServiceFields.find((field) => field.key === "service_url")?.createOnly, true);
assert.equal(
  businessActionFields.find((field) => field.key === "code")?.systemObjectCode,
  undefined,
);
assert.equal(apiResourceFields.find((field) => field.key === "action")?.createOnly, true);
assert.equal(policyFields.find((field) => field.key === "action")?.label, "业务动作");
assert.equal(apiResourceFields.find((field) => field.key === "action")?.label, "业务动作");
assert.match(policyFields.find((field) => field.key === "action")?.help ?? "", /不是页面按钮或 HTTP 方法/);
assert.match(apiResourceFields.find((field) => field.key === "action")?.help ?? "", /不是页面按钮或 HTTP 方法/);
assert.match(describeApiGovernanceCodeError("Matter.Read") ?? "", /小写字母开头/);
assert.equal(
  describeOAuthClientPayloadError({ client_type: "confidential" }, true)?.includes(
    "回调地址",
  ) ?? false,
  true,
);
assert.equal(
  describeOnboardingManifestError({
    format: "sand-iam.route-sync/v1",
    organization_code: "sand",
    application_code: "lawyer",
    environment_code: "production",
    routes: [],
  }),
  null,
);
assert.equal(
  describeOnboardingManifestError({
    format: "sand-iam.route-sync/v1",
    organization_code: "sand",
  })?.includes("routes 列表") ?? false,
  true,
);
assert.equal(
  parseRouteManifestPreview({
    data: {
      dry_run: true,
      valid: false,
      preview_hash: "a".repeat(64),
      operation_id: "route-preview-1",
      organization_id: 1,
      application_id: 2,
      changes: [],
      problems: [
        {
          operation: "unbound",
          method: "POST",
          route_template: "/cases",
        },
      ],
    },
  })?.canApply,
  false,
);
assert.equal(
  parsePolicySimulation({
    allowed: false,
    code: "SAND_IAM_POLICY_DENIED",
    final_reason: "未命中有效允许规则",
    request_id: "rid-1",
    matched_rules: [],
    missing_context: [],
  })?.allowed,
  false,
);
assert.equal(sandIamTaskPaths["auth-session"].entryPath, "/sand-iam/auth-session");
assert.equal(sandIamTaskPaths["auth-session"].steps[0]?.path, "/sand-iam/auth-policy");
assert.equal(sandIamTaskPaths["auth-session"].steps[0]?.endpoint, "auth-policy");
assert.equal(
  sandIamTaskStepNeedsRequest(sandIamTaskPaths["auth-session"].steps[0]!),
  true,
);
assert.equal(sandIamTaskPaths["auth-session"].steps[1]?.path, "/sand-iam/identity");
assert.equal(sandIamTaskPaths["auth-session"].steps[2]?.path, "/sand-iam/identity-group");
assert.equal(sandIamTaskPaths["auth-session"].steps[2]?.contractStatus, "frozen");
assert.equal(sandIamTaskPaths["auth-session"].steps[3]?.path, "/sand-iam/identity-invitation");
assert.equal(sandIamTaskPaths["auth-session"].steps[3]?.contractStatus, "frozen");
assert.equal(sandIamTaskPaths["auth-session"].steps[4]?.path, "/sand-iam/identity-import");
assert.equal(sandIamTaskPaths["auth-session"].steps[4]?.contractStatus, "frozen");
assert.equal(sandIamTaskPaths["auth-session"].steps[5]?.path, SAND_IAM_APPLICATION_PORTAL_URL);
assert.equal(sandIamTaskPaths["auth-session"].steps[5]?.contractStatus, "runtime");
assert.equal(
  sandIamTaskStepNeedsRequest(sandIamTaskPaths["auth-session"].steps[5]!),
  false,
);
assert.equal(sandIamTaskPaths["auth-session"].steps[6]?.path, SAND_IAM_APPLICATION_PORTAL_URL);
assert.equal(sandIamTaskPaths["auth-session"].steps[6]?.contractStatus, "runtime");
assert.equal(sandIamTaskPaths["auth-session"].steps[9]?.path, "/sand-iam/identity-provider");
assert.equal(sandIamTaskPaths["auth-session"].steps[10]?.path, "/sand-iam/federation-config");
assert.equal(sandIamTaskPaths["auth-session"].steps[10]?.contractStatus, "frozen");
assert.equal(sandIamTaskPaths["auth-session"].steps[10]?.permission, "sand_iam:federation:configure");
assert.equal(sandIamTaskPaths["auth-session"].steps[11]?.path, "/sand-iam/scim-tokens");
assert.equal(sandIamTaskPaths["auth-session"].steps[11]?.contractStatus, "frozen");
assert.equal(sandIamTaskPaths["auth-session"].steps[11]?.permission, "sand_iam:scim:token_index");
assert.equal(identityProviderFields[0]?.key, "name");
assert.equal(
  identityProviderFields.find((field) => field.key === "code")?.systemObjectCode,
  undefined,
);
assert.match(
  describeIdentityProviderCodeError("Local Account") ?? "",
  /小写字母开头/,
);
assert.equal(
  describeIdentityProviderPayloadError(
    {
      code: "local-account",
      name: "本地账号",
      organization_id: 1,
      scope_type: "organization",
      application_id: 9,
    },
    true,
  )?.includes("不要选择接入应用") ?? false,
  true,
);
assert.equal(
  describeFederationConfigError(
    "kerberos",
    {},
    { subject: "sub" },
    "reject",
  )?.includes("Kerberos") ?? false,
  true,
);
assert.equal(
  describeFederationConfigError(
    "oidc",
    {
      client_id: "app",
      issuer: "https://accounts.example.com",
      handoff_return_uris: ["https://app.example.com/callback"],
    },
    { subject: "sub" },
    "reject",
  )?.includes("客户端密钥") ?? false,
  true,
);
assert.equal(
  describeFederationConfigError(
    "oidc",
    {
      client_id: "app",
      client_secret: "write-only",
      issuer: "https://accounts.example.com",
      handoff_return_uris: ["https://app.example.com/callback"],
    },
    { subject: "sub" },
    "reject",
  ),
  null,
);
assert.equal(
  parseSandIamRuntimeFactor({
    id: 3,
    type: "totp",
    name: "办公室手机",
    status: 1,
    create_time: "2026-08-23 10:00:00",
    last_used_time: null,
    secret: "must-not-surface",
  })?.name,
  "办公室手机",
);
assert.equal(
  parseSandIamRuntimeFactor({
    id: 3,
    type: "totp",
    name: "办公室手机",
    status: 1,
    create_time: "2026-08-23 10:00:00",
  }) !== null &&
    "secret" in
      (parseSandIamRuntimeFactor({
        id: 3,
        type: "totp",
        name: "办公室手机",
        status: 1,
        create_time: "2026-08-23 10:00:00",
        secret: "x",
      }) ?? {}),
  false,
);
assert.equal(
  parseSandIamRuntimeFactors({
    data: [
      {
        id: 3,
        type: "passkey",
        name: "本机钥匙",
        status: 1,
        create_time: "2026-08-23 10:00:00",
        last_used_time: "2026-08-23 10:05:00",
      },
    ],
  })[0]?.type,
  "passkey",
);

assert.equal(identityFields[0]?.key, "display_name");
assert.equal(identityFields.find((field) => field.key === "code")?.systemObjectCode, true);
assert.equal(
  identityFields.some((field) => field.key === "id"),
  false,
);

const authPolicyKeys = authPolicyFields
  .filter((field) => field.omitFromPayload !== true)
  .map((field) => field.key);
assert.deepEqual(authPolicyKeys, [
  "application_id",
  "registration_enabled",
  "password_min_length",
  "password_max_length",
  "require_uppercase",
  "require_lowercase",
  "require_digit",
  "require_symbol",
  "require_email_verification",
  "require_phone_verification",
  "require_captcha",
  "max_login_failures",
  "lock_seconds",
  "rate_limit_per_minute",
  "access_token_ttl_seconds",
  "refresh_token_ttl_seconds",
  "verification_ttl_seconds",
  "webauthn_rp_id",
  "webauthn_allowed_origins",
  "webauthn_user_verification",
  "status",
]);
assert.equal(authPolicyFields.find((field) => field.key === "webauthn_allowed_origins")?.jsonArray, true);
assert.equal(authPolicyFields.find((field) => field.key === "webauthn_allowed_origins")?.advanced, true);
assert.match(
  describeAuthPolicyPayloadError({ password_min_length: 8 }) ?? "",
  /超出允许范围/,
);
assert.match(
  describeAuthPolicyPayloadError({
    password_min_length: 20,
    password_max_length: 12,
  }) ?? "",
  /最小长度不能大于最大长度/,
);
assert.equal(
  describeAuthPolicyPayloadError({
    password_min_length: 12,
    password_max_length: 128,
    max_login_failures: 5,
  }),
  null,
);
assert.deepEqual(parseJsonStringArray("[]", "通行密钥允许来源"), []);
assert.deepEqual(parseJsonStringArray('["https://login.example.com"]', "通行密钥允许来源"), [
  "https://login.example.com",
]);
assert.throws(() => parseJsonStringArray("{}", "通行密钥允许来源"), /必须是列表/);

const sampleSession = parseSandIamRuntimeSession({
  id: 9,
  create_time: "2026-08-23 10:00:00",
  last_used_time: "2026-08-23 10:05:00",
  access_expire_time: "2026-08-23 10:15:00",
  refresh_expire_time: "2026-09-22 10:00:00",
  current: 1,
  access_token: "must-not-surface",
});
assert.equal(sampleSession?.id, 9);
assert.equal(sampleSession?.current, true);
assert.equal(
  sampleSession !== null && "access_token" in sampleSession,
  false,
);
assert.equal(
  parseSandIamRuntimeSessions({
    data: [
      {
        id: 9,
        create_time: "2026-08-23 10:00:00",
        last_used_time: "2026-08-23 10:05:00",
        access_expire_time: "2026-08-23 10:15:00",
        refresh_expire_time: "2026-09-22 10:00:00",
        current: false,
      },
    ],
  }).length,
  1,
);
assert.equal(
  sandIamTaskStepNeedsRequest(sandIamTaskPaths.connection.steps[0]!),
  true,
);
assert.equal(
  sandIamTaskPaths["admin-scope"].steps[0]?.endpoint,
  "admin-organization-grant",
);
assert.equal(
  sandIamTaskPaths["admin-scope"].steps[1]?.path,
  "/sand-iam/admin-application-grant",
);
assert.equal(
  sandIamTaskPaths["admin-scope"].steps[1]?.contractStatus,
  "frozen",
);
assert.match(
  adminApplicationGrantFields.find((field) => field.key === "admin_user_id")
    ?.help ?? "",
  /禁止手填编号/,
);
assert.equal(sandIamTaskPaths["event-notification"].steps[0]?.path, "/sand-iam/webhook");
assert.equal(
  sandIamTaskPaths["event-notification"].steps[0]?.contractStatus,
  "frozen",
);
assert.equal(
  sandIamTaskPaths["event-notification"].steps[1]?.path,
  "/sand-iam/webhook-delivery",
);
assert.equal(summarizeWebhookUrl("https://hooks.example.com/sand-iam?token=secret"), "hooks.example.com/sand-iam");
assert.equal(
  parseWebhookSecretResult({
    secret: "siwh_once",
    secret_version: 1,
    id: 9,
  }).available,
  true,
);
assert.equal(
  parseWebhookSecretResult({
    secret_available: false,
    secret_version: 2,
    secret: "must-not-surface",
  }).secret,
  null,
);
assert.equal(webhookDeliveryRetryable(1), true);
assert.equal(webhookDeliveryRetryable(3), false);
assert.equal(webhookDeliveryRetryable(4), true);
assert.deepEqual([...SAND_IAM_AUDIT_DEFAULT_COLUMNS], [
  "发生时间",
  "接入应用",
  "操作者",
  "操作",
  "资源",
  "结果",
]);
assert.equal(SAND_IAM_AUDIT_HIDDEN_DEFAULT_COLUMNS.includes("id"), true);
assert.equal(SAND_IAM_AUDIT_HIDDEN_DEFAULT_COLUMNS.includes("actor_ref"), true);
assert.equal(auditActionLabel("identity.login"), "应用用户登录");
assert.equal(auditActionLabel("unrecognized.action"), "其他操作");
assert.equal(auditResourceTypeLabel("auth_session"), "登录会话");
assert.equal(auditResourceTypeLabel("unrecognized_object"), "其他对象");
assert.equal(auditActorLabel("unrecognized_actor"), "其他操作者");
assert.equal(auditOutcomeLabel("unrecognized_outcome"), "其他结果");
assert.equal(auditTimeLabel("2026-08-28 09:05:20"), "2026年8月28日 09:05");
assert.equal(auditTimeLabel("not-a-time"), "时间未知");
assert.match(
  describeAuditExportRangeError("", "2026-08-23") ?? "",
  /31 天/,
);
assert.equal(
  describeAuditExportRangeError("2026-08-01 00:00:00", "2026-08-20 23:59:59"),
  null,
);
assert.match(
  describeAuditExportRangeError("2026-07-01 00:00:00", "2026-08-20 23:59:59") ??
    "",
  /31 天/,
);

const portalProfile = parsePortalProfile({
  identity_id: 99,
  display_name: "张三",
  organization: { code: "xinghe", name: "星河" },
  application: { code: "lvxu", name: "律序" },
  create_time: "2026-08-23 10:00:00",
});
assert.equal(portalProfile?.applicationName, "律序");
assert.equal(portalProfile !== null && "identity_id" in portalProfile, false);
const portalHeaders = portalRequestHeaders("siam_at_demo", "req-1", false);
assert.equal(portalHeaders.Authorization, "Bearer siam_at_demo");
assert.equal(
  Object.keys(portalHeaders).some((name) => isPortalAdminHeader(name)),
  false,
);
assert.equal("check_admin" in portalHeaders, false);

assert.equal(
  sandIamTaskPaths["auth-session"].steps.find(
    (step) => step.key === "application-experience",
  )?.path,
  "/sand-iam/application-experience",
);
assert.equal(
  sandIamTaskPaths["auth-session"].steps.find(
    (step) => step.key === "message-provider",
  )?.contractStatus,
  "frozen",
);
assert.equal(
  applicationExperienceFields.find((field) => field.key === "brand_name")
    ?.required,
  true,
);
assert.equal(
  applicationExperienceFields.find((field) => field.key === "login_methods")
    ?.kind,
  "select",
);
assert.equal(
  applicationExperienceFields.find((field) => field.key === "login_methods")
    ?.multiple,
  true,
);
assert.equal(
  applicationExperienceFields.find((field) => field.key === "registration_fields")
    ?.multiple,
  true,
);
const publicExperience = parsePublicExperience({
  organization_code: "xinghe",
  application_code: "lvxu",
  brand_name: "律序",
  logo_url: "",
  primary_color: "#1677ff",
  theme_mode: "light",
  default_locale: "zh-CN",
  terms_url: "",
  privacy_url: "",
  registration_mode: "disabled",
  login_methods: ["passkey"],
  registration_fields: ["username", "email"],
});
assert.equal(publicExperience?.brandName, "律序");
assert.equal(experienceAllowsPassword(publicExperience!), false);
assert.equal(
  parseMessageProviders({
    data: [
      {
        id: 3,
        organization_id: 1,
        code: "aliyun-sms",
        name: "生产短信",
        provider_type: "sms",
        driver_code: "aliyun-sms",
        config_version: 2,
        config_configured: true,
        status: 1,
        encrypted_config: "must-not-surface",
      },
    ],
  })[0]?.config_configured,
  true,
);
assert.equal(
  parseMessageProviders({
    data: [
      {
        id: 3,
        organization_id: 1,
        code: "aliyun-sms",
        name: "生产短信",
        provider_type: "sms",
        driver_code: "aliyun-sms",
        config_version: 2,
        config_configured: true,
        status: 1,
        encrypted_config: "x",
      },
    ],
  })[0] !== undefined &&
    "encrypted_config" in
      (parseMessageProviders({
        data: [
          {
            id: 3,
            organization_id: 1,
            code: "aliyun-sms",
            name: "生产短信",
            provider_type: "sms",
            driver_code: "aliyun-sms",
            config_version: 2,
            config_configured: true,
            status: 1,
            encrypted_config: "x",
          },
        ],
      })[0] ?? {}),
  false,
);

assert.equal(lifecycleStateLabel("pending"), "等待邀请");
assert.equal(lifecycleStateLabel("active"), "正常");
assert.equal(lifecycleStateLabel("disabled"), "已停用");
assert.equal(lifecycleStateLabel("guest"), "访客");
assert.equal(lifecycleStateLabel("deleted"), "已删除");
assert.notEqual(lifecycleStateLabel("active"), "1");
assert.match(IDENTITY_LOGIN_IDENTIFIER_UNAVAILABLE, /当前列表未提供主要登录标识/);

const parsedIdentity = parseSandIamIdentities({
  data: [
    {
      id: 8,
      application_id: 3,
      display_name: "张律师",
      code: "zhang",
      status: 1,
      lifecycle_state: "active",
      login_identifier_masked: "zh***@example.com",
      group_summary: "法务组",
    },
  ],
})[0];
assert.equal(parsedIdentity?.display_name, "张律师");
assert.equal(parsedIdentity !== undefined && "login_identifier_masked" in parsedIdentity, false);
assert.equal(parsedIdentity !== undefined && "group_summary" in parsedIdentity, false);
assert.equal(
  identityCanDisable({
    id: 8,
    application_id: 3,
    display_name: "待邀请",
    code: "pending-user",
    status: 1,
    lifecycle_state: "pending",
  }),
  false,
);

const groupRows = parseSandIamIdentityGroups({
  data: [
    {
      id: 11,
      application_id: 3,
      code: "legal",
      name: "法务组",
      parent_name: "",
      description: "",
      member_count: 1,
      status: 1,
    },
  ],
});
assert.equal(
  identityGroupSummaryLabel(
    8,
    identityGroupNamesByMember(groupRows, {
      11: [
        {
          identity_id: 8,
          display_name: "张律师",
          code: "zhang",
          lifecycle_state: "active",
        },
      ],
    }),
    true,
  ),
  "法务组",
);
assert.equal(identityGroupSummaryLabel(8, new Map(), false), "无权查看用户组摘要");
assert.equal(
  selectableGroupIdentities([
    {
      id: 9,
      application_id: 3,
      display_name: "已删除用户",
      code: "gone",
      status: 2,
      lifecycle_state: "deleted",
    },
  ]).length,
  0,
);
const groupRoleRows = parseSandIamIdentityGroupRoles({
  data: [
    {
      id: 21,
      identity_group_id: 11,
      role_id: 7,
      status: 1,
      role_code: "customer.read",
      role_name: "客户查看者",
      role_status: 1,
      application_id: 3,
    },
  ],
});
assert.deepEqual(groupRoleRows, [
  {
    id: 21,
    identity_group_id: 11,
    role_id: 7,
    status: 1,
    role_code: "customer.read",
    role_name: "客户查看者",
    role_status: 1,
  },
]);
assert.deepEqual(
  selectableGroupRoles(
    parseSandIamRoleOptions({
      data: [
        { id: 7, application_id: 3, name: "客户查看者", code: "customer.read", status: 1 },
        { id: 8, application_id: 3, name: "已停用角色", code: "disabled", status: 2 },
        { id: 9, application_id: 4, name: "其他应用角色", code: "other.read", status: 1 },
      ],
    }),
    3,
  ).map((row) => row.id),
  [7],
);
assert.equal(
  sandIamTaskPaths["people-access"].steps.find((step) => step.key === "identity-group")
    ?.path,
  "/sand-iam/identity-group",
);
assert.equal(
  sandIamTaskPaths["auth-session"].steps.find((step) => step.key === "identity-group")
    ?.contractStatus,
  "frozen",
);
assert.equal(
  sandIamTaskPaths["people-access"].steps.find((step) => step.key === "identity-invitation")
    ?.path,
  "/sand-iam/identity-invitation",
);
assert.equal(invitationStateLabel("delivery_failed"), "投递失败");
assert.equal(invitationCanResend("accepted"), false);
const invitation = parseSandIamInvitations({
  data: [
    {
      id: 4,
      application_id: 3,
      target_type: "email",
      target_masked: "zh***@example.com",
      guest_identity_name: "",
      initial_group_names: ["法务组"],
      state: "pending",
      expire_time: "2026-08-24 10:00:00",
      delivery_error_code: null,
      status: 1,
      token: "must-not-surface",
      token_hash: "must-not-surface",
      encrypted_target: "must-not-surface",
    },
  ],
})[0];
assert.equal(invitation?.target_masked, "zh***@example.com");
assert.equal(invitation !== undefined && "token" in invitation, false);
assert.equal(invitation !== undefined && "encrypted_target" in invitation, false);
assert.equal(
  parseInvitationAcceptResult({ id: 88, display_name: "张律师" })?.displayName,
  "张律师",
);
assert.equal(
  parseInvitationAcceptResult({ id: 88, display_name: "张律师" }) !== null &&
    "id" in (parseInvitationAcceptResult({ id: 88, display_name: "张律师" }) ?? {}),
  false,
);
assert.equal(invitationTokenLooksValid("not-a-token"), false);
assert.match(sandIamImportTemplateCsv(), /^用户名,显示名称,邮箱,手机号,账号状态,用户组代码\n/);
assert.equal(
  parseSandIamImportPreview({
    id: 6,
    digest: "abc",
    total: 3,
    valid: 2,
    invalid: 1,
    encrypted_payload: "no",
  }) !== null &&
    "encrypted_payload" in
      (parseSandIamImportPreview({
        id: 6,
        digest: "abc",
        total: 3,
        valid: 2,
        invalid: 1,
        encrypted_payload: "no",
      }) ?? {}),
  false,
);
const importJob = parseSandIamImportJobs({
  data: [
    {
      id: 6,
      application_id: 3,
      original_name: "users.csv",
      content_digest: "abc",
      mode: "create",
      state: "previewed",
      total_count: 3,
      valid_count: 2,
      invalid_count: 1,
      success_count: 0,
      warning_count: 0,
      failure_count: 0,
    },
  ],
})[0];
assert.equal(importJob?.original_name, "users.csv");
assert.equal(importJob !== undefined && importJobCanConfirm(importJob), false);
assert.equal(
  sandIamTaskPaths["people-access"].steps.find((step) => step.key === "identity-import")
    ?.path,
  "/sand-iam/identity-import",
);
assert.equal(
  sandIamTaskPaths["people-access"].steps.find((step) => step.key === "sync-connector")
    ?.path,
  "/sand-iam/sync-connector",
);
const syncConnector = parseSandIamSyncConnectors({
  data: [
    {
      id: 2,
      application_id: 3,
      code: "hr-db",
      name: "人事库",
      direction: "inbound",
      driver_code: "postgresql",
      conflict_policy: "manual",
      missing_protection_hours: 24,
      disable_threshold_percent: 20,
      config_version: 1,
      config_configured: true,
      cursor_configured: true,
      last_sync_time: "2026-08-23 10:00:00",
      status: 1,
      encrypted_config: "must-not-surface",
    },
  ],
})[0];
assert.equal(syncConnector?.name, "人事库");
assert.equal(syncConnector !== undefined && "encrypted_config" in syncConnector, false);
assert.equal(syncConfigLabel(true, 1), "已配置（版本 1）");
const failedOutbox = parseSandIamSyncOutboxRows({
  data: [
    {
      id: 11,
      sync_connector_id: 2,
      application_id: 3,
      identity_id: 8,
      event_id: "sync-event-0001",
      operation: "update",
      state: "failed",
      attempt_count: 3,
      error_code: "SAND_IAM_SYNC_OUTBOUND_NOT_ACCEPTED",
      delivered_time: null,
      create_time: "2026-09-12 08:00:00",
      update_time: "2026-09-12 08:10:00",
      encrypted_payload: "must-not-surface",
      payload: { secret: "must-not-surface" },
      ciphertext: "must-not-surface",
    },
    {
      id: 12,
      event_id: "sync-event-pending",
      operation: "create",
      state: "pending",
      attempt_count: 0,
    },
  ],
});
assert.equal(failedOutbox.length, 2);
assert.equal(failedOutbox[0]?.event_id, "sync-event-0001");
assert.equal(failedOutbox[0]?.operation, "update");
assert.equal(failedOutbox[0]?.state, "failed");
assert.equal(failedOutbox[0]?.attempt_count, 3);
assert.equal(failedOutbox[0]?.error_code, "SAND_IAM_SYNC_OUTBOUND_NOT_ACCEPTED");
assert.equal(failedOutbox[0]?.time, "2026-09-12 08:10:00");
assert.equal(failedOutbox[0] !== undefined && "encrypted_payload" in failedOutbox[0], false);
assert.equal(failedOutbox[0] !== undefined && "payload" in failedOutbox[0], false);
assert.equal(failedOutbox[0] !== undefined && "ciphertext" in failedOutbox[0], false);
assert.equal(
  parseSandIamSyncOutboxRows({
    data: [{ id: 13, event_id: "bad", operation: "unknown", state: "failed", attempt_count: 1 }],
  }).length,
  0,
);

assert.equal(
  describeRegistrationTokenIssueError("用途", "https://app.example.com", "openid", 24, 1)
    ?.includes("完整 URL") ?? false,
  true,
);
assert.equal(
  parseRegistrationTokenRows({
    data: [
      {
        id: 9,
        application_id: 3,
        name: "移动端动态注册",
        allowed_redirect_hosts: ["app.example.com"],
        allowed_scopes: ["openid"],
        remaining_uses: 1,
        expire_time: "2026-08-24 12:00:00",
        status: 1,
        token: "siam_dcr_must-not-surface",
        token_hash: "hidden",
      },
    ],
  })[0]?.name,
  "移动端动态注册",
);
assert.equal(
  parseRegistrationTokenRows({
    data: [
      {
        id: 9,
        application_id: 3,
        name: "移动端动态注册",
        allowed_redirect_hosts: ["app.example.com"],
        allowed_scopes: ["openid"],
        remaining_uses: 1,
        expire_time: "2026-08-24 12:00:00",
        status: 1,
        token: "siam_dcr_must-not-surface",
      },
    ],
  })[0] !== undefined &&
    "token" in
      (parseRegistrationTokenRows({
        data: [
          {
            id: 9,
            application_id: 3,
            name: "移动端动态注册",
            allowed_redirect_hosts: ["app.example.com"],
            allowed_scopes: ["openid"],
            remaining_uses: 1,
            expire_time: "2026-08-24 12:00:00",
            status: 1,
            token: "siam_dcr_must-not-surface",
          },
        ],
      })[0] ?? {}),
  false,
);
assert.equal(parseRegistrationTokenIssue({ id: 1, expire_time: "x", max_uses: 1 }), null);
assert.equal(
  parseCasInteraction({
    organization_code: "demo-organization",
    application_code: "demo-application",
    application_id: 3,
    application_name: "律序",
    service_name: "案件系统",
    service_url: "https://app.example.com/cas/callback",
    expires_in: 120,
  })?.applicationName,
  "律序",
);
assert.equal(
  parseCasConfirm({ redirect_uri: "https://app.example.com/cas/callback?ticket=ST-x" })
    ?.redirectUri.includes("ticket=") ?? false,
  true,
);
assert.equal(casRequestLooksValid("not-a-request"), false);
assert.equal(
  parseRadiusNasRows({
    data: [
      {
        id: 4,
        application_id: 3,
        name: "办公区交换机",
        source_cidr: "10.20.0.0/24",
        secret_configured: true,
        accounting_enabled: false,
        status: 1,
        encrypted_shared_secret: "must-not-surface",
        secret_version: "v1",
      },
    ],
  })[0]?.secret_configured,
  true,
);
assert.equal(
  parseRadiusNasRows({
    data: [
      {
        id: 4,
        application_id: 3,
        name: "办公区交换机",
        source_cidr: "10.20.0.0/24",
        secret_configured: true,
        accounting_enabled: false,
        status: 1,
        encrypted_shared_secret: "must-not-surface",
      },
    ],
  })[0] !== undefined &&
    "encrypted_shared_secret" in
      (parseRadiusNasRows({
        data: [
          {
            id: 4,
            application_id: 3,
            name: "办公区交换机",
            source_cidr: "10.20.0.0/24",
            secret_configured: true,
            accounting_enabled: false,
            status: 1,
            encrypted_shared_secret: "must-not-surface",
          },
        ],
      })[0] ?? {}),
  false,
);
assert.equal(
  parseSecurityAlertRows({
    data: [
      {
        id: 7,
        organization_id: 1,
        application_id: 3,
        rule_code: "login.failure.burst",
        severity: "high",
        occurrence_count: 8,
        first_seen_time: "2026-08-23 10:00:00",
        last_seen_time: "2026-08-23 11:00:00",
        status: "open",
        fingerprint: "must-not-surface",
      },
    ],
  })[0] !== undefined &&
    "fingerprint" in
      (parseSecurityAlertRows({
        data: [
          {
            id: 7,
            organization_id: 1,
            application_id: 3,
            rule_code: "login.failure.burst",
            severity: "high",
            occurrence_count: 8,
            first_seen_time: "2026-08-23 10:00:00",
            last_seen_time: "2026-08-23 11:00:00",
            status: "open",
            fingerprint: "must-not-surface",
          },
        ],
      })[0] ?? {}),
  false,
);
const initPreview = parseInitializationPreview({
  preview_hash: "abc",
  package_hash: "def",
  organization_id: 1,
  application_id: 3,
  changes: [
    {
      object_type: "role",
      object_key: "lawyer",
      operation: "create",
      after: { name: "律师" },
      before: null,
    },
  ],
  counts: { create: 1, update: 0, no_change: 0 },
  warnings: ["初始化包采用合并模式"],
  manifest: { format: "must-not-surface" },
});
assert.equal(initPreview?.changes[0]?.object_name, "律师");
assert.equal(initPreview !== null && "manifest" in initPreview, false);
assert.equal(
  describeFederationConfigError(
    "kerberos",
    {
      service_principal: "HTTP/app.example.com@EXAMPLE.COM",
      keytab_ref: "prod-http-keytab",
      allowed_realms: ["EXAMPLE.COM"],
      require_channel_binding: true,
      require_replay_cache: true,
      require_mutual_auth: true,
    },
    { subject: "sub" },
    "reject",
  ),
  null,
);
assert.equal(
  sandIamTaskPaths["auth-session"].steps.find((step) => step.key === "radius-nas")?.path,
  "/sand-iam/radius-nas",
);
assert.equal(
  sandIamTaskPaths["audit-troubleshooting"].steps.find((step) => step.key === "initialization")
    ?.path,
  "/sand-iam/initialization",
);


console.log("SandIAM helper contracts passed (not browser behavior)");
