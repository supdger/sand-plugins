const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const { createRequire } = require('node:module')

// Use an existing frontend dependency installation; this test never installs or starts a host.
const frontend = createRequire(
  path.resolve(
    process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'),
    'package.json'
  )
)
const ts = frontend('typescript')
const vue = frontend('vue')
const options = { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 }
const contracts = { exports: {} }
vm.runInNewContext(
  ts.transpileModule(
    fs.readFileSync(path.join(__dirname, '../api/syncConnectorContracts.ts'), 'utf8'),
    { compilerOptions: options }
  ).outputText,
  { exports: contracts.exports, module: contracts }
)
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const script = source.slice(source.indexOf('>') + 1, source.indexOf('</script>'))
const parsed = ts.createSourceFile('component.ts', script, ts.ScriptTarget.Latest, true)
const runnable = ts.createPrinter().printFile(
  ts.factory.updateSourceFile(
    parsed,
    parsed.statements.filter((statement) => !ts.isImportDeclaration(statement))
  )
)
const code = ts.transpileModule(
  runnable +
    `
globalThis.page = { selectedIdValue, failedOutbox, requestError, loadFailedOutbox,
  retryFailedOutbox, busy: typeof outboxLoading === 'undefined' ? loading : outboxLoading };
`,
  {
    compilerOptions: options
  }
).outputText

function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

function setup(deferWrites = false) {
  const reads = [],
    writes = [],
    notices = []
  const scope = vue.effectScope()
  const context = {
    ...vue,
    ...contracts.exports,
    exports: {},
    onMounted: () => {},
    useAuth: () => ({ hasAuth: () => true }),
    ElMessage: { success: (message) => notices.push(message) },
    ElMessageBox: {},
    describeSandIamError: (error) => ({ title: 'error', detail: error.message }),
    listSandIamResource: async () => [],
    getSandIamAdmin: (endpoint, params) => {
      const pending = deferred()
      reads.push({ endpoint, params, ...pending })
      return pending.promise
    },
    postSandIamAction: (endpoint, body) => {
      const pending = deferred()
      writes.push({ endpoint, body, ...pending })
      if (!deferWrites) pending.resolve()
      return pending.promise
    }
  }
  scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, reads, writes, notices, stop: () => scope.stop() }
}
const rows = (id) => ({
  data: [
    {
      id,
      event_id: `event-${id}`,
      operation: 'update',
      state: 'failed',
      attempt_count: 1,
      error_code: 'REMOTE_FAILURE'
    }
  ]
})
async function select(page, id) {
  page.selectedIdValue.value = id
  await vue.nextTick()
}

async function run() {
  {
    const h = setup()
    await select(h.page, '101')
    const old = h.page.loadFailedOutbox()
    await select(h.page, '202')
    const current = h.page.loadFailedOutbox()
    h.reads[1].resolve(rows(2020))
    await current
    h.reads[0].resolve(rows(1010))
    await old
    assert.equal(
      h.page.failedOutbox.value[0].id,
      2020,
      'old connector response replaced current rows'
    )
    h.stop()
  }
  {
    const h = setup()
    await select(h.page, '101')
    const old = h.page.loadFailedOutbox()
    await select(h.page, '202')
    const current = h.page.loadFailedOutbox()
    h.reads[0].reject(new Error('old connection denied'))
    await old
    assert.equal(h.page.requestError.value, null, 'old failure replaced current error state')
    assert.equal(h.page.busy.value, true, 'old finally cleared current loading')
    h.reads[1].resolve(rows(2020))
    await current
    await select(h.page, '')
    assert.equal(h.page.failedOutbox.value.length, 0)
    assert.equal(h.page.busy.value, false)
    h.stop()
  }
  {
    const h = setup()
    await select(h.page, '101')
    const loaded = h.page.loadFailedOutbox()
    h.reads[0].resolve(rows(1010))
    await loaded
    const oldRow = h.page.failedOutbox.value[0]
    await select(h.page, '202')
    await h.page.retryFailedOutbox(oldRow)
    assert.equal(h.writes.length, 0, 'retry paired current connector with an old row')
    const current = h.page.loadFailedOutbox()
    h.reads[1].resolve(rows(2020))
    await current
    const retry = h.page.retryFailedOutbox(h.page.failedOutbox.value[0])
    await new Promise((resolve) => setImmediate(resolve))
    assert.equal(h.writes[0].body.id, 202)
    assert.equal(h.writes[0].body.outbox_id, 2020)
    h.reads[2].resolve({ data: [] })
    await retry
    assert.equal(h.page.failedOutbox.value.length, 0)
    assert.equal(h.page.busy.value, false)
    h.stop()
  }
  {
    const h = setup()
    await select(h.page, '101')
    const old = h.page.loadFailedOutbox(),
      current = h.page.loadFailedOutbox()
    h.reads[1].resolve(rows(1020))
    await current
    h.reads[0].resolve(rows(1010))
    await old
    assert.equal(h.page.failedOutbox.value[0].id, 1020, 'older request in same connector won')
    const cleared = h.page.loadFailedOutbox()
    await select(h.page, '')
    h.reads[2].resolve(rows(1010))
    await cleared
    assert.equal(h.page.failedOutbox.value.length, 0, 'cleared selection accepted pending rows')
    assert.equal(h.page.busy.value, false)
    h.stop()
  }
  for (const fails of [false, true]) {
    const h = setup(true)
    await select(h.page, '101')
    const loaded = h.page.loadFailedOutbox()
    h.reads[0].resolve(rows(1010))
    await loaded
    const retry = h.page.retryFailedOutbox(h.page.failedOutbox.value[0])
    await select(h.page, '202')
    const current = h.page.loadFailedOutbox()
    if (fails) h.writes[0].reject(new Error('old retry denied'))
    else h.writes[0].resolve()
    await retry
    assert.equal(h.reads.length, 2, 'old retry refreshed a different connector')
    assert.equal(h.notices.length, 0, 'old retry emitted a current success notice')
    assert.equal(h.page.requestError.value, null, 'old retry failure changed current state')
    assert.equal(h.page.busy.value, true, 'old retry finally cleared current loading')
    h.reads[1].resolve(rows(2020))
    await current
    assert.equal(h.page.failedOutbox.value[0].id, 2020)
    h.stop()
  }
  {
    const h = setup(true)
    await select(h.page, '101')
    const denied = h.page.loadFailedOutbox()
    h.reads[0].reject(new Error('403 permission denied'))
    await denied
    assert.equal(h.page.requestError.value.detail, '403 permission denied')
    assert.equal(h.page.failedOutbox.value.length, 0)
    assert.equal(h.page.busy.value, false)
    const loaded = h.page.loadFailedOutbox()
    h.reads[1].resolve(rows(1010))
    await loaded
    const retry = h.page.retryFailedOutbox(h.page.failedOutbox.value[0])
    h.writes[0].reject(new Error('409 no longer retryable'))
    await retry
    assert.equal(h.page.requestError.value.detail, '409 no longer retryable')
    assert.equal(h.page.failedOutbox.value[0].id, 1010)
    assert.equal(h.page.busy.value, false)
    h.stop()
  }
  console.log('failed outbox delayed response and retry behavior: PASS')
}
run().catch((error) => {
  console.error(error)
  process.exitCode = 1
})
