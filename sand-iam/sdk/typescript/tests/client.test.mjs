import { SandIamClient, SandIamDeniedError, SandIamError, SandIamCredentialResult, SandIamManagementClient, SandIamManagementError } from '../dist/index.js'
import './mfa.test.mjs'
import './passkey.test.mjs'
import './security-flow.test.mjs'

function assert(condition, message) {
  if (!condition) throw new Error(message)
}

for (const status of [301, 302, 303, 307, 308]) {
  for (const management of [false, true]) {
    let requests = 0
    const fetch = async (url, init) => {
      requests++
      assert(init.redirect === 'error', 'SDK transport may follow credential redirects')
      assert(init.headers.Authorization === 'Bearer fixture-token', 'original credential header changed')
      assert(!url.includes('fixture-token'), 'credential leaked into URL')
      return new Response(JSON.stringify({ msg: 'SAND_IAM_REDIRECT_REJECTED', data: {} }), {
        status, headers: { Location: 'https://external.example.test/collect' },
      })
    }
    let rejected = false
    try {
      if (management) {
        await new SandIamManagementClient({
          baseUrl: 'https://iam.example.test', administratorToken: () => 'fixture-token', fetch,
        }).onboardingPreview({ operation_id: 'redirect-test' }, 'redirect-request-01')
      } else {
        await new SandIamClient({
          baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
          accessToken: () => 'fixture-token', fetch,
        }).profile()
      }
    } catch (error) {
      rejected = error instanceof SandIamError || error instanceof SandIamManagementError
    }
    assert(rejected && requests === 1, `redirect ${status} accepted or retried`)
  }
}

for (const method of ['requestVerification', 'confirmVerification']) {
  for (const channel of ['email', 'phone']) {
    let calls = 0
    const client = new SandIamClient({
      baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
      accessToken: () => { throw new Error('verification must not read session') },
      fetch: async (url, init) => {
        calls++
        assert(url.endsWith(`/auth/verification/${method === 'requestVerification' ? 'request' : 'confirm'}`), 'verification path')
        assert(init.method === 'POST' && init.headers.Authorization === undefined, 'anonymous verification')
        assert(init.headers['X-Request-Id'] === 'verify-request', 'verification request ID')
        const body = JSON.parse(init.body)
        assert(body.organization_code === 'sand' && body.application_code === 'app', 'verification scope fixed')
        assert(body.identifier === 'alice' && body.channel === channel, 'verification account')
        assert(body.purpose === (channel === 'email' ? 'email_verify' : 'phone_verify'), 'purpose derived, not overridable')
        assert(!('_password_reset_endpoint' in body), 'private flags not forwarded')
        assert(method === 'requestVerification' ? !('code' in body) : body.code === '123456', 'confirmation code')
        return new Response(JSON.stringify({ code: 200, data: '通用成功消息' }), { status: 200 })
      },
    })
    const result = await client[method]({ identifier: 'alice', channel, code: '123456',
      requestId: 'verify-request', purpose: 'password_reset', application_code: 'forged',
      organization_code: 'forged', _password_reset_endpoint: true })
    assert(result === undefined && calls === 1, 'verification void and no login')
  }
  let calls = 0
  const client = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app', accessToken: () => '',
    fetch: async () => { calls++; return new Response(JSON.stringify({ code: 400, msg: 'SAND_IAM_AUTH_VERIFICATION_INVALID' }), { status: 400 }) },
  })
  for (const channel of ['sms', 'email']) {
    let rejected = false
    try { await client[method]({ identifier: 'alice', channel, code: '123456' }) }
    catch (error) { rejected = error instanceof SandIamError && error.status === (channel === 'sms' ? 0 : 400) }
    assert(rejected, 'verification validation/error propagation')
  }
  assert(calls === 1, 'invalid verification input must not send')
}

for (const channel of ['email', 'phone']) {
  for (const method of ['forgotPassword', 'resetPassword']) {
    let calls = 0
    const client = new SandIamClient({
      baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
      accessToken: () => { throw new Error('Recovery must not read session') },
      fetch: async (url, init) => {
        calls++
        assert(url.endsWith(`/auth/password/${method === 'forgotPassword' ? 'forgot' : 'reset'}`), 'recovery endpoint')
        assert(init.method === 'POST' && init.headers.Authorization === undefined, 'anonymous recovery')
        assert(init.headers['X-Request-Id'] === 'recovery-request', 'recovery request id')
        const body = JSON.parse(init.body)
        assert(body.identifier === 'alice' && body.channel === channel, 'recovery identity/channel')
        assert(body.organization_code === 'sand' && body.application_code === 'app', 'fixed recovery scope')
        assert(!('new_password' in body), 'reset does not use change-password field')
        assert(method === 'forgotPassword' ? !('password' in body) && !('code' in body)
          : body.password === 'new-password' && body.code === '123456', 'recovery proof fields')
        return new Response(JSON.stringify({ code: 200, data: '通用成功消息' }), { status: 200 })
      },
    })
    const result = await client[method]({ identifier: 'alice', channel, code: '123456',
      password: 'new-password', requestId: 'recovery-request',
      organization_code: 'forged', application_code: 'forged' })
    assert(result === undefined && calls === 1, 'recovery returns void with no auto-login')
  }
}
for (const method of ['forgotPassword', 'resetPassword']) {
  let calls = 0
  const client = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app', accessToken: () => '',
    fetch: async () => { calls++; return new Response(JSON.stringify({ code: 400, msg: 'SAND_IAM_AUTH_VERIFICATION_INVALID' }), { status: 400 }) },
  })
  for (const channel of ['sms', 'email']) {
    let rejected = false
    try { await client[method]({ identifier: 'alice', channel, code: '123456', password: 'new-password' }) }
    catch (error) { rejected = error instanceof SandIamError && error.status === (channel === 'sms' ? 0 : 400) }
    assert(rejected, 'recovery validation/backend errors propagate')
  }
  assert(calls === 1, 'invalid channel must not send')
}

