const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'))
const ts = req('typescript'), vue = req('vue')
const options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/securityAlertContracts.ts'), 'utf8'),
  { compilerOptions: options }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { status, severity, ruleCode, resolving, searchOrganizations, searchApplications, organizations, applications, optionError, organizationId, applicationId, loadAlerts, resolveAlert, alerts, loading, requestError, successHint,
 get currentPage() { return typeof currentPage === 'undefined' ? null : currentPage },
 get total() { return typeof total === 'undefined' ? null : total } };`, { compilerOptions: options }).outputText
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function harness(authorized = true) {
  const requests = [], writes = [], notices = [], candidates = []
  let unmount = () => {}
  const permission = vue.ref(authorized)
  const context = {
    ...vue, ...contracts.exports, onMounted: () => {}, onUnmounted: fn => { unmount = fn },
    useAuth: () => ({ hasAuth: () => permission.value }), ElMessage: { success: message => notices.push(message) },
    describeSandIamError: error => ({ detail: error.message }),
    listSandIamResource: (resource, params) => { const next = deferred(); candidates.push({ ...next, resource, params }); return next.promise },
    getSandIamAdmin: (path, params) => { const next = deferred(); requests.push({ ...next, params }); return next.promise },
    postSandIamAction: (path, params) => { const next = deferred(); writes.push({ ...next, params }); return next.promise }
  }
  const scope = vue.effectScope()
  scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, requests, writes, notices, candidates, permission, stop: () => { unmount(); scope.stop() } }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = id => ({ id, organization_id: 1, application_id: 1, rule_code: 'login', severity: 'high', occurrence_count: 1, status: 'open' })
const result = (id, page = 1) => ({ data: [row(id)], total: 21, current_page: page, per_page: 20 })
async function run() {
  const h = harness()
  const first = h.page.loadAlerts()
  h.requests[0].resolve({ ...result(1), data: Array.from({ length: 20 }, (_, i) => row(i + 1)) }); await first
  assert.equal(h.page.total?.value, 21, 'history beyond first 20 must be reachable')
  h.page.currentPage.value = 2
  const second = h.page.loadAlerts()
  assert.equal(h.requests[1].params.page, 2)
  assert.equal(h.requests[1].params.limit, 20)
  h.requests[1].resolve(result(21, 2)); await second
  const resolve = h.page.resolveAlert(h.page.alerts.value[0])
  await h.page.resolveAlert(h.page.alerts.value[0])
  assert.equal(h.writes.length, 1, 'duplicate resolution is suppressed')
  assert.equal(h.writes[0].params.id, 21)
  h.writes[0].resolve({}); await tick()
  assert.equal(h.requests[2].params.page, 2)
  h.requests[2].resolve(result(21, 2)); await resolve
  const late = h.page.resolveAlert(h.page.alerts.value[0])
  h.page.organizationId.value = '2'; await tick()
  assert.equal(h.page.currentPage.value, 1)
  const fresh = h.page.loadAlerts()
  h.writes[1].resolve({}); await late
  assert.equal(h.page.successHint.value, '')
  assert.equal(h.page.loading.value, true)
  assert.equal(h.requests.length, 4, 'old resolve must not refresh new scope')
  h.requests[3].resolve(result(2)); await fresh
  const old = h.page.loadAlerts()
  h.page.applicationId.value = '3'; await tick()
  const newer = h.page.loadAlerts()
  h.requests[4].reject(new Error('old error')); await old
  assert.equal(h.page.requestError.value, null)
  assert.equal(h.page.loading.value, true)
  h.requests[5].resolve(result(3)); await newer
  assert.equal(h.page.alerts.value[0].id, 3)
  h.page.currentPage.value = 2
  const olderPage = h.page.loadAlerts()
  h.page.currentPage.value = 1
  const latestPage = h.page.loadAlerts()
  h.requests[7].resolve(result(1)); await latestPage
  h.requests[6].resolve(result(21, 2)); await olderPage
  assert.equal(h.page.currentPage.value, 1)
  assert.equal(h.page.alerts.value[0].id, 1)
  const failure = h.page.resolveAlert(h.page.alerts.value[0])
  h.writes[2].reject(new Error('permission denied')); await failure
  assert.equal(h.page.requestError.value.detail, 'permission denied')
  assert.equal(h.page.loading.value, false, 'failed resolve releases retry')
  const retry = h.page.resolveAlert(h.page.alerts.value[0])
  h.writes[3].resolve({}); await tick()
  h.requests[8].resolve(result(1)); await retry
  assert.equal(h.page.successHint.value, '告警已标记为已处理。')
  h.page.currentPage.value = 2
  h.page.applicationId.value = '4'
  assert.equal(h.page.currentPage.value, 1)
  await h.page.resolveAlert({ ...row(1), status: 'resolved' })
  assert.equal(h.writes.length, 4)
  assert.equal(contracts.exports.parseSecurityAlertRows([row(1)])[0].id, 1)
  const wrapped = contracts.exports.parseSecurityAlertPage({ data: result(21, 2) })
  assert.equal(wrapped.currentPage, 2)
  assert.equal(wrapped.total, 21)
  assert.equal(wrapped.data[0].id, 21)
  h.stop()
  const denied = harness(false)
  await denied.page.loadAlerts(); await denied.page.resolveAlert(row(1))
  assert.equal(denied.requests.length + denied.writes.length, 0)
  denied.stop()
  const f = harness()
  f.page.status.value = 'open'; f.page.severity.value = 'high'; f.page.ruleCode.value = '  login.failed  '
  const filtered = f.page.loadAlerts()
  assert.equal(f.requests[0].params.status, 'open'); assert.equal(f.requests[0].params.severity, 'high')
  assert.equal(f.requests[0].params.rule_code, 'login.failed')
  f.requests[0].resolve(result(101)); await filtered
  await f.page.resolveAlert(row(999)); assert.equal(f.writes.length, 0)
  const resolved = f.page.resolveAlert(f.page.alerts.value[0])
  f.writes[0].resolve({}); await tick(); f.requests[1].reject(new Error('refresh unavailable')); await resolved
  assert.match(f.page.successHint.value, /已处理.*刷新失败/)
  assert.equal(f.page.resolving.value, false)
  const oldOrg = f.page.searchOrganizations('old')
  const newOrg = f.page.searchOrganizations('organization101')
  f.candidates[1].resolve({data:[{id:101,name:'organization101'}]}); await newOrg
  f.candidates[0].resolve({data:[{id:1,name:'old'}]}); await oldOrg
  assert.equal(f.page.organizations.value[0].id, 101)
  f.page.applicationId.value = '8'; f.page.organizationId.value = '101'
  assert.equal(f.page.applicationId.value, '')
  const app = f.page.searchApplications('application101')
  assert.equal(f.candidates[3].params.keywords, 'application101')
  assert.equal(f.candidates[3].params.organization_id, 101)
  f.candidates[3].resolve({data:[{id:101,organization_id:101,name:'application101'},{id:2,organization_id:2}]}); await app
  assert.equal(f.page.applications.value.length, 1)
  f.candidates[2].resolve({data:[{id:2,organization_id:101}]}); await tick()
  assert.equal(f.page.applications.value[0].id, 101)
  const pending = f.page.loadAlerts(); f.permission.value = false
  f.requests[2].resolve(result(9)); await pending
  assert.equal(f.page.alerts.value.length, 0); assert.equal(f.page.applications.value.length, 0)
  await f.page.searchApplications('denied'); assert.equal(f.candidates.length, 4)
  f.permission.value = true
  const unmounted = f.page.searchOrganizations('late'); f.stop()
  f.candidates[4].resolve({data:[{id:1}]}); await unmounted
  assert.equal(f.page.organizations.value.length, 0)
  assert.match(source, /remote-method="searchApplications"/)
  assert.match(source, /remote-method="searchOrganizations"/)
  console.log('Security alert pagination, filters, remote candidates and resolution behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
