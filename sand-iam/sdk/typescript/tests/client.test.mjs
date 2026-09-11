import { SandIamClient, SandIamDeniedError, SandIamError, SandIamCredentialResult, SandIamManagementClient, SandIamManagementError } from '../dist/index.js'

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

function decision(allowed) {
  return {
    allowed,
    code: allowed ? 'allowed' : 'SAND_IAM_POLICY_DENIED',
    policy_ids: allowed ? [12] : [],
    scope: { equals: { organization_id: 42 } },
    application_id: 3,
    identity_id: 101,
    api_code: 'matter.detail',
    api_version: 'v1',
    resource_code: 'matter',
    action: 'matter.read',
    operation: 'read',
    risk_level: 'medium',
  }
}

let capturedRequest
const allowClient = new SandIamClient({
  baseUrl: 'https://iam.example.test/',
  organizationCode: 'sand',
  applicationCode: 'lawyer',
  accessToken: () => 'siam_at_test',
  fetch: async (url, init) => {
    capturedRequest = { url, init }
    return new Response(JSON.stringify({ code: 200, data: decision(true) }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  },
})
const allowed = await allowClient.authorize({ apiCode: 'matter.detail', requestId: 'request-1' })
assert(allowed.allowed && allowed.scope.equals.organization_id === 42, 'allow decision was not returned')
assert(capturedRequest.url.endsWith('/api/sand-iam/v1/authorization/decide'), 'decision URL is incorrect')
assert(capturedRequest.init.headers.Authorization === 'Bearer siam_at_test', 'access token header missing')
assert(capturedRequest.init.headers['X-Request-Id'] === 'request-1', 'request ID missing')

const denyClient = new SandIamClient({
  baseUrl: 'https://iam.example.test',
  organizationCode: 'sand',
  applicationCode: 'lawyer',
  accessToken: () => 'siam_at_test',
  fetch: async () => new Response(JSON.stringify({ code: 200, data: decision(false) }), { status: 200 }),
})
try {
  await denyClient.authorize({ apiCode: 'matter.detail' })
  throw new Error('deny decision did not throw')
} catch (error) {
  assert(error instanceof SandIamDeniedError && error.code === 'SAND_IAM_POLICY_DENIED', 'deny error mapping is incorrect')
}

const errorClient = new SandIamClient({
  baseUrl: 'https://iam.example.test',
  organizationCode: 'sand',
  applicationCode: 'lawyer',
  accessToken: () => 'expired',
  fetch: async () => new Response(JSON.stringify({ msg: 'SAND_IAM_AUTHENTICATION_FAILED: 登录状态已失效' }), { status: 401 }),
})
try {
  await errorClient.decide({ apiCode: 'matter.detail' })
  throw new Error('HTTP error did not throw')
} catch (error) {
  assert(error instanceof SandIamError && error.code === 'SAND_IAM_AUTHENTICATION_FAILED' && error.status === 401, 'HTTP error mapping is incorrect')
}

const authRequests = []
let activeToken = ''
const authClient = new SandIamClient({
  baseUrl: 'https://iam.example.test',
  organizationCode: 'sand',
  applicationCode: 'lawyer',
  accessToken: () => activeToken,
  fetch: async (url, init) => {
    authRequests.push({ url, init })
    const path = new URL(url).pathname
    const data = path === '/api/sand-iam/v1/auth/login'
      ? { access_token: 'siam_at_login', refresh_token: 'siam_rt_login', identity: { id: 9, display_name: '测试用户' } }
      : path === '/api/sand-iam/v1/me/profile'
        ? { identity_id: 9, display_name: '测试用户', organization: { code: 'sand', name: 'Sand' }, application: { code: 'lawyer', name: '律序' }, create_time: null }
        : path === '/api/sand-iam/v1/auth/sessions'
          ? [{ id: 20, current: true, create_time: null, last_used_time: null, access_expire_time: null, refresh_expire_time: null }]
          : 'ok'
    return new Response(JSON.stringify({ code: 200, data }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  },
})
const login = await authClient.login({ identifier: 'lawyer@example.test', password: 'secret-password', requestId: 'sdk-login' })
assert(login.access_token === 'siam_at_login', 'login response was not returned')
assert(authRequests[0].init.method === 'POST' && authRequests[0].init.headers.Authorization === undefined, 'login unexpectedly used an application session')
const loginBody = JSON.parse(authRequests[0].init.body)
assert(loginBody.organization_code === 'sand' && loginBody.application_code === 'lawyer', 'login did not bind the configured application')
activeToken = login.access_token
const profile = await authClient.profile('sdk-profile')
assert(profile.display_name === '测试用户' && authRequests[1].init.method === 'GET' && authRequests[1].init.body === undefined, 'profile request or response is incorrect')
const sessions = await authClient.sessions('sdk-sessions')
assert(sessions.length === 1 && sessions[0].current, 'session list was not returned')
await authClient.changePassword('old-password', 'new-password', 'sdk-password')
const passwordBody = JSON.parse(authRequests[3].init.body)
assert(authRequests[3].init.headers.Authorization === 'Bearer siam_at_login' && passwordBody.current_password === 'old-password', 'password change did not use the authenticated application session')

const workloadRequests = []
const workloadClient = new SandIamClient({
  baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'lawyer', accessToken: () => 'unused',
  fetch: async (url, init) => {
    workloadRequests.push({ url, init })
    const path = new URL(url).pathname
    const data = path.endsWith('/issue')
      ? { context: 'signed-context', context_id: 'ctx-1', expire_time: '2026-08-22 12:00:00' }
      : { context_id: 'ctx-1', service_code: 'sand-ai', audience: 'sand-ai', actions: ['inference.chat', 'inference.embed'] }
    return new Response(JSON.stringify({ code: 200, data }), { status: 200 })
  },
})
const issued = await workloadClient.issueContext({ credential: 'siam_wc_secret', serviceCode: 'sand-ai', audience: 'sand-ai', actions: ['inference.chat'], subjectScope: { case_id: 1 }, requestId: 'workload-issue-1' })
assert(issued.context === 'signed-context', 'workload context was not returned')
const issueBody = JSON.parse(workloadRequests[0].init.body)
assert(workloadRequests[0].init.headers.Authorization === 'Bearer siam_wc_secret' && workloadRequests[0].init.headers['Cache-Control'] === 'no-store', 'credential must be Bearer with no-store')
assert(!JSON.stringify(issueBody).includes('siam_wc_secret') && issueBody.organization_id === undefined && issueBody.application_id === undefined, 'workload body leaked secret or scope identifiers')
const claims = await workloadClient.verifyContext({ context: 'signed-context', serviceCode: 'sand-ai', audience: 'sand-ai', actions: ['inference.chat', 'inference.embed'], sourceIp: '127.0.0.1', requestId: 'workload-verify-1' })
assert(claims.context_id === 'ctx-1' && workloadRequests.length === 3, 'verify did not check every requested action')
for (const request of workloadRequests.slice(1)) {
  const body = JSON.parse(request.init.body)
  assert(request.init.headers.Authorization === undefined && body.source_ip === undefined, 'verify must not send caller source IP or context as Bearer')
}

const managementRequests = []
const managementClient = new SandIamManagementClient({
  baseUrl: 'https://iam.example.test',
  administratorToken: () => 'sandadmin-session-token',
  fetch: async (url, init) => {
    managementRequests.push({ url, init })
    const path = new URL(url).pathname
    const data = path.endsWith('/identity-provider-preset/index')
      ? [{ code: 'github_oauth2', name: 'GitHub OAuth 应用' }]
      : path.endsWith('/credential/issue')
        ? { id: 7, key_prefix: 'siam_wc_', credential: 'one-time-credential' }
        : { preview_hash: 'preview-1', route_sync: { created: 1 } }
    return new Response(JSON.stringify({ code: 200, data }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  },
})
const routePreview = await managementClient.routeSyncPreview({ operation_id: 'route-sync-op-1', route_manifest: { format: 'sand-iam.route-sync/v1' } }, 'management-preview-1')
assert(routePreview.preview_hash === 'preview-1', 'route sync preview must use onboarding preview')
const routeApply = await managementClient.routeSyncApply({ manifest: { operation_id: 'route-sync-op-1', route_manifest: { format: 'sand-iam.route-sync/v1' } }, previewHash: 'preview-1', requestId: 'management-apply-1' })
assert(routeApply.route_sync.created === 1, 'route sync apply must use onboarding apply')
const issuedCredential = await managementClient.credentialIssue({ workloadClientId: 7, name: 'production', requestId: 'credential-issue-1' })
assert(issuedCredential instanceof SandIamCredentialResult && issuedCredential.secretAvailable && issuedCredential.revealSecretOnce() === 'one-time-credential', 'management client did not protect one-time credential handoff')
assert(JSON.stringify(issuedCredential).includes('one-time-credential') === false, 'credential result JSON leaked one-time secret')
try {
  String(issuedCredential)
  throw new Error('credential result unexpectedly stringified')
} catch (error) {
  assert(error instanceof TypeError && error.message.includes('禁止'), 'credential result stringification did not fail closed')
}
const presets = await managementClient.presetList('preset-list-1')
assert(presets[0].code === 'github_oauth2', 'preset list was not returned')
for (const request of managementRequests) {
  assert(request.init.headers.Authorization === 'Bearer sandadmin-session-token' && request.init.headers['Cache-Control'] === 'no-store', 'management API must use administrator Bearer and no-store')
  assert(!String(request.init.body ?? '').includes('sandadmin-session-token'), 'administrator token leaked into management request body')
}
try {
  await managementClient.credentialRevoke({ credentialId: 7, requestId: 'short' })
  throw new Error('credential revoke accepted missing explicit request id')
} catch (error) {
  assert(error instanceof SandIamManagementError && error.code === 'SAND_IAM_SDK_REQUEST_ID_REQUIRED', 'management mutation request ID error is not stable')
}

console.log('SandIAM TypeScript SDK tests passed')
