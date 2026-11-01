import assert from 'node:assert/strict'
import { canUseGoal, guidanceGoals, guidanceQuery, resolveGuidanceGoal, nextGoalStep, goalStepParams, activeClientAudience } from '../src/views/plugin/sand-iam/api/goalGuidance'
const machine = resolveGuidanceGoal('machine', undefined)!
assert.ok(machine.steps.every(step => !['employee-login', 'auth-settings', 'identity-invitation', 'identity'].includes(step.key)))
const login = resolveGuidanceGoal('login', 'register')!
assert.ok(login.steps.some(step => step.key === 'self-register' && step.contractStatus === 'runtime' && !step.permission))
assert.ok(!login.steps.some(step => step.key === 'identity-invitation'))
assert.ok(!login.steps.some(step => ['resource', 'policy', 'role'].includes(step.key)))
const scim = resolveGuidanceGoal('directory', 'scim')!
assert.ok(scim.steps.some(step => step.key === 'identity-provider'))
assert.ok(scim.steps.some(step => step.key === 'scim-tokens' && !step.optional))
assert.ok(!scim.steps.some(step => step.key === 'sync-connector'))
const connector = resolveGuidanceGoal('directory', 'connector')!
assert.ok(connector.steps.some(step => step.key === 'sync-connector'))
assert.ok(!connector.steps.some(step => step.key === 'scim-tokens'))
for (const goal of guidanceGoals) {
  assert.equal(canUseGoal(goal, () => false), false)
  assert.equal(canUseGoal(goal, permission => permission === 'sand_iam:audit:index'), false)
  assert.equal(canUseGoal(goal, permission => permission === 'sand_iam:developer:openapi'), false)
}
assert.equal(canUseGoal(machine, permission => permission === 'sand_iam:credential:index'), true)
const context = { organization_id: 1, application_id: 10, environment_id: 100 }
assert.deepEqual(guidanceQuery(context, 'directory', 'scim-tokens', 'scim'), { organization_id: '1', application_id: '10', environment_id: '100', goal: 'directory', step: 'scim-tokens', method: 'scim' })
assert.equal(nextGoalStep(scim, 'identity-provider')?.key, 'scim-tokens')
assert.equal(resolveGuidanceGoal('forged', 'scim'), undefined)
console.log('Goal guidance contracts passed: independent branches, scope continuity, deny-all, and sequence')

assert.equal(goalStepParams(machine.steps[0], { application_id: 10 }), null)
assert.deepEqual(goalStepParams(machine.steps[0], { application_id: 10, environment_id: 100 }), { page: 1, limit: 1, application_id: 10, environment_id: 100 })
assert.deepEqual(goalStepParams(machine.steps[1], { application_id: 10, environment_id: 101, workload_client_id: 901 }), { page: 1, limit: 1, application_id: 10, environment_id: 101, workload_client_id: 901 })

assert.equal(activeClientAudience({ id: 901, status: 1, audience: 'provider-b' }, 901), 'provider-b')
assert.equal(activeClientAudience({ id: 901, status: 1, audience: 'provider-b' }, 902), null)
assert.equal(activeClientAudience({ id: 901, status: 2, audience: 'provider-b' }, 901), null)
assert.equal(activeClientAudience({ id: 901, status: 1, code: 'provider-b' }, 901), null)

const access = resolveGuidanceGoal('access', undefined)!
assert.ok(access.steps.findIndex(step => step.key === 'business-action') < access.steps.findIndex(step => step.key === 'policy'))
assert.ok(access.steps.some(step => step.key === 'business-action' && !step.optional && step.endpoint === 'application-business-action'))
const api = resolveGuidanceGoal('api', undefined)!
assert.deepEqual(api.steps.slice(0, 3).map(step => step.key), ['resource', 'business-action', 'api-resource'])
assert.equal(goalStepParams(access.steps.find(step => step.key === 'business-action')!, { application_id: 20 })?.application_id, 20)
console.log('Required business action and resource prerequisites PASS')

for (const method of ['invite', 'register']) {
  const login = resolveGuidanceGoal('login', method)!
  const appearance = login.steps.find(step => step.key === 'application-experience')!
  assert.ok(appearance && !appearance.optional)
  assert.equal(appearance.permission, 'sand_iam:application_experience:index')
  assert.deepEqual(goalStepParams(appearance, { application_id: 20 }), { page: 1, limit: 1, application_id: 20, status: 1 })
  assert.equal(nextGoalStep(login, 'auth-settings')?.key, 'application-experience')
  assert.equal(nextGoalStep(login, 'application-experience')?.key, method === 'register' ? 'self-register' : 'identity-invitation')
}
const customLogin = resolveGuidanceGoal('login', 'custom')!
assert.ok(customLogin.steps.every(step => !['application-experience', 'self-register', 'identity-invitation'].includes(step.key)))
assert.equal(nextGoalStep(customLogin, 'auth-settings')?.key, 'developer-docs')
assert.equal(nextGoalStep(customLogin, 'developer-docs')?.key, 'employee-login')
assert.match(customLogin.steps.find(step => step.key === 'employee-login')!.input, /现有登录入口/)
assert.equal(guidanceQuery({ application_id: 20 }, 'login', 'developer-docs', 'custom').method, 'custom')
console.log('Login prerequisites PASS: enabled built-in experience, reuse, permission, and custom-login branch')
