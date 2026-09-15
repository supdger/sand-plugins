const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const compile = source => ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
const exportsObject = {}
vm.runInNewContext(compile(fs.readFileSync(path.join(__dirname, '../api/oauthRegistrationContracts.ts'), 'utf8')), { exports: exportsObject })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified, n, ast)).join('\n')
const code = compile(`${body}; globalThis.page = { applications,applicationId,tokenName,hostsText,scopesText,ttlHours,maxUses,tokens,page,pageSize,total,saving,requestError,issuedToken,issuedOwner,secretReplayHint,secretDialogOpen,loadApplications,loadTokens,issueToken,revoke,closeSecretDialog };`)
function deferred() { let resolve, reject; const promise = new Promise((a,b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function harness() {
  const reads = [], writes = [], confirms = [], permissions = vue.ref(true)
  const context = { ...vue, ...exportsObject, onMounted() {}, useAuth: () => ({ hasAuth: () => permissions.value }),
    ElMessage: { success() {} }, ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    listSandIamResource: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    getSandIamAdmin: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  const p = context.page
  p.applications.value = [{ id: 1, name: 'One' }, { id: 2, name: 'Two' }]; p.applicationId.value = '1'
  return { p, reads, writes, confirms, permissions, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = app => ({ id: 21, application_id: app, name: 'Purpose', remaining_uses: 1, status: 1 })
const json = value => JSON.stringify(value)
async function run() {
  const h = harness(), p = h.p
  p.tokenName.value = 'Purpose'; p.hostsText.value = 'https://bad'; await p.issueToken()
  assert.equal(h.writes.length, 0); assert.match(p.requestError.value.detail, /主机/)
  p.hostsText.value = 'APP.EXAMPLE.COM,app.example.com'; p.scopesText.value = 'openid profile openid'
  let pending = p.issueToken(); await p.issueToken(); assert.equal(h.writes.length, 1)
  assert.equal(json(h.writes[0].params), '{"application_id":1,"name":"Purpose","allowed_redirect_hosts":["app.example.com"],"allowed_scopes":["openid","profile"],"ttl_hours":24,"max_uses":1}')
  p.applicationId.value = '2'; h.writes[0].resolve({ token: 'once' }); await pending
  assert.equal(p.issuedToken.value, 'once'); assert.match(p.issuedOwner.value, /One.*1.*Purpose/)
  assert.equal(h.reads.length, 0); p.tokenName.value = 'Other'; await p.issueToken(); assert.equal(h.writes.length, 1)
  let closed = false
  pending = p.closeSecretDialog(() => { closed = true }); h.confirms.at(-1).reject('cancel'); await pending
  assert.equal(p.issuedToken.value, 'once'); assert.equal(closed, false)
  pending = p.closeSecretDialog(() => { closed = true }); h.confirms.at(-1).resolve(); await pending
  assert.equal(p.issuedToken.value, ''); assert.equal(closed, true)
  pending = p.issueToken(); h.writes.at(-1).resolve({ replayed: true }); await tick()
  h.reads.at(-1).resolve({ data: [row(2)], total: 21 }); await pending
  assert.equal(p.issuedToken.value, ''); assert.match(p.secretReplayHint.value, /不会再次显示/)
  const stale = p.loadTokens(), fresh = p.loadTokens()
  h.reads.at(-1).resolve({ data: [row(2)], total: 21 }); await fresh
  h.reads.at(-2).reject(new Error('stale')); await stale
  assert.equal(p.tokens.value.length, 1); assert.equal(p.requestError.value, null)
  p.page.value = 2; await tick()
  assert.equal(h.reads.at(-1).params.page, 2); assert.equal(h.reads.at(-1).params.limit, 20)
  h.reads.at(-1).resolve({ data: [row(2)], total: 21 }); await tick()
  assert.equal(p.total.value, 21)
  let selected = p.tokens.value[0]
  pending = p.revoke(selected); await p.revoke(selected)
  h.confirms.at(-1).resolve(); await tick()
  assert.equal(h.writes.at(-1).path, 'oauth-registration-token/revoke'); assert.equal(h.writes.at(-1).params.id, 21)
  h.writes.at(-1).reject(new Error('retry')); await pending; assert.equal(p.saving.value, false)
  pending = p.revoke(selected); h.confirms.at(-1).resolve(); await tick()
  h.writes.at(-1).resolve({}); await tick(); assert.equal(h.reads.at(-1).params.page, 2)
  h.reads.at(-1).resolve({ data: [row(2)], total: 21 }); await pending
  selected = p.tokens.value[0]; const count = h.writes.length
  await p.revoke({ ...selected }); assert.equal(p.saving.value, false)
  pending = p.revoke(selected); h.permissions.value = false; h.confirms.at(-1).resolve(); await pending
  assert.equal(h.writes.length, count); h.permissions.value = true
  pending = p.revoke(selected); p.applicationId.value = '1'; h.confirms.at(-1).resolve(); await pending
  await tick(); if (h.reads.at(-1).params.application_id === 1) { h.reads.at(-1).resolve({ data: [], total: 0 }); await tick() }
  assert.equal(h.writes.length, count); assert.equal(p.page.value, 1)
  const old = p.loadApplications('old'), latest = p.loadApplications(' User101 ')
  assert.equal(h.reads.at(-1).params.keywords, 'User101')
  h.reads.at(-1).resolve([{ id: 101, name: 'Found' }]); await latest
  h.reads.at(-2).resolve([{ id: 9 }]); await old; assert.equal(p.applications.value[0].id, 101)
  p.applicationId.value = '101'; p.tokenName.value = 'Purpose'
  pending = p.issueToken(); h.writes.at(-1).reject(new Error('failed')); await pending
  assert.equal(p.saving.value, false)
  pending = p.issueToken(); h.stop(); h.writes.at(-1).resolve({ token: 'late' }); await pending
  assert.equal(p.issuedToken.value, '')
  const denied = harness(); denied.permissions.value = false; denied.p.tokenName.value = 'Purpose'
  await denied.p.issueToken(); await denied.p.loadTokens(); await denied.p.loadApplications('test')
  assert.equal(denied.reads.length + denied.writes.length, 0); denied.stop()
  console.log('OAuth registration token context behavior PASS (real contract validators/parsers)')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
