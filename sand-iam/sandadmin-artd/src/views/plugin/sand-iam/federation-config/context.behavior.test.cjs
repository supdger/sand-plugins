const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/federationContracts.ts'), 'utf8'),
  { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = { providers, applications, selectedProviderId, mountApplicationId, providerType,
clientId, clientSecret, issuer, discoveryUrl, redirectUri, handoffReturnUris, spPrivateKey, ldapBindPassword, kerberosPrincipal, kerberosKeytabRef, kerberosRealms,
searchProviders, saveConfig, mountProvider, syncDirectory, saving, lastRequestHint, requestError, buildConfig, buildMapping,
presets,presetCode,presetClientId,presetRedirectUri,presetReturnUris,presetTenantId,presetError,presetBusy,loadPresets,generatePresetDraft,oauthScope };`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true) {
  const writes = [], reads = [], confirms = []
  const context = { ...vue, ...contracts.exports, onMounted: () => {}, useAuth: () => ({ hasAuth: () => allowed }), ElMessage: { success: () => {} },
    ElMessageBox: { confirm: () => { const d = deferred(); confirms.push(d); return d.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    listSandIamResource: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    getSandIamAdmin: (path, params) => { const d = deferred(); reads.push({ ...d, path, params }); return d.promise },
    postSandIamAction: (path, params) => { const d = deferred(); writes.push({ ...d, path, params }); return d.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, writes, reads, confirms, stop: () => scope.stop() }
}
const provider = (id, type = 'oidc') => ({ id, name: `P${id}`, provider_type: type, status: 1, organization_id: 1, scope_type: 'organization' })
const tick = () => new Promise(resolve => setImmediate(resolve))
function fill(p) {
  p.clientId.value = 'client'; p.clientSecret.value = 'secret'; p.issuer.value = 'https://issuer.example.test'
  p.discoveryUrl.value = 'https://issuer.example.test/.well-known/openid-configuration'
  p.redirectUri.value = 'https://login.example.test/callback'; p.handoffReturnUris.value = ['https://app.example.test/callback']
}
async function run() {
  const h = harness(); const a = h.p.searchProviders(' old '), b = h.p.searchProviders(' P101 ')
  assert.equal(h.reads[1].params.keywords, 'P101')
  h.reads[1].resolve([provider(101), provider(102, 'ldap')]); await b
  h.reads[0].resolve([provider(1)]); await a
  assert.equal(h.p.providers.value[0].id, 101)
  h.p.selectedProviderId.value = '101'; fill(h.p)
  const save = h.p.saveConfig(); await h.p.saveConfig(); assert.equal(h.writes.length, 1)
  assert.equal(h.writes[0].params.provider_id, 101); assert.equal(h.writes[0].params.config.client_secret, 'secret')
  h.writes[0].reject(new Error('retry')); await save; assert.equal(h.p.clientSecret.value, 'secret')
  const retry = h.p.saveConfig(); h.p.selectedProviderId.value = '102'
  assert.equal(h.p.clientSecret.value, ''); assert.equal(h.p.clientId.value, '')
  h.p.ldapBindPassword.value = 'new password'; h.writes[1].resolve({}); await retry
  assert.equal(h.p.ldapBindPassword.value, 'new password'); assert.equal(h.p.lastRequestHint.value, '')
  h.p.applications.value = [{ id: 7, organization_id: 1, status: 1 }]
  h.p.mountApplicationId.value = '7'
  const mount = h.p.mountProvider(); await h.p.mountProvider()
  assert.equal(JSON.stringify(h.writes[2].params), '{"provider_id":102,"application_id":7}')
  h.writes[2].resolve({}); await mount
  const sync = h.p.syncDirectory(); await h.p.syncDirectory(); assert.equal(h.confirms.length, 1)
  h.p.selectedProviderId.value = '101'; h.confirms[0].resolve(); await sync
  assert.equal(h.writes.length, 3)
  h.p.selectedProviderId.value = '102'; h.p.mountApplicationId.value = '7'
  const normal = h.p.syncDirectory(); h.confirms[1].resolve(); await tick()
  h.writes[3].resolve({ state: 'succeeded' }); await normal
  assert.match(h.p.lastRequestHint.value, /完整完成/)
  h.p.providerType.value = 'kerberos'
  h.p.kerberosPrincipal.value = 'HTTP/app@EXAMPLE.COM'; h.p.kerberosKeytabRef.value = 'ref'; h.p.kerberosRealms.value = ['EXAMPLE.COM']
  const config = h.p.buildConfig()
  assert.equal(config.require_channel_binding, true); assert.equal(config.require_replay_cache, true); assert.equal(config.require_mutual_auth, true)
  h.p.spPrivateKey.value = 'secret'; h.stop(); assert.equal(h.p.spPrivateKey.value, '')
  const denied = harness(false); denied.p.providers.value = [provider(1)]; denied.p.selectedProviderId.value = '1'
  fill(denied.p); await denied.p.saveConfig(); await denied.p.mountProvider(); await denied.p.syncDirectory(); await denied.p.searchProviders('p')
  assert.equal(denied.writes.length + denied.reads.length + denied.confirms.length, 0); denied.stop()
  console.log('Federation config context behavior PASS')
}
async function presetBehavior() {
  // Use the real catalog/draft generator, which performs no HTTP or persistence.
  const catalogPath = path.resolve(__dirname, '../../../../../../plugin/sand-iam/app/developer/ProviderPresetCatalog.php')
  const fixtures = JSON.parse(require('node:child_process').execFileSync('php', ['-r',
    'require $argv[1]; $class = "plugin\\\\SandIam\\\\app\\\\developer\\\\ProviderPresetCatalog"; $drafts=[]; foreach($class::all() as $preset) { if($preset["compatibility"]==="compatible") $drafts[$preset["code"]]=$class::draft($preset["code"],["client_id"=>"client","redirect_uri"=>"https://login.example.test/callback","handoff_return_uris"=>["https://app.example.test/callback"],"tenant_id"=>"tenant"]); } echo json_encode(["presets"=>$class::all(),"drafts"=>$drafts]);',
    catalogPath], { encoding: 'utf8' }))
  for (const [presetCode, draft] of Object.entries(fixtures.drafts)) {
    const h = harness(), p = h.p
    p.providers.value = [provider(1, draft.provider_type), provider(2, draft.provider_type)]
    p.selectedProviderId.value = '1'
    let task = p.loadPresets(); assert.equal(h.reads[0].path, 'identity-provider-preset/index')
    h.reads[0].resolve({ data: fixtures.presets }); await task
    p.presetCode.value = presetCode
    p.presetClientId.value = 'client'; p.presetRedirectUri.value = 'https://login.example.test/callback'
    p.presetReturnUris.value = 'https://app.example.test/callback'; p.presetTenantId.value = 'tenant'
    task = p.generatePresetDraft()
    assert.equal(h.writes[0].path, 'identity-provider-preset/draft')
    assert.equal(Object.keys(h.writes[0].params).some(key => /secret|password|token/.test(key)), false)
    h.writes[0].resolve(draft); await task
    const config = p.buildConfig()
    for (const [key, value] of Object.entries(draft.config)) assert.equal(JSON.stringify(config[key]), JSON.stringify(value))
    assert.equal(JSON.stringify(p.buildMapping()), JSON.stringify(draft.attribute_mapping))
    assert.equal(p.clientSecret.value, '')
    assert.equal(h.writes.length, 1, 'draft must not save configuration')
    // Existing secret/config requires confirmation, then secrets stay out of payload.
    p.clientSecret.value = 'sensitive-secret'
    task = p.generatePresetDraft(); await p.generatePresetDraft(); assert.equal(h.confirms.length, 1)
    h.confirms[0].reject('cancel'); await task
    assert.equal(h.writes.length, 1); assert.equal(p.clientSecret.value, 'sensitive-secret')
    task = p.generatePresetDraft(); h.confirms.at(-1).resolve(); await tick()
    assert.equal(JSON.stringify(h.writes.at(-1).params).includes('sensitive-secret'), false)
    p.clientSecret.value = 'new-secret'; h.writes.at(-1).resolve(draft); await task
    assert.equal(p.clientSecret.value, 'new-secret', 'late draft must not overwrite edited config')
    task = p.generatePresetDraft(); p.presetClientId.value = 'changed'
    h.confirms.at(-1).resolve(); await task; assert.equal(h.writes.length, 2)
    // Changing source while waiting for the response must not fill its new form.
    task = p.generatePresetDraft(); h.confirms.at(-1).resolve(); await tick()
    p.selectedProviderId.value = '2'; h.writes.at(-1).resolve(draft); await task
    assert.equal(p.clientId.value, '')
    p.presetCode.value = 'feishu_user_authorization'; await p.generatePresetDraft()
    assert.equal(h.writes.length, 3, 'manual preset must not post')
    h.stop()
  }
  const h = harness(), p = h.p
  p.providers.value = [provider(1)]; p.selectedProviderId.value = '1'
  let task = p.loadPresets(); h.reads[0].reject(new Error('catalog failed')); await task
  assert.equal(p.presetError.value, 'catalog failed')
  task = p.loadPresets(); h.reads[1].resolve(fixtures.presets); await task
  p.presetCode.value = 'google_oidc'; p.presetClientId.value = 'client'; p.presetRedirectUri.value = 'https://login.example.test/callback'
  p.presetReturnUris.value = 'https://app.example.test/callback'
  task = p.generatePresetDraft(); h.writes[0].reject(new Error('draft failed')); await task
  assert.equal(p.presetBusy.value, false); assert.equal(p.presetError.value, 'draft failed')
  task = p.generatePresetDraft(); h.stop(); h.writes[1].resolve(fixtures.drafts.google_oidc); await task
  assert.equal(p.clientId.value, '')
  const denied = harness(false); await denied.p.loadPresets(); await denied.p.generatePresetDraft()
  assert.equal(denied.reads.length + denied.writes.length, 0); denied.stop()
  console.log('Federation preset drafts PASS (real PHP catalog, offline SFC)')
}
run().then(presetBehavior).catch(error => { console.error(error); process.exitCode = 1 })