for (const method of ['totp', 'recovery_code', 'passkey']) {
  const response = { clientDataJSON: 'client', authenticatorData: 'auth', signature: 'signature', userHandle: 'handle' }
  const client = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
    accessToken: () => { throw new Error('MFA must not read session token') },
    fetch: async (url, init) => {
      assert(url === 'https://iam.example.test/api/sand-iam/v1/auth/mfa/challenge/verify', 'MFA route')
      assert(init.method === 'POST' && init.headers.Authorization === undefined, 'MFA anonymous POST')
      assert(init.headers['X-Request-Id'] === 'mfa-request', 'MFA request id')
      const body = JSON.parse(init.body)
      assert(body.organization_code === 'sand' && body.application_code === 'app', 'MFA scope cannot be overridden')
      assert(body.challenge_token === 'challenge' && body.method === method, 'MFA challenge')
      if (method === 'passkey') {
        assert(body.rawId === 'credential' && JSON.stringify(body.response) === JSON.stringify(response) && !('code' in body), 'MFA passkey fields')
      } else assert(body.code === '123456' && !('response' in body), 'MFA code fields')
      return new Response(JSON.stringify({ code: 200, data: method === 'passkey'
        ? { step_up: true, expires_in: 300 } : { access_token: 'session', session_id: 9 } }), { status: 200 })
    },
  })
  const result = await client.verifyMfaChallenge({
    challengeToken: 'challenge', method, code: '123456', rawId: 'credential', response,
    requestId: 'mfa-request', organization_code: 'forged', application_code: 'forged',
  })
  assert(method === 'passkey' ? result.step_up === true && result.expires_in === 300 && result.access_token === undefined
    : result.access_token === 'session' && result.step_up === undefined, 'MFA result kind retained')
}
{
  const client = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app', accessToken: () => '',
    fetch: async () => new Response(JSON.stringify({ code: 401, msg: 'SAND_IAM_MFA_CHALLENGE_INVALID' }), { status: 401 }),
  })
  let rejected = false
  try { await client.verifyMfaChallenge({ challengeToken: 'bad', method: 'totp', code: '123456' }) }
  catch (error) { rejected = error instanceof SandIamError && error.status === 401 }
  assert(rejected, 'MFA errors must propagate')
}

function clients(baseUrl) {
  return [
    () => new SandIamClient({ baseUrl, organizationCode: 'sand', applicationCode: 'app', accessToken: () => 'token' }),
    () => new SandIamManagementClient({ baseUrl, administratorToken: () => 'token' }),
  ]
}
for (const baseUrl of [
  'http://localhost.example.com', 'http://127.0.0.1.example.com',
  'http://localhost@remote.example.com', 'http://127.0.0.12',
  'https://user:password@iam.example.com', 'https://iam.example.com?target=x',
  'https://iam.example.com#fragment', 'https://', 'https://iam.example.com\n',
  'http://localhost\\@remote.example.com',
  'http:localhost', 'http:/localhost', 'https:iam.example.com',
]) {
  for (const construct of clients(baseUrl)) {
    let rejected = false
    try { construct() } catch { rejected = true }
    assert(rejected, `unsafe SDK base URL accepted: ${baseUrl}`)
  }
}
for (const baseUrl of ['https://iam.example.com/prefix', 'http://localhost:8080', 'http://127.0.0.1:8080', 'http://[::1]:8080']) {
  for (const construct of clients(baseUrl)) construct()
}
// The browser runtime SDK also supports same-origin requests.
clients('')[0]()

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

