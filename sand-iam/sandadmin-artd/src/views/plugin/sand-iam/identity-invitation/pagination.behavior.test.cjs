const assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path'), vm = require('node:vm')
const { createRequire } = require('node:module')
const req = createRequire(path.resolve(process.env.SAND_IAM_FRONTEND_ROOT || path.resolve(__dirname, '../../../../..'), 'package.json'))
const ts = req('typescript'), vue = req('vue')
const options = { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS }
const contracts = { exports: {} }
vm.runInNewContext(ts.transpileModule(fs.readFileSync(path.join(__dirname, '../api/invitationContracts.ts'), 'utf8'), { compilerOptions: options }).outputText, { exports: contracts.exports, module: contracts })
const source = fs.readFileSync(path.join(__dirname, 'index.vue'), 'utf8')
const ast = ts.createSourceFile('page.ts', source.slice(source.indexOf('>') + 1, source.indexOf('</script>')), ts.ScriptTarget.Latest, true)
const printer = ts.createPrinter()
const body = ast.statements.filter(node => !ts.isImportDeclaration(node)).map(node => printer.printNode(ts.EmitHint.Unspecified, node, ast)).join('\n')
const code = ts.transpileModule(`${body}
globalThis.page = { applicationId, target, loadInvitations, loadContext, sendInvitation, resendInvitation, revokeInvitation, invitations, groups, guests, requestError, searchGuests, guestIdentityId,
 get currentPage() { return typeof currentPage === 'undefined' ? null : currentPage },
 get stateFilter() { return typeof stateFilter === 'undefined' ? null : stateFilter },
 get total() { return typeof total === 'undefined' ? null : total } };`, { compilerOptions: options }).outputText
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no }); return { promise, resolve, reject } }
function harness(remote = false, allowed = true) {
  const reads = [], writes = [], confirms = [], searches = []
  const context = {
    ...vue, ...contracts.exports, onMounted: () => {},
    useAuth: () => ({ hasAuth: () => allowed }), ElMessage: { success: () => {} },
    ElMessageBox: { confirm: () => { const next = deferred(); confirms.push(next); return next.promise } },
    describeSandIamError: error => ({ detail: error.message }),
    parseSandIamIdentityGroups: value => value, parseSandIamIdentities: value => value,
    listSandIamResource: (path, params) => {
      if (!remote) return Promise.resolve([])
      const next = deferred(); searches.push({ ...next, path, params }); return next.promise
    },
    getSandIamAdmin: (path, params) => { const next = deferred(); reads.push({ ...next, path, params }); return next.promise },
    postSandIamAction: (path, params) => { const next = deferred(); writes.push({ ...next, path, params }); return next.promise }
  }
  const scope = vue.effectScope(); scope.run(() => vm.runInNewContext(code, context))
  return { page: context.page, reads, writes, confirms, searches, stop: () => scope.stop() }
}
const tick = () => new Promise(resolve => setImmediate(resolve))
const row = id => ({ id, application_id: 1, target_type: 'email', target_masked: 'a***', state: 'pending', status: 1 })
const result = (rows, current = 1, total = 21) => ({ data: rows, total, current_page: current, per_page: 20 })
async function finishList(h, pending, rows, current = 1, total = 21) {
  h.reads.at(-1).resolve(result(rows, current, total)); await tick()
  if (h.reads.at(-1).path === 'identity-group/index') h.reads.at(-1).resolve([])
  await pending
}
async function run() {
  {
  const guest = harness(true); guest.page.applicationId.value = '1'
  const person = { id: 101, application_id: 1, display_name: 'Guest 101', lifecycle_state: 'guest' }
  const context = guest.page.loadContext()
  const search = guest.page.searchGuests(' Guest 101 ')
  assert.equal(guest.searches[0].path, 'identity')
  assert.equal(JSON.stringify(guest.searches[0].params), '{"page":1,"limit":100,"application_id":1,"keywords":"Guest 101"}')
  guest.searches[0].resolve([person, { ...person, id: 102, lifecycle_state: 'active' }, { ...person, id: 103, application_id: 2 }]); await search
  guest.reads[0].resolve([]); await context
  assert.equal(guest.searches.length, 1, 'late context must not restart or overwrite remote search')
  assert.equal(guest.page.guests.value.length, 1)
  guest.page.guestIdentityId.value = '101'; guest.page.target.value = 'g@example.test'
  const sending = guest.page.sendInvitation()
  assert.equal(guest.writes[0].params.guest_identity_id, 101)
  guest.writes[0].reject(new Error('retry')); await sending
  const old = guest.page.searchGuests('old')
  const latest = guest.page.searchGuests('latest')
  assert.equal(guest.page.guestIdentityId.value, '')
  guest.searches[2].resolve([{ ...person, id: 104 }]); await latest
  guest.searches[1].resolve([person]); await old
  assert.equal(guest.page.guests.value[0].id, 104)
  guest.page.guestIdentityId.value = '101'
  await guest.page.sendInvitation()
  assert.equal(guest.writes.length, 1, 'old guest id cannot be submitted')
  const switching = guest.page.searchGuests('switch')
  guest.page.applicationId.value = '2'
  guest.searches[3].resolve([person]); await switching
  assert.equal(guest.page.guests.value.length, 0)
  guest.page.target.value = 'ordinary@example.test'
  const ordinary = guest.page.sendInvitation()
  assert.equal('guest_identity_id' in guest.writes[1].params, false)
  guest.writes[1].reject(new Error('ordinary retry')); await ordinary
  const failure = guest.page.searchGuests('retry')
  guest.searches[4].reject(new Error('search')); await failure
  const retry = guest.page.searchGuests('retry')
  guest.searches[5].resolve([{ ...person, application_id: 2 }]); await retry
  assert.equal(guest.page.guests.value.length, 1)
  guest.stop()
  const denied = harness(true, false); denied.page.applicationId.value = '1'
  await denied.page.searchGuests('guest'); assert.equal(denied.searches.length, 0); denied.stop()
  }
  const h = harness(); h.page.applicationId.value = '1'
  const first = h.page.loadInvitations(); await finishList(h, first, [row(1)])
  assert.equal(h.page.total?.value, 21, 'invitation history must expose pagination')
  h.page.currentPage.value = 2
  const second = h.page.loadInvitations()
  assert.equal(h.reads.at(-1).params.page, 2)
  await finishList(h, second, [row(21)], 2)
  const revoke = h.page.revokeInvitation(h.page.invitations.value[0])
  h.confirms[0].resolve(); await tick()
  assert.equal(h.writes[0].params.id, 21)
  h.writes[0].resolve({}); await tick()
  assert.equal(h.reads.at(-1).params.page, 2)
  h.reads.at(-1).resolve(result([], 2, 20)); await tick()
  assert.equal(h.reads.at(-1).params.page, 1)
  await finishList(h, revoke, [row(1)], 1, 20)
  h.page.stateFilter.value = 'delivery_failed'
  assert.equal(h.page.currentPage.value, 1)
  const filtered = h.page.loadInvitations()
  assert.equal(h.reads.at(-1).params.state, 'delivery_failed')
  await finishList(h, filtered, [row(2)])
  const resend = h.page.resendInvitation(h.page.invitations.value[0])
  h.page.applicationId.value = '2'
  h.confirms[1].resolve(); await resend
  assert.equal(h.writes.length, 1, 'confirmation for old app cannot mutate')
  const context = h.page.loadContext()
  h.page.applicationId.value = '3'
  h.reads.at(-1).resolve([{ id: 99 }]); await context
  assert.equal(h.page.groups.value.length, 0)
  h.page.target.value = 'old@example.test'
  const send = h.page.sendInvitation()
  await h.page.sendInvitation()
  assert.equal(h.writes.length, 2)
  h.page.applicationId.value = '4'; h.page.target.value = 'new@example.test'
  const count = h.reads.length
  h.writes[1].resolve({}); await send
  assert.equal(h.page.target.value, 'new@example.test')
  assert.equal(h.reads.length, count)
  h.page.applicationId.value = '1'
  const ready = h.page.loadInvitations(); await finishList(h, ready, [row(3)])
  const lateRevoke = h.page.revokeInvitation(h.page.invitations.value[0])
  h.confirms[2].resolve(); await tick()
  const beforeSwitch = h.reads.length
  h.page.applicationId.value = '2'
  h.writes[2].resolve({}); await lateRevoke
  assert.equal(h.reads.length, beforeSwitch, 'old mutation cannot refresh a new application')
  h.page.target.value = 'retry@example.test'
  const failing = h.page.sendInvitation()
  h.writes[3].reject(new Error('send denied')); await failing
  assert.equal(h.page.target.value, 'retry@example.test')
  assert.equal(h.page.requestError.value.detail, 'send denied')
  const retrySend = h.page.sendInvitation()
  assert.equal(h.writes.length, 5, 'failed send releases busy state')
  h.stop()
  h.writes[4].resolve({}); await retrySend
  assert.equal(h.reads.length, beforeSwitch)
  const filteredSend = harness()
  filteredSend.page.applicationId.value = '1'
  filteredSend.page.target.value = 'once@example.test'
  const inFlight = filteredSend.page.sendInvitation()
  filteredSend.page.stateFilter.value = 'accepted'
  await filteredSend.page.sendInvitation()
  assert.equal(filteredSend.writes.length, 1, 'changing state must not release pending send lock')
  filteredSend.writes[0].resolve({}); await tick()
  assert.equal(filteredSend.reads[0].params.state, 'accepted', 'post-send refresh uses current state')
  await finishList(filteredSend, inFlight, [], 1, 0)
  filteredSend.page.target.value = 'next@example.test'
  const nextSend = filteredSend.page.sendInvitation()
  assert.equal(filteredSend.writes.length, 2, 'completed mutation releases lock')
  filteredSend.stop()
  filteredSend.writes[1].resolve({}); await nextSend
  console.log('Invitation pagination and mutation isolation behavior PASS')
}
run().catch(error => { console.error(error); process.exitCode = 1 })
