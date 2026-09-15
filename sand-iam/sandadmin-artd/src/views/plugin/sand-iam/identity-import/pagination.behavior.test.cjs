const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'))
const ts = req('typescript'), vue = req('vue'), options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/importContracts.ts'), 'utf8'), { compilerOptions: options }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { applicationId, selectedJobId, selectedFile, preview, previewImport, exportUsers, loadJobs, loadRows, confirmJob, jobs, rows, requestError,
 get jobPage() { return typeof jobPage === 'undefined' ? null : jobPage },
 get jobTotal() { return typeof jobTotal === 'undefined' ? null : jobTotal },
 get rowPage() { return typeof rowPage === 'undefined' ? null : rowPage },
 get rowState() { return typeof rowState === 'undefined' ? null : rowState } };`, { compilerOptions: options }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true) {
  const reads = [], writes = [], confirms = [], forms = [], downloads = [], clicks = []
  const context = {
    ...vue, ...contracts.exports, onMounted: () => {},
    useAuth: () => ({ hasAuth: () => allowed }), ElMessage: { success: () => {} },
    FormData,
    URL: { createObjectURL: () => 'blob:fake', revokeObjectURL: () => {} },
    document: { createElement: () => ({ click: () => clicks.push(true) }) },
    ElMessageBox: { confirm: () => { const next = deferred(); confirms.push(next); return next.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (path, params) => { const next = deferred(); reads.push({ ...next, path, params }); return next.promise },
    postSandIamAction: (path, params) => { const next = deferred(); writes.push({ ...next, path, params }); return next.promise },
    postSandIamForm: (path, data) => { const next = deferred(); forms.push({ ...next, path, data }); return next.promise },
    downloadSandIamAdminBlob: (path, params) => { const next = deferred(); downloads.push({ ...next, path, params }); return next.promise }
  }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, reads, writes, confirms, forms, downloads, clicks, stop: () => scope.stop() }
}
const job = id => ({ id, application_id: 1, original_name: 'users.csv', content_digest: 'digest', mode: 'create', state: 'previewed', total_count: 51, valid_count: 51, invalid_count: 0, success_count: 0, warning_count: 0, failure_count: 0 })
const row = id => ({ id, row_number: id, state: 'valid', summary: {}, validation_errors: [] })
const result = (data, total, current = 1, size = 20) => ({ data, total, current_page: current, per_page: size })
const tick = () => new Promise(resolve => setImmediate(resolve))
async function run() {
  const h = harness(); h.page.applicationId.value = '1'
  const first = h.page.loadJobs(); h.reads[0].resolve(result([job(1)], 21)); await first
  assert.equal(h.page.jobTotal?.value, 21, 'import jobs beyond first page must be reachable')
  h.page.jobPage.value = 2
  const second = h.page.loadJobs(); assert.equal(h.reads[1].params.page, 2)
  h.reads[1].resolve(result([job(21)], 21, 2)); await second
  const selected = h.page.jobs.value[0]
  const rows = h.page.loadRows(selected); h.reads[2].resolve(result([row(1)], 51, 1, 50)); await rows
  h.page.rowPage.value = 2
  const more = h.page.loadRows(selected)
  assert.equal(h.reads[3].params.page, 2); assert.equal(h.reads[3].params.limit, 50)
  h.reads[3].resolve(result([row(51)], 51, 2, 50)); await more
  assert.equal(h.page.rows.value[0].row_number, 51)
  h.page.rowState.value = 'invalid'
  assert.equal(h.page.rowPage.value, 1)
  const filtered = h.page.loadRows(selected)
  assert.equal(h.reads[4].params.state, 'invalid')
  h.reads[4].resolve(result([], 0, 1, 50)); await filtered
  const confirming = h.page.confirmJob(selected)
  h.page.rowState.value = ''
  await h.page.confirmJob(selected)
  assert.equal(h.confirms.length, 1, 'row filter must not release confirmation lock')
  h.page.selectedFile.value = { name: 'sensitive.csv' }
  h.page.preview.value = { id: 21 }
  h.page.applicationId.value = '2'
  h.confirms[0].resolve(); await confirming
  assert.equal(h.writes.length, 0, 'old application confirmation must not submit')
  assert.equal(h.page.selectedJobId.value, '')
  assert.equal(h.page.selectedFile.value, null)
  assert.equal(h.page.preview.value, null)
  h.page.applicationId.value = '1'
  const again = h.page.loadJobs(); h.reads[5].resolve(result([job(1), job(2)], 2)); await again
  const oldRows = h.page.loadRows(h.page.jobs.value[0])
  const newRows = h.page.loadRows(h.page.jobs.value[1])
  h.reads[7].resolve(result([row(2)], 1, 1, 50)); await newRows
  h.reads[6].resolve(result([row(1)], 1, 1, 50)); await oldRows
  assert.equal(h.page.rows.value[0].id, 2)
  const confirmOldJob = h.page.confirmJob(h.page.jobs.value[1])
  const changedJob = h.page.loadRows(h.page.jobs.value[0])
  h.reads[8].resolve(result([row(1)], 1, 1, 50)); await changedJob
  h.confirms[1].resolve(); await confirmOldJob
  assert.equal(h.writes.length, 0, 'changing selected job during confirmation blocks old action')
  const confirmCurrent = h.page.confirmJob(h.page.jobs.value[0])
  h.confirms[2].resolve(); await tick()
  assert.equal(JSON.stringify(h.writes[0].params), '{"id":1,"digest":"digest"}')
  h.writes[0].resolve({ state: 'completed', success: 1, warning: 0, failure: 0 }); await tick()
  h.reads[9].resolve(result([job(1), job(2)], 2)); await tick()
  h.reads[10].resolve(result([row(1)], 1, 1, 50)); await confirmCurrent
  const oldJobs = h.page.loadJobs()
  h.stop()
  h.reads[11].resolve(result([job(3)], 1)); await oldJobs
  assert.equal(h.page.jobs.value[0].id, 1, 'unmounted response must not replace jobs')
  const previewing = harness()
  previewing.page.applicationId.value = '1'
  previewing.page.jobPage.value = 2
  previewing.page.selectedFile.value = new File(['name\nAlice'], 'users.csv', { type: 'text/csv' })
  const previewTask = previewing.page.previewImport()
  assert.equal(previewing.forms[0].data.get('application_id'), '1')
  assert.equal(previewing.forms[0].data.get('mode'), 'create')
  assert.equal(previewing.forms[0].data.get('file').name, 'users.csv')
  previewing.forms[0].resolve({ id: 22, digest: 'new', total: 1, valid: 1, invalid: 0 }); await tick()
  assert.equal(previewing.reads[0].params.page, 1, 'preview refresh must locate newest job on page one')
  previewing.reads[0].resolve(result([job(22)], 22)); await tick()
  previewing.reads[1].resolve(result([row(1)], 1, 1, 50)); await previewTask
  assert.equal(previewing.page.selectedJobId.value, '22')
  previewing.stop()
  const stale = harness()
  stale.page.applicationId.value = '1'
  stale.page.selectedFile.value = new File(['x'], 'old.csv')
  const oldPreview = stale.page.previewImport()
  stale.page.applicationId.value = '2'
  stale.forms[0].resolve({ id: 99, digest: 'old', total: 1, valid: 1, invalid: 0 }); await oldPreview
  assert.equal(stale.page.preview.value, null)
  assert.equal(stale.reads.length, 0)
  stale.page.selectedFile.value = new File(['x'], 'retry.csv')
  const failedPreview = stale.page.previewImport()
  stale.forms[1].reject(new Error('preview failed')); await failedPreview
  const retriedPreview = stale.page.previewImport()
  assert.equal(stale.forms.length, 3, 'preview failure releases busy state')
  stale.forms[2].reject(new Error('retry failed')); await retriedPreview
  const masked = stale.page.exportUsers(false)
  assert.equal(stale.downloads[0].path, 'identity-export/masked')
  assert.equal(stale.downloads[0].params.application_id, 2)
  stale.downloads[0].resolve(new Blob(['csv'])); await masked
  assert.equal(stale.clicks.length, 1)
  const sensitive = stale.page.exportUsers(true)
  stale.confirms[0].resolve(); await tick()
  assert.equal(stale.downloads[1].path, 'identity-export/sensitive')
  stale.downloads[1].resolve(new Blob(['csv'])); await sensitive
  assert.equal(stale.clicks.length, 2)
  const switched = stale.page.exportUsers(true)
  stale.page.applicationId.value = '3'
  stale.confirms[1].resolve(); await switched
  assert.equal(stale.downloads.length, 2, 'sensitive confirmation cannot export old application')
  const failedExport = stale.page.exportUsers(false)
  stale.downloads[2].reject(new Error('export failed')); await failedExport
  const retryExport = stale.page.exportUsers(false)
  assert.equal(stale.downloads.length, 4)
  stale.stop()
  stale.downloads[3].resolve(new Blob(['csv'])); await retryExport
  assert.equal(stale.clicks.length, 2, 'unmounted export cannot download')
  const denied = harness(false)
  denied.page.applicationId.value = '1'
  denied.page.selectedFile.value = new File(['x'], 'denied.csv')
  await denied.page.previewImport(); await denied.page.exportUsers(false); await denied.page.exportUsers(true)
  assert.equal(denied.forms.length + denied.downloads.length + denied.confirms.length, 0)
  denied.stop()
  console.log('Import jobs and row pagination behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
