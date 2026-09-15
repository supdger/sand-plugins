const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json')), ts = req('typescript'), vue = req('vue')
const compile = text => ts.transpileModule(text, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS } }).outputText
const contracts = {}
vm.runInNewContext(compile(fs.readFileSync(path.join(__dirname, '../api/radiusNasContracts.ts'), 'utf8')), { exports: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true), printer = ts.createPrinter()
const body = ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified, n, ast)).join('\n')
const code = compile(`${body};globalThis.page={applicationId,applications,devices,name,sourceCidr,editingId,sharedSecret,secretTargetId,page,total,saving,loadApplications,loadDevices,saveDevice,configureSecret,disableDevice,beginEdit};`)
function deferred() { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b}); return {promise,resolve,reject} }
function harness() {
  const reads=[], writes=[], confirms=[], permission=vue.ref(true)
  const io = list => (path,params) => { const d=deferred(); list.push({...d,path,params}); return d.promise }
  const context={...vue,...contracts,onMounted(){},useAuth:()=>({hasAuth:()=>permission.value}),describeSandIamError:e=>({detail:e.message}),
    ElMessage:{success(){}},ElMessageBox:{confirm:()=>{const d=deferred();confirms.push(d);return d.promise}},
    listSandIamResource:io(reads),getSandIamAdmin:io(reads),postSandIamAction:io(writes)}
  const scope=vue.effectScope();scope.run(()=>vm.runInNewContext(code,context))
  const p=context.page;p.applications.value=[{id:1},{id:2}];p.applicationId.value='1'
  return {p,reads,writes,confirms,permission,stop:()=>scope.stop()}
}
const tick=()=>new Promise(r=>setImmediate(r))
const row={id:21,application_id:1,name:'NAS',source_cidr:'10.0.0.0/24',accounting_enabled:false,secret_configured:false,status:1}
async function refresh(h){await tick();h.reads.at(-1).resolve({data:[row],total:21});await tick()}
async function run(){
  const h=harness(),p=h.p;p.name.value='NAS';p.sourceCidr.value='10.0.0.0/24'
  let pending=p.saveDevice();await p.saveDevice();assert.equal(h.writes.length,1)
  assert.equal(JSON.stringify(h.writes[0].params),'{"application_id":1,"name":"NAS","source_cidr":"10.0.0.0/24","accounting_enabled":false}')
  h.writes[0].resolve({});await refresh(h);await pending
  p.page.value=2;assert.equal(h.reads.at(-1).params.page,2);await refresh(h);assert.equal(p.total.value,21)
  p.beginEdit(p.devices.value[0]);p.name.value='New';pending=p.saveDevice()
  assert.equal(h.writes.at(-1).params.id,21);assert.equal(h.writes.at(-1).params.application_id,undefined)
  h.writes.at(-1).reject(new Error('retry'));await pending;assert.equal(p.saving.value,false)
  pending=p.saveDevice();h.writes.at(-1).resolve({});await refresh(h);await pending
  p.secretTargetId.value=21;p.sharedSecret.value='old';pending=p.configureSecret();await p.configureSecret()
  assert.equal(h.writes.at(-1).params.shared_secret,'old')
  p.sharedSecret.value='new';h.writes.at(-1).resolve({secret_configured:true});await pending
  assert.equal(p.sharedSecret.value,'new')
  pending=p.configureSecret();p.applicationId.value='2';p.sharedSecret.value='B-secret'
  h.writes.at(-1).resolve({secret_configured:true});await pending;assert.equal(p.sharedSecret.value,'B-secret')
  assert.equal(p.secretTargetId.value,null)
  await tick();if(h.reads.at(-1).params.application_id===2){h.reads.at(-1).resolve({data:[],total:0});await tick()}
  p.applicationId.value='1';pending=p.loadDevices();await refresh(h);await pending
  let count=h.writes.length;await p.disableDevice({...row});assert.equal(h.writes.length,count)
  pending=p.disableDevice(p.devices.value[0]);p.applicationId.value='2';h.confirms.at(-1).resolve();await pending
  assert.equal(h.writes.length,count)
  p.applicationId.value='1';pending=p.loadDevices();await refresh(h);await pending
  pending=p.disableDevice(p.devices.value[0]);h.confirms.at(-1).resolve();await tick()
  assert.equal(h.writes.at(-1).params.id,21);h.writes.at(-1).resolve({});await refresh(h);await pending
  const search=p.loadApplications('App101');assert.equal(h.reads.at(-1).params.keywords,'App101')
  h.reads.at(-1).resolve([{id:101}]);await search;assert.equal(p.applications.value[0].id,101)
  h.permission.value=false;count=h.writes.length;await p.saveDevice();await p.configureSecret();assert.equal(h.writes.length,count)
  p.sharedSecret.value='secret';h.stop();assert.equal(p.sharedSecret.value,'');assert.equal(p.secretTargetId.value,null)
  console.log('RADIUS NAS context behavior PASS (offline stubs, real parsers)')
}
run().catch(e=>{console.error(e);process.exitCode=1})
