const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
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
globalThis.page = { status, loadApplications, applications, openDetail, detailOpen, detail, payloadOpen, retrying, successHint, webhookName, applicationId, loadDeliveries, retry, deliveries, loading, requestError,
 get currentPage() { return typeof currentPage === 'undefined' ? null : currentPage },
 get total() { return typeof total === 'undefined' ? null : total } };`, { compilerOptions: options }).outputText
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
function harness(names = true) {
  const requests = [], writes = [], candidates = []
  let unmount = () => {}
  const allowed = vue.ref(true)
  let writeResult = null
  const context = {
    ...vue, ...contracts.exports, onMounted: () => {}, onUnmounted: fn => { unmount = fn },
    useAuth: () => ({ hasAuth: key => allowed.value && (key !== 'sand_iam:webhook:index' || names) }), ElMessage: { success: () => {} },
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (path, params) => {
      if (path === 'webhook/index') return Promise.reject(new Error('403 name access'))
      const next = deferred(); requests.push({ ...next, path, params }); return next.promise
    },
    listSandIamResource: (resource, params) => { const next = deferred(); candidates.push({...next,params}); return next.promise },
    postSandIamAction: async (path, params) => { writes.push({ path, params }); if (writeResult) return writeResult.promise }
  }
  const scope = vue.effectScope()
  scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, requests, writes, candidates, allowed, delayWrite: () => (writeResult = deferred()), stop: () => { unmount(); scope.stop() } }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = id => ({ id, application_id: 1, webhook_endpoint_id: 1, event_id: `event-${id}`, event_type: 'identity.updated', status: 4, attempt_count: 1 })
async function run() {
  const h = harness()
  h.page.applicationId.value = '1'; await tick()
  const first = h.page.loadDeliveries(); await tick()
  h.requests.at(-1).resolve({ data: Array.from({ length: 20 }, (_, index) => row(index + 1)), total: 21, current_page: 1, per_page: 20 }); await first
  assert.equal(h.page.total?.value, 21, 'pagination metadata must remain reachable')
  h.page.currentPage.value = 2
  const second = h.page.loadDeliveries(); await tick()
  assert.equal(h.requests.at(-1).params.page, 2)
  assert.equal(h.requests.at(-1).params.limit, 20)
  h.requests.at(-1).resolve({ data: [row(21)], total: 21, current_page: 2, per_page: 20 }); await second
  const retry = h.page.retry(h.page.deliveries.value[0]); await tick()
  assert.equal(h.writes[0].params.id, 21)
  assert.equal(h.requests.at(-1).params.page, 2, 'retry refresh preserves page')
  h.requests.at(-1).resolve({ data: [row(21)], total: 21, current_page: 2, per_page: 20 }); await retry
  const old = h.page.loadDeliveries(); await tick()
  const oldRequest = h.requests.at(-1)
  h.page.applicationId.value = '2'; await tick()
  assert.equal(h.page.currentPage.value, 1)
  const fresh = h.page.loadDeliveries(); await tick()
  oldRequest.reject(new Error('old page failed')); await old
  assert.equal(h.page.loading.value, true)
  assert.equal(h.page.requestError.value, null)
  h.requests.at(-1).resolve({ data: [], total: 0, current_page: 1, per_page: 20 }); await fresh
  assert.equal(h.page.deliveries.value.length, 0)
  h.page.currentPage.value = 2
  const olderPage = h.page.loadDeliveries(); await tick()
  const olderRequest = h.requests.at(-1)
  h.page.currentPage.value = 1
  const newerPage = h.page.loadDeliveries(); await tick()
  h.requests.at(-1).resolve({ data: [row(1)], total: 21, current_page: 1, per_page: 20 }); await newerPage
  olderRequest.resolve({ data: [row(21)], total: 21, current_page: 2, per_page: 20 }); await olderPage
  assert.equal(h.page.deliveries.value[0].id, 1, 'old page cannot replace newer page')
  assert.equal(h.page.currentPage.value, 1)
  assert.equal(contracts.exports.parseSandIamWebhookDeliveries([row(1)])[0].id, 1, 'legacy array parser remains compatible')
  const wrapped = contracts.exports.parseSandIamWebhookDeliveryPage({
    data: { data: [row(21)], total: 21, current_page: 2, per_page: 20 }
  })
  assert.equal(wrapped.total, 21)
  assert.equal(wrapped.currentPage, 2)
  assert.equal(wrapped.data[0].id, 21)
  h.stop()
  const f = harness(false)
  f.page.applicationId.value = '1'; f.page.status.value = '4'
  const list = f.page.loadDeliveries()
  assert.equal(f.requests[0].path, 'webhook/delivery/index')
  assert.equal(f.requests[0].params.status, 4)
  f.requests[0].resolve({data:[row(101)],total:1,current_page:1,per_page:20}); await list
  assert.equal(f.page.webhookName(101), '通知 #101')
  await f.page.retry(row(999)); assert.equal(f.writes.length, 0)
  const detail = f.page.openDetail(f.page.deliveries.value[0])
  f.page.detailOpen.value = false
  f.requests[1].resolve({...row(101), payload:{secret:'old'}}); await detail
  assert.equal(f.page.detail.value, null)
  const read = f.page.openDetail(f.page.deliveries.value[0])
  f.requests[2].resolve({...row(101),response_status:500,payload:{event:'ok'}}); await read
  assert.equal(f.page.detail.value.response_status, 500)
  f.page.payloadOpen.value = true; f.page.detailOpen.value = false
  assert.equal(f.page.detail.value, null); assert.equal(f.page.payloadOpen.value, false)
  const write = f.delayWrite(); const failedRetry = f.page.retry(f.page.deliveries.value[0])
  await f.page.retry(f.page.deliveries.value[0]); assert.equal(f.writes.length, 1)
  write.reject(new Error('retry failed')); await failedRetry
  assert.equal(f.page.retrying.value, false); assert.equal(f.page.requestError.value.detail, 'retry failed')
  const write2 = f.delayWrite(); const retry2 = f.page.retry(f.page.deliveries.value[0]); write2.resolve({}); await tick()
  f.requests[3].reject(new Error('refresh failed')); await retry2
  assert.match(f.page.successHint.value, /已重新排队.*刷新失败/)
  const oldCandidate = f.page.loadApplications('old'), freshCandidate = f.page.loadApplications('application101')
  assert.equal(f.candidates[1].params.keywords, 'application101')
  f.candidates[1].resolve({data:[{id:101,name:'application101'}]}); await freshCandidate
  f.candidates[0].resolve({data:[{id:1}]}); await oldCandidate
  assert.equal(f.page.applications.value[0].id, 101)
  f.allowed.value = false; await f.page.loadDeliveries(); await f.page.loadApplications('denied')
  assert.equal(f.page.applications.value.length, 0); assert.equal(f.requests.length, 4)
  f.allowed.value = true
  const reload = f.page.loadDeliveries()
  f.requests[4].resolve({data:[row(10), {...row(11),status:3}],total:2,current_page:1,per_page:20}); await reload
  await f.page.retry(f.page.deliveries.value[1]); assert.equal(f.writes.length, 2, 'delivered status is not retryable')
  const firstDetail = f.page.openDetail(f.page.deliveries.value[0])
  const secondDetail = f.page.openDetail(f.page.deliveries.value[1])
  f.requests[6].resolve({...row(11),payload:{current:true}}); await secondDetail
  f.requests[5].resolve({...row(10),payload:{old:true}}); await firstDetail
  assert.equal(f.page.detail.value.id, 11)
  const oldWrite = f.delayWrite(); const obsolete = f.page.retry(f.page.deliveries.value[0])
  f.page.applicationId.value = '2'
  oldWrite.resolve({}); await obsolete
  assert.equal(f.requests.length, 7, 'old retry cannot reload another application')
  assert.equal(f.page.successHint.value, '')
  const pending = f.page.loadApplications('unmount'); f.stop()
  f.candidates[2].resolve({data:[{id:2}]}); await pending
  assert.equal(f.page.applications.value.length, 0)
  assert.match(source, /remote-method="loadApplications"/)
  assert.match(source, /detail.response_status/)
  console.log('Webhook delivery pagination, optional names, filters, detail and retry behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
