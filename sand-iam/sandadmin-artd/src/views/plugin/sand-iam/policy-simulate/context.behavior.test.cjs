const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = req('typescript'), vue = req('vue')
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = { applicationId, identityId, identities, searchIdentities, resourceCode, action,
runSimulate, simulation, requestError, accessToken, organizationCode, applicationCode, apiCode, runDecide, decision, deciding, acting };`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true) {
  const reads = [], writes = [], decisions = []
  const context = { ...vue, onMounted: () => {}, useAuth: () => ({ hasAuth: () => allowed }),
    parseJsonObject: JSON.parse, parsePolicySimulation: value => value,
    describeSandIamError: error => ({ detail: error.message }), describeRuntimeDecideError: error => ({ detail: error.message }),
    listSandIamResource: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise },
    decideSandIamAuthorization: (token, params) => { const d = deferred(); decisions.push({ ...d, token, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, reads, writes, decisions, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
async function run() {
  const h = harness(); h.p.applicationId.value = '1'; h.reads[0].resolve([]); await tick()
  const old = h.p.searchIdentities('old'), current = h.p.searchIdentities(' User101 ')
  assert.equal(JSON.stringify(h.reads[2].params), '{"page":1,"limit":100,"application_id":1,"keywords":"User101"}')
  h.reads[2].resolve([{ id: 101, application_id: 1 }, { id: 201, application_id: 2 }]); await current
  h.reads[1].resolve([{ id: 1, application_id: 1 }]); await old
  assert.equal(h.p.identities.value.length, 1); assert.equal(h.p.identities.value[0].id, 101)
  h.p.identityId.value = '101'
  await h.p.runSimulate(); assert.equal(h.writes.length, 0)
  h.p.resourceCode.value = 'matter'; h.p.action.value = 'matter.read'
  const simulate = h.p.runSimulate(); await h.p.runSimulate()
  assert.equal(h.writes.length, 1)
  assert.equal(JSON.stringify(h.writes[0].params), '{"application_id":1,"identity_id":101,"resource_code":"matter","action":"matter.read","operation":"read","attributes":{}}')
  h.writes[0].reject(new Error('retry')); await simulate; assert.equal(h.p.acting.value, false)
  const retry = h.p.runSimulate(); h.writes[1].resolve({ allowed: true }); await retry
  assert.equal(h.p.simulation.value.allowed, true)
  const stale = h.p.runSimulate(); h.p.action.value = 'changed'
  h.writes[2].resolve({ allowed: true }); await stale; assert.equal(h.p.simulation.value, null)
  h.p.applicationId.value = '2'; assert.equal(h.p.identityId.value, '')
  h.reads[3].resolve([]); await tick()
  h.p.accessToken.value = 'secret'; h.p.organizationCode.value = 'org'; h.p.applicationCode.value = 'app'; h.p.apiCode.value = 'api'
  const decide = h.p.runDecide(); await h.p.runDecide()
  assert.equal(h.decisions.length, 1); assert.equal(h.decisions[0].token, 'secret')
  assert.equal(JSON.stringify(h.decisions[0].params), '{"organization_code":"org","application_code":"app","api_code":"api","api_version":"v1","attributes":{}}')
  h.decisions[0].resolve({ requestId: 'request', data: { allowed: true } }); await decide
  assert.equal(h.p.decision.value.allowed, true)
  const changed = h.p.runDecide(); h.p.accessToken.value = 'new token'
  h.decisions[1].resolve({ requestId: 'old', data: { allowed: true } }); await changed
  assert.equal(h.p.decision.value, null)
  const disposed = h.p.runDecide(); h.stop(); assert.equal(h.p.accessToken.value, '')
  h.decisions[2].resolve({ requestId: 'old', data: { allowed: true } }); await disposed
  assert.equal(h.p.decision.value, null)
  const denied = harness(false); denied.p.applicationId.value = '1'
  await denied.p.runSimulate(); assert.equal(denied.writes.length + denied.reads.length, 0)
  denied.p.accessToken.value = 'runtime token'
  const runtime = denied.p.runDecide()
  assert.equal(denied.decisions.length, 1, 'runtime adapter must not require admin policy permission')
  denied.decisions[0].reject(new Error('runtime reject')); await runtime
  assert.equal(denied.p.deciding.value, false); denied.stop()
  console.log('Policy simulate context behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
