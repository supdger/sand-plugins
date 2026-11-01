const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const { createRequire } = require('node:module')
const frontend = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = frontend('typescript')
const vue = frontend('vue')
const source = fs.readFileSync(path.join(__dirname, 'BusinessActionSelect.vue'), 'utf8')
const script = source.slice(source.indexOf('>') + 1, source.indexOf('</script>'))
const ast = ts.createSourceFile('select.ts', script, ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node))
  .map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.control = { actions, loading, error, selected, validation, load, actionLocation };`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText

function harness(modelValue = '') {
  const requests = [], events = []
  const props = vue.reactive({ applicationId: 20, modelValue })
  const scope = vue.effectScope()
  const context = {
    ...vue, defineProps: () => props,
    defineEmits: () => (event, value) => events.push([event, value]),
    useRoute: () => ({ query: { application_id: '20', organization_id: '2', goal: 'access', step: 'policy' } }),
    normalizeReferenceValue: value => Number.isSafeInteger(Number(value)) && Number(value) > 0 ? Number(value) : null,
    describeSandIamError: error => ({ detail: error.message }),
    getSandIamAdmin: (route, params, showErrorMessage) => new Promise((resolve, reject) => requests.push({ route, params, showErrorMessage, resolve, reject }))
  }
  scope.run(() => vm.runInNewContext(code, context))
  return { ...context.control, props, requests, events, stop: () => scope.stop() }
}
const flush = () => new Promise(resolve => setImmediate(resolve))
const row = (application_id, code, status = 1) => ({ id: 1, application_id, code, name: `动作 ${code}`, status })

async function main() {
  const current = harness('inspect')
  assert.match(current.validation.value, /读取/)
  assert.equal(current.requests[0].showErrorMessage, false)
  current.props.applicationId = 21
  current.requests[1].resolve({ data: [row(21, 'edit'), row(20, 'inspect')], total: 2 })
  await flush()
  assert.equal(current.actions.value.length, 1, 'foreign application action must not enter options')
  assert.match(current.validation.value, /未在此应用声明/)
  current.requests[0].resolve({ data: [row(20, 'inspect')], total: 1 })
  await flush()
  assert.equal(current.actions.value[0].code, 'edit', 'late previous application must not overwrite current options')
  current.props.modelValue = 'edit'
  assert.equal(current.validation.value, '')
  assert.equal(current.actionLocation.value.query.application_id, '21')
  assert.equal(current.actionLocation.value.query.goal, 'access')
  assert.equal(current.actionLocation.value.query.organization_id, undefined, 'old URL parents are re-derived from the selected application')
  const loading = current.load()
  current.requests[2].resolve({ data: [row(21, 'edit', 2)], total: 1 })
  await loading
  assert.match(current.validation.value, /已停用/)
  const failing = current.load()
  current.requests[3].reject(new Error('无权查看当前应用动作，请由有权管理员配置'))
  await failing
  assert.match(current.validation.value, /无权/)
  assert.equal(current.actions.value.length, 0)
  current.stop()

  const pages = harness('last.action')
  pages.requests[0].resolve({ data: Array.from({ length: 100 }, (_, i) => row(20, `action.${i}`)), total: 101 })
  await flush()
  assert.equal(pages.requests[1].params.page, 2)
  pages.requests[1].resolve({ data: [row(20, 'last.action')], total: 101 })
  await flush()
  assert.equal(pages.selected.value.code, 'last.action')
  assert.equal(pages.validation.value, '')
  pages.stop()

  const closed = harness()
  closed.stop()
  closed.requests[0].resolve({ data: [row(20, 'inspect')], total: 1 })
  await flush()
  assert.equal(closed.actions.value.length, 0, 'closed control ignores late responses')
  console.log('BusinessActionSelect PASS: app isolation, stale response, disabled/legacy/error, pagination, context, disposal')
}
main().catch(error => { console.error(error); process.exitCode = 1 })
