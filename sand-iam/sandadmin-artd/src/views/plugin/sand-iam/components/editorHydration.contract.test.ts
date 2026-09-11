import assert from 'node:assert/strict'
import { planSandIamEditorHydrationFinish } from '../api/editorLifecycle'

assert.deepEqual(planSandIamEditorHydrationFinish(7, 7, true), {
  clearHydration: true,
  captureDependencyValues: true
})

assert.deepEqual(planSandIamEditorHydrationFinish(8, 7, false), {
  clearHydration: false,
  captureDependencyValues: false
})

assert.deepEqual(planSandIamEditorHydrationFinish(7, 7, false), {
  clearHydration: true,
  captureDependencyValues: false
})

console.log('editor hydration completion contract: PASS')
