const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'))
const ts = req('typescript'), vue = req('vue')
const compilerOptions = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const moduleObject = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/syncConnectorContracts.ts'), 'utf8'),
  { compilerOptions }).outputText, { exports: moduleObject.exports, module: moduleObject })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const script = ts.transpileModule(`${body}
globalThis.page = { applicationId, selectedIdValue, connectors, configText, name, code, loading, requestError,
  loadConnectors, createConnector, configureConnector, act, disableConnector,
  get settingsDraft() { return typeof settingsDraft === 'undefined' ? null : settingsDraft },
  get beginSettings() { return typeof beginSettings === 'undefined' ? null : beginSettings },
  get saveSettings() { return typeof saveSettings === 'undefined' ? null : saveSettings },
  get cancelSettings() { return typeof cancelSettings === 'undefined' ? null : cancelSettings } };`,
  { compilerOptions }).outputText
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function harness() {
  const reads = [], writes = [], confirms = [], messages = [], denied = vue.reactive(new Set())
  const context = { ...vue, ...moduleObject.exports, onMounted: () => {},
    useAuth: () => ({ hasAuth: permission => !denied.has(permission) }),
    ElMessage: { success: message => messages.push(message) },
    ElMessageBox: { confirm: () => { const next = deferred(); confirms.push(next); return next.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (endpoint, params) => { const next = deferred(); reads.push({ endpoint, params, ...next }); return next.promise },
    postSandIamAction: (endpoint, params) => { const next = deferred(); writes.push({ endpoint, params, ...next }); return next.promise },
  }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(script, context))
  return { page: context.page, reads, writes, confirms, messages, denied, stop: () => scope.stop() }
}
const row = (id = 1, app = 1) => ({ id, application_id: app, code: `code${id}`, name: `Connector ${id}`, driver_code: 'postgresql',
  direction: 'inbound', conflict_policy: 'manual', missing_protection_hours: 24, disable_threshold_percent: 20, status: 1 })
const plain = value => JSON.parse(JSON.stringify(value))
function selected(h) { h.page.applicationId.value = '1'; h.page.connectors.value = [row(), row(2)]; h.page.selectedIdValue.value = '1' }
async function run() {
  const h = harness(); selected(h)
  h.page.beginSettings?.()
  assert.equal(h.page.settingsDraft?.value?.name, 'Connector 1', 'selected connector must expose an editable protection draft')
  assert.equal(h.page.settingsDraft.value.missing_protection_hours, 24)
  h.page.settingsDraft.value.name = ' Updated '
  h.page.settingsDraft.value.conflict_policy = 'local_wins'
  const save = h.page.saveSettings()
  assert.equal(h.writes[0].endpoint, 'sync-connector/update')
  assert.deepEqual(plain(h.writes[0].params),
    { id: 1, name: 'Updated', conflict_policy: 'local_wins', missing_protection_hours: 24, disable_threshold_percent: 20 })
  h.page.name.value = 'New connector'; h.page.code.value = 'new-connector'; h.page.configText.value = '{"host":"fixture"}'
  await h.page.saveSettings(); await h.page.createConnector(); await h.page.configureConnector(); await h.page.act('sync-connector/test', 'tested')
  assert.equal(h.writes.length, 1, 'all connection writes share the mutex')
  h.writes[0].reject(new Error('save failed')); await save
  assert.equal(h.page.settingsDraft.value.name, ' Updated ')
  assert.equal(h.page.loading.value, false)
  assert.match(h.page.requestError.value.detail, /save failed/)
  const retry = h.page.saveSettings()
  h.writes[1].resolve({}); await new Promise(setImmediate)
  assert.equal(h.reads[0].endpoint, 'sync-connector/index')
  h.reads[0].resolve({ data: [{ ...row(), name: 'Updated' }], total: 1, current_page: 1, per_page: 20 }); await retry
  assert.equal(h.page.settingsDraft.value, null)
  assert.equal(h.page.connectors.value[0].name, 'Updated')
  h.page.beginSettings(); h.page.cancelSettings()
  assert.equal(h.page.settingsDraft.value, null)
  h.stop()

  for (const change of [{ name: '' }, { name: 'x'.repeat(129) }, { missing_protection_hours: 0 }, { missing_protection_hours: 721 },
    { missing_protection_hours: 1.5 }, { disable_threshold_percent: 0 }, { disable_threshold_percent: 101 }, { conflict_policy: 'unknown' }]) {
    const invalid = harness(); selected(invalid); invalid.page.beginSettings()
    Object.assign(invalid.page.settingsDraft.value, change); await invalid.page.saveSettings()
    assert.equal(invalid.writes.length, 0); assert.ok(invalid.page.requestError.value); invalid.stop()
  }
  for (const field of ['app', 'selection', 'permission', 'dispose']) {
    const stale = harness(); selected(stale); stale.page.beginSettings()
    const pending = stale.page.saveSettings()
    if (field === 'app') stale.page.applicationId.value = '2'
    if (field === 'selection') { stale.page.selectedIdValue.value = '2'; stale.page.selectedIdValue.value = '1' }
    if (field === 'permission') stale.denied.add('sand_iam:sync_connector:update')
    if (field === 'dispose') stale.stop()
    stale.writes[0].resolve({}); await pending
    assert.equal(stale.reads.length, 0, `old ${field} response cannot refresh`)
    assert.equal(stale.messages.length, 0)
    if (field !== 'dispose') stale.stop()
  }
  const confirm = harness(); selected(confirm)
  const oldDisable = confirm.page.disableConnector(confirm.page.connectors.value[0])
  assert.equal(confirm.page.loading.value, true, 'confirmation owns the write mutex')
  confirm.page.selectedIdValue.value = '2'
  confirm.confirms[0].resolve(); await oldDisable
  assert.equal(confirm.writes.length, 0, 'confirmation cannot target a changed selection')
  assert.equal(confirm.page.selectedIdValue.value, '2')
  confirm.denied.add('sand_iam:sync_connector:update'); confirm.page.beginSettings()
  assert.equal(confirm.page.settingsDraft.value, null)
  confirm.stop()
  const disable = harness(); selected(disable)
  const disabling = disable.page.disableConnector(disable.page.connectors.value[1])
  disable.confirms[0].resolve(); await new Promise(setImmediate)
  assert.deepEqual(plain(disable.writes[0].params), { id: 2 }, 'disable targets the confirmed row, not the selected row')
  disable.writes[0].resolve({}); await new Promise(setImmediate)
  disable.reads[0].resolve({ data: [row(), { ...row(2), status: 2 }], total: 2, current_page: 1, per_page: 20 })
  await disabling
  assert.equal(disable.page.selectedIdValue.value, '1')
  disable.stop()
  const create = harness(); selected(create); create.page.beginSettings()
  create.page.name.value = 'New'; create.page.code.value = 'new'
  const creating = create.page.createConnector()
  await create.page.saveSettings()
  assert.equal(create.writes.length, 1, 'create also blocks settings submission')
  create.page.applicationId.value = '2'
  create.writes[0].reject(new Error('old create error')); await creating
  assert.equal(create.page.requestError.value, null)
  create.stop()
  console.log('Sync protection editing behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
