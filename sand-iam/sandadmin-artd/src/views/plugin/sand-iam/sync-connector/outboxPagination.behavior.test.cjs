const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'))
const ts = req('typescript'), vue = req('vue')
const options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/syncConnectorContracts.ts'), 'utf8'),
  { compilerOptions: options }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { applicationId, selectedIdValue, loadFailedOutbox, retryFailedOutbox, failedOutbox, outboxLoading, requestError,
 get outboxPage() { return typeof outboxPage === 'undefined' ? null : outboxPage },
 get outboxPageSize() { return typeof outboxPageSize === 'undefined' ? null : outboxPageSize },
 get outboxTotal() { return typeof outboxTotal === 'undefined' ? null : outboxTotal } };`, { compilerOptions: options }).outputText
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function harness() {
  const reads = [], writes = []
  const context = {
    ...vue, ...contracts.exports, onMounted: () => {},
    useAuth: () => ({ hasAuth: () => true }), ElMessage: { success: () => {} },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (path, params) => { const next = deferred(); reads.push({ ...next, path, params }); return next.promise },
    postSandIamAction: (path, params) => { const next = deferred(); writes.push({ ...next, path, params }); return next.promise }
  }
  const scope = vue.effectScope()
  scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, reads, writes, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = id => ({ id, event_id: `event-${id}`, operation: 'update', state: 'failed', attempt_count: 1, payload_ciphertext: 'must-not-leak' })
const result = (rows, current = 1, total = 21) => ({ data: rows, total, current_page: current, per_page: 20 })
async function run() {
  const h = harness()
  h.page.selectedIdValue.value = '7'
  const first = h.page.loadFailedOutbox()
  h.reads[0].resolve(result(Array.from({ length: 20 }, (_, i) => row(i + 1)))); await first
  assert.equal(h.page.outboxTotal?.value, 21, 'failed history total must remain reachable')
  h.page.outboxPage.value = 2
  const second = h.page.loadFailedOutbox()
  assert.equal(h.reads[1].params.page, 2)
  assert.equal(h.reads[1].params.limit, 20)
  h.reads[1].resolve(result([row(21)], 2)); await second
  assert.equal(Object.hasOwn(h.page.failedOutbox.value[0], 'payload_ciphertext'), false)
  const retry = h.page.retryFailedOutbox(h.page.failedOutbox.value[0])
  assert.equal(JSON.stringify(h.writes[0].params), '{"id":7,"outbox_id":21}')
  h.writes[0].resolve({}); await tick()
  assert.equal(h.reads[2].params.page, 2, 'retry refreshes current page')
  h.reads[2].resolve(result([], 2, 20)); await tick()
  assert.equal(h.reads[3].params.page, 1, 'empty last page falls back to valid page')
  h.reads[3].resolve(result([row(1)], 1, 20)); await retry
  for (const request of h.reads.slice(0, 4)) {
    assert.equal(request.path, 'sync-connector/outbox')
    assert.equal(request.params.id, 7)
    assert.equal(request.params.state, 'failed')
    assert.equal(request.params.limit, 20)
  }
  assert.equal(h.page.outboxPage.value, 1)
  assert.equal(h.page.failedOutbox.value[0].id, 1)
  h.page.outboxPage.value = 2
  const old = h.page.loadFailedOutbox()
  h.page.selectedIdValue.value = '8'
  assert.equal(h.page.outboxPage.value, 1)
  assert.equal(h.page.outboxTotal.value, 0)
  const fresh = h.page.loadFailedOutbox()
  h.reads[4].reject(new Error('stale')); await old
  assert.equal(h.page.outboxLoading.value, true)
  assert.equal(h.page.requestError.value, null)
  h.reads[5].resolve(result([row(8)])); await fresh
  h.page.applicationId.value = '2'
  assert.equal(h.page.failedOutbox.value.length, 0)
  assert.equal(h.page.outboxTotal.value, 0)
  h.page.selectedIdValue.value = '9'
  const removed = h.page.loadFailedOutbox()
  h.stop()
  h.reads[6].resolve(result([row(9)])); await removed
  assert.equal(h.page.failedOutbox.value.length, 0)
  const shrinking = harness()
  shrinking.page.selectedIdValue.value = '10'
  shrinking.page.outboxPageSize.value = 50
  shrinking.page.outboxPage.value = 3
  const shrinkingLoad = shrinking.page.loadFailedOutbox()
  assert.equal(shrinking.reads[0].params.limit, 50)
  shrinking.reads[0].resolve({ data: [], total: 51, current_page: 3, per_page: 50 }); await tick()
  assert.equal(shrinking.reads[1].params.page, 2)
  shrinking.reads[1].resolve({ data: [], total: 0, current_page: 2, per_page: 50 }); await shrinkingLoad
  assert.equal(shrinking.reads.length, 2, 'further shrink never triggers a third request')
  assert.equal(shrinking.page.outboxLoading.value, false)
  assert.equal(shrinking.page.failedOutbox.value.length, 0)
  for (const request of shrinking.reads) {
    assert.equal(request.path, 'sync-connector/outbox')
    assert.equal(request.params.id, 10)
    assert.equal(request.params.state, 'failed')
    assert.equal(request.params.limit, 50)
  }
  shrinking.stop()
  console.log('Failed outbox pagination behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
