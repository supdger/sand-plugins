/** Exercises safe UI recovery with the host HTTP error adapter isolated; no backend or browser. */
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'

const adapterUrl = new URL('./fixtures/http-error-adapter.cjs', import.meta.url).href
const hooks = registerHooks({
  resolve(specifier, context, nextResolve) {
    if (specifier === '@/utils/http/error') return { url: adapterUrl, shortCircuit: true }
    return nextResolve(specifier, context)
  }
})
async function run(): Promise<void> {
  const { describeSandIamError, SandIamTransportError } = await import('../src/views/plugin/sand-iam/api/errors')
  const mismatch = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 服务授权受众必须与服务调用身份完全一致'))
  assert.match(mismatch.detail, /复制.*服务受众/)
  const immutable = describeSandIamError(new Error('SAND_IAM_SERVICE_GRANT_IMMUTABLE: 服务调用身份、服务动作和受众创建后不可修改'))
  assert.match(immutable.detail, /撤销原授权.*新建/)
  const conflict = describeSandIamError(new SandIamTransportError('secret=do-not-render', 409, { message: 'secret=do-not-render' }))
  assert.equal(conflict.http, 409)
  assert.doesNotMatch(conflict.detail, /请编辑现有记录|do-not-render/)
  const unknown = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: password=do-not-render'))
  assert.doesNotMatch(JSON.stringify(unknown), /do-not-render/)
  assert.match(unknown.detail, /字段提示/)
  console.log('SandIAM safe error recovery contracts passed')
}
void run().catch(error => { console.error(error); process.exitCode = 1 }).finally(() => hooks.deregister())
