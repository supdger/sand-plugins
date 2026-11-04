/** Pure contract checks for the access-matrix api module; host HTTP is stubbed. */
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'

const adapterUrl = new URL('./fixtures/http-adapter.cjs', import.meta.url).href
registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '@/utils/http') return { url: adapterUrl, shortCircuit: true }
    return nextResolve(specifier, context)
  }
})

async function run(): Promise<void> {
  const {
    LVXU_GRANT_PRESET,
    activeCredential,
    grantActive,
    matrixClientFor,
    parseAccessMatrix,
    parseMatrixGrantResult,
  } = await import('../src/views/plugin/sand-iam/api/accessMatrix')

  // --- full payload parses into the grid model ---
  const payload = {
    organizations: [{ id: 1, code: 'default', name: '本机构' }],
    services: [{
      id: 5,
      code: 'sand_ai',
      name: 'SandAI',
      actions: [
        { id: 50, code: 'sand_ai.chat.complete', name: 'AI 对话' },
        { id: 51, code: 'sand_ai.document_parse', name: '私有文件解析' },
      ],
    }],
    applications: [{
      id: 10,
      organization_id: 1,
      code: 'lvxu',
      name: '律序',
      status: 1,
      environments: [{
        id: 100,
        code: 'prod',
        name: '正式',
        status: 1,
        clients: [{
          id: 200,
          code: 'sand-ai',
          audience: 'sand-ai',
          status: 1,
          grants: [{
            service_action_id: 50,
            action_code: 'sand_ai.chat.complete',
            service_code: 'sand_ai',
            status: 1,
            expire_time: null,
          }],
          credentials: [{
            id: 300,
            name: '首页一键接入',
            key_prefix: 'siam_abcd1234',
            expire_time: null,
            status: 1,
          }],
        }],
      }],
    }],
  }
  const matrix = parseAccessMatrix(payload)
  assert.equal(matrix.organizations.length, 1)
  assert.equal(matrix.services[0].actions.length, 2)
  assert.equal(matrix.applications[0].environments[0].clients[0].grants[0].action_code, 'sand_ai.chat.complete')

  // envelope {code,msg,data} shape also parses
  const enveloped = parseAccessMatrix({ code: 200, msg: 'ok', data: payload })
  assert.equal(enveloped.applications.length, 1)

  // --- helpers ---
  const app = matrix.applications[0]
  const client = matrixClientFor(app)
  assert.equal(client?.id, 200)
  assert.equal(grantActive(client, 50), true)
  assert.equal(grantActive(client, 51), false)
  assert.equal(grantActive(null, 50), false)
  assert.equal(activeCredential(client)?.key_prefix, 'siam_abcd1234')
  assert.equal(activeCredential(null), null)

  // expired grant is not active
  const expired = {
    ...client!,
    grants: [{ ...client!.grants[0], expire_time: '2000-01-01 00:00:00' }],
  }
  assert.equal(grantActive(expired, 50), false)
  // revoked grant is not active
  const revoked = { ...client!, grants: [{ ...client!.grants[0], status: 2 }] }
  assert.equal(grantActive(revoked, 50), false)

  // --- invalid payloads rejected ---
  for (const invalid of [
    null,
    'x',
    { organizations: [{ id: 'x', code: 'a', name: 'b' }] },
    { organizations: [{ id: 1, code: 'a', name: 'b' }], applications: [{ id: 0, organization_id: 1, code: 'a', name: 'b' }] },
  ]) {
    assert.throws(() => parseAccessMatrix(invalid))
  }
  // missing sections are leniently empty (structurally valid)
  assert.deepEqual(parseAccessMatrix({ organizations: 'no' }), { organizations: [], services: [], applications: [] })

  // matrix payload must not carry secret material through parsing
  const withSecret = JSON.parse(JSON.stringify(payload)) as Record<string, unknown>
  ;(((withSecret.applications as unknown[])[0] as Record<string, unknown>).environments as unknown[])[0] = {
    ...((withSecret.applications as unknown[])[0] as { environments: Record<string, unknown>[] }).environments[0],
    clients: [{
      ...matrix.applications[0].environments[0].clients[0],
      credentials: [{ id: 1, name: 'k', key_prefix: 'siam_x', expire_time: null, status: 1, secret_hash: 'leak' }],
    }],
  }
  const parsed = parseAccessMatrix(withSecret)
  assert.equal('secret_hash' in (parsed.applications[0].environments[0].clients[0].credentials[0] as object), false)

  // --- grant result parses, plaintext only when present ---
  const granted = parseMatrixGrantResult({
    application_id: 10,
    environment_id: 100,
    workload_client_id: 200,
    organization_id: 1,
    granted: [{ service_action_id: 50, action_code: 'sand_ai.chat.complete', service_code: 'sand_ai', state: 'created' }],
    credential: { id: 300, key_prefix: 'siam_abcd1234', credential: 'siam_plain', expire_time: null },
  })
  assert.equal(granted.credential?.credential, 'siam_plain')
  assert.equal(granted.secret_available, true)
  // credential-only issuance (empty action_codes): zero grants, credential present
  const credentialOnly = parseMatrixGrantResult({
    application_id: 10,
    environment_id: 100,
    workload_client_id: 200,
    organization_id: 1,
    granted: [],
    credential: { id: 301, key_prefix: 'siam_efgh5678', credential: 'siam_plain2', expire_time: null },
  })
  assert.equal(credentialOnly.granted.length, 0)
  assert.equal(credentialOnly.credential?.key_prefix, 'siam_efgh5678')
  assert.equal(credentialOnly.secret_available, true)
  // idempotent replay: backend strips secret material and flags it
  const replayed = parseMatrixGrantResult({
    application_id: 10,
    environment_id: 100,
    workload_client_id: 200,
    organization_id: 1,
    granted: [],
    secret_available: false,
  })
  assert.equal(replayed.credential, undefined)
  assert.equal(replayed.secret_available, false)
  assert.throws(() => parseMatrixGrantResult({ application_id: 'x' }))

  // --- lvxu preset contract ---
  assert.deepEqual(LVXU_GRANT_PRESET.application, { code: 'lvxu', name: '律序' })
  assert.equal(LVXU_GRANT_PRESET.service_code, 'sand_ai')
  assert.deepEqual(LVXU_GRANT_PRESET.action_codes, ['sand_ai.chat.complete', 'sand_ai.document_parse'])

  console.log('SandIAM access-matrix contracts passed')
}

void run()
