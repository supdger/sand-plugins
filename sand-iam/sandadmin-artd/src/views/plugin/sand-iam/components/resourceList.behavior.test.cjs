const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const { createRequire } = require('node:module')
const frontend = createRequire(path.resolve(
  process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'
))
const ts = frontend('typescript')
const vue = frontend('vue')
const presentation = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/presentation.ts'), 'utf8'),
  { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { exports: presentation.exports, module: presentation })
const source = fs.readFileSync(path.join(__dirname, 'ResourceListPage.vue'), 'utf8')
const script = source.slice(source.indexOf('>') + 1, source.indexOf('</script>'))
const ast = ts.createSourceFile('page.ts', script, ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node))
  .map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { load, runWrite, onEditorSubmit, clearSensitiveState, confirmAction, openCreate, openEdit,
 confirmDisable, restoreEnabled, editorOpen, keywords,
 rows, total, loading, saving, requestError, issuedSecret, credentialDialogOpen, identityId, referenceKeys,
 relationTargetId, referenceOptions, loadReferenceOption, grantRelation };`,
{ compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function harness(overrides = {}, manualConfirm = false) {
  const requests = [], writes = [], notices = [], confirms = []
  const allowed = vue.ref(true)
  let unmount = () => {}
  const context = {
    ...vue, console, onMounted: () => {}, onUnmounted: callback => { unmount = callback },
    defineProps: () => ({ endpoint: 'credential', title: '凭证', filters: [], columns: [], ...overrides }),
    withDefaults: (props, defaults) => ({ ...defaults, ...props, formFields: [] }),
    useAuth: () => ({ hasAuth: permission => allowed.value &&
      (!overrides.testPermissions || overrides.testPermissions.includes(permission)) }),
    positiveReferenceId: value => /^[1-9]\d*$/.test(String(value)) ? Number(value) : null,
    sandIamReferenceEndpoint: presentation.exports.sandIamReferenceEndpoint,
    sandIamFieldLabel: key => key,
    sandIamReferenceLabel: row => String(row.name || row.id),
    describeSandIamError: error => ({ http: error.http || 500, detail: error.message }),
    ElMessage: { success: text => notices.push(text), warning: text => notices.push(text) },
    ElMessageBox: { confirm: () => {
      if (!manualConfirm) return Promise.resolve()
      const next = deferred(); confirms.push(next); return next.promise
    } },
    listSandIamResource: (...args) => { const next = deferred(); requests.push({ ...next, args }); return next.promise },
    postSandIamAction: (...args) => { const next = deferred(); writes.push({ ...next, args }); return next.promise }
  }
  for (const name of ['saveSandIamResource', 'updateSandIamResource', 'disableSandIamResource']) {
    context[name] = (...args) => { const next = deferred(); writes.push({ ...next, args, name }); return next.promise }
  }
  const scope = vue.effectScope()
  scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, requests, writes, notices, confirms, deny: () => { allowed.value = false },
    stop: () => { unmount(); scope.stop() } }
}
async function run() {
  const crud = harness({ endpoint: 'environment', writeMode: 'crud', permissionPrefix: 'sand_iam:environment', filters: ['keywords'] }, true)
  crud.page.openCreate()
  let action = crud.page.onEditorSubmit({ name: 'Env', code: 'test', application_id: 1 })
  await crud.page.onEditorSubmit({ name: 'duplicate' })
  assert.equal(crud.writes.length, 1)
  assert.equal(crud.writes[0].name, 'saveSandIamResource')
  assert.equal(crud.writes[0].args[2], false, 'environment create keeps its request compatibility')
  crud.writes[0].resolve({}); await new Promise(resolve => setImmediate(resolve))
  crud.requests.at(-1).resolve([{ id: 4, status: 1 }]); await action
  let visible = crud.page.rows.value[0]
  crud.page.openEdit({ id: 4 }); assert.equal(crud.page.editorOpen.value, false)
  crud.page.openEdit(visible)
  action = crud.page.onEditorSubmit({ id: 999, name: 'Updated' })
  assert.equal(crud.writes.at(-1).args[1].id, 4, 'editor cannot replace original ID')
  crud.writes.at(-1).reject(new Error('retry')); await action
  assert.equal(crud.page.editorOpen.value, true); assert.equal(crud.page.saving.value, false)
  action = crud.page.onEditorSubmit({ name: 'Updated' })
  crud.writes.at(-1).resolve({}); await new Promise(resolve => setImmediate(resolve))
  crud.requests.at(-1).resolve([{ id: 4, status: 1 }]); await action
  visible = crud.page.rows.value[0]
  action = crud.page.confirmDisable(visible); await crud.page.confirmDisable(visible)
  assert.equal(crud.confirms.length, 1)
  crud.confirms.at(-1).resolve(); await new Promise(resolve => setImmediate(resolve))
  assert.equal(crud.writes.at(-1).name, 'disableSandIamResource')
  assert.equal(crud.writes.at(-1).args[1], 4)
  crud.writes.at(-1).resolve({}); await new Promise(resolve => setImmediate(resolve))
  crud.requests.at(-1).resolve([{ id: 4, status: 2 }]); await action
  action = crud.page.restoreEnabled(crud.page.rows.value[0])
  assert.equal(JSON.stringify(crud.writes.at(-1).args[1]), '{"id":4,"status":1}')
  crud.writes.at(-1).resolve({}); await new Promise(resolve => setImmediate(resolve))
  crud.requests.at(-1).resolve([{ id: 4, status: 1 }]); await action
  visible = crud.page.rows.value[0]; crud.page.openEdit(visible)
  const count = crud.writes.length
  crud.page.keywords.value = 'changed'
  await crud.page.onEditorSubmit({ name: 'stale' }); assert.equal(crud.writes.length, count)
  crud.page.openEdit(visible)
  action = crud.page.onEditorSubmit({ name: 'pending' })
  crud.page.keywords.value = 'new-scope'
  crud.writes.at(-1).resolve({}); await action
  assert.equal(crud.page.editorOpen.value, true, 'old response cannot close editor')
  action = crud.page.confirmDisable(visible); crud.deny(); crud.confirms.at(-1).resolve(); await action
  assert.equal(crud.writes.length, count + 1)
  crud.stop()
  const wrongPermission = harness({ writeMode: 'crud', permissionPrefix: 'sand_iam:application',
    testPermissions: ['sand_iam:application:issue', 'sand_iam:application:rotate', 'sand_iam:application:revoke'] })
  wrongPermission.page.openCreate(); await wrongPermission.page.onEditorSubmit({})
  wrongPermission.page.rows.value = [{ id: 1, status: 1 }]
  wrongPermission.page.openEdit(wrongPermission.page.rows.value[0])
  await wrongPermission.page.confirmDisable(wrongPermission.page.rows.value[0])
  assert.equal(wrongPermission.page.editorOpen.value, false); assert.equal(wrongPermission.writes.length, 0)
  wrongPermission.stop()
  for (const [action, mode] of [['policy/publish', 'policy'], ['policy/revoke', 'policy'], ['grant/revoke', 'grant'], ['application-business-action/publish', 'business-action']]) {
    const h = harness({ endpoint: action.split('/')[0], writeMode: mode }, true)
    h.page.rows.value = [{ id: 8, status: 1, state: 'draft' }]
    const call = () => h.page.confirmAction(action, { id: 999 }, '确认', h.page.rows.value[0], '')
    await h.page.confirmAction(action, { id: 8 }, '', { id: 8, status: 1 }, '')
    assert.equal(h.confirms.length, 0)
    const old = call(); await call(); assert.equal(h.confirms.length, 1)
    const changed = h.page.load(); h.requests.at(-1).resolve([{ id: 8, status: 1 }]); await changed
    h.confirms[0].resolve(); await old; assert.equal(h.writes.length, 0)
    const failed = call(); h.confirms[1].resolve(); await new Promise(resolve => setImmediate(resolve))
    assert.equal(h.writes[0].args[0], action); assert.equal(h.writes[0].args[1].id, 8)
    h.writes[0].reject(new Error('retry')); await failed
    const retry = call(); h.confirms[2].resolve(); await new Promise(resolve => setImmediate(resolve))
    h.writes[1].resolve({}); await new Promise(resolve => setImmediate(resolve))
    h.requests.at(-1).resolve([{ id: 8, status: 1 }]); await retry
    const denied = call(); h.deny(); h.confirms[3].resolve(); await denied
    assert.equal(h.writes.length, 2)
    h.stop()
  }
  const updateOnly = harness({ endpoint: 'oauth-client', writeMode: 'oauth-client', testPermissions: ['sand_iam:oauth_client:update'] }, true)
  updateOnly.page.rows.value = [{ id: 1, status: 1, client_type: 'confidential' }]
  await updateOnly.page.confirmAction('oauth-client/secret/rotate', { id: 1 }, '', updateOnly.page.rows.value[0], '')
  assert.equal(updateOnly.confirms.length, 0, 'update permission is not rotate permission')
  updateOnly.stop()
  for (const action of ['credential/rotate', 'credential/revoke', 'oauth-client/secret/rotate']) {
    const oauth = action.startsWith('oauth')
    const h = harness({ endpoint: oauth ? 'oauth-client' : 'credential', writeMode: oauth ? 'oauth-client' : 'credential' }, true)
    const row = { id: 7, status: 1, client_type: 'confidential' }
    h.page.rows.value = [row]
    const call = () => h.page.confirmAction(action, { id: 999 }, '操作', h.page.rows.value[0], '')
    await h.page.confirmAction(action, { id: 7 }, '', { ...row }, '')
    assert.equal(h.confirms.length, 0, 'fabricated row cannot trigger confirmation')
    const switched = call(); await call(); assert.equal(h.confirms.length, 1)
    const loading = h.page.load(); h.requests.at(-1).resolve([row]); await loading
    h.confirms[0].resolve(); await switched; assert.equal(h.writes.length, 0)
    const failed = call(); h.confirms[1].resolve(); await new Promise(resolve => setImmediate(resolve))
    assert.equal(h.writes[0].args[1].id, 7)
    assert.equal(h.writes[0].args[0], action)
    h.writes[0].reject(new Error('retry')); await failed
    assert.equal(h.page.saving.value, false)
    const retry = call(); h.confirms[2].resolve(); await new Promise(resolve => setImmediate(resolve))
    const changePage = h.page.load(); h.requests.at(-1).resolve([]); await changePage
    const isRotate = action !== 'credential/revoke'
    h.writes[1].resolve(isRotate ? { [oauth ? 'client_secret' : 'credential']: 'retained-secret' } : {})
    await new Promise(resolve => setImmediate(resolve))
    if (isRotate) h.requests.at(-1).resolve([])
    await retry
    if (isRotate) {
      assert.equal(h.page.issuedSecret.value, 'retained-secret', 'page change cannot discard rotation secret')
      h.page.rows.value = [row]
      await call(); assert.equal(h.confirms.length, 3, 'undelivered secret blocks next rotation')
    }
    h.page.clearSensitiveState()
    h.page.rows.value = [row]
    const denied = call(); h.deny(); h.confirms.at(-1).resolve(); await denied
    assert.equal(h.writes.length, 2)
    h.stop()
  }
  for (const endpoint of ['identity-role', 'identity-user-type']) {
    const h = harness({ endpoint, writeMode: 'relation', permissionPrefix: `sand_iam:${endpoint.replaceAll('-', '_')}` }, true)
    h.page.identityId.value = '1'
    h.page.rows.value = [{ id: 301, identity_id: 1, status: 1 }]
    const revoke = () => h.page.confirmAction(`${endpoint}/revoke`, { id: 301 }, '撤销', h.page.rows.value[0], 'impact')
    const first = revoke(); await revoke(); assert.equal(h.confirms.length, 1)
    h.page.identityId.value = '2'
    h.confirms[0].resolve(); await first
    assert.equal(h.writes.length, 0)
    h.page.identityId.value = '1'
    const changedRows = revoke()
    h.page.rows.value = [{ id: 302, identity_id: 1 }]
    h.confirms[1].resolve(); await changedRows; assert.equal(h.writes.length, 0)
    const normal = revoke(); h.confirms[2].resolve(); await new Promise(resolve => setImmediate(resolve))
    assert.equal(h.writes[0].args[0], `${endpoint}/revoke`)
    assert.equal(h.writes[0].args[1].id, 302, 'payload derives from verified row, never caller data')
    h.writes[0].reject(new Error('retry')); await normal
    const retry = revoke(); h.confirms[3].resolve(); await new Promise(resolve => setImmediate(resolve))
    h.writes[1].resolve({}); await new Promise(resolve => setImmediate(resolve))
    h.requests.at(-1).resolve([]); await retry
    h.page.rows.value = [{ id: 303, identity_id: 1 }]
    const stale = revoke(); h.confirms[4].resolve(); await new Promise(resolve => setImmediate(resolve))
    h.page.identityId.value = '2'; const requests = h.requests.length
    h.writes[2].resolve({}); await stale
    assert.equal(h.requests.length, requests, 'old revoke response cannot refresh new identity')
    h.page.identityId.value = '1'; h.page.rows.value = [{ id: 304, identity_id: 1 }]
    const denied = revoke(); h.deny(); h.confirms[5].resolve(); await denied
    assert.equal(h.writes.length, 3)
    h.stop()
  }
  for (const field of ['role_id', 'user_type_id']) {
    const endpoint = field === 'role_id' ? 'identity-role' : 'identity-user-type'
    const h = harness({ endpoint, writeMode: 'relation', relationGrantField: field, permissionPrefix: `sand_iam:${endpoint.replaceAll('-', '_')}` })
    h.page.referenceOptions.identity_id = [
      { value: '1', label: 'A', row: { id: 1, application_id: 11 } },
      { value: '2', label: 'B', row: { id: 2, application_id: 12 } }
    ]
    h.page.identityId.value = '1'
    h.requests.at(-1).resolve([])
    await new Promise(resolve => setImmediate(resolve))
    const searched = h.page.loadReferenceOption(field, ' Name101 ')
    assert.equal(JSON.stringify(h.requests.at(-1).args[1]), '{"page":1,"limit":100,"application_id":11,"keywords":"Name101"}')
    h.requests.at(-1).resolve([{ id: 101, name: 'Name101', application_id: 11 }, { id: 999, name: 'wrong', application_id: 12 }])
    await searched
    assert.equal(h.page.referenceOptions[field].length, 1)
    h.page.relationTargetId.value = '101'
    const granted = h.page.grantRelation(); await h.page.grantRelation()
    assert.equal(h.writes.length, 1)
    assert.equal(h.writes[0].args[0], `${endpoint}/grant`)
    assert.equal(h.writes[0].args[1][field], 101)
    assert.equal(h.writes[0].args[1].identity_id, 1)
    h.writes[0].resolve({}); await new Promise(resolve => setImmediate(resolve))
    h.requests.at(-1).resolve([]); await granted
    const old = h.page.loadReferenceOption(field, 'old'), oldRequest = h.requests.at(-1)
    const current = h.page.loadReferenceOption(field, 'new')
    h.requests.at(-1).resolve([{ id: 102, name: 'new', application_id: 11 }]); await current
    oldRequest.resolve([{ id: 101, name: 'old', application_id: 11 }]); await old
    assert.equal(h.page.referenceOptions[field][0].value, '102')
    h.page.relationTargetId.value = '101'; await h.page.grantRelation()
    assert.equal(h.writes.length, 1, 'noncandidate id must not grant')
    const stale = h.page.loadReferenceOption(field, 'stale'), staleRequest = h.requests.at(-1)
    h.page.identityId.value = '2'
    assert.equal(h.page.relationTargetId.value, '')
    h.requests.at(-1).resolve([{ id: 201, name: 'B', application_id: 12 }])
    await new Promise(resolve => setImmediate(resolve))
    staleRequest.resolve([{ id: 101, name: 'A', application_id: 11 }]); await stale
    assert.equal(h.page.referenceOptions[field][0].value, '201')
    h.stop()
  }
  const grantSource = fs.readFileSync(path.join(__dirname, '../service-grant/index.vue'), 'utf8')
  const grantAst = ts.createSourceFile('grant.ts', grantSource.slice(grantSource.indexOf('>') + 1, grantSource.indexOf('</script>')), ts.ScriptTarget.Latest, true)
  const grantBody = grantAst.statements.filter(node => !ts.isImportDeclaration(node))
    .map(node => printer.printNode(ts.EmitHint.Unspecified, node, grantAst)).join('\n')
  const grantContext = {}
  vm.runInNewContext(ts.transpileModule(`${grantBody}; globalThis.grant = { columns, filters }`,
    { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, grantContext)
  const grant = harness({ ...grantContext.grant, writeMode: 'grant' })
  assert.equal(grant.page.referenceKeys.value.includes('service_id'), false)
  assert.equal(grant.page.referenceKeys.value.includes('service_action_id'), false)
  assert.equal(grantContext.grant.columns.some(column => column.key === 'service_action_name'), true)
  assert.equal(grantContext.grant.columns.some(column => column.key === 'service_name'), true)
  grant.stop()
  const h = harness()
  const first = h.page.load(), second = h.page.load()
  h.requests[1].resolve([{ id: 2 }]); await second
  h.requests[0].resolve([{ id: 1 }]); await first
  assert.equal(h.page.rows.value[0].id, 2, 'latest query must win')
  const pending = deferred()
  let calls = 0
  const write = h.page.runWrite(() => { calls++; return pending.promise }, '完成')
  await h.page.runWrite(() => { calls++; return Promise.resolve({}) }, '重复')
  assert.equal(calls, 1, 'duplicate writes must not execute')
  pending.resolve({})
  await new Promise(resolve => setImmediate(resolve))
  h.requests.at(-1).resolve([{ id: 3 }]); await write
  assert.equal(h.page.rows.value[0].id, 3)
  h.stop()
  const stale = harness()
  const old = stale.page.load(), fresh = stale.page.load()
  stale.page.issuedSecret.value = 'still-needed'
  stale.requests[0].reject({ http: 401, message: 'old unauthorized' }); await old
  assert.equal(stale.page.loading.value, true, 'old finally must not finish new loading')
  assert.equal(stale.page.requestError.value, null)
  assert.equal(stale.page.issuedSecret.value, 'still-needed', 'old failure must not clear secret')
  stale.requests[1].resolve([{ id: 4 }]); await fresh
  const denied = stale.page.load()
  stale.requests[2].reject({ http: 403, message: 'permission denied' }); await denied
  assert.equal(stale.page.requestError.value.http, 403, 'current permission errors remain visible')
  stale.stop()
  const identity = harness({ requireIdentityId: true })
  identity.page.identityId.value = '12'
  const identityLoad = identity.page.load()
  identity.page.identityId.value = ''
  await identity.page.load()
  identity.requests[0].resolve([{ id: 12 }]); await identityLoad
  assert.equal(identity.page.rows.value.length, 0, 'missing identity invalidates pending query')
  assert.equal(identity.page.loading.value, false)
  identity.stop()
  const removed = harness()
  const removedLoad = removed.page.load()
  removed.stop()
  removed.requests[0].resolve([{ id: 7 }]); await removedLoad
  assert.equal(removed.page.rows.value.length, 0, 'unmounted page ignores responses')
  const secret = harness({ writeMode: 'credential' })
  secret.page.openCreate()
  const issue = secret.page.onEditorSubmit({ id: 1 })
  await secret.page.onEditorSubmit({ id: 1 })
  assert.equal(secret.writes.length, 1, 'real issue action is mutually exclusive')
  secret.writes[0].resolve({ credential: 'one-time' })
  await new Promise(resolve => setImmediate(resolve))
  secret.requests[0].resolve([]); await issue
  assert.equal(secret.page.issuedSecret.value, 'one-time')
  await secret.page.onEditorSubmit({ id: 2 })
  await secret.page.confirmAction('credential/rotate', { id: 1 }, '轮换凭证', { id: 1 }, '')
  assert.equal(secret.writes.length, 1, 'unacknowledged secret blocks issue and rotation')
  let ordinaryCalls = 0
  const ordinary = secret.page.runWrite(async () => { ordinaryCalls++; return {} }, '已停用')
  await new Promise(resolve => setImmediate(resolve))
  secret.requests[1].resolve([]); await ordinary
  assert.equal(ordinaryCalls, 1, 'non-secret operation remains available')
  assert.equal(secret.page.issuedSecret.value, 'one-time')
  secret.page.clearSensitiveState()
  secret.page.openCreate()
  const retry = secret.page.onEditorSubmit({ id: 2 })
  secret.writes[1].reject({ http: 403, message: 'cannot issue' }); await retry
  assert.equal(secret.page.requestError.value.http, 403)
  assert.equal(secret.page.saving.value, false, 'failed write releases retry')
  const next = secret.page.onEditorSubmit({ id: 2 })
  secret.writes[2].resolve({ data: { credential: 'next-secret' } })
  await new Promise(resolve => setImmediate(resolve))
  secret.requests[2].resolve([]); await next
  assert.equal(secret.page.issuedSecret.value, 'next-secret')
  secret.stop()
  const removedWrite = harness({ writeMode: 'credential' })
  removedWrite.page.openCreate()
  const late = removedWrite.page.onEditorSubmit({})
  removedWrite.stop()
  removedWrite.writes[0].resolve({ credential: 'late-secret' }); await late
  assert.equal(removedWrite.page.issuedSecret.value, null)
  assert.equal(removedWrite.requests.length, 0)
  console.log('ResourceList query/write behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
