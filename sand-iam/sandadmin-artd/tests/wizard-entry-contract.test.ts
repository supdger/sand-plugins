import assert from 'node:assert/strict'
import { newApplicationQuery, readWizardDraft, wizardEntry } from '../src/views/plugin/sand-iam/getting-started/wizardEntry'
import { createWizardContext, parseWizardContext } from '../src/views/plugin/sand-iam/getting-started/wizardState'

const flow = '00000000-0000-4000-8000-000000000001'
const secondFlow = '00000000-0000-4000-8000-000000000002'
const oldContext = { organization_id: 19, application_id: 19, environment_id: 31, workload_client_id: 42 }
const query = newApplicationQuery(oldContext, 'login', 'register', flow)
assert.deepEqual(query, { organization_id: '19', intent: 'new-application', flow, goal: 'login', method: 'register' })
const fresh = wizardEntry(query)
assert.deepEqual(fresh.supplied, { organization_id: 19 })
assert.deepEqual(wizardEntry({ ...query, application_id: '19', environment_id: '31' }).supplied, { organization_id: 19 })
assert.deepEqual(wizardEntry(newApplicationQuery({}, 'machine', undefined, flow)).supplied, {})
assert.equal(wizardEntry({ intent: 'new-application', flow: '../old-progress' }).storageKey, null)

// A prior general wizard cannot seed a new registration; refresh/back within the same flow can resume it.
const regular = wizardEntry({ application_id: '19', environment_id: '31' })
assert.deepEqual(regular.supplied, { application_id: 19, environment_id: 31 })
assert.notEqual(regular.storageKey, fresh.storageKey)
assert.equal(wizardEntry(query).storageKey, fresh.storageKey)
assert.notEqual(wizardEntry({ ...query, flow: secondFlow }).storageKey, fresh.storageKey)
const now = Date.now()
const saved = JSON.stringify(createWizardContext(
  { organization: 19, application: 55, environment: null },
  { organization: 'confirmed', application: 'confirmed', environment: 'draft' },
  { organization: null, application: null, environment: null }, now
))
const storage = new Map([[regular.storageKey, saved]])
assert.equal(parseWizardContext(storage.get(fresh.storageKey) ?? null, now), null)
storage.set(fresh.storageKey, saved)
assert.equal(parseWizardContext(storage.get(wizardEntry(query).storageKey) ?? null, now)?.ids.application, 55)

// Unsubmitted form drafts survive reload but are data only, never verified object identity.
assert.deepEqual(readWizardDraft(JSON.stringify({ name: '新应用', code: 'new-app', status: '1', savedAt: now }), now),
  { name: '新应用', code: 'new-app', status: '1' })
assert.equal(readWizardDraft('{"application_id":19}', now), null)
assert.equal(readWizardDraft(JSON.stringify({ name: '旧草稿', code: 'old', status: '1', savedAt: now - 86400001 }), now), null)
assert.equal(readWizardDraft('invalid-json', now), null)
console.log('Wizard entry contracts passed: new intent, stale context isolation, resume, existing application continuation, and drafts')
