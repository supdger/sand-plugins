const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm')
const {createRequire}=require('node:module'),req=createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT,'package.json'))
const ts=req('typescript'),vue=req('vue'),options={target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}
const helpers={}
function loadContract(file){const m={exports:{}};vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname,'../api',file+'.ts'),'utf8'),{compilerOptions:options}).outputText,{module:m,exports:m.exports,require:name=>loadContract(name.replace('./',''))});return m.exports}
for(const file of ['auditFilterOptions','federationContracts','identityLifecycleContracts','delegationContracts']) Object.assign(helpers,loadContract(file))
function deferred(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
const extras={identity:'loadIdentities', 'identity-group':'loadGroups', 'application-business-action':'loadClaims', 'federation-config':'mountProvider,providers,selectedProviderId,clientSecret,loadOptions',audit:'loadAudits,loadOrganizations,organizationId,organizations,loadOptions'}
async function run(){
for(const name of Object.keys(extras)){
 const source=fs.readFileSync(path.join(__dirname,'..',name,'index.vue'),'utf8'),ast=ts.createSourceFile('page.ts',source.slice(source.indexOf('>')+1,source.indexOf('</script>')),ts.ScriptTarget.Latest,true),printer=ts.createPrinter()
 const body=ast.statements.filter(n=>!ts.isImportDeclaration(n)).map(n=>printer.printNode(ts.EmitHint.Unspecified,n,ast)).join('\n')
 const selected=name==='federation-config'?'mountApplicationId':'applicationId'
 const error=name==='audit'?'applicationOptionError':'applicationError'
 const code=ts.transpileModule(`${body};globalThis.page={loadApplications,applications,applicationLoading,applicationKeywords,selected:${selected},error:${error},${extras[name]}}`,{compilerOptions:options}).outputText
 const candidates=[],business=[];const orgAllowed=vue.ref(false)
 const context={...vue,...helpers,onMounted:()=>{},useAuth:()=>({hasAuth:key=>key==='sand_iam:organization:index'?orgAllowed.value:true}),ElMessage:{success:()=>{}},describeSandIamError:e=>({detail:e.message}),
 listSandIamResource:(resource,params)=>{if(!['application','organization','identity-provider'].includes(resource)){business.push({params});return Promise.reject(new Error('captured'))}const d=deferred();candidates.push({...d,resource,params});return d.promise},
 getSandIamAdmin:(endpoint,params)=>{business.push({endpoint,params});return Promise.reject(new Error('captured'))},postSandIamAction:(endpoint,params)=>{business.push({endpoint,params});return Promise.reject(new Error('captured'))}}
 const scope=vue.effectScope();scope.run(()=>vm.runInNewContext(code,context));const p=context.page
 // The business-action page starts its initial candidate load during setup.
 const offset=candidates.length
 const old=p.loadApplications('old'),fresh=p.loadApplications('Application101')
 assert.equal(candidates[offset+1].params.keywords,'Application101')
 const app={id:101,name:'Application101',organization_id:7,organization_name:'Org7',status:1}
 candidates[offset+1].resolve({data:[app]});await fresh
 candidates[offset].resolve({data:[{id:1,name:'old'}]});await old
 assert.equal(p.applications.value[0].id,101,name);p.selected.value='101'
 if(name==='federation-config'){p.providers.value=[{id:8,name:'P8',organization_id:7,status:1,scope_type:'organization',provider_type:'oidc'}];p.selectedProviderId.value='8';p.selected.value='101';p.clientSecret.value='keep secret'}
 const searched=p.loadApplications('other');candidates.at(-1).resolve({data:[{id:102,name:'Other',organization_id:8}]});await searched
 assert.equal(p.selected.value,'101');assert.equal(p.applications.value.find(r=>r.id===101).name,'Application101')
 if(name==='identity')await p.loadIdentities()
 if(name==='identity-group')await p.loadGroups()
 if(name==='application-business-action')await p.loadClaims()
 if(name==='federation-config'){assert.equal(p.clientSecret.value,'keep secret');await p.mountProvider()}
 if(name==='audit')await p.loadAudits()
 assert.equal(business.at(-1).params.application_id,101,name)
 const failed=p.loadApplications('retry');candidates.at(-1).reject(new Error('search failed'));await failed
 assert.equal(name==='audit'?p.error.value.detail:p.error.value,'search failed');assert.equal(p.applicationLoading.value,false)
 const retry=p.loadApplications(p.applicationKeywords.value);candidates.at(-1).resolve({data:[]});await retry
 assert.equal(p.selected.value,'101');assert.ok(name==='audit'?p.error.value===null:p.error.value==='')
 if(name==='audit'){
   await p.loadOrganizations('forbidden');assert.equal(candidates.some(r=>r.resource==='organization'),false)
   assert.ok(p.organizations.value.some(r=>r.id===7),'organization derives only from visible applications')
   orgAllowed.value=true
   const firstOrg=p.loadOrganizations('old'),nextOrg=p.loadOrganizations('Org101')
   const orgRequests=candidates.filter(r=>r.resource==='organization')
   assert.equal(orgRequests[1].params.keywords,'Org101')
   orgRequests[1].resolve({data:[{id:101,name:'Org101'}]});await nextOrg
   orgRequests[0].resolve({data:[{id:1,name:'old'}]});await firstOrg
   assert.equal(p.organizations.value[0].id,101);p.organizationId.value='101'
   const orgError=p.loadOrganizations('retry');candidates.at(-1).reject(new Error('org failed'));await orgError
   const orgRetry=p.loadOrganizations('retry');candidates.at(-1).resolve({data:[]});await orgRetry
   assert.equal(p.organizationId.value,'101');assert.equal(p.organizations.value[0].id,101)
 }
 if(name==='federation-config'){
   const initial=p.loadOptions();const appRequest=candidates.filter(r=>r.resource==='application').at(-1),providerRequest=candidates.filter(r=>r.resource==='identity-provider').at(-1)
   providerRequest.reject(new Error('provider failed'));await initial
   appRequest.resolve({data:[{id:105,name:'App105'}]});await Promise.resolve();await Promise.resolve()
   assert.ok(p.applications.value.some(row=>row.id===105));assert.equal(p.clientSecret.value,'keep secret')
 }
 const late=p.loadApplications('unmounted');const last=candidates.at(-1);scope.stop();const snapshot=JSON.stringify(p.applications.value)
 last.resolve({data:[{id:999}]});await late;assert.equal(JSON.stringify(p.applications.value),snapshot)
 assert.match(source,/remote-method="loadApplications"/)
 if(offset)candidates[0].resolve({data:[]})
 console.log(name+' remote application candidate PASS')
}
}
run().catch(e=>{console.error(e);process.exitCode=1})
