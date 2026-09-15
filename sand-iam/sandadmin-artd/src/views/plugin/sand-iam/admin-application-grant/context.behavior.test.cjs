const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const {createRequire}=require('node:module')
const req=createRequire(path.join(process.env.SAND_IAM_FRONTEND_ROOT,'package.json')),ts=req('typescript'),vue=req('vue')
const compile=s=>ts.transpileModule(s,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText
const contracts={};vm.runInNewContext(compile(fs.readFileSync(path.join(__dirname,'../api/delegationContracts.ts'),'utf8')),{exports:contracts})
const source=fs.readFileSync(path.join(__dirname,'index.vue'),'utf8')
const ast=ts.createSourceFile('page.ts',source.slice(source.indexOf('>')+1,source.indexOf('</script>')),ts.ScriptTarget.Latest,true),printer=ts.createPrinter()
const code=compile(ast.statements.filter(n=>!ts.isImportDeclaration(n)).map(n=>printer.printNode(ts.EmitHint.Unspecified,n,ast)).join('\n')+
 ';globalThis.page={applications,adminOptions,selectedAdminId,selectedApplicationId,grants,page,total,saving,loadApplications,searchAdmins,loadGrants,saveGrant,disableGrant};')
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
function harness(){
 const reads=[],writes=[],confirms=[],permission=vue.ref(true),io=list=>(path,params)=>{const d=deferred();list.push({...d,path,params});return d.promise}
 const context={...vue,...contracts,onMounted(){},useAuth:()=>({hasAuth:()=>permission.value}),describeSandIamError:e=>({detail:e.message}),
 ElMessage:{success(){}},ElMessageBox:{confirm:()=>{const d=deferred();confirms.push(d);return d.promise}},
 getSandIamAdmin:io(reads),listSandIamResource:io(reads),postSandIamAction:io(writes)}
 const scope=vue.effectScope();scope.run(()=>vm.runInNewContext(code,context));return{p:context.page,reads,writes,confirms,permission,stop:()=>scope.stop()}
}
const tick=()=>new Promise(r=>setImmediate(r)),row={id:21,application_id:101,admin_user_id:9,admin_user_name:'Admin',status:1}
async function refresh(h,status=1){await tick();h.reads.at(-1).resolve({data:[{...row,status}],total:21});await tick()}
async function run(){
 const h=harness(),p=h.p
 await p.searchAdmins('a');assert.equal(h.reads.length,0)
 const old=p.searchAdmins('old'),last=p.searchAdmins('new')
 h.reads.at(-1).resolve([{id:9,name:'Admin',username:'admin'}]);await last
 h.reads.at(-2).resolve([{id:8,name:'Old',username:'old'}]);await old;assert.equal(p.adminOptions.value[0].id,9)
 p.selectedAdminId.value='9';await p.searchAdmins('x');assert.equal(p.selectedAdminId.value,'')
 let pending=p.loadApplications('App101');assert.equal(h.reads.at(-1).params.keywords,'App101')
 h.reads.at(-1).resolve([{id:101,name:'App'}]);await pending
 pending=p.searchAdmins('Admin');h.reads.at(-1).resolve([{id:9,name:'Admin',username:'admin'}]);await pending
 p.selectedAdminId.value='9';p.selectedApplicationId.value='101'
 pending=p.saveGrant();await p.saveGrant();assert.equal(h.writes.length,1)
 assert.equal(JSON.stringify(h.writes[0].params),'{"admin_user_id":9,"application_id":101,"status":1}')
 h.writes[0].reject(new Error('retry'));await pending;assert.equal(p.saving.value,false)
 pending=p.saveGrant();h.writes.at(-1).resolve({});await refresh(h);await pending
 p.page.value=2;assert.equal(h.reads.at(-1).params.page,2);await refresh(h)
 assert.equal(p.total.value,21)
 await p.disableGrant({...row});assert.equal(h.confirms.length,0)
 pending=p.disableGrant(p.grants.value[0]);await p.disableGrant(p.grants.value[0]);assert.equal(h.confirms.length,1)
 h.confirms.at(-1).resolve();await tick();assert.equal(h.writes.at(-1).params.id,21)
 h.writes.at(-1).resolve({});await refresh(h,2);await pending
 pending=p.disableGrant(p.grants.value[0],true);h.confirms.at(-1).resolve();await tick()
 assert.equal(h.writes.at(-1).path,'admin-application-grant/update')
 assert.equal(JSON.stringify(h.writes.at(-1).params),'{"id":21,"status":1}')
 h.writes.at(-1).resolve({});await refresh(h);await pending
 const count=h.writes.length;pending=p.disableGrant(p.grants.value[0]);p.selectedAdminId.value='9'
 h.confirms.at(-1).resolve();await pending;assert.equal(h.writes.length,count)
 p.selectedApplicationId.value='101';pending=p.saveGrant();p.selectedAdminId.value=''
 h.writes.at(-1).resolve({});await pending;assert.equal(p.selectedApplicationId.value,'101')
 h.permission.value=false;await p.saveGrant();await p.loadGrants();assert.equal(h.writes.length,count+1)
 h.permission.value=true;pending=p.searchAdmins('late');h.stop();h.reads.at(-1).resolve([{id:6,name:'Late',username:'late'}]);await pending
 assert.equal(p.adminOptions.value.length,0)
 console.log('Application admin delegation context behavior PASS (offline, real parsers)')
}
run().catch(e=>{console.error(e);process.exitCode=1})
