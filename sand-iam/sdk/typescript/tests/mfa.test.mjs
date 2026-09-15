import assert from 'node:assert/strict'
import { SandIamClient, SandIamError } from '../dist/index.js'

const factor = { id: 4, type: 'totp', name: 'Phone', status: 1, create_time: '2026-09-14', last_used_time: null }
const cases = [
  ['mfaFactors', '/factors', undefined, [factor], null],
  ['startTotp', '/totp/start', { name: 'Phone', currentPassword: 'password' }, { factor_id: 4, secret: 'BASE32', otpauth_uri: 'otpauth://totp/test' }, { name: 'Phone', current_password: 'password' }],
  ['confirmTotp', '/totp/confirm', { factorId: 4, code: '123456' }, { enabled: true, recovery_codes: ['code-1'] }, { factor_id: 4, code: '123456' }],
  ['renameMfaFactor', '/factors/rename', { factorId: 4, type: 'passkey', name: 'New' }, 'renamed', { factor_id: 4, type: 'passkey', name: 'New' }],
  ['revokeMfaFactor', '/factors/revoke', { factorId: 4, type: 'totp', password: 'password' }, 'revoked', { factor_id: 4, type: 'totp', password: 'password' }],
  ['regenerateRecoveryCodes', '/recovery/regenerate', { password: 'password' }, { recovery_codes: ['code-2'] }, { password: 'password' }],
]
for (const [method, path, input, data, body] of cases) {
  let calls = 0
  const client = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app', accessToken: () => 'session',
    fetch: async (url, init) => {
      calls++
      assert.equal(url, `https://iam.example.test/api/sand-iam/v1/auth/mfa${path}`)
      assert.equal(init.method, body === null ? 'GET' : 'POST')
      assert.equal(init.headers.Authorization, 'Bearer session')
      assert.equal(init.headers['X-Request-Id'], 'mfa-management')
      assert.deepEqual(init.body === undefined ? null : JSON.parse(init.body), body)
      return new Response(JSON.stringify({ code: 200, data }), { status: 200 })
    },
  })
  const result = await client[method](input === undefined ? 'mfa-management' : { ...input, requestId: 'mfa-management' })
  assert.deepEqual(result, typeof data === 'string' ? undefined : data)
  assert.equal(calls, 1)
  const denied = new SandIamClient({
    baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app', accessToken: () => '',
    fetch: async () => { throw new Error('must not send unauthenticated MFA management') },
  })
  await assert.rejects(denied[method](input), error => error instanceof SandIamError && error.status === 401)
}
let calls = 0
const client = new SandIamClient({
  baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app', accessToken: () => 'session',
  fetch: async () => { calls++; return new Response('{"msg":"SAND_IAM_MFA_FACTOR_NOT_FOUND"}', { status: 400 }) },
})
for (const input of [{ factorId: 0, type: 'totp', password: 'p' }, { factorId: 1, type: 'other', password: 'p' }]) {
  await assert.rejects(client.revokeMfaFactor(input), error => error instanceof SandIamError && error.status === 0)
}
assert.equal(calls, 0)
await assert.rejects(client.confirmTotp({ factorId: 4, code: 'bad' }), error => error instanceof SandIamError && error.status === 400)
assert.equal(calls, 1, 'errors must not trigger automatic retry')
for (const [method, , input] of cases.filter(([name]) => !['renameMfaFactor', 'revokeMfaFactor'].includes(name))) {
  const malformed = new SandIamClient({ baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
    accessToken: () => 'session', fetch: async () => new Response('{"code":200,"data":{}}', { status: 200 }) })
  await assert.rejects(malformed[method](input), error => error instanceof SandIamError && error.code === 'SAND_IAM_SDK_INVALID_RESPONSE')
}
console.log('TypeScript MFA management offline tests passed')
for (const [method, input, data] of [
  ['startTotp', { currentPassword: 'password' }, { factor_id: 4, secret_available: false }],
  ['confirmTotp', { factorId: 4, code: '123456' }, { enabled: true, secret_available: false }],
  ['regenerateRecoveryCodes', { password: 'password' }, { secret_available: false }],
]) {
  const replay = new SandIamClient({ baseUrl: 'https://iam.example.test', organizationCode: 'sand', applicationCode: 'app',
    accessToken: () => 'session', fetch: async () => new Response(JSON.stringify({ code: 200, data }), { status: 200 }) })
  assert.deepEqual(await replay[method](input), data)
}
