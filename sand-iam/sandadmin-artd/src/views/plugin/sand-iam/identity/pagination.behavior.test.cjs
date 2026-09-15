const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = req('typescript'), vue = req('vue'), options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/identityLifecycleContracts.ts'), 'utf8'), { compilerOptions: options }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { applicationId, displayName, code, identities, keywords, currentPage, pageSize, total, loadIdentities, createIdentity, act, saving, requestError, groupNamesByIdentity, groupSummaryAvailable, editIdentity, cancelEdit, editingIdentity };`, { compilerOptions: options }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true, summaries = false) {
  const reads = [], writes = [], confirms = []
  const read = (path, params) => { const next = deferred(); reads.push({ ...next, path, params }); return next.promise }
  const write = (path, params) => { const next = deferred(); writes.push({ ...next, path, params }); return next.promise }
  const context = { ...vue, ...contracts.exports, onMounted: () => {},
    useAuth: () => ({ hasAuth: permission => allowed && (summaries || !permission.includes('identity_group')) }),
    ElMessage: { success: () => {} }, ElMessageBox: { confirm: () => { const next = deferred(); confirms.push(next); return next.promise } },
    describeSandIamError: error => ({ detail: error.message }), listSandIamResource: read, getSandIamAdmin: read,
    postSandIamAction: write, saveSandIamResource: write }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, reads, writes, confirms, stop: () => scope.stop() }
}
const row = (id, state = 'active') => ({ id, application_id: 1, display_name: `U${id}`, code: `u${id}`, status: state === 'active' ? 1 : 2, lifecycle_state: state })
const result = data => ({ data, total: 101, per_page: 100, current_page: 1 })
const tick = () => new Promise(resolve => setImmediate(resolve))
async function list(h, rows) { const pending = h.p.loadIdentities(); h.reads.at(-1).resolve(result(rows)); await pending }
async function finishWrite(h, pending) {
  h.writes.at(-1).resolve(); await tick(); h.reads.at(-1).resolve(result([])); await pending
}
async function run() {
  {
    const edit = harness()
    await list(edit, [row(101)])
    edit.p.editIdentity(edit.p.identities.value[0])
    assert.equal(edit.p.code.value, 'u101')
    edit.p.cancelEdit(); assert.equal(edit.p.editingIdentity.value, null)
    edit.p.editIdentity(edit.p.identities.value[0])
    edit.p.displayName.value = ' '
    await edit.p.createIdentity(); assert.equal(edit.writes.length, 0)
    edit.p.displayName.value = ' Updated '
    const update = edit.p.createIdentity(); await edit.p.createIdentity()
    assert.equal(edit.writes.length, 1)
    assert.equal(edit.writes[0].path, 'identity/update')
    assert.equal(JSON.stringify(edit.writes[0].params), '{"id":101,"display_name":"Updated"}')
    edit.writes[0].reject(new Error('retry')); await update
    assert.equal(edit.p.displayName.value, ' Updated ')
    const retry = edit.p.createIdentity(); await finishWrite(edit, retry)
    assert.equal(edit.p.editingIdentity.value, null)
    await list(edit, [row(101)])
    edit.p.editIdentity(edit.p.identities.value[0])
    edit.p.currentPage.value = 2
    await edit.p.createIdentity(); assert.equal(edit.writes.length, 2, 'off-page edit must not submit')
    edit.p.cancelEdit()
    await list(edit, [row(101)])
    edit.p.editIdentity(edit.p.identities.value[0])
    const stale = edit.p.createIdentity()
    edit.p.applicationId.value = '2'; edit.p.displayName.value = 'new draft'
    edit.writes[2].resolve(); await stale
    assert.equal(edit.p.editingIdentity.value, null); assert.equal(edit.p.displayName.value, 'new draft')
    edit.stop()
  }
  const h = harness()
  await list(h, [row(1)]); assert.equal(h.p.total.value, 101)
  assert.equal('application_id' in h.reads[0].params, false, 'all-application list remains available')
  h.p.currentPage.value = 2
  await list(h, [row(101)]); assert.equal(h.reads[1].params.page, 2); assert.equal(h.p.identities.value[0].id, 101)
  h.p.keywords.value = ' U101 '; assert.equal(h.p.currentPage.value, 1)
  const old = h.p.loadIdentities()
  h.p.keywords.value = 'new'
  const latest = h.p.loadIdentities()
  h.reads[3].resolve(result([row(2)])); await latest
  h.reads[2].resolve(result([row(1)])); await old
  assert.equal(h.reads[2].params.keywords, 'U101'); assert.equal(h.p.identities.value[0].id, 2)
  h.p.applicationId.value = '1'
  await list(h, [row(101)])
  const action = () => h.p.act(h.p.identities.value[0], 'identity/disable', 'disable', 'impact', 'done')
  const confirm = action(); await action(); assert.equal(h.confirms.length, 1)
  h.p.applicationId.value = '2'; h.confirms[0].resolve(); await confirm
  assert.equal(h.writes.length, 0)
  h.p.applicationId.value = '1'
  for (const [verb, state] of [['disable', 'active'], ['enable', 'disabled'], ['delete', 'active'], ['restore', 'deleted']]) {
    await list(h, [row(101, state)])
    const pending = h.p.act(h.p.identities.value[0], `identity/${verb}`, verb, 'impact', 'done')
    h.confirms.at(-1).resolve(); await tick()
    assert.equal(h.writes.at(-1).path, `identity/${verb}`); assert.equal(h.writes.at(-1).params.id, 101)
    await finishWrite(h, pending)
  }
  await list(h, [row(101)])
  const fail = action(); h.confirms.at(-1).resolve(); await tick()
  h.writes.at(-1).reject(new Error('denied')); await fail
  assert.equal(h.p.saving.value, false); assert.equal(h.p.requestError.value.detail, 'denied')
  const retry = action(); h.confirms.at(-1).resolve(); await tick(); await finishWrite(h, retry)
  const count = h.confirms.length
  await h.p.act(row(999), 'identity/disable', '', '', '')
  await list(h, [row(1, 'deleted')])
  await h.p.act(h.p.identities.value[0], 'identity/disable', '', '', '')
  await h.p.act(h.p.identities.value[0], 'unrelated/path', '', '', '')
  assert.equal(h.confirms.length, count)
  await list(h, [row(1)])
  const lateMutation = action(); h.confirms.at(-1).resolve(); await tick()
  const readsBefore = h.reads.length
  h.p.applicationId.value = '2'
  h.writes.at(-1).resolve(); await lateMutation
  assert.equal(h.reads.length, readsBefore, 'old lifecycle response must not refresh another application')
  h.p.applicationId.value = '1'
  h.p.displayName.value = 'New'; h.p.code.value = 'new'
  const create = h.p.createIdentity(); await h.p.createIdentity()
  assert.equal(h.writes.at(-1).params.display_name, 'New')
  h.writes.at(-1).reject(new Error('create failed')); await create
  assert.equal(h.p.displayName.value, 'New')
  const createRetry = h.p.createIdentity(); await finishWrite(h, createRetry)
  assert.equal(h.p.displayName.value, '')
  h.p.displayName.value = 'Old'; h.p.code.value = 'old'
  const staleCreate = h.p.createIdentity(); const writes = h.writes.length
  h.p.applicationId.value = '2'; h.p.displayName.value = 'new app'; h.p.code.value = 'new app'
  await h.p.createIdentity(); assert.equal(h.writes.length, writes)
  h.writes.at(-1).resolve(); await staleCreate; assert.equal(h.p.displayName.value, 'new app')
  const unloaded = h.p.loadIdentities(); h.stop()
  h.reads.at(-1).resolve(result([row(500)])); await unloaded
  assert.equal(h.p.identities.value.length, 0)
  const summary = harness(true, true); summary.p.applicationId.value = '1'
  const first = summary.p.loadIdentities(); summary.reads[0].resolve(result([row(1)])); await tick()
  summary.reads[1].resolve([{ id: 7, application_id: 1, name: 'old group', code: 'g', status: 1, member_count: 1 }]); await tick()
  summary.p.applicationId.value = '2'
  summary.reads[2].resolve([{ identity_id: 1, display_name: 'U1', code: 'u1' }]); await first
  assert.equal(summary.p.groupSummaryAvailable.value, false)
  assert.equal(summary.p.groupNamesByIdentity.value.size, 0); summary.stop()
  assert.equal(contracts.exports.parseSandIamIdentityPage([row(1)]).total, 1)
  assert.equal(contracts.exports.parseSandIamIdentityPage({ data: result([row(1)]) }).total, 101)
  const denied = harness(false)
  denied.p.applicationId.value = '1'; denied.p.displayName.value = 'N'; denied.p.code.value = 'n'
  denied.p.identities.value = [row(1)]
  denied.p.editIdentity(denied.p.identities.value[0]); assert.equal(denied.p.editingIdentity.value, null)
  await denied.p.loadIdentities(); await denied.p.createIdentity(); await denied.p.act(denied.p.identities.value[0], 'identity/delete', '', '', '')
  assert.equal(denied.reads.length + denied.writes.length + denied.confirms.length, 0); denied.stop()
  console.log('Identity pagination and lifecycle behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
