const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified, n, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = {organizationId,organizations,policies,editingId,archiveAfterDays,retentionDays,purgeEnabled,alertWindowSeconds,alertFailureThreshold,page,pageSize,total,saving,requestError,loadPolicies,loadOrganizations,beginCreate,beginEdit,savePolicy,disablePolicy};`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((a,b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function harness() {
  const reads = [], writes = [], confirms = [], permissions = vue.ref(true)
  const context = { ...vue, onMounted() {}, useAuth: () => ({ hasAuth: () => permissions.value }),
    ElMessage: { success() {} }, ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    listSandIamResource: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    getSandIamAdmin: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  const p = context.page
  p.organizations.value = [{ id: 1, name: 'Org' }, { id: 2, name: 'Other' }]; p.organizationId.value = '1'
  return { p, reads, writes, confirms, permissions, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = { id: 21, organization_id: 1, archive_after_days: 90, retention_days: 365, purge_enabled: true, status: 1 }
async function refresh(h) { await tick(); h.reads.at(-1).resolve({ data: [row], total: 21 }); await tick() }
async function run() {
  const h = harness(), p = h.p
  p.retentionDays.value = 10; await p.savePolicy(); assert.equal(h.writes.length, 0)
  p.retentionDays.value = 365; p.alertWindowSeconds.value = 59; await p.savePolicy(); assert.equal(h.writes.length, 0)
  p.alertWindowSeconds.value = 300; p.purgeEnabled.value = true
  let pending = p.savePolicy(); await p.savePolicy(); assert.equal(h.writes.length, 1)
  assert.equal(JSON.stringify(h.writes[0].params), '{"organization_id":1,"archive_after_days":90,"retention_days":365,"purge_enabled":true,"alert_window_seconds":300,"alert_failure_threshold":5}')
  h.writes[0].resolve({}); await refresh(h); await pending
  p.page.value = 2; await tick(); assert.equal(h.reads.at(-1).params.page, 2)
  assert.equal(h.reads.at(-1).params.limit, 20); h.reads.at(-1).resolve({ data: [row], total: 21 }); await tick()
  assert.equal(p.total.value, 21)
  p.beginEdit({ ...row }); assert.equal(p.editingId.value, null)
  p.beginEdit(p.policies.value[0]); p.retentionDays.value = 500
  pending = p.savePolicy()
  assert.equal(h.writes.at(-1).path, 'audit-retention-policy/update')
  assert.equal(h.writes.at(-1).params.id, 21); assert.equal(h.writes.at(-1).params.organization_id, 1)
  h.writes.at(-1).reject(new Error('retry')); await pending
  assert.equal(p.retentionDays.value, 500); assert.equal(p.saving.value, false)
  pending = p.savePolicy(); h.writes.at(-1).resolve({}); await refresh(h); await pending
  pending = p.disablePolicy(p.policies.value[0]); await p.disablePolicy(p.policies.value[0])
  assert.equal(h.confirms.length, 1); h.confirms.at(-1).resolve(); await tick()
  assert.equal(h.writes.at(-1).params.id, 21); h.writes.at(-1).resolve({}); await refresh(h); await pending
  const count = h.writes.length
  pending = p.disablePolicy(p.policies.value[0]); h.permissions.value = false; h.confirms.at(-1).resolve(); await pending
  assert.equal(h.writes.length, count); h.permissions.value = true
  p.beginEdit(p.policies.value[0]); pending = p.savePolicy(); p.organizationId.value = '2'
  p.retentionDays.value = 600; h.writes.at(-1).resolve({}); await pending
  assert.equal(p.editingId.value, null); assert.equal(p.retentionDays.value, 600)
  await tick(); if (h.reads.at(-1).params.organization_id === 2) { h.reads.at(-1).resolve({ data: [], total: 0 }); await tick() }
  const first = p.loadOrganizations('old'), last = p.loadOrganizations(' Org101 ')
  assert.equal(h.reads.at(-1).params.keywords, 'Org101')
  h.reads.at(-1).resolve([{ id: 101, name: 'Found' }]); await last
  h.reads.at(-2).resolve([{ id: 9 }]); await first
  assert.equal(p.organizations.value[0].id, 101)
  p.organizationId.value = '101'
  const old = p.loadPolicies(); h.stop()
  h.reads.at(-1).resolve({ data: [row], total: 21 }); await old
  assert.equal(p.policies.value.length, 0)
  const denied = harness(); denied.permissions.value = false
  await denied.p.savePolicy(); await denied.p.loadPolicies(); await denied.p.loadOrganizations('No')
  assert.equal(denied.reads.length + denied.writes.length, 0); denied.stop()
  console.log('Audit retention policy context behavior PASS (offline only; no purge)')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
