/**
 * 普通确认对话框配套纯函数的单元测试（node:test + Node 原生 type stripping）：
 *
 *   node --test tests/frontend/plain-confirmations.test.ts
 *
 * 覆盖：撤回升级包 / 登记现有插件的服务端确认串由前端按行字段自动生成
 * （与 InstallLogic/LegacyInstallLogic 校验格式逐字一致，不再需要用户手打）；
 * 卸载对话框依赖方警告文案（列表、缺省标题回退、无依赖方不渲染、
 * 不出现内部名词）。不挂载 Vue、不请求网络。
 */
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  discardUpgradeCandidateConfirmation,
  registerExistingConfirmation,
  uninstallDependentLabel,
  uninstallDependentsText,
  uninstallUnverifiableText
} from '../../sandadmin-artd/src/views/plugin/sandpackage/install/plain-confirmations.ts'

test('撤回升级包确认串与服务端 DISCARD app@version 校验一致', () => {
  assert.equal(
    discardUpgradeCandidateConfirmation('sand-ai', '0.4.8'),
    'DISCARD sand-ai@0.4.8'
  )
  // 行字段原样进入串中，前端不再要求用户逐字输入。
  assert.equal(
    discardUpgradeCandidateConfirmation('sand-iam', '0.9.5'),
    'DISCARD sand-iam@0.9.5'
  )
})

test('登记现有插件确认串与服务端 REGISTER app@version 校验一致', () => {
  assert.equal(registerExistingConfirmation('sand-ai', '0.4.8'), 'REGISTER sand-ai@0.4.8')
})

test('依赖方展示名：优先通俗标题，缺省回退标识', () => {
  assert.equal(
    uninstallDependentLabel({ app: 'sand-ai', title: 'SandAI AI 能力底座' }),
    'SandAI AI 能力底座（sand-ai）'
  )
  assert.equal(uninstallDependentLabel({ app: 'sand-ai' }), 'sand-ai')
  assert.equal(uninstallDependentLabel({ app: 'sand-ai', title: '' }), 'sand-ai')
  assert.equal(uninstallDependentLabel({ app: 'sand-ai', title: 'sand-ai' }), 'sand-ai')
})

test('卸载依赖方警告：列出依赖方并表达需先卸载依赖方', () => {
  const text = uninstallDependentsText([
    { app: 'sand-ai', title: 'SandAI AI 能力底座' },
    { app: 'sand-workflow', title: 'SandWorkflow 工作流' }
  ])
  assert.ok(text.includes('SandAI AI 能力底座（sand-ai）'))
  assert.ok(text.includes('SandWorkflow 工作流（sand-workflow）'))
  // 与服务端硬拒绝一致：需要先卸载依赖方，而非暗示卸载会放行。
  assert.ok(text.includes('需要先卸载'))
  assert.ok(!text.includes('可能无法正常工作'))
  // 不出现内部名词或英文指令。
  assert.ok(!/config\.json|plugin_dependencies|DISCARD|sha256|JSON/.test(text))
})

test('无法核对的依赖方警告：说明可能被拒绝且不夸张承诺', () => {
  const text = uninstallUnverifiableText([{ app: 'sand-ai', title: 'SandAI AI 能力底座' }])
  assert.ok(text.includes('SandAI AI 能力底座（sand-ai）'))
  assert.ok(text.includes('无法确认'))
  assert.ok(!/config\.json|plugin_dependencies|DISCARD|sha256|JSON/.test(text))
})

test('无依赖方/无无法核对项返回空串，由调用方决定是否渲染警告行', () => {
  assert.equal(uninstallDependentsText([]), '')
  assert.equal(uninstallUnverifiableText([]), '')
})
