const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT, 'package.json'))
const ts = req('typescript'), vue = req('vue'), options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/identityLifecycleContracts.ts'), 'utf8'), { compilerOptions: options }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { applicationId, groups, identities, roles, members, groupRoles, roleId, memberIdentityId, selectedGroupId, name, code, parentId, createGroup, disableGroup, loading, editGroup, cancelEdit, editingGroup, description, groupStatus,
loadGroups, loadMembers, loadGroupRoles, grantRole, revokeRole, addMember, removeMember, requestError,
get searchIdentities() { return typeof searchIdentities === 'undefined' ? null : searchIdentities },
get searchRoles() { return typeof searchRoles === 'undefined' ? null : searchRoles } };`, { compilerOptions: options }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(allowed = true) {
  const reads = [], writes = [], confirms = []
  const read = (path, params) => { const next = deferred(); reads.push({ ...next, path, params }); return next.promise }
  const context = { ...vue, ...contracts.exports, onMounted: () => {}, useAuth: () => ({ hasAuth: () => allowed }),
    ElMessage: { success: () => {} }, ElMessageBox: { confirm: () => { const next = deferred(); confirms.push(next); return next.promise } },
    describeSandIamError: error => ({ detail: error.message }), getSandIamAdmin: read, listSandIamResource: read,
    postSandIamAction: (path, params) => { const next = deferred(); writes.push({ ...next, path, params }); return next.promise } }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  const p = context.page; p.applicationId.value = '1'; p.groups.value = [group(1), group(2)]
  return { p, reads, writes, confirms, stop: () => scope.stop() }
}
const group = id => ({ id, application_id: 1, name: `G${id}`, code: `g${id}`, status: 1, member_count: 0, parent_id: null, description: '' })
const member = id => ({ identity_id: id, display_name: `U${id}`, code: `u${id}`, lifecycle_state: 'active' })
const binding = (id, groupId) => ({ id, identity_group_id: groupId, role_id: 7, role_name: 'R', role_code: 'r', status: 1, role_status: 1 })
const tick = () => new Promise(resolve => setImmediate(resolve))
async function view(h, mode, index = 0, rows = []) {
  const pending = mode === 'members' ? h.p.loadMembers(h.p.groups.value[index]) : h.p.loadGroupRoles(h.p.groups.value[index])
  h.reads.at(-1).resolve(rows); await pending
}
async function completeWrite(h, pending, rows = []) {
  h.writes.at(-1).resolve(); await tick()
  h.reads.at(-1).resolve(rows); await pending
}
async function run() {
  assert.equal(contracts.exports.parseSandIamIdentityGroup({ ...group(1), parent_id: 2, parent_name: 'same name' }).parent_id, 2)
  assert.equal(contracts.exports.parseSandIamIdentityGroup(group(1)).parent_id, null)
  {
    const edit = harness()
    edit.p.groups.value[0].parent_id = 2
    edit.p.groups.value[0].description = 'original'
    edit.p.editGroup(edit.p.groups.value[0])
    assert.equal(edit.p.parentId.value, '2'); assert.equal(edit.p.description.value, 'original')
    edit.p.cancelEdit(); assert.equal(edit.p.editingGroup.value, null)
    edit.p.editGroup(edit.p.groups.value[0])
    edit.p.parentId.value = '1'; await edit.p.createGroup()
    assert.equal(edit.writes.length, 0, 'cannot select self as parent')
    edit.p.parentId.value = ''; edit.p.name.value = 'Updated'; edit.p.description.value = 'details'
    edit.p.groupStatus.value = 2
    const update = edit.p.createGroup(); await edit.p.createGroup()
    assert.equal(edit.writes.length, 1)
    assert.equal(edit.writes[0].path, 'identity-group/update')
    assert.equal(JSON.stringify(edit.writes[0].params), '{"name":"Updated","description":"details","parent_id":null,"id":1,"status":2}')
    edit.writes[0].reject(new Error('retry')); await update
    assert.equal(edit.p.description.value, 'details')
    const retry = edit.p.createGroup()
    edit.writes[1].resolve(); await tick()
    edit.reads.slice(-3).forEach((read, index) => read.resolve(index === 0 ? [group(1)] : []))
    await retry; assert.equal(edit.p.editingGroup.value, null)
    edit.p.editGroup(edit.p.groups.value[0])
    const stale = edit.p.createGroup()
    edit.p.applicationId.value = '2'; edit.p.description.value = 'new draft'
    edit.writes[2].resolve(); await stale
    assert.equal(edit.p.description.value, 'new draft'); assert.equal(edit.p.editingGroup.value, null)
    edit.stop()
  }
  const roleSearch = harness()
  assert.equal(typeof roleSearch.p.searchRoles, 'function', 'roles beyond first 100 require remote search')
  await view(roleSearch, 'roles')
  const role101 = { id: 101, application_id: 1, name: 'Role 101', code: 'r101', status: 1 }
  const roleFound = roleSearch.p.searchRoles(' Role 101 ')
  assert.equal(roleSearch.reads.at(-1).path, 'role')
  assert.equal(JSON.stringify(roleSearch.reads.at(-1).params), '{"page":1,"limit":100,"application_id":1,"keywords":"Role 101"}')
  roleSearch.p.roleId.value = '101'
  await roleSearch.p.grantRole(); assert.equal(roleSearch.writes.length, 0)
  roleSearch.reads.at(-1).resolve([role101, { ...role101, id: 999, application_id: 2 }]); await roleFound
  assert.equal(roleSearch.p.roles.value.length, 1)
  roleSearch.p.roleId.value = '101'
  const grant101 = roleSearch.p.grantRole()
  assert.equal(JSON.stringify(roleSearch.writes[0].params), '{"identity_group_id":1,"role_id":101}')
  await completeWrite(roleSearch, grant101)
  roleSearch.p.roleId.value = '101'
  const oldRoleSearch = roleSearch.p.searchRoles('old')
  const oldRoleRead = roleSearch.reads.at(-1)
  assert.equal(roleSearch.p.roleId.value, '')
  const newRoleSearch = roleSearch.p.searchRoles('latest')
  roleSearch.reads.at(-1).resolve([{ ...role101, id: 102 }]); await newRoleSearch
  oldRoleRead.resolve([role101]); await oldRoleSearch
  assert.equal(roleSearch.p.roles.value[0].id, 102)
  const oldRoleApp = roleSearch.p.searchRoles('old app')
  const oldRoleAppRead = roleSearch.reads.at(-1)
  roleSearch.p.applicationId.value = '2'
  oldRoleAppRead.resolve([role101]); await oldRoleApp
  assert.equal(roleSearch.p.roles.value.length, 0)
  const failedRole = roleSearch.p.searchRoles('retry')
  roleSearch.reads.at(-1).reject(new Error('failed')); await failedRole
  const retryRole = roleSearch.p.searchRoles('retry')
  roleSearch.reads.at(-1).resolve([{ ...role101, application_id: 2 }]); await retryRole
  assert.equal(roleSearch.p.roles.value[0].application_id, 2)
  const disposedRole = roleSearch.p.searchRoles('disposed')
  roleSearch.stop()
  roleSearch.reads.at(-1).resolve([{ ...role101, application_id: 2 }]); await disposedRole
  assert.equal(roleSearch.p.roles.value.length, 0)
  const search = harness()
  assert.equal(typeof search.p.searchIdentities, 'function', 'member choices must support remote search beyond first 100')
  await view(search, 'members')
  const user101 = { id: 101, application_id: 1, display_name: 'User 101', code: 'u101', status: 1, lifecycle_state: 'active' }
  const found = search.p.searchIdentities('  User 101  ')
  assert.equal(search.reads.at(-1).path, 'identity')
  assert.equal(JSON.stringify(search.reads.at(-1).params), '{"page":1,"limit":100,"application_id":1,"keywords":"User 101"}')
  search.reads.at(-1).resolve([user101]); await found
  search.p.memberIdentityId.value = '101'
  const add101 = search.p.addMember()
  assert.equal(JSON.stringify(search.writes[0].params), '{"id":1,"identity_id":101}')
  await completeWrite(search, add101, [member(101)])
  search.p.memberIdentityId.value = '101'
  const oldSearch = search.p.searchIdentities('old')
  const oldRead = search.reads.at(-1)
  assert.equal(search.p.memberIdentityId.value, '', 'changing search clears mismatched selection')
  const newSearch = search.p.searchIdentities('latest')
  search.reads.at(-1).resolve([{ ...user101, id: 102 }]); await newSearch
  oldRead.resolve([user101]); await oldSearch
  assert.equal(search.p.identities.value[0].id, 102)
  const oldAppSearch = search.p.searchIdentities('old app')
  const oldAppRead = search.reads.at(-1)
  search.p.applicationId.value = '2'
  oldAppRead.resolve([user101]); await oldAppSearch
  assert.equal(search.p.identities.value.length, 0)
  const failSearch = search.p.searchIdentities('retry')
  search.reads.at(-1).reject(new Error('search failed')); await failSearch
  const retrySearch = search.p.searchIdentities('retry')
  search.reads.at(-1).resolve([{ ...user101, application_id: 2 }]); await retrySearch
  assert.equal(search.p.identities.value[0].application_id, 2)
  search.stop()
  const draft = harness()
  draft.p.name.value = 'old'; draft.p.code.value = 'old'
  const staleCreate = draft.p.createGroup()
  draft.p.applicationId.value = '2'
  draft.p.name.value = 'new'; draft.p.code.value = 'new'
  await draft.p.createGroup()
  assert.equal(draft.writes.length, 1, 'application switch must not release pending write lock')
  draft.writes[0].resolve(); await tick()
  assert.equal(draft.p.name.value, 'new', 'old create must preserve new application draft')
  await staleCreate; draft.stop()
  const management = harness()
  management.p.name.value = ' New '; management.p.code.value = ' code '
  management.p.parentId.value = '99'
  await management.p.createGroup(); assert.equal(management.writes.length, 0)
  management.p.parentId.value = '1'
  const create = management.p.createGroup()
  await management.p.createGroup()
  await management.p.disableGroup(management.p.groups.value[0])
  assert.equal(management.writes.length, 1); assert.equal(management.confirms.length, 0)
  assert.equal(JSON.stringify(management.writes[0].params), '{"name":"New","description":"","parent_id":1,"application_id":1,"code":"code"}')
  management.writes[0].reject(new Error('failed')); await create
  assert.equal(management.p.loading.value, false)
  assert.equal(management.p.name.value, ' New ')
  const createRetry = management.p.createGroup()
  management.writes[1].resolve(); await tick()
  management.reads.slice(-3).forEach((read, index) => read.resolve(index === 0 ? [group(1)] : []))
  await createRetry
  assert.equal(management.p.name.value, '')
  const disable = management.p.disableGroup(management.p.groups.value[0])
  await management.p.disableGroup(management.p.groups.value[0])
  assert.equal(management.confirms.length, 1)
  management.p.applicationId.value = '2'
  management.confirms[0].resolve(); await disable
  assert.equal(management.writes.length, 2)
  management.p.applicationId.value = '1'; management.p.groups.value = [group(1)]
  const disableCurrent = management.p.disableGroup(management.p.groups.value[0])
  management.confirms[1].resolve(); await tick()
  assert.equal(management.writes[2].path, 'identity-group/disable')
  assert.equal(JSON.stringify(management.writes[2].params), '{"id":1}')
  management.writes[2].reject(new Error('retry disable')); await disableCurrent
  assert.equal(management.p.loading.value, false)
  const disableRetry = management.p.disableGroup(management.p.groups.value[0])
  management.confirms[2].resolve(); await tick()
  management.writes[3].resolve(); await tick()
  management.reads.slice(-3).forEach(read => read.resolve([])); await disableRetry
  management.stop()
  const h = harness()
  const old = h.p.loadMembers(h.p.groups.value[0]), current = h.p.loadMembers(h.p.groups.value[1])
  h.reads[1].resolve([member(2)]); await current
  h.reads[0].resolve([member(1)]); await old
  assert.equal(h.p.members.value[0].identity_id, 2, 'late group A must not overwrite group B')
  await view(h, 'roles', 0, [binding(8, 1)])
  const confirming = h.p.revokeRole(h.p.groupRoles.value[0])
  await h.p.revokeRole(h.p.groupRoles.value[0])
  assert.equal(h.confirms.length, 1)
  await view(h, 'roles', 1)
  h.confirms[0].resolve(); await confirming
  assert.equal(h.writes.length, 0, 'switching group cancels old confirmation')
  await view(h, 'members', 0)
  h.p.identities.value = [{ id: 3, application_id: 1, display_name: 'U', status: 1, lifecycle_state: 'active' }]
  h.p.memberIdentityId.value = '3'
  const adding = h.p.addMember(); await h.p.addMember()
  h.p.name.value = 'blocked'; h.p.code.value = 'blocked'
  await h.p.createGroup(); await h.p.disableGroup(h.p.groups.value[0])
  assert.equal(h.confirms.length, 1, 'relation write blocks group disable')
  assert.equal(h.writes.length, 1)
  assert.equal(JSON.stringify(h.writes[0].params), '{"id":1,"identity_id":3}')
  await completeWrite(h, adding, [member(3)])
  await h.p.removeMember(member(3))
  assert.equal(h.writes.length, 1, 'fabricated row cannot remove member')
  const removing = h.p.removeMember(h.p.members.value[0])
  assert.equal(h.writes[1].path, 'identity-group/member/remove')
  assert.equal(JSON.stringify(h.writes[1].params), '{"id":1,"identity_id":3}')
  h.writes[1].reject(new Error('denied')); await removing
  assert.equal(h.p.requestError.value.detail, 'denied')
  const retry = h.p.removeMember(h.p.members.value[0]); await completeWrite(h, retry)
  await view(h, 'roles', 0)
  h.p.roles.value = [{ id: 7, application_id: 1, name: 'R', code: 'r', status: 1 }]
  h.p.roleId.value = '7'
  const granting = h.p.grantRole()
  assert.equal(JSON.stringify(h.writes.at(-1).params), '{"identity_group_id":1,"role_id":7}')
  await completeWrite(h, granting, [binding(8, 1)])
  const revoking = h.p.revokeRole(h.p.groupRoles.value[0])
  h.confirms.at(-1).resolve(); await tick()
  assert.equal(h.writes.at(-1).path, 'identity-group-role/revoke')
  assert.equal(JSON.stringify(h.writes.at(-1).params), '{"id":8}')
  await completeWrite(h, revoking)
  h.p.roleId.value = '7'
  const staleWrite = h.p.grantRole()
  await view(h, 'members', 1)
  const readCount = h.reads.length
  h.writes.at(-1).resolve(); await staleWrite
  assert.equal(h.reads.length, readCount, 'old mutation must not refresh new group')
  assert.equal(h.p.selectedGroupId.value, '2')
  const staleRead = h.p.loadMembers(h.p.groups.value[1])
  h.stop()
  h.reads.at(-1).resolve([member(90)]); await staleRead
  assert.equal(h.p.members.value.length, 0, 'unmounted page ignores read result')

  const apps = harness()
  const firstApp = apps.p.loadGroups()
  apps.p.applicationId.value = '2'
  const secondApp = apps.p.loadGroups()
  apps.reads[3].resolve([{ ...group(20), application_id: 2 }])
  apps.reads[4].resolve([]); apps.reads[5].resolve([]); await secondApp
  apps.reads[0].resolve([group(1)]); apps.reads[1].reject(new Error('old candidate error'))
  apps.reads[2].resolve([{ id: 7, application_id: 1, name: 'old', code: 'old', status: 1 }]); await firstApp
  assert.equal(apps.p.groups.value[0].id, 20)
  assert.equal(apps.p.roles.value.length, 0)
  assert.equal(apps.p.requestError.value, null)
  apps.stop()
  const denied = harness(false)
  denied.p.editGroup(denied.p.groups.value[0]); assert.equal(denied.p.editingGroup.value, null)
  await denied.p.loadMembers(denied.p.groups.value[0])
  await denied.p.loadGroupRoles(denied.p.groups.value[0])
  await denied.p.addMember(); await denied.p.grantRole()
  await denied.p.searchIdentities('User 101')
  await denied.p.searchRoles('Role 101')
  denied.p.name.value = 'no'; denied.p.code.value = 'no'
  await denied.p.createGroup(); await denied.p.disableGroup(denied.p.groups.value[0])
  assert.equal(denied.confirms.length, 0)
  assert.equal(denied.reads.length + denied.writes.length, 0)
  denied.stop()
  console.log('identity-group context behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
