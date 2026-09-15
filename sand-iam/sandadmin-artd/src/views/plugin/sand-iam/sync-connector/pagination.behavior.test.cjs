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
globalThis.page = { applicationId, selectedIdValue, loadConnectors, loadRuns, connectors, runs, requestError,
 configureConnector, act, configText,
 get connectorPage() { return typeof connectorPage === 'undefined' ? null : connectorPage },
 get connectorTotal() { return typeof connectorTotal === 'undefined' ? null : connectorTotal },
 get runPage() { return typeof runPage === 'undefined' ? null : runPage },
 get runTotal() { return typeof runTotal === 'undefined' ? null : runTotal } };`, { compilerOptions: options }).outputText
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function harness() {
  const requests = [], writes = []
  const context = {
    ...vue, ...contracts.exports, onMounted: () => {},
    useAuth: () => ({ hasAuth: () => true }), ElMessage: { success: () => {} },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (path, params) => { const next = deferred(); requests.push({ ...next, path, params }); return next.promise },
    postSandIamAction: (path, params) => { writes.push({ path, params }); return Promise.reject(new Error('unexpected write')) }
  }
  const scope = vue.effectScope()
  scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, requests, writes, stop: () => scope.stop() }
}
const connector = id => ({ id, application_id: 1, code: `c${id}`, name: `Connector ${id}`, direction: 'inbound', driver_code: 'postgresql', status: 1 })
const runRow = name => ({ start_time: name, state: 'failed', pulled: 0, pushed: 0, created: 0, updated: 0, missing: 0, disabled: 0, conflict: 0 })
const page = (rows, current = 1) => ({ data: rows, total: 21, current_page: current, per_page: 20 })
async function run() {
  const h = harness()
  h.page.applicationId.value = '1'
  const first = h.page.loadConnectors()
  h.requests[0].resolve(page(Array.from({ length: 20 }, (_, i) => connector(i + 1)))); await first
  assert.equal(h.page.connectorTotal?.value, 21, 'connector history must expose total')
  h.page.selectedIdValue.value = '1'
  h.page.configText.value = '{"host":"local"}'
  h.page.connectorPage.value = 2
  const second = h.page.loadConnectors()
  await h.page.configureConnector()
  await h.page.act('sync-connector/run', '已执行')
  assert.equal(h.writes.length, 0, 'loading another page blocks actions on previous selection')
  assert.equal(h.requests[1].params.page, 2)
  assert.equal(h.requests[1].params.limit, 20)
  h.requests[1].resolve(page([connector(21)], 2)); await second
  assert.equal(h.page.selectedIdValue.value, '', 'invisible selection must be cleared')
  assert.equal(h.page.configText.value, '', 'previous connector draft is cleared')
  await h.page.configureConnector()
  await h.page.act('sync-connector/run', '已执行')
  assert.equal(h.writes.length, 0, 'invisible connector cannot receive configuration or execution')
  assert.equal(h.page.connectors.value[0].id, 21)
  h.page.selectedIdValue.value = '21'
  const runs = h.page.loadRuns()
  assert.equal(h.requests[2].params.id, 21)
  h.requests[2].resolve(page([runRow('first')])); await runs
  assert.equal(h.page.runTotal.value, 21)
  h.page.runPage.value = 2
  const olderRuns = h.page.loadRuns()
  assert.equal(h.requests[3].params.page, 2)
  h.requests[3].resolve(page([runRow('twenty-first')], 2)); await olderRuns
  assert.equal(h.page.runs.value[0].start_time, 'twenty-first')
  const staleRuns = h.page.loadRuns()
  h.page.selectedIdValue.value = '22'
  assert.equal(h.page.runPage.value, 1)
  h.requests[4].resolve(page([runRow('stale')], 2)); await staleRuns
  assert.equal(h.page.runs.value.length, 0)
  const old = h.page.loadConnectors()
  h.page.applicationId.value = '2'
  assert.equal(h.page.connectorPage.value, 1)
  assert.equal(h.page.selectedIdValue.value, '')
  assert.equal(h.page.runTotal.value, 0)
  const fresh = h.page.loadConnectors()
  h.requests[5].reject(new Error('old failure')); await old
  assert.equal(h.page.requestError.value, null)
  h.requests[6].resolve(page([connector(22)])); await fresh
  assert.equal(h.page.connectors.value[0].id, 22)
  h.stop()
  console.log('Sync connector and run pagination behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
