const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = { manifestText, preview, requestError, disableMissing, previewKind, runPreview, confirmApply, acting, issuedCredential, applied, acknowledgeCredential, openApiImportText, openApiImportPreview, openApiImportApplied, openApiImportError, fillOpenApiImportExample, runOpenApiImportPreview, confirmOpenApiImportApply };`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true) {
  const writes = [], confirms = []
  const context = { ...vue, useAuth: () => ({ hasAuth: () => allowed }), ElMessage: { success: () => {} },
    ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    SAND_IAM_ROUTE_SYNC_FORMAT: 'sand-iam.route-sync/v1',
    describeOnboardingManifestError: () => null, parseOnboardingPreview: value => value, parseRouteManifestPreview: value => value,
    parseOpenApiImportPreview: value => value,
    describeSandIamError: error => ({ detail: error.message }),
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, writes, confirms, stop: () => scope.stop() }
}
const preview = hash => ({ dryRun: true, canApply: true, previewHash: hash, changes: [] })
const tick = () => new Promise(resolve => setImmediate(resolve))
async function run() {
  const h = harness(); h.p.manifestText.value = '{"name":"old"}'
  const old = h.p.runPreview(); await h.p.runPreview(); assert.equal(h.writes.length, 1)
  h.p.manifestText.value = '{"name":"new"}'
  h.writes[0].resolve(preview('old')); await old; assert.equal(h.p.preview.value, null)
  const fresh = h.p.runPreview(); h.writes[1].resolve(preview('new')); await fresh
  const confirming = h.p.confirmApply(); await h.p.confirmApply(); assert.equal(h.confirms.length, 1)
  h.p.manifestText.value = '{"name":"changed"}'; h.confirms[0].resolve(); await confirming
  assert.equal(h.writes.length, 2)
  const latest = h.p.runPreview(); h.writes[2].resolve(preview('changed')); await latest
  const apply = h.p.confirmApply(); h.confirms[1].resolve(); await tick()
  assert.equal(JSON.stringify(h.writes[3].params), '{"manifest":{"name":"changed"},"preview_hash":"changed","apply":true}')
  h.p.manifestText.value = '{"name":"next"}'
  h.writes[3].resolve({ credential: 'one-time' }); await apply
  assert.equal(h.p.issuedCredential.value, 'one-time'); assert.equal(h.p.applied.value, false)
  await h.p.runPreview(); await h.p.confirmApply(); assert.equal(h.writes.length, 4)
  h.p.acknowledgeCredential(); assert.equal(h.p.issuedCredential.value, '')
  const failed = h.p.runPreview(); h.writes[4].reject(new Error('retry')); await failed
  const retry = h.p.runPreview(); h.writes[5].resolve(preview('next')); await retry
  const normal = h.p.confirmApply(); h.confirms[2].resolve(); await tick()
  h.writes[6].reject(new Error('apply retry')); await normal; assert.equal(h.p.acting.value, false)
  const normalRetry = h.p.confirmApply(); h.confirms[3].resolve(); await tick()
  h.writes[7].resolve({ credential: 'normal-secret' }); await normalRetry
  assert.equal(h.p.applied.value, true)
  h.stop(); assert.equal(h.p.issuedCredential.value, '')
  const denied = harness(false); denied.p.manifestText.value = '{}'
  await denied.p.runPreview(); await denied.p.confirmApply()
  assert.equal(denied.writes.length + denied.confirms.length, 0); denied.stop()
  const route = harness()
  route.p.manifestText.value = '{"format":"sand-iam.route-sync/v1"}'
  route.p.disableMissing.value = true
  const routePreview = route.p.runPreview()
  assert.equal(route.writes[0].path, 'developer/route-manifest/preview')
  assert.equal(route.writes[0].params.disable_missing, true)
  route.writes[0].resolve(preview('a'.repeat(64))); await routePreview
  const routeApply = route.p.confirmApply(); route.confirms[0].resolve(); await tick()
  assert.equal(route.writes[1].path, 'developer/route-manifest/apply')
  assert.equal(route.writes[1].params.preview_hash, 'a'.repeat(64))
  assert.equal(route.writes[1].params.disable_missing, true)
  route.writes[1].resolve({ replayed: false }); await routeApply
  assert.equal(route.p.applied.value, true); route.stop()
  const blocked = harness()
  blocked.p.manifestText.value = '{"format":"sand-iam.route-sync/v1"}'
  const blockedPreview = blocked.p.runPreview()
  blocked.writes[0].resolve({ dryRun: true, canApply: false, previewHash: 'b'.repeat(64), changes: [] })
  await blockedPreview
  await blocked.p.confirmApply()
  assert.equal(blocked.confirms.length, 0)
  assert.match(blocked.p.requestError.value.detail, /重新确认|重新预检|先生成变更预览/)
  blocked.stop()
  const openapi = harness()
  openapi.p.fillOpenApiImportExample()
  const example = JSON.parse(openapi.p.openApiImportText.value)
  assert.equal(example.mappings[0].operation_key, 'GET /work-items/{id}')
  assert.equal(example.mappings[0].resource_code, 'work_item')
  assert.equal(example.mappings[0].action, 'work_item.read')
  assert.equal(example.document.paths['/work-items/{id}'].get['x-sand-iam'].riskLevel, 'low')
  assert.deepEqual(example.document.paths['/work-items/{id}'].get.parameters[0], {
    name: 'id',
    in: 'path',
    required: true,
    schema: { type: 'string' }
  })
  const openapiPreview = openapi.p.runOpenApiImportPreview()
  assert.equal(openapi.writes[0].path, 'developer/openapi-import/preview')
  openapi.writes[0].resolve(preview('c'.repeat(64))); await openapiPreview
  const openapiApply = openapi.p.confirmOpenApiImportApply(); openapi.confirms[0].resolve(); await tick()
  assert.equal(openapi.writes[1].path, 'developer/openapi-import/apply')
  assert.equal(openapi.writes[1].params.preview_hash, 'c'.repeat(64))
  assert.equal(openapi.writes[1].params.apply, true)
  openapi.writes[1].resolve({ dry_run: false }); await openapiApply
  assert.equal(openapi.p.openApiImportApplied.value, true)
  openapi.stop()
  console.log('Route manifest context behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
