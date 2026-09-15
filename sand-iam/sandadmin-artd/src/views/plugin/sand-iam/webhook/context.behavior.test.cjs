const assert = require('node:assert/strict')
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const req = require('node:module').createRequire(path.join(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = req('typescript'), vue = req('vue')
const compile = text => ts.transpileModule(text, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText
const contracts = {}
vm.runInNewContext(compile(fs.readFileSync(path.join(__dirname, '../api/delegationContracts.ts'), 'utf8')), { exports: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const code = compile(ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified, n, ast)).join('\n') +
  ';globalThis.page={applicationId,applications,webhooks,page,pageSize,total,loading,saving,code,name,url,eventTypes,editingId,issuedSecret,secretOwner,secretDialogOpen,requestError,loadApplications,loadWebhooks,beginCreate,beginEdit,saveWebhook,rotateSecret,disableWebhook,closeSecretDialog};')
function deferred() { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b}); return {promise,resolve,reject} }
function harness() {
  const reads=[], writes=[], confirms=[], permissions=vue.ref(['index','save','update','disable','secret_rotate'])
  const io = list => (path,params) => { const d=deferred(); list.push({...d,path,params}); return d.promise }
  const context={...vue,...contracts,onMounted(){},useAuth:()=>({hasAuth:p=>permissions.value.includes(p.split(':').at(-1))}),
    describeSandIamError:e=>({detail:e.message}),ElMessage:{success(){},error(){}},
    ElMessageBox:{confirm:()=>{const d=deferred();confirms.push(d);return d.promise}},
    listSandIamResource:io(reads),getSandIamAdmin:io(reads),postSandIamAction:io(writes)}
  const scope=vue.effectScope(); scope.run(()=>vm.runInNewContext(code,context))
  return {p:context.page,reads,writes,confirms,permissions,stop:()=>scope.stop()}
}
const tick=()=>new Promise(r=>setImmediate(r))
const row={id:21,application_id:101,code:'hook',name:'Orders',url:'https://example.test/hooks',
  event_types:['identity.created'],timeout_seconds:10,max_attempts:5,status:1,secret_version:1}
async function fill(h,rows=[row],total=21){h.reads.at(-1).resolve({data:rows,total});await tick()}
function draft(p){p.code.value='new-hook';p.name.value='New';p.url.value='https://example.test/hooks';p.eventTypes.value=['identity.created']}
async function run(){
  const h=harness(),p=h.p
  let task=p.loadApplications('App101');assert.equal(h.reads.at(-1).params.keywords,'App101')
  h.reads.at(-1).resolve([{id:101,name:'App101',status:1},{id:102,name:'App102',status:1}]);await task
  p.applicationId.value='101';await fill(h)
  p.page.value=2;assert.equal(JSON.stringify(h.reads.at(-1).params),JSON.stringify({application_id:101,page:2,limit:20}));await fill(h);assert.equal(p.total.value,21)
  // Edit from all-app results must retain the row's application without a selected filter.
  p.applicationId.value='';await fill(h);p.beginEdit(p.webhooks.value[0])
  assert.equal(p.applicationId.value,'');p.name.value='Edited'
  task=p.saveWebhook();await p.saveWebhook();assert.equal(h.writes.length,1)
  assert.equal(h.writes[0].path,'webhook/update');assert.equal(h.writes[0].params.id,21)
  assert.equal(h.writes[0].params.application_id,undefined)
  h.writes[0].reject(new Error('retry'));await task;assert.equal(p.saving.value,false);assert.equal(p.name.value,'Edited')
  task=p.saveWebhook();h.writes.at(-1).resolve({});await tick();await fill(h);await task
  // Confirmation is a write lock; scope change cancels the pending action.
  task=p.rotateSecret(p.webhooks.value[0]);await p.disableWebhook(p.webhooks.value[0]);assert.equal(h.confirms.length,1)
  p.applicationId.value='102';h.confirms[0].resolve();await task;assert.equal(h.writes.length,2);await fill(h,[])
  p.applicationId.value='101';await fill(h)
  task=p.rotateSecret(p.webhooks.value[0]);h.confirms.at(-1).resolve();await tick()
  assert.equal(h.writes.at(-1).path,'webhook/secret/rotate');assert.equal(h.writes.at(-1).params.id,21)
  p.applicationId.value='102';await fill(h,[])
  h.writes.at(-1).resolve({id:21,secret:'original-secret',secret_available:true});await task
  assert.equal(p.issuedSecret.value,'original-secret');assert.match(p.secretOwner.value,/101.*Orders/)
  draft(p);await p.saveWebhook();assert.equal(h.writes.length,3)
  // Footer and native close share the same explicit acknowledgement path.
  let closed=0;task=p.closeSecretDialog(()=>closed++);assert.equal(p.issuedSecret.value,'original-secret')
  h.confirms.at(-1).reject('cancel');await task;assert.equal(closed,0);assert.equal(p.issuedSecret.value,'original-secret')
  task=p.closeSecretDialog(()=>closed++);h.confirms.at(-1).resolve();await task
  assert.equal(closed,1);assert.equal(p.issuedSecret.value,'')
  draft(p);task=p.saveWebhook();p.applicationId.value='101';await fill(h)
  p.name.value='New scope draft';h.writes.at(-1).resolve({id:22,secret:'created-secret'});await task
  assert.equal(p.name.value,'New scope draft');assert.match(p.secretOwner.value,/102.*New/);assert.equal(p.issuedSecret.value,'created-secret')
  // List responses from the old app cannot populate the latest list.
  const old=p.loadWebhooks();p.applicationId.value='102';await fill(h,[],0)
  h.reads.at(-2).resolve({data:[row],total:99});await old;assert.equal(p.total.value,0)
  p.applicationId.value='101';await fill(h)
  task=p.disableWebhook(p.webhooks.value[0]);h.permissions.value=['index'];h.confirms.at(-1).resolve();await task
  assert.equal(h.writes.length,4)
  await p.saveWebhook();await p.rotateSecret(p.webhooks.value[0]);assert.equal(h.writes.length,4)
  h.stop();assert.equal(p.issuedSecret.value,'');assert.equal(p.secretOwner.value,'')
  const h2=harness(),q=h2.p
  q.applications.value=[{id:101,name:'App',status:1}];q.applicationId.value='101';await fill(h2)
  q.beginEdit(q.webhooks.value[0]);task=q.saveWebhook();q.name.value='Changed during update'
  h2.writes.at(-1).resolve({});await task
  assert.equal(q.name.value,'Changed during update');assert.equal(h2.reads.length,1)
  task=q.disableWebhook(q.webhooks.value[0]);h2.confirms.at(-1).resolve();await tick()
  h2.writes.at(-1).reject(new Error('disable failed'));await task
  assert.equal(q.saving.value,false);assert.equal(q.requestError.value.detail,'disable failed')
  task=q.disableWebhook(q.webhooks.value[0]);h2.confirms.at(-1).resolve();await tick()
  h2.writes.at(-1).resolve({});await tick();await fill(h2,[],0);await task
  const first=q.loadApplications('old'),second=q.loadApplications('new')
  h2.reads.at(-1).resolve([{id:102,name:'New'}]);await second
  h2.reads.at(-2).reject(new Error('old search'));await first
  assert.equal(q.applications.value[0].id,102)
  assert.notEqual(q.requestError.value?.detail,'old search')
  h2.stop()
  assert(!source.includes('@closed="clearSecret"'),'closed event must not erase a newer secret')
  console.log('Webhook context behavior PASS (real SFC/parser, offline requests)')
}
run().catch(error=>{console.error(error);process.exitCode=1})
