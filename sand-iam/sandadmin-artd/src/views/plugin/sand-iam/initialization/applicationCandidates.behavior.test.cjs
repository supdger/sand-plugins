const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = req('typescript'), vue = req('vue')
const compilerOptions = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const extras = {
  'identity-invitation': 'loadInvitations, target, sendInvitation',
  'identity-import': 'loadJobs, selectedFile, previewImport',
  'policy-simulate': 'runSimulate, identityId, identities, resourceCode, action',
  initialization: 'loadRuns, exportPackageCode, exportPackage'
}
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
async function run() {
  for (const [name, extra] of Object.entries(extras)) {
    const source = fs.readFileSync(path.join(__dirname, '..', name, 'index.vue'), 'utf8')
    const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
    const printer = ts.createPrinter()
    const body = ast.statements.filter(n => !ts.isImportDeclaration(n)).map(n => printer.printNode(ts.EmitHint.Unspecified,n,ast)).join('\n')
    const code = ts.transpileModule(`${body};globalThis.page={loadApplications,applications,applicationId,applicationLoading,applicationError,applicationKeywords,${extra}}`, {compilerOptions}).outputText
    const candidates = [], business = []
    const context = { ...vue, onMounted: () => {}, useAuth: () => ({hasAuth: () => true}),
      describeSandIamError: e => ({detail:e.message}), parseJsonObject: JSON.parse,
      parseSandIamIdentityGroups: () => [], parseSandIamIdentities: () => [], parseSandIamImportPreview: value => value,
      ElMessage: {success: () => {}},
      listSandIamResource: (resource, params) => {
        if (resource !== 'application') return Promise.resolve([])
        const d = deferred(); candidates.push({...d,params}); return d.promise
      },
      getSandIamAdmin: (endpoint,params) => { business.push({endpoint,params}); return Promise.reject(new Error('offline request captured')) },
      postSandIamAction: (endpoint,params) => { business.push({endpoint,params}); return Promise.reject(new Error('offline request captured')) },
      postSandIamForm: (endpoint,params) => { business.push({endpoint,params}); return Promise.reject(new Error('offline request captured')) },
      FormData, Blob
    }
    const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code,context)); const p=context.page
    const first=p.loadApplications(), newest=p.loadApplications('Application101')
    assert.equal(candidates[1].params.keywords,'Application101', name)
    assert.equal(candidates[1].params.limit,100)
    candidates[1].resolve({data:[{id:101,name:'Application101'}]}); await newest
    candidates[0].resolve({data:[{id:1,name:'old'}]}); await first
    assert.equal(p.applications.value[0].id,101,name)
    p.applicationId.value='101'
    const search=p.loadApplications('Different')
    candidates[2].resolve({data:[{id:102,name:'Different'}]}); await search
    assert.equal(p.applicationId.value,'101',name)
    assert.equal(p.applications.value.find(row=>row.id===101).name,'Application101',name)
    if(name==='identity-invitation') await p.loadInvitations()
    if(name==='identity-import') await p.loadJobs()
    if(name==='initialization') await p.loadRuns()
    if(name==='policy-simulate') {
      await Promise.resolve(); p.identities.value=[{id:5,application_id:101}];p.identityId.value='5';p.resourceCode.value='document';p.action.value='read';await p.runSimulate()
    }
    assert.equal(business.at(-1).params.application_id,101,name)
    if(name==='identity-invitation') {
      p.target.value='person@example.test';await p.sendInvitation()
      assert.equal(business.at(-1).endpoint,'identity-invitation/send');assert.equal(business.at(-1).params.application_id,101)
    }
    if(name==='identity-import') {
      p.selectedFile.value=new File(['code,display_name\nu1,User'],'users.csv',{type:'text/csv'});await p.previewImport()
      assert.equal(business.at(-1).endpoint,'identity-import/preview');assert.equal(business.at(-1).params.get('application_id'),'101')
    }
    if(name==='initialization') {
      p.exportPackageCode.value='package';await p.exportPackage()
      assert.equal(business.at(-1).endpoint,'initialization/export');assert.equal(business.at(-1).params.application_id,101)
    }
    // Draft content remains tied to the selected application during a candidate search.
    const draft = name==='identity-invitation'?p.target:name==='initialization'?p.exportPackageCode:name==='policy-simulate'?p.resourceCode:null
    if(draft) draft.value='keep draft'
    const failure=p.loadApplications('retry');candidates[3].reject(new Error('search failed'));await failure
    assert.equal(p.applicationError.value,'search failed');assert.equal(p.applicationLoading.value,false)
    const retry=p.loadApplications(p.applicationKeywords.value);candidates[4].resolve({data:[]});await retry
    assert.equal(p.applicationError.value,'');assert.equal(p.applicationId.value,'101');if(draft)assert.equal(draft.value,'keep draft')
    const lateError=p.loadApplications('late error'), current=p.loadApplications('current')
    candidates[6].resolve({data:[{id:103,name:'current'}]});await current
    candidates[5].reject(new Error('obsolete'));await lateError;assert.equal(p.applicationError.value,'')
    const late=p.loadApplications('disposed');scope.stop();const snapshot=JSON.stringify(p.applications.value)
    candidates[7].resolve({data:[{id:999}]});await late
    assert.equal(JSON.stringify(p.applications.value),snapshot,name)
    await p.loadApplications('after dispose');assert.equal(candidates.length,8)
    assert.match(source,/remote-method="loadApplications"/);assert.match(source,/loadApplications\(applicationKeywords\)/)
    console.log(`${name} application remote candidates PASS`)
  }
}
run().catch(error=>{console.error(error);process.exitCode=1})
