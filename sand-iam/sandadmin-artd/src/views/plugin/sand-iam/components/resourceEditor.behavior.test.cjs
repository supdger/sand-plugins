const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const { createRequire } = require('node:module')
const frontend = createRequire(path.resolve(
  process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'
))
const ts = frontend('typescript')
const vue = frontend('vue')
const options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const modules = new Map()
function loadModule(filename) {
  if (modules.has(filename)) return modules.get(filename)
  const module = { exports: {} }
  modules.set(filename, module.exports)
  const code = ts.transpileModule(fs.readFileSync(filename, 'utf8'), { compilerOptions: options }).outputText
  vm.runInNewContext(code, {
    exports: module.exports, module,
    require: request => loadModule(path.resolve(path.dirname(filename), `${request}.ts`))
  })
  return module.exports
}
const api = name => loadModule(path.resolve(__dirname, `../api/${name}.ts`))
const { applicationExperienceFields, grantFields } = api('fields')
const source = fs.readFileSync(path.join(__dirname, 'ResourceEditor.vue'), 'utf8')
assert.match(
  source,
  /<ElInput\s+v-if="field\.kind === 'text' \|\| field\.kind === 'number'"/,
  'the generic input must render only for text and number fields'
)
assert.doesNotMatch(
  source,
  /<ElInput\s+v-else\s/,
  'select, reference, status and specialized fields must not receive a second generic input'
)
const script = source.slice(source.indexOf('>') + 1, source.indexOf('</script>'))
const ast = ts.createSourceFile('editor.ts', script, ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node))
  .map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.editor = { form, buildPayload, resetForm, loadReferenceField, hydrateSelectedReference, searchReference, showAdvancedConfig,
 referenceOptions, referenceError, prepareEditor, visibleFields,
 activate: () => { editorSession = editorSessions.next(); return editorSession } };`, { compilerOptions: options }).outputText
function harness(fields = grantFields, creating = false, row = { id: 8 }) {
  const requests = []
  const props = { modelValue: false, title: '服务授权', fields, creating, row, writeMode: 'grant' }
  const context = {
    ...vue, ...api('editorLifecycle'), ...api('referenceValues'), ...api('formValues'),
    ...api('uxContracts'), ...api('policyJson'),
    defineProps: () => props,
    defineEmits: () => () => {},
    describeSandIamError: error => ({ http: 403, code: null, detail: error.message }),
    getSandIamAdmin: (path, params) => {
      let resolve, reject
      const promise = new Promise((yes, no) => { resolve = yes; reject = no })
      requests.push({ path, params, resolve, reject })
      return promise
    },
    listSandIamGrantCandidates: (kind, params, id) => {
      let resolve, reject
      const promise = new Promise((yes, no) => { resolve = yes; reject = no })
      requests.push({ kind, params, id, resolve, reject })
      return promise
    },
    listSandIamResource: async endpoint => endpoint === 'application' ? [{ id: 11, organization_id: 1, organization_name: 'Org' }] : [],
    readSandIamResource: async (endpoint, id) => {
      if (endpoint === 'service' || endpoint === 'action') throw new Error('global catalog must not be read')
      return { id, environment_id: 111, application_id: 11, organization_id: 1, name: endpoint }
    }
  }
  const scope = vue.effectScope()
  scope.run(() => vm.runInNewContext(code, context))
  return { ...context.editor, requests, props, stop: () => scope.stop() }
}
function fillRequired(editor) {
  for (const field of grantFields) {
    if (field.required) editor.form[field.key] = field.kind === 'reference' ? 1 : 'sand-ai'
  }
}
const applicationContext = harness(applicationExperienceFields, true, null)
assert.deepEqual(
  Array.from(applicationContext.visibleFields.value.slice(0, 2), field => field.key),
  ['application_id', 'organization_id'],
  'application must be selected before the derived read-only organization'
)
applicationContext.stop()
const edit = harness(grantFields, false, { id: 8, data_class: 'private', expire_time: '2027-01-01 00:00:00' })
edit.resetForm()
fillRequired(edit)
edit.form.data_class = ''
edit.form.expire_time = ''
const cleared = edit.buildPayload()
assert.equal(cleared.data_class, null, 'cleared existing data class must be explicit null')
assert.equal(cleared.expire_time, null, 'cleared existing expiration must be explicit null')
assert.equal(cleared.id, 8)
edit.form.data_class = ' confidential '
edit.form.expire_time = '2027-01-01 00:00:00'
assert.equal(edit.buildPayload().data_class, 'confidential')
assert.equal(edit.buildPayload().expire_time, '2027-01-01 00:00:00')
edit.stop()
const create = harness(grantFields, true, null)
create.resetForm()
fillRequired(create)
assert.equal(create.buildPayload().data_class, null, 'new optional constraint defaults to null')
assert.equal(create.buildPayload().expire_time, null)
assert.equal(Object.hasOwn(create.buildPayload(), 'id'), false)
create.stop()
const other = harness([
  { key: 'plain', label: '普通文本', kind: 'text' },
  { key: 'date', label: '日期', kind: 'datetime' },
  { key: 'secret', label: '密钥', kind: 'text' },
  { key: 'required', label: '必填文本', kind: 'text', required: true, clearableToNull: true }
])
other.form.required = ' '
assert.throws(() => other.buildPayload(), /请填写必填文本/)
other.form.required = 'kept'
const unchanged = other.buildPayload()
assert.equal(unchanged.required, 'kept')
for (const key of ['plain', 'date', 'secret']) {
  assert.equal(Object.hasOwn(unchanged, key), false, `${key} blank must remain omitted`)
}
other.form.secret = 'new-secret'
assert.equal(other.buildPayload().secret, 'new-secret')
other.stop()
console.log('ResourceEditor clearable grant fields PASS')
let candidateStage = 'start'
let candidateComplete = false
process.on('beforeExit', () => {
  if (!candidateComplete) { console.error(`Candidate behavior did not finish: ${candidateStage}`); process.exitCode = 1 }
})
async function candidateBehavior() {
  const sent = []
  const resource = { exports: {} }
  vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.resolve(__dirname, '../api/resource.ts'), 'utf8'),
    { compilerOptions: options }).outputText, {
    exports: resource.exports, module: resource,
    require: name => {
      assert.equal(name, '@/utils/http')
      return { default: { get: async request => { sent.push(request); return [] } } }
    }
  })
  await resource.exports.listSandIamGrantCandidates('actions', {
    page: 2, limit: 20, workload_client_id: 1, service_id: 5, keywords: 'Parse'
  }, 99)
  assert.equal(sent[0].url, '/app/sand-iam/admin/grant/actions')
  assert.equal(JSON.stringify(sent[0].params), '{"page":2,"limit":20,"keywords":"Parse","workload_client_id":1,"service_id":5,"id":99}')
  const service = grantFields.find(field => field.key === 'service_id')
  const action = grantFields.find(field => field.key === 'service_action_id')
  const editor = harness()
  editor.resetForm()
  editor.form.workload_client_id = 1
  await vue.nextTick()
  const first = editor.loadReferenceField(service)
  assert.equal(editor.requests[0].kind, 'services')
  assert.equal(editor.requests[0].params.workload_client_id, 1)
  editor.requests[0].resolve([{ id: 5, code: 'ai', name: 'AI' }]); await first
  candidateStage = 'actions'
  editor.form.service_id = 5
  await vue.nextTick()
  const actions = editor.loadReferenceField(action)
  assert.equal(editor.requests[1].params.service_id, 5)
  editor.requests[1].resolve([{ id: 50, service_id: 5, name: 'Parse' }]); await actions
  candidateStage = 'switch'
  assert.equal(editor.referenceOptions.service_action_id[0].value, 50)
  const old = editor.loadReferenceField(action)
  editor.form.workload_client_id = 2
  await vue.nextTick()
  editor.requests[2].resolve([{ id: 51, service_id: 5, name: 'Old' }]); await old
  assert.equal(editor.referenceOptions.service_action_id.some(option => option.value === 51), false)
  editor.form.workload_client_id = 1
  editor.form.service_id = 5
  editor.form.service_action_id = 99
  await vue.nextTick()
  editor.form.service_id = 5
  await vue.nextTick()
  editor.form.service_action_id = 99
  const exact = editor.hydrateSelectedReference(action)
  assert.equal(editor.requests[3].id, 99)
  assert.equal(editor.requests[3].params.workload_client_id, 1)
  editor.requests[3].resolve([{ id: 99, service_id: 5, name: 'Exact' }]); await exact
  candidateStage = 'edit prepare'
  assert.equal(editor.referenceOptions.service_action_id[0].value, 99)
  editor.stop()
  // Editing a grant has only action id; exact candidate read restores its parent service.
  const edit = harness(grantFields, false, { id: 8, workload_client_id: 1, service_action_id: 99 })
  edit.props.modelValue = true
  const preparing = edit.prepareEditor(edit.activate())
  const tick = () => new Promise(resolve => setImmediate(resolve))
  await tick()
  assert.equal(edit.requests[0].kind, 'actions')
  assert.equal(edit.requests[0].id, 99)
  assert.equal(edit.requests[0].params.service_id, undefined)
  edit.requests[0].resolve([{ id: 99, service_id: 5, name: 'Exact' }]); await tick()
  assert.equal(edit.form.service_id, 5)
  let answered = 1
  let prepared = false
  preparing.then(() => { prepared = true })
  for (let turn = 0; turn < 10 && !prepared; turn++) {
    for (const request of edit.requests.slice(answered)) {
      assert.equal(request.params.workload_client_id, 1)
      if (request.kind === 'actions') assert.equal(request.params.service_id, 5)
      request.resolve(request.kind === 'services' ? [{ id: 5, name: 'AI' }] : [{ id: 99, service_id: 5, name: 'Exact' }])
      answered++
    }
    await tick()
  }
  assert.equal(prepared, true, 'edit initialization must complete')
  await preparing
  assert.equal(edit.form.service_action_id, 99)
  edit.stop()
  candidateComplete = true
  console.log('ResourceEditor scoped grant candidates PASS')
}
candidateBehavior().catch(error => { console.error(error); process.exitCode = 1 })
async function organizationAdminBehavior() {
  const tick = () => new Promise(resolve => setImmediate(resolve))
  const { adminGrantFields } = api('fields')
  const field = adminGrantFields.find(item => item.key === 'admin_user_id')
  assert.equal(field.kind, 'reference')
  const e = harness(adminGrantFields, false, { id: 1, admin_user_id: 9, organization_id: 2, status: 1 })
  e.resetForm(); e.form.admin_user_id = 9; e.form.organization_id = 2
  e.props.modelValue = true
  const session = e.activate()
  const hydrate = e.hydrateSelectedReference(field, session)
  assert.equal(e.requests[0].path, 'admin-organization-grant/admin-options')
  assert.equal(e.requests[0].params.id, 9)
  e.requests[0].resolve([{ id: 9, name: 'Admin', username: 'admin' }]); await hydrate
  assert.equal(e.buildPayload().admin_user_id, 9)
  e.searchReference(field, 'a'); await tick()
  assert.equal(e.requests.length, 1); assert.throws(() => e.buildPayload())
  e.searchReference(field, 'old'); e.searchReference(field, 'new')
  e.requests[2].resolve([{ id: 10, name: 'New' }]); await tick()
  e.requests[1].resolve([{ id: 11, name: 'Old' }]); await tick()
  assert.equal(e.referenceOptions.admin_user_id[0].value, 10)
  e.form.admin_user_id = 10; assert.equal(e.buildPayload().admin_user_id, 10)
  e.stop(); console.log('ResourceEditor organization administrator candidates PASS')
}
organizationAdminBehavior().catch(error => { console.error(error); process.exitCode = 1 })
function emptyStringBehavior() {
  const { authPolicyFields, apiResourceFields } = api('fields')
  const fields = [
    ...authPolicyFields.filter(field => ['webauthn_rp_id', 'webauthn_allowed_origins'].includes(field.key)),
    ...apiResourceFields.filter(field => ['required_scope', 'description'].includes(field.key))
  ]
  const editor = harness(fields, false, {
    id: 8, webauthn_rp_id: 'login.example.com',
    webauthn_allowed_origins: ['https://login.example.com'], required_scope: 'orders.read', description: 'Old'
  })
  editor.resetForm(); editor.showAdvancedConfig.value = true
  assert.equal(editor.form.webauthn_rp_id, 'login.example.com')
  editor.form.webauthn_rp_id = ''
  editor.form.webauthn_allowed_origins = ''
  editor.form.required_scope = ' '
  editor.form.description = ''
  const payload = editor.buildPayload()
  assert.equal(payload.webauthn_rp_id, '')
  assert.equal(JSON.stringify(payload.webauthn_allowed_origins), '[]')
  assert.equal(payload.required_scope, '')
  assert.equal(Object.hasOwn(payload, 'description'), false)
  assert.equal(api('uxContracts').describeAuthPolicyPayloadError(payload), null)
  editor.form.required_scope = 'orders.read'; assert.equal(editor.buildPayload().required_scope, 'orders.read')
  editor.stop()
  const create = harness(fields, true, null)
  create.resetForm(); create.showAdvancedConfig.value = true
  assert.equal(create.buildPayload().webauthn_rp_id, '')
  assert.equal(create.buildPayload().required_scope, '')
  assert.equal(JSON.stringify(create.buildPayload().webauthn_allowed_origins), '[]')
  assert.equal(api('uxContracts').describeAuthPolicyPayloadError(create.buildPayload()), null)
  create.stop()
  const required = harness(fields.map(field => ({ ...field, required: true })))
  required.resetForm(); required.showAdvancedConfig.value = true
  assert.throws(() => required.buildPayload())
  required.stop()
  const strictArray = harness(fields.filter(field => field.jsonArray).map(field => ({ ...field, allowEmptyArray: false })))
  strictArray.resetForm(); strictArray.showAdvancedConfig.value = true
  assert.throws(() => strictArray.buildPayload())
  strictArray.stop()
  console.log('ResourceEditor explicit empty-string configuration PASS')
}
emptyStringBehavior()
function optionalClientAndExperienceBehavior() {
  const { oauthClientFields, applicationExperienceFields } = api('fields')
  const urls = ['frontchannel_logout_uri', 'backchannel_logout_uri', 'default_audience',
    'logo_url', 'terms_url', 'privacy_url']
  const arrays = ['post_logout_redirect_uris', 'allowed_audiences']
  const fields = [...oauthClientFields, ...applicationExperienceFields]
    .filter(field => [...urls, ...arrays].includes(field.key))
  const row = { id: 5 }
  for (const key of urls) row[key] = 'https://app.example.com/old'
  for (const key of arrays) row[key] = ['https://app.example.com/old']
  const edit = harness(fields, false, row)
  edit.resetForm(); edit.showAdvancedConfig.value = true
  for (const key of urls) assert.equal(edit.form[key], row[key])
  for (const key of [...urls, ...arrays]) edit.form[key] = ''
  const payload = edit.buildPayload()
  for (const key of urls) assert.equal(payload[key], '', key)
  for (const key of arrays) assert.equal(JSON.stringify(payload[key]), '[]', key)
  edit.stop()
  const create = harness(fields, true, null)
  create.resetForm(); create.showAdvancedConfig.value = true
  const defaults = create.buildPayload()
  for (const key of urls) assert.equal(defaults[key], '', key)
  for (const key of arrays) assert.equal(JSON.stringify(defaults[key]), '[]', key)
  create.stop()
  for (const key of ['redirect_uris', 'allowed_scopes']) {
    const strict = harness(oauthClientFields.filter(field => field.key === key), true, null)
    strict.resetForm()
    assert.equal(JSON.stringify(strict.buildPayload()[key]), JSON.stringify(
      key === 'redirect_uris' ? ['https://app.example.com/callback'] : ['openid', 'profile']))
    strict.form[key] = ''
    assert.throws(() => strict.buildPayload(), undefined, `${key} must reject empty`)
    strict.stop()
  }
  console.log('ResourceEditor OAuth and experience optional clearing PASS')
}
optionalClientAndExperienceBehavior()
function optionalNetworkAndCasArrays() {
  const { casServiceFields, applicationNetworkPolicyFields } = api('fields')
  const values = { released_attributes: ['email'], allow_cidrs: ['10.20.0.0/24'], deny_cidrs: ['10.30.0.0/24'] }
  const fields = [...casServiceFields, ...applicationNetworkPolicyFields].filter(field => Object.hasOwn(values, field.key))
  const create = harness(fields, true, null)
  create.resetForm()
  const defaults = create.buildPayload()
  assert.equal(JSON.stringify(defaults.released_attributes), '["display_name"]')
  assert.equal(JSON.stringify(defaults.allow_cidrs), '[]')
  assert.equal(JSON.stringify(defaults.deny_cidrs), '[]')
  create.stop()
  const edit = harness(fields, false, { id: 4, ...values })
  edit.resetForm()
  for (const [key, value] of Object.entries(values)) {
    assert.equal(edit.form[key], value.join('\n'))
    assert.equal(JSON.stringify(edit.buildPayload()[key]), JSON.stringify(value))
  }
  for (const key of Object.keys(values)) edit.form[key] = ''
  for (const key of Object.keys(values)) assert.equal(JSON.stringify(edit.buildPayload()[key]), '[]')
  edit.stop()
  const required = harness(fields.map(field => ({ ...field, required: true })))
  required.resetForm()
  for (const key of Object.keys(values)) required.form[key] = ''
  assert.throws(() => required.buildPayload())
  required.stop()
  console.log('ResourceEditor network and CAS array clearing PASS')
}
optionalNetworkAndCasArrays()
function grantOptionalConstraints() {
  const fields = grantFields.filter(field => ['quota_policy', 'network_policy'].includes(field.key))
  const empty = harness(fields, false, { id: 7, quota_policy: [], network_policy: [] })
  empty.resetForm(); empty.showAdvancedConfig.value = true
  assert.equal(JSON.stringify(empty.buildPayload().quota_policy), '{}')
  assert.equal(JSON.stringify(empty.buildPayload().network_policy), '{}')
  empty.stop()
  const stored = harness(fields, false, { id: 7,
    quota_policy: { max_invocation_attempts: 10, window_seconds: 60 },
    network_policy: { allow_cidrs: ['10.0.0.0/8'], deny_cidrs: [] } })
  stored.resetForm(); stored.showAdvancedConfig.value = true
  assert.equal(stored.buildPayload().quota_policy.max_invocation_attempts, 10)
  assert.equal(JSON.stringify(stored.buildPayload().network_policy.allow_cidrs), '["10.0.0.0/8"]')
  stored.form.quota_policy = ''; stored.form.network_policy = ''
  assert.equal(JSON.stringify(stored.buildPayload().quota_policy), '{}')
  assert.equal(JSON.stringify(stored.buildPayload().network_policy), '{}')
  stored.form.quota_policy = '[1]'
  assert.throws(() => stored.buildPayload(), /必须是对象/)
  stored.stop()
  const malformed = harness(fields, false, { id: 7, quota_policy: [1], network_policy: [] })
  malformed.resetForm(); malformed.showAdvancedConfig.value = true
  assert.throws(() => malformed.buildPayload(), /必须是对象/)
  malformed.stop()
  console.log('ResourceEditor grant optional constraints PASS')
}
grantOptionalConstraints()
