const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified, n, ast)).join('\n')
const code = ts.transpileModule(`${body}; globalThis.page = {accessToken,currentPassword,totpName,totpCode,factors,pendingFactorId,oneTimeSecret,oneTimeOtpauth,oneTimeCodes,requestError,lastRequestId,acting,loadFactors,startTotp,confirmTotp,rename,revoke,regenerate,acknowledgeCodes};`,
  { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
function deferred() { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }
function harness() {
  const reads = [], writes = [], prompts = []; let unmount
  const context = { ...vue, onUnmounted: fn => { unmount = fn }, ElMessage: { success() {} },
    ElMessageBox: { prompt: () => { const d = deferred(); prompts.push(d); return d.promise } },
    describeRuntimeAuthError: error => ({ detail: error.message, http: 400 }),
    listSandIamRuntimeFactors: token => { const d = deferred(); reads.push({ ...d, token }); return d.promise } }
  for (const name of ['startSandIamTotp','confirmSandIamTotp','renameSandIamRuntimeFactor','revokeSandIamRuntimeFactor','regenerateSandIamRecoveryCodes']) {
    context[name] = (...args) => { const d = deferred(); writes.push({ ...d, name, args }); return d.promise }
  }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { p: context.page, reads, writes, prompts, stop: () => { unmount(); scope.stop() } }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const result = data => ({ data, requestId: 'request' })
const factor = { id: 7, type: 'totp', name: 'Phone', status: 1 }
async function populate(h) { const p = h.p.loadFactors(); h.reads.at(-1).resolve(result([factor])); await p; return h.p.factors.value[0] }
async function run() {
  const h = harness(), p = h.p
  await p.startTotp(); await p.regenerate(); assert.equal(h.writes.length + h.prompts.length, 0)
  p.accessToken.value = 'A'; p.currentPassword.value = 'password'; p.totpName.value = 'Mine'
  let pending = p.startTotp(); await p.startTotp(); assert.equal(h.writes.length, 1)
  assert.deepEqual(h.writes[0].args, ['A','Mine','password'])
  h.writes[0].resolve(result({ factorId: 7, secret: 'secret', otpauthUri: 'uri' })); await pending
  assert.equal(p.pendingFactorId.value, 7); assert.equal(p.currentPassword.value, '')
  await p.startTotp(); assert.equal(h.writes.length, 1)
  p.totpCode.value = '123456'; pending = p.confirmTotp()
  assert.deepEqual(h.writes[1].args, ['A',7,'123456'])
  h.writes[1].resolve(result(['code'])); await tick(); h.reads.at(-1).resolve(result([factor])); await pending
  assert.equal(p.oneTimeSecret.value, ''); assert.equal(p.totpCode.value, '')
  assert.equal(p.pendingFactorId.value, null); assert.equal(p.oneTimeCodes.value[0], 'code')
  await p.regenerate(); assert.equal(h.prompts.length, 0)
  p.acknowledgeCodes(); pending = p.regenerate(); await p.regenerate()
  assert.equal(h.prompts.length, 1); h.prompts.at(-1).resolve({ value: 'pw' }); await tick()
  assert.deepEqual(h.writes.at(-1).args, ['A','pw'])
  h.writes.at(-1).reject(new Error('retry')); await pending; assert.equal(p.acting.value, false)
  pending = p.regenerate(); h.prompts.at(-1).resolve({ value: 'pw' }); await tick()
  h.writes.at(-1).resolve(result(['new-code'])); await pending
  p.accessToken.value = 'B'; assert.equal(p.oneTimeCodes.value.length, 0)
  assert.equal(p.requestError.value, null); assert.equal(p.lastRequestId.value, '')
  let row = await populate(h)
  for (const action of ['rename','revoke']) {
    pending = p[action](row); h.prompts.at(-1).resolve({ value: action === 'rename' ? 'New' : 'pw' }); await tick()
    assert.deepEqual(h.writes.at(-1).args, ['B',7,'totp',action === 'rename' ? 'New' : 'pw'])
    h.writes.at(-1).resolve(result(null)); await tick(); h.reads.at(-1).resolve(result([factor])); await pending
    row = p.factors.value[0]
  }
  let count = h.writes.length
  pending = p.revoke(row); p.accessToken.value = 'C'; h.prompts.at(-1).resolve({ value: 'pw' }); await pending
  assert.equal(h.writes.length, count)
  await p.rename(row); assert.equal(h.writes.length, count)
  pending = p.startTotp(); p.accessToken.value = 'D'
  h.writes.at(-1).resolve(result({ factorId: 99, secret: 'old', otpauthUri: 'old' })); await pending
  assert.equal(p.pendingFactorId.value, null); assert.equal(p.oneTimeSecret.value, '')
  const old = p.loadFactors(), latest = p.loadFactors()
  h.reads.at(-1).resolve(result([factor])); await latest; h.reads.at(-2).reject(new Error('old')); await old
  assert.equal(p.factors.value.length, 1); assert.equal(p.requestError.value, null)
  pending = p.regenerate(); h.prompts.at(-1).resolve({ value: 'pw' }); await tick()
  p.accessToken.value = 'E'; h.writes.at(-1).resolve(result(['old-code'])); await pending
  assert.equal(p.oneTimeCodes.value.length, 0)
  pending = p.startTotp()
  h.writes.at(-1).resolve(result({ factorId: 8, secret: 'bound', otpauthUri: 'uri' })); await pending
  p.totpCode.value = '111111'; pending = p.confirmTotp()
  h.writes.at(-1).reject(new Error('bad-code')); await pending
  assert.equal(p.pendingFactorId.value, 8); assert.equal(p.acting.value, false)
  pending = p.confirmTotp(); p.accessToken.value = 'F'
  h.writes.at(-1).resolve(result(['wrong-user'])); await pending
  assert.equal(p.oneTimeCodes.value.length, 0); assert.equal(p.pendingFactorId.value, null)
  assert.equal(p.totpCode.value, ''); assert.equal(p.requestError.value, null)
  pending = p.startTotp(); h.stop()
  h.writes.at(-1).resolve(result({ factorId: 8, secret: 'late', otpauthUri: 'late' })); await pending
  assert.equal(p.accessToken.value, ''); assert.equal(p.currentPassword.value, '')
  assert.equal(p.oneTimeSecret.value, ''); assert.equal(p.pendingFactorId.value, null)
  console.log('Runtime MFA context behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
