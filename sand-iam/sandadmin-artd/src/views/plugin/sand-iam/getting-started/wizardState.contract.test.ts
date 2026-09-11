/**
 * Real pure-function contract: imports wizardState and evaluates its public
 * state transitions. It does not mount a Vue component or exercise a browser.
 */
import assert from 'node:assert/strict'
import {
  WIZARD_CONTEXT_MAX_AGE_MS,
  canNavigateTo,
  canPostForPhase,
  createWizardContext,
  parseWizardContext,
  phaseAfterSave,
  phaseAfterVerifiedRecord,
  verificationRecoveryForHttp
} from './wizardState'

const now = 1_800_000_000_000
const ids = { organization: 11, application: 22, environment: null } as const
const phases = {
  organization: 'confirmed',
  application: 'created_pending_confirmation',
  environment: 'draft'
} as const
const pendingCodes = { organization: null, application: null, environment: null } as const

const current = createWizardContext(ids, phases, pendingCodes, now)
assert.deepEqual(parseWizardContext(JSON.stringify(current), now), current)
assert.deepEqual(
  parseWizardContext(
    JSON.stringify(createWizardContext(ids, phases, pendingCodes, now - WIZARD_CONTEXT_MAX_AGE_MS)),
    now
  ),
  createWizardContext(ids, phases, pendingCodes, now - WIZARD_CONTEXT_MAX_AGE_MS)
)
assert.equal(
  parseWizardContext(
    JSON.stringify(
      createWizardContext(ids, phases, pendingCodes, now - WIZARD_CONTEXT_MAX_AGE_MS - 1)
    ),
    now
  ),
  null
)
assert.equal(
  parseWizardContext(JSON.stringify(createWizardContext(ids, phases, pendingCodes, now + 1)), now),
  null
)

assert.equal(phaseAfterSave(44), 'created_pending_confirmation')
assert.equal(phaseAfterSave(null), 'save_outcome_unknown')
assert.equal(canPostForPhase('draft'), true)
assert.equal(canPostForPhase('created_pending_confirmation'), false)
assert.equal(canPostForPhase('save_outcome_unknown'), false)
assert.equal(verificationRecoveryForHttp(null), 'retain_pending')
assert.equal(verificationRecoveryForHttp(503), 'retain_pending')
assert.equal(verificationRecoveryForHttp(404), 'lookup_required')
assert.equal(verificationRecoveryForHttp(403), 'lookup_required')
assert.equal(verificationRecoveryForHttp(401), 'lookup_required')
assert.equal(canPostForPhase('lookup_required'), false)
const lookupContext = createWizardContext(
  { organization: 11, application: null, environment: null },
  { organization: 'lookup_required', application: 'draft', environment: 'draft' },
  { organization: 'org-1', application: null, environment: null },
  now
)
assert.deepEqual(parseWizardContext(JSON.stringify(lookupContext), now), lookupContext)
assert.equal(canNavigateTo('organization', phases), true)
assert.equal(canNavigateTo('application', phases), true)
assert.equal(canNavigateTo('environment', phases), false)
assert.equal(
  canNavigateTo('application', {
    organization: 'disabled',
    application: 'draft',
    environment: 'draft'
  }),
  false
)
assert.equal(
  phaseAfterVerifiedRecord({ id: 11, name: '主体甲', code: 'org-1', status: 2 }),
  'disabled'
)
assert.equal(
  phaseAfterVerifiedRecord({ id: 11, name: '主体甲', code: 'org-1', status: 1 }),
  'confirmed'
)

console.log('wizard state recovery contract: PASS')
