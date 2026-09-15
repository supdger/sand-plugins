const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'))
const ts = req('typescript'), vue = req('vue')
const options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/delegationContracts.ts'), 'utf8'),
  { compilerOptions: options }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { applicationId, action, resourceId, fromTime, toTime, loadAudits, openDetail, exportAudits, rows, detail, loading, requestError,
 get mode() { return typeof mode === 'undefined' ? null : mode },
 get currentPage() { return typeof currentPage === 'undefined' ? null : currentPage },
 get total() { return typeof total === 'undefined' ? null : total } };`, { compilerOptions: options }).outputText
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function harness(permissions = null) {
  const reads = [], downloads = [], clicks = []
  const context = {
    ...vue, ...contracts.exports, onMounted: () => {},
    useAuth: () => ({ hasAuth: permission => permissions === null || permissions.includes(permission) }), ElMessage: { success: () => {} },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (path, params) => { const next = deferred(); reads.push({ ...next, path, params }); return next.promise },
    downloadSandIamAdminBlob: (path, params) => { const next = deferred(); downloads.push({ ...next, path, params }); return next.promise },
    URL: { createObjectURL: () => 'blob:fake', revokeObjectURL: () => {} },
    document: { createElement: () => { const link = { click: () => clicks.push(link.download) }; return link } }
  }
  const scope = vue.effectScope()
  scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, reads, downloads, clicks, stop: () => scope.stop() }
}
const row = id => ({ id, create_time: '2026-09-01', actor_type: 'admin', action: 'test', resource_type: 'test', outcome: 'succeeded', request_id: `r${id}` })
const result = (rows, current = 1) => ({ data: rows, total: 51, current_page: current, per_page: 50 })
async function run() {
  const h = harness()
  const first = h.page.loadAudits()
  h.reads[0].resolve(result(Array.from({ length: 50 }, (_, i) => row(i + 1)))); await first
  assert.equal(h.page.total?.value, 51, 'audit history beyond page one must be reachable')
  h.page.currentPage.value = 2
  const second = h.page.loadAudits()
  assert.equal(h.reads[1].params.page, 2); assert.equal(h.reads[1].params.limit, 50)
  h.reads[1].resolve(result([row(51)], 2)); await second
  const detail = h.page.openDetail(h.page.rows.value[0])
  assert.equal(h.reads[2].params.id, 51)
  h.reads[2].resolve(row(51)); await detail
  assert.equal(h.page.detail.value.id, 51)
  const oldDetail = h.page.openDetail(row(51))
  h.page.applicationId.value = '2'
  assert.equal(h.page.currentPage.value, 1)
  const fresh = h.page.loadAudits()
  h.reads[3].resolve(row(51)); await oldDetail
  assert.equal(h.page.detail.value, null)
  assert.equal(h.page.loading.value, true)
  h.reads[4].resolve(result([row(2)])); await fresh
  const older = h.page.loadAudits(), newer = h.page.loadAudits()
  h.reads[5].reject(new Error('stale')); await older
  assert.equal(h.page.loading.value, true); assert.equal(h.page.requestError.value, null)
  h.reads[6].resolve(result([row(3)])); await newer
  const olderDetail = h.page.openDetail(row(2)), newerDetail = h.page.openDetail(row(3))
  h.reads[8].resolve(row(3)); await newerDetail
  h.reads[7].resolve(row(2)); await olderDetail
  assert.equal(h.page.detail.value.id, 3)
  h.page.fromTime.value = '2026-09-01'; h.page.toTime.value = '2026-09-02'
  h.page.currentPage.value = 2
  const exporting = h.page.exportAudits()
  assert.equal(h.downloads[0].params.page, undefined); assert.equal(h.downloads[0].params.limit, undefined)
  h.page.fromTime.value = '2026-09-03'
  h.downloads[0].resolve({}); await exporting
  assert.equal(h.clicks[0], 'sand-iam-audit-2026-09-01-2026-09-02.csv')
  h.page.toTime.value = '2026-09-04'
  const staleList = h.page.loadAudits()
  const staleDetail = h.page.openDetail(row(3))
  const removed = h.page.exportAudits()
  h.stop(); h.downloads[1].resolve({}); await removed
  h.reads[9].resolve(result([row(9)])); await staleList
  h.reads[10].resolve(row(9)); await staleDetail
  assert.equal(h.page.rows.value.length, 0)
  assert.equal(h.page.detail.value, null)
  assert.equal(h.clicks.length, 1)
  const archive = harness(['sand_iam:audit:archive_index', 'sand_iam:audit:archive_read'])
  assert.notEqual(archive.page.mode, null, 'archive mode must be available')
  assert.equal(archive.page.mode.value, 'archive', 'archive-only account starts in archive mode')
  const archived = { ...row(700), original_audit_id: 51, original_create_time: '2026-08-01', create_time: undefined }
  const archiveList = archive.page.loadAudits()
  assert.equal(archive.reads[0].path, 'audit/archive/index')
  archive.reads[0].resolve(result([archived])); await archiveList
  assert.equal(archive.page.rows.value[0].id, 700)
  assert.equal(archive.page.rows.value[0].original_audit_id, 51)
  assert.equal(archive.page.rows.value[0].create_time, '2026-08-01')
  const archiveDetail = archive.page.openDetail(archive.page.rows.value[0])
  assert.equal(archive.reads[1].path, 'audit/archive/read')
  assert.equal(archive.reads[1].params.id, 700)
  archive.reads[1].resolve(archived); await archiveDetail
  assert.equal(archive.page.detail.value.original_audit_id, 51)
  await archive.page.exportAudits()
  assert.equal(archive.downloads.length, 0)
  archive.page.currentPage.value = 2
  const lateArchive = archive.page.openDetail(archive.page.rows.value[0])
  archive.page.mode.value = 'current'
  assert.equal(archive.page.currentPage.value, 1)
  archive.reads[2].resolve(archived); await lateArchive
  assert.equal(archive.page.detail.value, null)
  await archive.page.loadAudits()
  assert.equal(archive.reads.length, 3, 'archive-only permission must not allow current index')
  archive.stop()
  const switching = harness()
  const oldCurrent = switching.page.loadAudits()
  switching.page.mode.value = 'archive'
  const newArchive = switching.page.loadAudits()
  switching.reads[1].resolve(result([archived])); await newArchive
  switching.reads[0].resolve(result([row(1)])); await oldCurrent
  assert.equal(switching.page.rows.value[0].id, 700)
  assert.equal(switching.page.rows.value[0].original_audit_id, 51)
  await switching.page.exportAudits()
  assert.equal(switching.downloads.length, 0, 'even export permission cannot export archive through current endpoint')
  switching.stop()
  const currentOnly = harness(['sand_iam:audit:index', 'sand_iam:audit:read'])
  currentOnly.page.mode.value = 'archive'
  await currentOnly.page.loadAudits(); await currentOnly.page.openDetail(archived)
  assert.equal(currentOnly.reads.length, 0)
  currentOnly.stop()
  const denied = harness([])
  await denied.page.loadAudits()
  assert.equal(denied.reads.length, 0, 'no list permission must not issue initial request')
  denied.stop()
  const both = harness()
  assert.equal(both.page.mode.value, 'current', 'current index remains preferred when both are allowed')
  both.stop()
  const objectFilter = harness()
  objectFilter.page.fromTime.value = '2026-09-01'
  objectFilter.page.toTime.value = '2026-09-02'
  for (const invalid of ['0', '-1', '1.5', 'oops']) {
    objectFilter.page.resourceId.value = invalid
    await objectFilter.page.loadAudits()
    assert.equal(objectFilter.page.requestError.value.detail, '对象编号必须是正整数')
    await objectFilter.page.exportAudits()
  }
  assert.equal(objectFilter.reads.length, 0)
  assert.equal(objectFilter.downloads.length, 0)
  objectFilter.page.resourceId.value = ' 9223372036854775807 '
  const exact = objectFilter.page.loadAudits()
  assert.equal(objectFilter.reads[0].params.resource_id, '9223372036854775807')
  objectFilter.reads[0].resolve(result([row(1)])); await exact
  const filteredExport = objectFilter.page.exportAudits()
  assert.equal(objectFilter.downloads[0].params.resource_id, '9223372036854775807')
  objectFilter.downloads[0].resolve({}); await filteredExport
  objectFilter.page.mode.value = 'archive'
  const archiveExact = objectFilter.page.loadAudits()
  assert.equal(objectFilter.reads[1].path, 'audit/archive/index')
  assert.equal(objectFilter.reads[1].params.resource_id, '9223372036854775807')
  objectFilter.reads[1].resolve(result([archived])); await archiveExact
  objectFilter.page.currentPage.value = 2
  const previousObject = objectFilter.page.loadAudits()
  objectFilter.page.resourceId.value = ''
  assert.equal(objectFilter.page.currentPage.value, 1)
  const noObject = objectFilter.page.loadAudits()
  assert.equal(Object.hasOwn(objectFilter.reads[3].params, 'resource_id'), false)
  objectFilter.reads[2].reject(new Error('old object failed')); await previousObject
  assert.equal(objectFilter.page.requestError.value, null)
  objectFilter.reads[3].resolve(result([])); await noObject
  objectFilter.stop()
  assert.equal(contracts.exports.parseSandIamArchivedAuditPage([archived]).total, 1)
  assert.equal(contracts.exports.parseSandIamArchivedAudit({ ...archived, original_audit_id: null }), null)
  console.log('Audit pagination and detail isolation behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
