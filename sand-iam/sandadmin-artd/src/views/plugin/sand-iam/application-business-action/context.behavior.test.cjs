const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const {createRequire}=require('node:module'),req=createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT,'package.json'))
const ts=req('typescript'),vue=req('vue'),source=fs.readFileSync(path.join(__dirname,'index.vue'),'utf8')
const ast=ts.createSourceFile('page.ts',source.slice(source.indexOf('>')+1,source.indexOf('</script>')),ts.ScriptTarget.Latest,true),printer=ts.createPrinter()
const body=ast.statements.filter(n=>!ts.isImportDeclaration(n)).map(n=>printer.printNode(ts.EmitHint.Unspecified,n,ast)).join('\n')
const code=ts.transpileModule(`${body};globalThis.page={applicationId,loadClaims,claims,claimError,loadingClaims}`,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
const response=(app,code)=>({data:{application_id:app,state:'pending_claim',items:[{code,sources:['route']}]}})
async function run(){
 const reads=[],allowed=vue.ref(true),context={...vue,useAuth:()=>({hasAuth:()=>allowed.value}),describeSandIamError:e=>({detail:e.message}),listSandIamResource:async()=>[],getSandIamAdmin:(endpoint,params)=>{const d=deferred();reads.push({...d,endpoint,params});return d.promise}}
 const scope=vue.effectScope();scope.run(()=>vm.runInNewContext(code,context));const p=context.page
 p.applicationId.value='1';const first=p.loadClaims();reads[0].resolve(response(1,'A'));await first;assert.equal(p.claims.value[0].code,'A')
 p.applicationId.value='2';assert.equal(p.claims.value.length,0,'switching application must clear the old pending-claim report')
 const old=p.loadClaims();p.applicationId.value='3';const fresh=p.loadClaims()
 reads[2].resolve(response(3,'C'));await fresh;reads[1].resolve(response(2,'B'));await old;assert.equal(p.claims.value[0].code,'C')
 const failure=p.loadClaims();reads[3].reject(new Error('failed'));await failure;assert.equal(p.claimError.value.detail,'failed');assert.equal(p.loadingClaims.value,false)
 const retry=p.loadClaims();reads[4].resolve(response(3,'retry'));await retry;assert.equal(p.claims.value[0].code,'retry')
 const mismatched=p.loadClaims();reads[5].resolve(response(2,'wrong'));await mismatched;assert.equal(p.claims.value.length,0);assert.ok(p.claimError.value)
 const pending=p.loadClaims();await p.loadClaims();assert.equal(reads.length,7,'duplicate loads are suppressed')
 allowed.value=false;assert.equal(p.claims.value.length,0);reads[6].resolve(response(3,'revoked'));await pending;assert.equal(p.claims.value.length,0)
 await p.loadClaims();assert.equal(reads.length,7,'no request without report permission')
 allowed.value=true;const disposed=p.loadClaims();scope.stop();reads[7].resolve(response(3,'disposed'));await disposed;assert.equal(p.claims.value.length,0)
 assert.match(source,/:disabled="!canReadClaims"/)
 console.log('Business-action pending claims context PASS')
}
run().catch(e=>{console.error(e);process.exitCode=1})
