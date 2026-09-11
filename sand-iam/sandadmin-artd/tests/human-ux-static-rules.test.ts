/**
 * behavior-test-gate: static-rule
 *
 * This file reads source only to enforce static structure, menu/copy and
 * layout rules. It never proves mounted components, clicks, API saves, or
 * refreshes; those require the browser acceptance matrix.
 */
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'

function source(path: string): string {
  return readFileSync(new URL(path, import.meta.url), 'utf8')
}

const taskPathSource = source('../src/views/plugin/sand-iam/components/TaskPath.vue')
assert.doesNotMatch(taskPathSource, /适用角色|等待 Codex|max-height:\s*242px|overflow:\s*auto/)
assert.match(taskPathSource, /SAND_IAM_APPLICATION_PORTAL_URL/)
assert.match(taskPathSource, /v-if="canOpen\(step\)"/)

const overviewSource = source('../src/views/plugin/sand-iam/index/index.vue')
assert.match(overviewSource, /第一次使用/)
assert.match(overviewSource, /column-count:\s*3/)
assert.doesNotMatch(overviewSource, /<ElRow\b|未冻结|等待 Codex|不编造接口|运行面/)

const wizardSource = source('../src/views/plugin/sand-iam/getting-started/index.vue')
assert.match(wizardSource, /import WizardStepForm from '\.\/WizardStepForm\.vue'/)
assert.match(wizardSource, /<WizardStepForm/)
assert.match(wizardSource, /@submit="saveStep"/)
assert.match(wizardSource, /function canRead\(step: WizardStep\)/)
assert.match(wizardSource, /function canWrite\(step: WizardStep, updating: boolean\)/)
assert.match(wizardSource, /:disabled="!hasAuth\(goal\.permission\)"/)
assert.doesNotMatch(wizardSource, /ResourceEditor|goalCanOpen|canSave\(resource\)/)

const wizardFormSource = source('../src/views/plugin/sand-iam/getting-started/WizardStepForm.vue')
assert.match(wizardFormSource, /defineEmits<[\s\S]*submit:/)
assert.match(wizardFormSource, /@submit\.prevent="submit"/)
assert.match(wizardFormSource, /创建后不可修改/)

const pageCss = source('../src/views/plugin/sand-iam/components/sandIamPage.css')
assert.match(pageCss, /min-height:\s*var\(--art-full-height/)
assert.doesNotMatch(pageCss, /overflow:\s*(hidden|auto)|(?:^|[^-])height:\s*var\(--art-full-height/)

const fieldsSource = source('../src/views/plugin/sand-iam/api/fields.ts')
assert.doesNotMatch(fieldsSource, /律序|律所|律师|案件|未冻结|运行面/)
assert.match(fieldsSource, /field\(['"]login_methods['"], ['"]select['"]/)
assert.match(fieldsSource, /field\(['"]registration_fields['"], ['"]select['"]/)

assert.equal(
  existsSync(new URL('../src/views/plugin/sand-iam/waiting-codex/index.vue', import.meta.url)),
  false,
  'a waiting page must not remain reachable as management UI'
)

console.log('SandIAM static UX rules passed (not browser behavior)')
