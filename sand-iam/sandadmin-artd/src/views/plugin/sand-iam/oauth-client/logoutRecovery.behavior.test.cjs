const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const {createRequire}=require('node:module')
const req=createRequire(path.join(process.env.SAND_IAM_FRONTEND_ROOT,'package.json')),ts=req('typescript'),vue=req('vue')
const compile=s=>ts.transpileModule(s,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText
const contracts={};vm.runInNewContext(compile(fs.readFileSync(path.join(__dirname,'../api/logoutDeliveryContracts.ts'),'utf8')),{exports:contracts})
const source=fs.readFileSync(path.join(__dirname,'LogoutDeliveries.vue'),'utf8')
const ast=ts.createSourceFile('page.ts',source.slice(source.indexOf('>')+1,source.indexOf('</script>')),ts.ScriptTarget.Latest,true),printer=ts.createPrinter()
const code=compile(ast.statements.filter(n=>!ts.isImportDeclaration(n)).map(n=>printer.printNode(ts.EmitHint.Unspecified,n,ast)).join('\n')+
 ';globalThis.page={load,recover,retryFailed,rows,page,failedRequest,recovering};')
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
function harness(){
 const reads=[],writes=[],confirms=[],permission=vue.ref(true),props=vue.reactive({clientId:1,clientName:'Client'});let next=0
 const context={...vue,...contracts,defineProps:()=>props,defineEmits:()=>()=>{},useAuth:()=>({hasAuth:()=>permission.value}),
 describeSandIamError:e=>({detail:e.message,code:null}),createSandIamRequestId:()=>`request-${++next}`,SAND_IAM_ADMIN_PREFIX:'/app/sand-iam/admin',
 ElMessage:{success(){}},ElMessageBox:{confirm:()=>{const d=deferred();confirms.push(d);return d.promise}},
 getSandIamAdmin:(path,params)=>{const d=deferred();reads.push({...d,path,params});return d.promise},
 request:{post:params=>{const d=deferred();writes.push({...d,params});return d.promise}}}
 const scope=vue.effectScope();scope.run(()=>vm.runInNewContext(code,context));return{p:context.page,props,reads,writes,confirms,permission,stop:()=>scope.stop()}
}
const tick=()=>new Promise(r=>setImmediate(r))
const row={id:21,event_id:'event',state:'dead',status:2,attempt_count:3,last_error_code:null,update_time:null}
const ok={source_delivery_id:21,delivery_id:22,event_id:'new',state:'pending',already_reissued:false}
async function fill(h){h.reads.at(-1).resolve({data:[row],total:21});await tick()}
async function run(){
 const h=harness(),p=h.p;await fill(h)
 let task=p.recover(p.rows.value[0]);await p.recover(p.rows.value[0]);assert.equal(h.confirms.length,1)
 p.page.value=2;h.confirms.at(-1).resolve();await task;assert.equal(h.writes.length,0);await fill(h)
 task=p.recover(p.rows.value[0]);h.confirms.at(-1).resolve();await tick()
 assert.equal(JSON.stringify(h.writes[0].params.data),'{"id":1,"delivery_id":21}')
 const id=h.writes[0].params.headers['X-Request-Id'];h.writes[0].reject(new Error('network'));await task
 assert.equal(p.failedRequest.value.requestId,id)
 p.retryFailed();await tick();assert.equal(h.writes[1].params.headers['X-Request-Id'],id)
 h.writes[1].resolve(ok);await tick();await fill(h);assert.equal(p.failedRequest.value,null)
 task=p.recover(p.rows.value[0]);h.confirms.at(-1).resolve();await tick()
 h.props.clientId=2;h.writes.at(-1).reject(new Error('old failure'));await task
 assert.equal(p.failedRequest.value,null);await fill(h)
 task=p.recover(p.rows.value[0]);h.permission.value=false;h.confirms.at(-1).resolve();await task
 assert.equal(h.writes.length,3)
 h.permission.value=true;await fill(h);task=p.recover(p.rows.value[0]);h.stop()
 h.confirms.at(-1).resolve();await task;assert.equal(h.writes.length,3)
 assert.equal(p.rows.value.length,0)
 console.log('Logout recovery context behavior PASS (offline; original request ID retained)')
}
run().catch(e=>{console.error(e);process.exitCode=1})
