const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = { providers, applications, providerId, applicationId, tokenName, tokens, loadTokens,
searchOptions, issueToken, revoke, issuedToken, issuedOwner, acknowledgeToken, saving, requestError };`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true) {
  const writes = [], reads = [], confirms = []
  const context = { ...vue, onMounted: () => {}, useAuth: () => ({ hasAuth: () => allowed }), ElMessage: { success: () => {} },
    ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    listSandIamResource: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  const p = context.page
  p.providers.value = [{ id: 1, name: 'Provider', status: 1, organization_id: 7, provider_type: 'ldap' }]
  p.applications.value = [{ id: 2, name: 'App', status: 1, organization_id: 7 }, { id: 3, name: 'Other', status: 1, organization_id: 7 }]
  p.providerId.value = '1'; p.applicationId.value = '2'
  return { p, writes, reads, confirms, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = { id: 10, name: 'Token', status: 1 }
async function run() {
  const h = harness(); h.p.tokenName.value = 'Purpose'
  const issue = h.p.issueToken(); await h.p.issueToken()
  assert.equal(h.writes.length, 1)
  assert.equal(JSON.stringify(h.writes[0].params), '{"provider_id":1,"application_id":2,"name":"Purpose","expire_time":null}')
  h.p.applicationId.value = '3'
  h.writes[0].resolve({ token: 'one-time' }); await issue
  assert.equal(h.p.issuedToken.value, 'one-time'); assert.match(h.p.issuedOwner.value, /Provider \/ App \/ Purpose/)
  assert.equal(h.reads.length, 0)
  h.p.tokenName.value = 'Next'; await h.p.issueToken(); assert.equal(h.writes.length, 1)
  h.p.acknowledgeToken()
  const fail = h.p.issueToken(); h.writes[1].reject(new Error('retry')); await fail
  const retry = h.p.issueToken(); h.writes[2].resolve({ token: 'next-token' }); await tick()
  h.reads[0].resolve({ data: [row] }); await retry
  const revoke = h.p.revoke(h.p.tokens.value[0]); await h.p.revoke(h.p.tokens.value[0])
  assert.equal(h.confirms.length, 1)
  h.p.applicationId.value = '2'; h.confirms[0].resolve(); await revoke; assert.equal(h.writes.length, 3)
  const list = h.p.loadTokens(); h.reads[1].resolve({ data: [row] }); await list
  const normal = h.p.revoke(h.p.tokens.value[0]); h.confirms[1].resolve(); await tick()
  assert.equal(JSON.stringify(h.writes[3].params), '{"provider_id":1,"application_id":2,"token_id":10}')
  h.writes[3].reject(new Error('revoke retry')); await normal; assert.equal(h.p.saving.value, false)
  const revokeRetry = h.p.revoke(h.p.tokens.value[0]); h.confirms[2].resolve(); await tick()
  h.writes[4].resolve({}); await tick(); h.reads[2].resolve({ data: [] }); await revokeRetry
  const old = h.p.searchOptions('provider', 'old'), latest = h.p.searchOptions('provider', ' latest ')
  assert.equal(h.reads[4].params.keywords, 'latest')
  h.reads[4].resolve([{ id: 101 }]); await latest; h.reads[3].resolve([{ id: 1 }]); await old
  assert.equal(h.p.providers.value[0].id, 101)
  const app = h.p.searchOptions('application', 'App101'); h.reads[5].resolve([{ id: 101 }]); await app
  assert.equal(h.p.applications.value[0].id, 101)
  h.stop(); assert.equal(h.p.issuedToken.value, '')
  const denied = harness(false); denied.p.tokenName.value = 'No'
  await denied.p.issueToken(); await denied.p.loadTokens(); await denied.p.revoke(row)
  await denied.p.searchOptions('provider', 'No')
  assert.equal(denied.reads.length + denied.writes.length + denied.confirms.length, 0); denied.stop()
  console.log('SCIM token context behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
