const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified, n, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = {accessToken,sessions,viewState,requestError,lastRequestId,detailOpen,detailSession,acting,loadSessions,openDetail,revoke};`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function harness() {
  const reads = [], writes = [], confirms = []
  let unmount
  const context = { ...vue, onUnmounted: fn => { unmount = fn }, ElMessage: { success() {} },
    ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    describeRuntimeAuthError: error => ({ detail: error.message, http: 400 }),
    listSandIamRuntimeSessions: token => { const d = deferred(); reads.push({ ...d, token }); return d.promise },
    revokeSandIamRuntimeSession: (token, id) => { const d = deferred(); writes.push({ ...d, token, id }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, reads, writes, confirms, stop: () => { unmount(); scope.stop() } }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = current => ({ id: current ? 1 : 2, current, create_time: 'now', last_used_time: 'now' })
async function populate(h, current = false) {
  const load = h.p.loadSessions(); h.reads.at(-1).resolve({ data: [row(current)], requestId: 'list' }); await load
  return h.p.sessions.value[0]
}
async function run() {
  const h = harness(); h.p.accessToken.value = 'A'
  const a = await populate(h)
  h.p.openDetail(a); assert.equal(h.p.detailOpen.value, true)
  h.p.accessToken.value = 'B'
  assert.equal(h.p.sessions.value.length, 0); assert.equal(h.p.detailSession.value, null)
  assert.equal(h.p.lastRequestId.value, ''); assert.equal(h.p.requestError.value, null)
  h.p.openDetail(a); await h.p.revoke(a); assert.equal(h.confirms.length, 0)
  const first = h.p.loadSessions(), last = h.p.loadSessions()
  h.reads.at(-1).resolve({ data: [row(true)], requestId: 'new' }); await last
  h.reads.at(-2).reject(new Error('old')); await first
  assert.equal(h.p.lastRequestId.value, 'new'); assert.equal(h.p.requestError.value, null)
  let revoke = h.p.revoke(h.p.sessions.value[0]); await h.p.revoke(h.p.sessions.value[0])
  assert.equal(h.confirms.length, 1)
  h.p.accessToken.value = 'C'; h.confirms.at(-1).resolve(); await revoke
  assert.equal(h.writes.length, 0); assert.equal(h.p.acting.value, false)
  let current = await populate(h, true)
  revoke = h.p.revoke(current); h.confirms.at(-1).resolve(); await tick()
  assert.equal(h.writes[0].token, 'C'); assert.equal(h.writes[0].id, 1)
  h.p.accessToken.value = 'D'; h.writes[0].resolve({ requestId: 'old-write' }); await revoke
  assert.equal(h.p.accessToken.value, 'D'); assert.equal(h.p.lastRequestId.value, '')
  current = await populate(h, true)
  revoke = h.p.revoke(current); h.confirms.at(-1).resolve(); await tick()
  h.writes.at(-1).resolve({ requestId: 'current' }); await revoke
  assert.equal(h.p.accessToken.value, ''); assert.equal(h.p.viewState.value, 'empty')
  h.p.accessToken.value = 'E'; const other = await populate(h)
  revoke = h.p.revoke(other); h.confirms.at(-1).resolve(); await tick()
  h.writes.at(-1).reject(new Error('retry')); await revoke
  assert.equal(h.p.acting.value, false); assert.equal(h.p.requestError.value.detail, 'retry')
  revoke = h.p.revoke(other); h.confirms.at(-1).resolve(); await tick()
  h.writes.at(-1).resolve({ requestId: 'ok' }); await tick()
  assert.equal(h.reads.at(-1).token, 'E')
  h.reads.at(-1).resolve({ data: [], requestId: 'refreshed' }); await revoke
  assert.equal(h.p.accessToken.value, 'E'); assert.equal(h.p.sessions.value.length, 0)
  const replaced = await populate(h)
  await h.p.revoke({ ...replaced }); assert.equal(h.p.acting.value, false)
  const count = h.writes.length
  revoke = h.p.revoke(replaced)
  await populate(h)
  h.confirms.at(-1).resolve(); await revoke
  assert.equal(h.writes.length, count)
  const visible = await populate(h)
  h.p.openDetail(visible); const pending = h.p.loadSessions(); h.stop()
  h.reads.at(-1).resolve({ data: [row(true)], requestId: 'disposed' }); await pending
  assert.equal(h.p.accessToken.value, ''); assert.equal(h.p.sessions.value.length, 0)
  assert.equal(h.p.detailSession.value, null); assert.equal(h.p.detailOpen.value, false)
  assert.equal(h.p.lastRequestId.value, '')
  console.log('Runtime auth sessions context behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
