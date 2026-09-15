const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const {createRequire}=require('node:module')
const req=createRequire(path.join(process.env.SAND_IAM_FRONTEND_ROOT,'package.json')),ts=req('typescript'),vue=req('vue')
const compile=s=>ts.transpileModule(s,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText
const state={};vm.runInNewContext(compile(fs.readFileSync(path.join(__dirname,'wizardState.ts'),'utf8')),{exports:state})
const source=fs.readFileSync(path.join(__dirname,'index.vue'),'utf8')
const ast=ts.createSourceFile('page.ts',source.slice(source.indexOf('>')+1,source.indexOf('</script>')),ts.ScriptTarget.Latest,true),printer=ts.createPrinter()
const code=compile(ast.statements.filter(n=>!ts.isImportDeclaration(n)).map(n=>printer.printNode(ts.EmitHint.Unspecified,n,ast)).join('\n')+
 ';globalThis.page={saveStep,loadCandidates,currentScreen,phases,pendingCodes,candidates,candidatePage,candidateTotal,ids};')
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
function harness(){
 const reads=[],writes=[],io=list=>(...args)=>{const d=deferred();list.push({...d,args});return d.promise}
 const context={...vue,...state,onMounted(){},useRouter:()=>({push(){}}),useAuth:()=>({hasAuth:()=>true}),ElMessage:{success(){}},
 describeSandIamError:e=>({http:e.http??null,code:e.code??null}),window:{localStorage:{setItem(){},getItem(){return null}}},
 listSandIamResource:io(reads),readSandIamResource:io(reads),saveSandIamResource:io(writes),updateSandIamResource:io(writes)}
 const scope=vue.effectScope();scope.run(()=>vm.runInNewContext(code,context));return{p:context.page,reads,writes,stop:()=>scope.stop()}
}
async function run(){
 assert.equal(state.phaseAfterCreateError(400,'OTHER'),'save_outcome_unknown')
 assert.equal(state.phaseAfterCreateError(500,'SAND_IAM_VALIDATION_ERROR'),'save_outcome_unknown')
 assert.equal(state.phaseAfterCreateError(409,'SAND_IAM_ENVIRONMENT_CONFLICT'),'draft')
 const h=harness(),p=h.p
 let task=p.saveStep({name:'Org',code:'code'})
 h.writes[0].reject({http:400,code:'SAND_IAM_VALIDATION_ERROR'});await task
 assert.equal(p.phases.organization,'draft')
 task=p.saveStep({name:'Fixed',code:'code'});assert.equal(h.writes.length,2)
 h.writes[1].reject(new Error('network'));await task
 assert.equal(p.phases.organization,'save_outcome_unknown')
 await p.saveStep({name:'Again',code:'code'});assert.equal(h.writes.length,2)
 task=p.loadCandidates('organization');assert.equal(h.reads[0].args[1].page,1)
 h.reads[0].resolve({data:[{id:1,code:'other'}],total:101});await task
 assert.equal(p.candidates.organization.length,0);assert.equal(p.candidateTotal.value,101)
 task=p.loadCandidates('organization',2)
 h.reads[1].resolve({data:[{id:101,code:'code'}],total:101});await task
 assert.equal(p.candidates.organization[0].id,101);assert.equal(p.candidatePage.value,2)
 p.ids.organization=1;p.currentScreen.value='application'
 task=p.loadCandidates('application');assert.equal(h.reads[2].args[1].organization_id,1)
 p.ids.organization=2;h.reads[2].resolve({data:[{id:5,organization_id:1}],total:1});await task
 assert.equal(p.candidates.application.length,0)
 h.stop();console.log('Getting started recovery/pagination behavior PASS (offline)')
}
run().catch(e=>{console.error(e);process.exitCode=1})
