import assert from 'node:assert/strict'
import { SandIamClient, SandIamError } from '../dist/index.js'

const attestation = { clientDataJSON: 'client', attestationObject: 'attestation' }
const assertion = { clientDataJSON: 'client', authenticatorData: 'auth', signature: 'sig', userHandle: 'user' }
const calls = [
  ['passkeyRegistrationOptions', 'registration/options', { name: 'Laptop', currentPassword: 'password', requestId: 'pk' },
    { name: 'Laptop', current_password: 'password' }, { challenge_token: 'challenge', public_key: { challenge: 'encoded' } }],
  ['passkeyRegistrationFinish', 'registration/finish', { challengeToken: 'challenge', rawId: 'raw', response: attestation, requestId: 'pk' },
    { challenge_token: 'challenge', rawId: 'raw', response: attestation }, 'registered'],
  ['passkeyAuthenticationOptions', 'authentication/options', 'pk',
    { organization_code: 'org', application_code: 'app' }, { challenge_token: 'challenge', public_key: { challenge: 'encoded' } }],
  ['passkeyAuthenticationFinish', 'authentication/finish', { challengeToken: 'challenge', rawId: 'raw', response: assertion, userAgent: 'platform', requestId: 'pk',
    organization_code: 'forged', application_code: 'forged' },
    { organization_code: 'org', application_code: 'app', challenge_token: 'challenge', rawId: 'raw', response: assertion, user_agent: 'platform' },
    { access_token: 'session', session_id: 42 }],
]
for (const [method, path, input, body, result] of calls) {
  let requests = 0
  let tokenReads = 0
  const client = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app',
    accessToken: () => { tokenReads++; return 'existing-session' },
    fetch: async (url, init) => {
      requests++
      assert.equal(url, `https://iam.example.test/api/sand-iam/v1/auth/passkeys/${path}`)
      assert.equal(init.method, 'POST')
      assert.equal(init.headers['X-Request-Id'], 'pk')
      assert.equal(init.headers.Authorization, path.startsWith('registration') ? 'Bearer existing-session' : undefined)
      assert.deepEqual(JSON.parse(init.body), body)
      return new Response(JSON.stringify({ code: 200, data: result }), { status: 200 })
    },
  })
  const value = await client[method](input)
  if (method === 'passkeyRegistrationFinish') assert.equal(value, undefined)
  else if (method.endsWith('Options')) assert.deepEqual(value, result)
  else { assert.equal(value.access_token, 'session'); assert.equal(value.session_id, 42) }
  assert.equal(requests, 1)
  assert.equal(tokenReads, path.startsWith('registration') ? 1 : 0)
}
for (const [method, , input] of calls) {
  let requests = 0
  const client = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app', accessToken: () => 'session',
    fetch: async () => { requests++; return new Response(JSON.stringify({ msg: 'SAND_IAM_PASSKEY_INVALID' }), { status: 400 }) },
  })
  await assert.rejects(client[method](input), error => error instanceof SandIamError && error.status === 400)
  assert.equal(requests, 1, 'service failure must not retry')
}
let requests = 0
const invalidClient = new SandIamClient({
  baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app', accessToken: () => '',
  fetch: async () => { requests++; throw new Error('invalid request sent') },
})
for (const [method, input] of [
  ['passkeyRegistrationOptions', { currentPassword: '' }],
  ['passkeyRegistrationOptions', { currentPassword: 'password' }],
  ['passkeyRegistrationFinish', { challengeToken: 'c', rawId: 'r', response: attestation }],
  ['passkeyRegistrationFinish', { challengeToken: '', rawId: 'r', response: attestation }],
  ['passkeyRegistrationFinish', { challengeToken: 'c', rawId: 'r', response: { clientDataJSON: 'c' } }],
  ['passkeyAuthenticationFinish', { challengeToken: 'c', rawId: 'r', response: { ...assertion, userHandle: '' } }],
]) await assert.rejects(invalidClient[method](input), SandIamError)
assert.equal(requests, 0)
for (const method of ['passkeyRegistrationOptions', 'passkeyAuthenticationOptions']) {
  for (const data of [null, [], {}, { challenge_token: 'c', public_key: [] }, { challenge_token: ' ', public_key: {} }]) {
    const client = new SandIamClient({
      baseUrl: 'https://iam.example.test', organizationCode: 'org', applicationCode: 'app', accessToken: () => 'session',
      fetch: async () => new Response(JSON.stringify({ code: 200, data }), { status: 200 }),
    })
    await assert.rejects(client[method](method.startsWith('passkeyRegistration') ? { currentPassword: 'p' } : undefined),
      error => error instanceof SandIamError && error.code === 'SAND_IAM_SDK_INVALID_RESPONSE')
  }
}
console.log('PASS Passkey four calls, session boundary, exact scope/proofs, invalid input/results and no retry')
