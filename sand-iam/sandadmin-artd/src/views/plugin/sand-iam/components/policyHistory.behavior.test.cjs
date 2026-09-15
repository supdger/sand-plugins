const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = req('typescript'), vue = req('vue')
const source = fs.readFileSync(path.join(__dirname, 'PolicyHistory.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = { load, rollback, page, size, total, rows, published, error, saving };`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(read = true, publish = true) {
  const props = vue.reactive({ policyId: null, busy: false }), reads = [], writes = [], confirms = [], events = []
  const context = { ...vue, defineProps: () => props, defineEmits: () => (...args) => events.push(args),
    useAuth: () => ({ hasAuth: key => key.endsWith(':read') ? read : publish }),
    ElMessage: { success: () => {} }, ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, props, reads, writes, confirms, events, stop: () => scope.stop() }
}
const row = id => ({ id, version_no: id, operation: 'publish', rollback_of_version_id: null, create_time: '2026-09-14' })
const result = rows => ({ data: rows, total: 21, current_page: 1, per_page: 20, published_version_id: 21 })
const tick = () => new Promise(resolve => setImmediate(resolve))
async function run() {
  const h = harness(); h.props.policyId = 7
  assert.equal(h.reads[0].path, 'policy/versions')
  assert.equal(JSON.stringify(h.reads[0].params), '{"id":7,"page":1,"limit":20}')
  h.reads[0].resolve(result([row(21)])); await tick()
  assert.equal(h.p.total.value, 21); assert.equal(h.p.published.value, 21)
  h.p.page.value = 2; const page2 = h.p.load()
  assert.equal(h.reads[1].params.page, 2)
  h.reads[1].resolve(result([row(1)])); await page2
  const cancelled = h.p.rollback(h.p.rows.value[0]); await h.p.rollback(h.p.rows.value[0])
  assert.equal(h.confirms.length, 1)
  h.props.policyId = 8; h.confirms[0].resolve(); await cancelled
  assert.equal(h.writes.length, 0)
  h.reads[2].resolve(result([row(2)])); await tick()
  const rollback = h.p.rollback(h.p.rows.value[0]); h.confirms[1].resolve(); await tick()
  assert.equal(h.writes[0].path, 'policy/rollback')
  assert.equal(JSON.stringify(h.writes[0].params), '{"id":8,"version_id":2}')
  h.writes[0].reject(new Error('retry')); await rollback
  assert.equal(h.p.rows.value[0].id, 2); assert.equal(h.p.saving.value, false)
  const retry = h.p.rollback(h.p.rows.value[0]); h.confirms[2].resolve(); await tick()
  h.writes[1].resolve({}); await tick(); h.reads.at(-1).resolve(result([row(22)])); await retry
  assert.equal(h.events.some(event => event[0] === 'changed'), true)
  assert.equal(h.p.rows.value[0].id, 22)
  const old = h.p.load(); const oldRead = h.reads.at(-1)
  h.props.policyId = 9; h.reads.at(-1).resolve(result([row(30)])); await tick()
  oldRead.resolve(result([row(1)])); await old; assert.equal(h.p.rows.value[0].id, 30)
  const pending = h.p.load(); h.stop(); h.reads.at(-1).resolve(result([row(90)])); await pending
  assert.equal(h.p.rows.value.length, 0)
  const denied = harness(false); denied.props.policyId = 1; assert.equal(denied.reads.length, 0); denied.stop()
  const readonly = harness(true, false); readonly.props.policyId = 1; readonly.reads[0].resolve(result([row(1)])); await tick()
  await readonly.p.rollback(readonly.p.rows.value[0]); assert.equal(readonly.confirms.length, 0); readonly.stop()
  console.log('Policy history behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
