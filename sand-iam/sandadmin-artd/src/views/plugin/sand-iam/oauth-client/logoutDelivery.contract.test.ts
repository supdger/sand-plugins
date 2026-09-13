import assert from 'node:assert/strict'
import {
  logoutDeliveryRecoverable,
  logoutRecoveryError,
  parseLogoutDelivery,
  parseLogoutDeliveryPage,
  parseLogoutRecovery
} from '../api/logoutDeliveryContracts'

const delivery = {
  id: 7,
  event_id: 'event-7',
  state: 'dead',
  attempt_count: 5,
  last_error_code: 'HTTP_503',
  update_time: '2026-09-13 12:00:00',
  status: 2
}
const untrusted = {
  ...delivery,
  auth_session_id: 123,
  response_digest: 'private-digest',
  encrypted_logout_token: 'private-token',
  unexpected: 'unknown'
}
assert.deepEqual(parseLogoutDelivery(untrusted), delivery)
assert.deepEqual(parseLogoutDeliveryPage({ data: [untrusted], total: 1 }), {
  data: [delivery],
  total: 1
})
assert.equal(logoutDeliveryRecoverable(parseLogoutDelivery(delivery)), true)
assert.equal(
  logoutDeliveryRecoverable(parseLogoutDelivery({ ...delivery, state: 'delivered' })),
  false
)
assert.equal(logoutDeliveryRecoverable(parseLogoutDelivery({ ...delivery, status: 1 })), false)
for (const invalid of [
  null,
  [],
  {},
  { ...delivery, id: -1 },
  { ...delivery, id: '7' },
  { ...delivery, state: 'unknown' },
  { ...delivery, status: 0 },
  { ...delivery, event_id: '' },
  { ...delivery, attempt_count: 1.5 },
  { ...delivery, last_error_code: {} },
  { ...delivery, update_time: 1 }
])
  assert.throws(() => parseLogoutDelivery(invalid))
for (const invalid of [null, { data: [], total: -1 }, { data: [null], total: 1 }]) {
  assert.throws(() => parseLogoutDeliveryPage(invalid))
}
const recovery = {
  source_delivery_id: 7,
  delivery_id: 8,
  event_id: 'event-8',
  state: 'pending',
  already_reissued: false,
  encrypted_logout_token: 'private-token'
}
assert.deepEqual(parseLogoutRecovery(recovery), { already_reissued: false })
assert.deepEqual(parseLogoutRecovery({ ...recovery, already_reissued: true }), {
  already_reissued: true
})
assert.throws(() => parseLogoutRecovery({ ...recovery, already_reissued: 'true' }))
assert.throws(() => parseLogoutRecovery({ ...recovery, source_delivery_id: null }))
assert.throws(() => parseLogoutRecovery({ ...recovery, state: 'unknown' }))
assert.match(logoutRecoveryError('SAND_IAM_OIDC_LOGOUT_SESSION_NOT_REVOKED'), /原会话尚未撤销/)
assert.match(logoutRecoveryError('SAND_IAM_IDEMPOTENCY_CONFLICT'), /请求编号冲突/)
assert.equal(logoutRecoveryError('没有查看权限'), '没有查看权限')
console.log('logout delivery DTO and recovery behavior: PASS')
