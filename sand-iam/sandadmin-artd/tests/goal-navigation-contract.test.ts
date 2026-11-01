import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { guidanceGoals, guidanceLocation, nextGoalStep, resolveGuidanceGoal } from '../src/views/plugin/sand-iam/api/goalGuidance'
import { sandIamTaskPaths } from '../src/views/plugin/sand-iam/api/taskPaths'

// Validate destinations against the installed public menu contract, not component folder names.
const menus = readFileSync(new URL('../../lifecycle/base.pgsql', import.meta.url), 'utf8')
const publicPaths = new Set([...menus.matchAll(/\('SandIAM[^']*','[^']*','([^']*)','\/plugin\/sand-iam\/[^']*'/g)]
  .map(match => `/sand-iam/${match[1]}`))
assert.ok(publicPaths.has('/sand-iam/overview'))
assert.ok(!publicPaths.has('/sand-iam/index'))
const context = { organization_id: 19, application_id: 20, environment_id: 21 }
const variants = [...guidanceGoals, resolveGuidanceGoal('login', 'register')!, resolveGuidanceGoal('login', 'custom')!, resolveGuidanceGoal('directory', 'scim')!]
for (const goal of variants) {
  const method = goal.id === 'directory' ? 'scim' : goal.id === 'login' ? 'register' : undefined
  for (const step of goal.steps) {
    if (step.contractStatus !== 'runtime') assert.ok(publicPaths.has(step.path), `${goal.id}/${step.key}: ${step.path}`)
    const next = nextGoalStep(goal, step.key)
    for (const target of [guidanceLocation(context, goal.id, step.key, method), guidanceLocation(context, goal.id, next?.key, method)]) {
      assert.ok(publicPaths.has(target.path), `${goal.id} return/continue must resolve`)
      assert.equal(target.query.application_id, '20')
      assert.equal(target.query.goal, goal.id)
      assert.equal(target.query.method, method)
    }
  }
}
for (const task of Object.values(sandIamTaskPaths)) {
  assert.ok(publicPaths.has(task.entryPath), task.entryPath)
  for (const step of task.steps) {
    if (step.contractStatus !== 'runtime') assert.ok(publicPaths.has(step.path), `${task.entryPath}/${step.key}: ${step.path}`)
  }
}
const completed = guidanceLocation({ organization_id: 19, application_id: 55 }, 'access')
assert.ok(publicPaths.has(completed.path))
assert.equal(completed.query.application_id, '55')
assert.equal(completed.query.intent, undefined)
assert.equal(completed.query.flow, undefined)
console.log('Goal navigation contracts passed: all six goals, method variants, legacy tasks, and new application completion resolve public menu paths')
