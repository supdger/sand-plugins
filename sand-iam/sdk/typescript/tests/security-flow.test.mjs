import assert from 'node:assert/strict'
import { SandIamClient, SandIamError } from '../dist/index.js'

const widget = { kind: 'turnstile', site_key: 'public-key', action: 'login', application_binding: 'binding' }
{
  let calls = 0
  let status = 200
  let data = { id: 42, display_name: '访客', access_token: 'must-not-return' }
  const input = { token: 'invite-token', username: 'new-user', password: 'password', displayName: '访客', requestId: 'invite-request' }
  const client = new SandIamClient({ baseUrl: 'https://iam.example.test/prefix', organizationCode: 'example', applicationCode: 'business',
    accessToken: () => { throw new Error('invitation read session') },
    fetch: async (url, init) => {
      calls++
      assert.equal(url, 'https://iam.example.test/prefix/api/sand-iam/v1/invitations/accept')
      assert.equal(init.method, 'POST')
      assert.equal(init.headers.Authorization, undefined)
      assert.equal(init.headers['Cache-Control'], 'no-store')
      assert.equal(init.headers['X-Request-Id'], 'invite-request')
      assert.deepEqual(JSON.parse(init.body), { token: 'invite-token', username: 'new-user', password: 'password', display_name: '访客' })
      return new Response(JSON.stringify({ data, msg: 'SAND_IAM_INVITATION_EXPIRED' }), { status })
    } })
  assert.deepEqual(await client.acceptInvitation(input), { id: 42, display_name: '访客' })
  for (data of [null, [], { id: 0, display_name: 'x' }, { id: '42', display_name: 'x' }, { id: 42 }]) {
    await assert.rejects(client.acceptInvitation(input), error => error.code === 'SAND_IAM_SDK_INVALID_RESPONSE')
  }
  const before = calls
  status = 410
  await assert.rejects(client.acceptInvitation(input), error => error.code === 'SAND_IAM_INVITATION_EXPIRED' && error.status === 410)
  for (const field of ['token', 'username', 'password']) {
    await assert.rejects(client.acceptInvitation({ ...input, [field]: '' }), error => error.code === 'SAND_IAM_SDK_INVALID_ARGUMENT')
  }
  assert.equal(calls, before + 1)
}
const make = (fetch, accessToken = () => 'session') => new SandIamClient({
  baseUrl: 'https://iam.example.test', organizationCode: 'org-one', applicationCode: 'app-two', accessToken, fetch,
})
for (const action of ['login', 'register']) {
  for (const data of [{ required: false }, { required: true, available: false },
    { required: true, available: true, widget: { ...widget, action } }]) {
    let count = 0
    const client = make(async (url, init) => {
      count++
      const parsed = new URL(url)
      assert.equal(parsed.pathname, '/api/sand-iam/v1/auth/captcha/config')
      assert.deepEqual(Object.fromEntries(parsed.searchParams), { organization_code: 'org-one', application_code: 'app-two', action })
      assert.equal(init.method, 'GET')
      assert.equal(init.body, undefined)
      assert.equal(init.headers.Authorization, undefined)
      assert.equal(init.headers['X-Request-Id'], 'security-request')
      assert.equal(init.headers['Cache-Control'], 'no-store')
      return new Response(JSON.stringify({ data }), { status: 200 })
    }, () => { throw new Error('captcha read session') })
    assert.deepEqual(await client.captchaConfiguration(action, 'security-request'), data)
    assert.equal(count, 1)
  }
}
const operations = [
  ['stepUpPassword', ['password', 'security-request'], '/auth/step-up/password', { password: 'password' }, { step_up: true, expires_in: 300 }],
  ['startMfaStepUp', ['security-request'], '/auth/step-up/mfa/start', {},
    { mfa_required: true, challenge_token: 'challenge', methods: ['totp', 'passkey'], expires_in: 300, public_key: { challenge: 'encoded' } }],
  ['unlinkFederation', [42, 'security-request'], '/auth/federation/unlink', { binding_id: 42 }, 'unlinked'],
]
for (const [method, args, path, body, data] of operations) {
  let count = 0
  const client = make(async (url, init) => {
    count++
    assert.equal(url, `https://iam.example.test/api/sand-iam/v1${path}`)
    assert.equal(init.method, 'POST')
    assert.equal(init.headers.Authorization, 'Bearer session')
    assert.equal(init.headers['X-Request-Id'], 'security-request')
    assert.deepEqual(JSON.parse(init.body), body)
    return new Response(JSON.stringify({ data }), { status: 200 })
  })
  const result = await client[method](...args)
  if (method === 'unlinkFederation') assert.equal(result, undefined)
  else {
    for (const [key, value] of Object.entries(data)) assert.deepEqual(result[key], value)
    assert.equal(result.access_token, undefined, 'upgrade must not manufacture login')
  }
  assert.equal(count, 1)
  const noSession = make(async () => { throw new Error('unauthenticated request sent') }, () => '')
  await assert.rejects(noSession[method](...args), error => error instanceof SandIamError && error.status === 401)
}
for (const [method, args] of [['captchaConfiguration', ['login']], ...operations]) {
  let count = 0
  const client = make(async () => {
    count++
    return new Response(JSON.stringify({ msg: 'SAND_IAM_STEP_UP_REQUIRED' }), { status: 403 })
  })
  await assert.rejects(client[method](...args), error => error instanceof SandIamError && error.status === 403)
  assert.equal(count, 1, 'failure must not trigger upgrade or retry')
}
const neverSend = make(async () => { throw new Error('invalid input sent') })
for (const [method, value] of [['captchaConfiguration', 'reset'], ['stepUpPassword', ''],
  ['unlinkFederation', 0], ['unlinkFederation', -1], ['unlinkFederation', 1.5]]) {
  await assert.rejects(neverSend[method](value), error => error instanceof SandIamError && error.code === 'SAND_IAM_SDK_INVALID_ARGUMENT')
}
for (const data of [null, [], {}, { required: 'false' }, { required: true }, { required: true, available: true },
  ...[{ action: 'register' }, { kind: 'other' }, { site_key: 'x'.repeat(256) }, { application_binding: 'x y' }].map(change =>
    ({ required: true, available: true, widget: { ...widget, ...change } }))]) {
  const client = make(async () => new Response(JSON.stringify({ data }), { status: 200 }))
  await assert.rejects(client.captchaConfiguration('login'), error => error instanceof SandIamError && error.code === 'SAND_IAM_SDK_INVALID_RESPONSE')
}
for (const [method, args] of operations.slice(0, 2)) {
  const client = make(async () => new Response(JSON.stringify({ data: {} }), { status: 200 }))
  await assert.rejects(client[method](...args), error => error instanceof SandIamError && error.code === 'SAND_IAM_SDK_INVALID_RESPONSE')
}
console.log('PASS Captcha branches, session upgrades/unlink, scope, errors and no automatic retries')

let stepUpCalls = 0
const stepUpClient = make(async (url, init) => {
  stepUpCalls++
  const starting = url.endsWith('/step-up/mfa/start')
  assert.equal(init.headers.Authorization, starting ? 'Bearer session' : undefined)
  if (!starting) {
    assert.ok(url.endsWith('/mfa/challenge/verify'))
    assert.deepEqual(JSON.parse(init.body), {
      organization_code: 'org-one', application_code: 'app-two', challenge_token: 'challenge',
      method: 'totp', code: '123456', user_agent: '',
    })
  }
  return new Response(JSON.stringify({ data: starting
    ? { mfa_required: true, challenge_token: 'challenge', methods: ['totp'], expires_in: 300 }
    : { step_up: true, expires_in: 300 } }), { status: 200 })
})
const challenge = await stepUpClient.startMfaStepUp('security-request')
const upgraded = await stepUpClient.verifyMfaChallenge({
  challengeToken: challenge.challenge_token, method: 'totp', code: '123456', requestId: 'verify-request',
})
assert.equal(upgraded.step_up, true)
assert.equal(upgraded.expires_in, 300)
assert.equal(upgraded.access_token, undefined)
assert.equal(stepUpCalls, 2)
