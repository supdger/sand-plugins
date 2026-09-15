const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const {createRequire}=require('node:module')
const req=createRequire(path.join(process.env.SAND_IAM_FRONTEND_ROOT,'package.json')),ts=req('typescript'),vue=req('vue')
const source=fs.readFileSync(path.join(__dirname,'index.vue'),'utf8')
const ast=ts.createSourceFile('page.ts',source.slice(source.indexOf('>')+1,source.indexOf('</script>')),ts.ScriptTarget.Latest,true),printer=ts.createPrinter()
const body=ast.statements.filter(n=>!ts.isImportDeclaration(n)).map(n=>printer.printNode(ts.EmitHint.Unspecified,n,ast)).join('\n')
const code=ts.transpileModule(`${body};globalThis.page={loadCatalogs,loadOpenApi,loadEvents,downloadJson,openApiDocument,eventCatalog,events,openApiError,eventsError};`,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
function harness(){
 const reads=[],downloads=[],blobs=[],allowed=vue.ref(['sand_iam:developer:openapi','sand_iam:developer:events'])
 const context={...vue,Blob,onMounted(){},useAuth:()=>({hasAuth:p=>allowed.value.includes(p)}),describeSandIamError:e=>({detail:e.message}),
 getSandIamAdmin:path=>{const d=deferred();reads.push({...d,path});return d.promise},
 URL:{createObjectURL:b=>{blobs.push(b);return'blob:offline'},revokeObjectURL(){}},
 document:{createElement:()=>{const a={href:'',download:'',click(){downloads.push(a.download)}};return a}}}
 const scope=vue.effectScope();scope.run(()=>vm.runInNewContext(code,context))
 return{p:context.page,reads,downloads,blobs,allowed,stop:()=>scope.stop()}
}
const api={openapi:'3.0.0',paths:{'/real':{}}},catalog={events:[{code:'actual',name:'Actual'}]}
async function run(){
 const h=harness(),p=h.p
 let task=p.loadCatalogs();assert.equal(h.reads.length,2)
 h.reads[0].resolve(api);h.reads[1].resolve(catalog);await task
 p.downloadJson('sand-iam-openapi.json',p.openApiDocument.value)
 p.downloadJson('sand-iam-events.json',p.eventCatalog.value)
 assert.deepEqual(h.downloads,['sand-iam-openapi.json','sand-iam-events.json'])
 assert.deepEqual(JSON.parse(await h.blobs[0].text()),api);assert.deepEqual(JSON.parse(await h.blobs[1].text()),catalog)
 task=p.loadCatalogs();assert.equal(p.openApiDocument.value,null);assert.equal(p.eventCatalog.value,null)
 h.reads[2].reject(new Error('api failed'));h.reads[3].resolve(catalog);await task
 assert.equal(p.openApiDocument.value,null);assert.equal(p.openApiError.value.detail,'api failed')
 p.downloadJson('sand-iam-events.json',p.eventCatalog.value);assert.equal(h.downloads.length,3)
 const old=p.loadEvents(),latest=p.loadEvents()
 h.reads.at(-1).resolve({events:[{code:'latest'}]});await latest
 h.reads.at(-2).resolve(catalog);await old;assert.equal(p.events.value[0].code,'latest')
 const stale=p.eventCatalog.value;h.allowed.value=[]
 assert.equal(p.eventCatalog.value,null);p.downloadJson('sand-iam-events.json',stale)
 await p.loadCatalogs();assert.equal(h.reads.length,6);assert.equal(h.downloads.length,3)
 h.allowed.value=['sand_iam:developer:openapi'];task=p.loadOpenApi()
 h.reads.at(-1).resolve({invalid:true});await task;assert.equal(p.openApiDocument.value,null)
 task=p.loadOpenApi();h.stop();h.reads.at(-1).resolve(api);await task
 assert.equal(p.openApiDocument.value,null);p.downloadJson('sand-iam-openapi.json',api)
 assert.equal(h.downloads.length,3)
 console.log('Developer catalog behavior PASS (offline JSON/Blob downloads)')
}
run().catch(e=>{console.error(e);process.exitCode=1})
