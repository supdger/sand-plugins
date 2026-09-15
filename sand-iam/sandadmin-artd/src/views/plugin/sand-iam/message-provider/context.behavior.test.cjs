const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const compile = source => ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
const contracts = {}
vm.runInNewContext(compile(fs.readFileSync(path.join(__dirname, '../api/messageProviderContracts.ts'), 'utf8')), { exports: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified, n, ast)).join('\n')
const code = compile(`${body}; globalThis.page = {organizationId,applicationId,organizations,applications,name,code,driverCode,providers,selectedProviderId,configEntries,configJson,testDestination,options,mounts,mountProviderId,page,pageSize,total,saving,requestError,loadProviders,loadMounts,searchOptions,createProvider,configureProvider,testProvider,disableProvider,saveMount,unmount};`)
function deferred() { let resolve, reject; const promise = new Promise((a,b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function harness() {
  const reads = [], writes = [], confirms = [], permissions = vue.ref(true)
  const context = { ...vue, ...contracts, onMounted() {}, useAuth: () => ({ hasAuth: () => permissions.value }),
    ElMessage: { success() {} }, ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    listSandIamResource: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    getSandIamAdmin: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  const p = context.page
  p.organizations.value = [{ id: 1, name: 'Org' }, { id: 2, name: 'Other' }]
  p.applications.value = [{ id: 10, organization_id: 1 }, { id: 20, organization_id: 1 }]
  p.organizationId.value = '1'
  return { p, reads, writes, confirms, permissions, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const provider = { id: 7, organization_id: 1, code: 'sms', name: 'SMS', provider_type: 'sms', driver_code: 'driver', config_version: 0, config_configured: false, status: 1 }
const mount = { id: 8, message_provider_id: 7, message_provider_name: 'SMS', provider_type: 'sms', purposes: ['verification'], priority: 100, status: 1 }
async function load(h) { const pending = h.p.loadProviders(); h.reads.at(-1).resolve({ data: [provider], total: 21 }); await pending }
async function mounts(h) {
  const pending = h.p.loadMounts(); h.reads.at(-1).resolve([provider]); await tick()
  h.reads.at(-1).resolve([mount]); await pending
}
async function run() {
  const h = harness(), p = h.p
  p.name.value = 'SMS'; p.code.value = 'sms'; p.driverCode.value = 'driver'
  let pending = p.createProvider(); await p.createProvider(); assert.equal(h.writes.length, 1)
  assert.equal(h.writes[0].params.organization_id, 1)
  h.writes[0].resolve({ id: 7 }); await tick(); h.reads.at(-1).resolve({ data: [provider], total: 21 }); await pending
  p.page.value = 2; await tick(); assert.equal(h.reads.at(-1).params.page, 2)
  h.reads.at(-1).resolve({ data: [provider], total: 21 }); await tick(); assert.equal(p.total.value, 21)
  p.selectedProviderId.value = '7'; p.configEntries.value = [{ key: 'secret', value: 'value' }]
  pending = p.configureProvider(); assert.equal(h.writes.at(-1).params.config.secret, 'value')
  h.writes.at(-1).reject(new Error('retry')); await pending; assert.equal(p.saving.value, false)
  pending = p.configureProvider(); h.writes.at(-1).resolve({ config_version: 1 }); await pending
  assert.equal(p.configEntries.value[0].value, ''); assert.equal(p.providers.value[0].config_version, 1)
  p.testDestination.value = 'offline-only'; pending = p.testProvider()
  assert.equal(h.writes.at(-1).params.destination_or_token, 'offline-only')
  h.writes.at(-1).resolve({}); await pending; assert.equal(p.testDestination.value, '')
  p.configJson.value = '{"key":"old"}'; pending = p.configureProvider()
  p.selectedProviderId.value = ''; p.configJson.value = '{"key":"new"}'
  h.writes.at(-1).resolve({ config_version: 2 }); await pending; assert.equal(p.configJson.value, '{"key":"new"}')
  let row = p.providers.value[0]; const count = h.writes.length
  await p.disableProvider({ ...row }); assert.equal(h.writes.length, count)
  pending = p.disableProvider(row); p.organizationId.value = '2'; h.confirms.at(-1).resolve(); await pending
  assert.equal(h.writes.length, count)
  await tick(); if (h.reads.at(-1).params.organization_id === 2) { h.reads.at(-1).resolve({ data: [], total: 0 }); await tick() }
  p.organizationId.value = '1'; await load(h)
  pending = p.disableProvider(p.providers.value[0]); h.confirms.at(-1).resolve(); await tick()
  assert.equal(h.writes.at(-1).params.id, 7); h.writes.at(-1).resolve({}); await tick()
  h.reads.at(-1).resolve({ data: [provider], total: 1 }); await pending
  p.applicationId.value = '10'; await mounts(h); p.mountProviderId.value = '7'
  pending = p.saveMount(); await p.saveMount()
  assert.equal(h.writes.at(-1).params.application_id, 10); assert.equal(h.writes.at(-1).params.message_provider_id, 7)
  h.writes.at(-1).resolve({}); await tick(); h.reads.at(-1).resolve([provider]); await tick()
  h.reads.at(-1).resolve([mount]); await pending
  pending = p.unmount(p.mounts.value[0]); assert.equal(h.writes.at(-1).params.id, 8)
  h.writes.at(-1).resolve({}); await tick(); h.reads.at(-1).resolve([provider]); await tick()
  h.reads.at(-1).resolve([]); await pending
  const old = p.loadMounts(); p.applicationId.value = '20'; h.reads.at(-1).resolve([provider]); await old
  assert.equal(p.options.value.length, 0); assert.equal(p.mounts.value.length, 0)
  const first = p.searchOptions('application','old'), last = p.searchOptions('application','Found101')
  h.reads.at(-1).resolve([{ id: 101, organization_id: 1 }]); await last
  h.reads.at(-2).resolve([{ id: 5 }]); await first; assert.equal(p.applications.value[0].id, 101)
  h.permissions.value = false; const denied = h.writes.length
  await p.createProvider(); await p.configureProvider(); await p.testProvider(); await p.saveMount()
  assert.equal(h.writes.length, denied)
  p.configJson.value = 'secret'; p.testDestination.value = 'destination'; h.stop()
  assert.equal(p.configJson.value, ''); assert.equal(p.testDestination.value, '')
  console.log('Message provider context behavior PASS (offline API stubs, real parsers)')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
