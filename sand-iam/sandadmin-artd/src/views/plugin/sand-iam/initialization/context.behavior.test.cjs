const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/initializationContracts.ts'), 'utf8'),
  { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = { applicationId, manifestText, preview, pendingManifest, previewManifest, applyManifest,
rollbackRun, runs, drafts, loadRuns, loadDrafts, loadDraftForEditing, saveDraft, disableDraft, startNewDraft, editingDraft, loading, requestError, exportPackage, exportPackageCode,
runPage, runSize, runTotal, draftPage, draftSize, draftTotal };`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true) {
  const writes = [], reads = [], confirms = [], hashes = [], blobs = [], clicks = [], revoked = []
  const permission = vue.ref(allowed)
  const context = { ...vue, Blob, onMounted: () => {}, useAuth: () => ({ hasAuth: () => permission.value }), ElMessage: { success: () => {} },
    URL: { createObjectURL: blob => { blobs.push(blob); return 'blob:offline' }, revokeObjectURL: url => revoked.push(url) },
    document: { createElement: () => ({ click() { clicks.push({ href: this.href, download: this.download }) } }) },
    ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    parseInitializationManifest: JSON.parse, parseInitializationPreview: value => value,
    parseInitializationRuns: value => Array.isArray(value) ? value : value.data,
    parseInitializationDrafts: value => Array.isArray(value) ? value : value.data,
    parseInitializationPagination: contracts.exports.parseInitializationPagination,
    parseInitializationDraftDetail: value => value, parseInitializationDraftMutation: value => value,
    describeSandIamError: error => ({ detail: error.message }),
    buildInitializationRollbackConfirmation: (id, hash) => { const d = deferred(); hashes.push({ ...d, id, hash }); return d.promise },
    getSandIamAdmin: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, writes, reads, confirms, hashes, blobs, clicks, revoked,
    deny: () => { permission.value = false }, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
async function preview(h) { const p = h.p.previewManifest(); h.writes.at(-1).resolve({ preview_hash: 'hash' }); await p }
async function run() {
  {
    const h = harness()
    const first = h.p.loadRuns(); h.reads[0].resolve({ data: [{ id: 1 }], total: 21 }); await first
    const drafts = h.p.loadDrafts(); h.reads[1].resolve({ data: [{ id: 2 }], total: 21 }); await drafts
    assert.equal(h.p.runTotal.value, 21); assert.equal(h.p.draftTotal.value, 21)
    h.p.runPage.value = 2
    const second = h.p.loadRuns(); assert.equal(h.reads[2].params.page, 2)
    h.reads[2].resolve({ data: [{ id: 21 }], total: 21 }); await second
    assert.equal(h.p.drafts.value[0].id, 2)
    h.p.draftPage.value = 2
    const next = h.p.loadDrafts(); assert.equal(h.reads[3].params.page, 2)
    h.reads[3].resolve({ data: [{ id: 22 }], total: 21 }); await next
    assert.equal(h.p.runs.value[0].id, 21)
    const stale = h.p.loadRuns(); h.p.runSize.value = 50; h.p.runPage.value = 1
    const current = h.p.loadRuns(); assert.equal(h.reads[5].params.limit, 50)
    h.reads[5].resolve({ data: [{ id: 50 }], total: 21 }); await current
    h.reads[4].resolve({ data: [{ id: 21 }], total: 21 }); await stale
    assert.equal(h.p.runs.value[0].id, 50)
    h.p.applicationId.value = '2'
    assert.equal(h.p.runPage.value, 1); assert.equal(h.p.draftPage.value, 1)
    assert.equal(h.p.drafts.value.length + h.p.runs.value.length, 0)
    h.stop()
    assert.equal(contracts.exports.parseInitializationPagination([], 0).total, 0)
    assert.equal(contracts.exports.parseInitializationPagination({ data: { total: 21, data: [] } }, 0).total, 21)
  }
  {
    const h = harness(); h.p.applicationId.value = '1'; h.p.exportPackageCode.value = ' package '
    const exported = h.p.exportPackage()
    assert.equal(JSON.stringify(h.reads[0].params), '{"application_id":1,"package_code":"package"}')
    h.reads[0].resolve({ data: { package_code: 'package', configuration: { enabled: true } } }); await exported
    assert.equal(h.clicks[0].download, 'package.json')
    assert.equal(JSON.parse(await h.blobs[0].text()).configuration.enabled, true)
    assert.equal(h.revoked.length, 1)
    const failed = h.p.exportPackage(); h.reads[1].reject(new Error('export failed')); await failed
    assert.equal(h.p.loading.value, false); assert.equal(h.clicks.length, 1)
    const renamed = h.p.exportPackage(); h.p.exportPackageCode.value = 'other'
    h.reads[2].resolve({ data: {} }); await renamed; assert.equal(h.clicks.length, 1)
    const switched = h.p.exportPackage(); h.p.applicationId.value = '2'
    h.reads[3].resolve({ data: {} }); await switched; assert.equal(h.clicks.length, 1)
    const unloaded = h.p.exportPackage(); h.stop()
    h.reads[4].resolve({ data: {} }); await unloaded; assert.equal(h.clicks.length, 1)
  }
  {
    const h = harness(); h.p.applicationId.value = '1'
    const draft = () => ({ id: 9, application_id: 1, package_code: 'draft', revision: 3, status: 1 })
    h.p.drafts.value = [draft()]
    const stale = h.p.disableDraft(h.p.drafts.value[0])
    h.p.applicationId.value = '2'; h.confirms[0].resolve(); await stale
    assert.equal(h.writes.length, 0)
    h.p.applicationId.value = '1'; h.p.drafts.value = [draft()]
    const failed = h.p.disableDraft(h.p.drafts.value[0]); h.confirms[1].resolve(); await tick()
    assert.equal(JSON.stringify(h.writes[0].params), '{"id":9,"revision":3}')
    h.writes[0].reject(new Error('disable failed')); await failed
    assert.equal(h.p.loading.value, false)
    const retry = h.p.disableDraft(h.p.drafts.value[0]); h.confirms[2].resolve(); await tick()
    h.writes[1].resolve({ draft_id: 9, revision: 4, status: 2 }); await tick()
    h.reads.at(-1).resolve([]); await retry
    assert.equal(h.p.drafts.value.length, 0)
    h.p.drafts.value = [draft()]
    const revoked = h.p.disableDraft(h.p.drafts.value[0]); h.deny(); h.confirms[3].resolve(); await revoked
    assert.equal(h.writes.length, 2); h.stop()
  }
  {
    const h = harness(); h.p.runs.value = [{ id: 10, application_id: 1, package_code: 'P', package_hash: 'digest', state: 'applied' }]
    const rollback = () => h.p.rollbackRun(h.p.runs.value[0])
    const fail = rollback(); h.confirms[0].resolve(); await tick()
    h.hashes[0].resolve('confirmation'); await tick()
    h.writes[0].reject(new Error('SAND_IAM_INITIALIZATION_ROLLBACK_DRIFT')); await fail
    assert.equal(h.p.loading.value, false)
    assert.match(h.p.requestError.value.detail, /ROLLBACK_DRIFT/)
    assert.equal(h.p.runs.value[0].state, 'applied')
    assert.equal(h.writes.length, 1, 'drift does not cause an automatic force retry')
    const retry = rollback(); assert.equal(h.confirms.length, 2)
    h.confirms[1].resolve(); await tick(); h.hashes[1].resolve('fresh-confirmation'); await tick()
    assert.equal(JSON.stringify(h.writes[1].params), '{"id":10,"confirmation":"fresh-confirmation"}')
    h.writes[1].resolve({}); await tick(); h.reads.at(-1).resolve([]); await retry
    h.stop()
  }
  const h = harness(); h.p.manifestText.value = '{"name":"A"}'
  const stale = h.p.previewManifest(); await h.p.previewManifest(); assert.equal(h.writes.length, 1)
  h.p.manifestText.value = '{"name":"B"}'; h.writes[0].resolve({ preview_hash: 'old' }); await stale
  assert.equal(h.p.preview.value, null)
  await preview(h)
  const apply = h.p.applyManifest(); await h.p.applyManifest()
  assert.equal(JSON.stringify(h.writes[2].params), '{"manifest":{"name":"B"},"preview_hash":"hash"}')
  h.writes[2].reject(new Error('retry')); await apply; assert.equal(h.p.preview.value, null)
  await h.p.applyManifest(); assert.equal(h.writes.length, 3, 'failed apply requires fresh preview')
  await preview(h); const retry = h.p.applyManifest(); h.writes[4].resolve({}); await tick()
  h.reads.at(-1).resolve([]); await retry
  h.p.runs.value = [{ id: 1, application_id: 1, package_code: 'P', package_hash: 'digest', state: 'applied' }]
  const rollback = h.p.rollbackRun(h.p.runs.value[0]); await h.p.rollbackRun(h.p.runs.value[0])
  assert.equal(h.confirms.length, 1); h.confirms[0].resolve(); await tick()
  assert.equal(h.hashes[0].hash, 'digest')
  h.p.applicationId.value = '2'; h.hashes[0].resolve('confirmation'); await rollback
  assert.equal(h.writes.length, 5)
  h.p.applicationId.value = '1'
  h.p.runs.value = [{ id: 2, application_id: 1, package_code: 'P', package_hash: 'digest2', state: 'applied' }]
  const normal = h.p.rollbackRun(h.p.runs.value[0]); h.confirms[1].resolve(); await tick()
  h.hashes[1].resolve('confirmation2'); await tick()
  assert.equal(JSON.stringify(h.writes[5].params), '{"id":2,"confirmation":"confirmation2"}')
  h.writes[5].resolve({}); await tick(); h.reads.at(-1).resolve([]); await normal
  h.p.drafts.value = [{ id: 3, application_id: 1, status: 1, revision: 1 }]
  const read = h.p.loadDraftForEditing(h.p.drafts.value[0])
  h.reads.at(-1).resolve({ id: 3, application_id: 1, status: 1, revision: 1, manifest: { name: 'draft' }, package_code: 'draft' }); await read
  assert.equal(h.p.editingDraft.value.id, 3)
  await preview(h)
  const save = h.p.saveDraft(); assert.equal(h.writes.at(-1).params.revision, 1)
  h.writes.at(-1).resolve({ draft_id: 3, status: 1, revision: 2, manifest_hash: 'hash2' }); await tick()
  h.reads.at(-1).resolve([]); await save; assert.equal(h.p.editingDraft.value.revision, 2)
  h.p.startNewDraft(); assert.equal(h.p.manifestText.value, '')
  const oldList = h.p.loadRuns(); h.p.applicationId.value = '2'; h.reads.at(-1).resolve([{ id: 99 }]); await oldList
  assert.equal(h.p.runs.value.length, 0); h.stop()
  const denied = harness(false); denied.p.manifestText.value = '{}'
  await denied.p.previewManifest(); await denied.p.applyManifest(); await denied.p.saveDraft()
  assert.equal(denied.writes.length, 0); denied.stop()
  console.log('Initialization context behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
