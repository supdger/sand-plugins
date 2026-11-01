import assert from 'node:assert/strict'
import { policyPublicationLabel, policyEditorFields } from '../src/views/plugin/sand-iam/api/policyPublication'
import { policyFields, businessActionFields } from '../src/views/plugin/sand-iam/api/fields'

for (const pointer of [null, 0, '0']) {
  assert.equal(policyPublicationLabel({ state: 'published', status: 1, published_version_id: pointer }), '尚未发布（请点击发布）')
}
for (const pointer of [1, '1', 450, '450']) {
  assert.equal(policyPublicationLabel({ state: 'draft', status: 1, published_version_id: pointer }), '已发布（启用）')
  assert.equal(policyPublicationLabel({ state: 'published', status: '1', published_version_id: pointer }), '已发布（启用）')
  assert.equal(policyPublicationLabel({ state: 'published', status: 2, published_version_id: pointer }), '已发布（暂停）')
  assert.equal(policyPublicationLabel({ state: 'revoked', status: '2', published_version_id: pointer }), '已发布（暂停）')
}
for (const pointer of [undefined, '', 'bad', -1, 1.5, '1e3', true, Number.MAX_SAFE_INTEGER + 1]) {
  assert.equal(policyPublicationLabel({ status: 1, published_version_id: pointer }), '运行版本未知（查看版本历史）')
}
assert.equal(policyPublicationLabel({ published_version_id: 1 }), '已发布（启停状态未知）')
assert.ok(!policyEditorFields.some(field => field.key === 'state'))
assert.ok(policyFields.some(field => field.key === 'state'), 'policy-specific form must not mutate shared source fields')
assert.ok(businessActionFields.some(field => field.key === 'state'), 'business-action publication editing is unaffected')
console.log('Policy publication PASS: runtime pointer, numeric API representations, paused/unknown, policy-only editor fields')