const captchaRequests = []
const mfaChallenge = {
  mfa_required: true, challenge_token: 'challenge', methods: ['totp', 'recovery_code', 'passkey'],
  expires_in: 300, public_key: { challenge: 'encoded-challenge', rpId: 'example.test', allowCredentials: [] },
}
async function mfaLogin(data) {
  return new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
    accessToken: () => '',
    fetch: async () => new Response(JSON.stringify({ code: 200, data }), { status: 200 }),
  }).login({ identifier: 'user', password: 'password' })
}
const mfaResult = await mfaLogin(mfaChallenge)
assert(JSON.stringify(mfaResult.methods) === JSON.stringify(mfaChallenge.methods), 'MFA methods were lost')
assert(mfaResult.expires_in === 300 && mfaResult.public_key.challenge === 'encoded-challenge', 'MFA challenge details were lost')
assert(mfaResult.access_token === undefined && mfaResult.mfa_required === true, 'MFA challenge became a session')
for (const invalid of [{ methods: 'totp' }, { methods: [1] }, { methods: [''] }, { expires_in: 0 }, { expires_in: '300' }, { public_key: [] }]) {
  let rejected = false
  try { await mfaLogin({ ...mfaChallenge, ...invalid }) } catch (error) {
    rejected = error instanceof SandIamError && error.code === 'SAND_IAM_SDK_INVALID_RESPONSE'
  }
  assert(rejected, `invalid MFA fields accepted: ${JSON.stringify(invalid)}`)
}
const captchaClient = new SandIamClient({
  baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
  accessToken: () => 'existing-session',
  fetch: async (url, init) => {
    captchaRequests.push({ url, init })
    return new Response(JSON.stringify({ code: 200, data: {
      access_token: 'token', refresh_token: 'refresh', identity: { id: 9, display_name: 'User' },
    } }), { status: 200 })
  },
})
await captchaClient.register({ username: 'user', password: 'password', captchaToken: 'register-proof' })
await captchaClient.login({ identifier: 'user', password: 'password' })
for (const [index, request] of captchaRequests.entries()) {
  const body = JSON.parse(request.init.body)
  assert(body.captcha_token === ['register-proof', ''][index], 'captcha registration or optional login is incorrect')
  assert(body.organization_code === 'sand' && body.application_code === 'app', 'captcha request scope changed')
  assert(request.init.headers.Authorization === undefined && !request.url.includes('?'), 'captcha used auth header or URL')
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
const login = await authClient.login({ identifier: 'lawyer@example.test', password: 'secret-password', requestId: 'sdk-login', captchaToken: 'captcha-login-proof' })
assert(login.access_token === 'siam_at_login', 'login response was not returned')
assert(authRequests[0].init.method === 'POST' && authRequests[0].init.headers.Authorization === undefined, 'login unexpectedly used an application session')
const loginBody = JSON.parse(authRequests[0].init.body)
assert(loginBody.captcha_token === 'captcha-login-proof', 'login captcha token was omitted')
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
const action96 = 'a'.repeat(96)
await workloadClient.issueContext({ credential: 'siam_wc_secret', serviceCode: 'sand-ai', audience: 'sand-ai', actions: [action96], requestId: 'workload-action-96' })
assert(JSON.parse(workloadRequests[1].init.body).actions[0] === action96, '96-character workload action was not accepted')
let action97Rejected = false
try {
  await workloadClient.issueContext({ credential: 'siam_wc_secret', serviceCode: 'sand-ai', audience: 'sand-ai', actions: ['a'.repeat(97)], requestId: 'workload-action-97' })
} catch (error) {
  action97Rejected = error instanceof SandIamError && error.code === 'SAND_IAM_SDK_INVALID_ARGUMENT'
}
assert(action97Rejected, '97-character workload action was not rejected with the stable SDK error')
const claims = await workloadClient.verifyContext({ context: 'signed-context', serviceCode: 'sand-ai', audience: 'sand-ai', actions: ['inference.chat', 'inference.embed'], sourceIp: '127.0.0.1', requestId: 'workload-verify-1' })
assert(claims.context_id === 'ctx-1' && workloadRequests.length === 4, 'verify did not check every requested action')
for (const request of workloadRequests.slice(2)) {
  const body = JSON.parse(request.init.body)
  assert(request.init.headers.Authorization === undefined && body.source_ip === undefined, 'verify must not send caller source IP or context as Bearer')
}

const managementRequests = []
const routePreviewHash = 'a'.repeat(64)
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
        : path.endsWith('/developer/route-manifest/preview')
          ? { preview_hash: routePreviewHash }
        : { preview_hash: 'preview-1', route_sync: { created: 1 } }
    return new Response(JSON.stringify({ code: 200, data }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  },
})
const routeManifest = { format: 'sand-iam.route-sync/v1', organization_code: 'sand', application_code: 'app', environment_code: 'production', routes: [] }
const routePreview = await managementClient.routeSyncPreview(routeManifest, true, 'management-preview-1')
assert(routePreview.preview_hash === routePreviewHash, 'route sync preview must use the route-manifest preview endpoint')
const routeApply = await managementClient.routeSyncApply({ manifest: routeManifest, previewHash: routePreviewHash, requestId: 'management-apply-1', disableMissing: true })
assert(routeApply.route_sync.created === 1, 'route sync apply must use the route-manifest apply endpoint')
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
