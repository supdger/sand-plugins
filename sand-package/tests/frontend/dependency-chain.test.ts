/**
 * 一键安装依赖链判定的单元测试（node:test + Node 原生 type stripping）：
 *
 *   node --test tests/frontend/dependency-chain.test.ts
 *
 * 覆盖：依赖入队条件（*_dependent_wait_install 标志）、安装结果状态判定、
 * 安装请求错误的结果未知识别、轮询收敛与预算耗尽；不挂载 Vue、不请求网络。
 */
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  dependencyKindOf,
  installOutcomeOf,
  installOutcomeReason,
  isUncertainInstallError,
  pollInstallOutcome,
  type DependencyChainRow
} from '../../sandadmin-artd/src/views/plugin/sandpackage/install/dependency-chain.ts'

const row = (overrides: Partial<DependencyChainRow> = {}): DependencyChainRow => ({
  app: 'sand-ai',
  state: 2,
  ...overrides
})

test('依赖入队条件：composer 优先于 npm，无标志不入队', () => {
  assert.equal(dependencyKindOf(row({ composer_dependent_wait_install: 1 })), 'composer')
  assert.equal(dependencyKindOf(row({ npm_dependent_wait_install: 1 })), 'npm')
  assert.equal(
    dependencyKindOf(row({ composer_dependent_wait_install: 1, npm_dependent_wait_install: 1 })),
    'composer'
  )
  assert.equal(dependencyKindOf(row({ state: 1 })), null)
  // INI_SCANNER_TYPED 产出的 int 之外的值都不触发入队
  assert.equal(dependencyKindOf(row({ composer_dependent_wait_install: 0 })), null)
  assert.equal(dependencyKindOf(undefined), null)
})

test('安装结果判定：依赖等待 / 已安装 / 进行中 / 失败', () => {
  assert.equal(
    installOutcomeOf(row({ state: 4, composer_dependent_wait_install: 1 })),
    'wait_dependencies'
  )
  assert.equal(installOutcomeOf(row({ state: 1 })), 'installed')
  assert.equal(installOutcomeOf(row({ state: 2, operation_pending: 1 })), 'pending')
  assert.equal(installOutcomeOf(row({ state: 4 })), 'pending')
  assert.equal(installOutcomeOf(row({ state: 8 })), 'failed')
  assert.equal(installOutcomeOf(row({ state: 7 })), 'failed')
  assert.equal(installOutcomeOf(row({ state: 3 })), 'failed')
  assert.equal(installOutcomeOf(row({ state: 5 })), 'failed')
  assert.equal(installOutcomeOf(undefined), 'pending')
})

test('安装结果判定：事务在途 operation_pending=1 判 pending，失败态不被吞掉', () => {
  // WP-PRE2 撞锁现场：deployFreshOrUpgrade 在锁内先登记 state=4+依赖等待
  // 标志再写载荷，此时 operation_pending=1 —— 必须按在途轮询而非入队。
  assert.equal(
    installOutcomeOf(row({ state: 4, composer_dependent_wait_install: 1, operation_pending: 1 })),
    'pending'
  )
  assert.equal(
    installOutcomeOf(row({ state: 4, npm_dependent_wait_install: 1, operation_pending: 1 })),
    'pending'
  )
  // 事务收尾窗口：state 已推进但 pending 尚未清除，仍按在途
  assert.equal(installOutcomeOf(row({ state: 1, operation_pending: 1 })), 'pending')
  // 安装失败现场同样保留 operation_pending=1 —— 失败终态优先，不被在途吞掉
  assert.equal(
    installOutcomeOf(row({ state: 8, operation_pending: 1, composer_dependent_wait_install: 1 })),
    'failed'
  )
  assert.equal(installOutcomeOf(row({ state: 8, operation_pending: 1 })), 'failed')
  // 事务提交解锁后同一等待标志才放行入队
  assert.equal(
    installOutcomeOf(row({ state: 4, composer_dependent_wait_install: 1, operation_pending: 0 })),
    'wait_dependencies'
  )
})

test('失败原因字段优先级与兜底', () => {
  assert.equal(
    installOutcomeReason(row({ recovery_reason: '恢复原因', last_error: '最后错误' })),
    '恢复原因'
  )
  assert.equal(installOutcomeReason(row({ last_error: '最后错误' })), '最后错误')
  assert.equal(installOutcomeReason(row({ stage_label: '阶段标签' })), '阶段标签')
  assert.equal(installOutcomeReason(row({})), '安装未完成，请到插件管理查看')
})

test('安装请求错误：超时/断网/取消视为结果未知，业务拒绝不在此列', () => {
  const timeoutError = Object.assign(new Error('timeout'), { transport: 'timeout' })
  const networkError = Object.assign(new Error('net'), { transport: 'network' })
  const cancelledError = Object.assign(new Error('cancel'), { transport: 'cancelled' })
  const unknownError = Object.assign(new Error('unknown'), { transport: 'unknown' })
  const httpRejected = Object.assign(new Error('插件不处于等待安装状态'), {
    transport: 'http',
    code: 400
  })
  const businessError = new Error('安装确认内容不匹配')

  assert.equal(isUncertainInstallError(timeoutError), true)
  assert.equal(isUncertainInstallError(networkError), true)
  assert.equal(isUncertainInstallError(cancelledError), true)
  assert.equal(isUncertainInstallError(unknownError), true)
  assert.equal(isUncertainInstallError(httpRejected), false)
  assert.equal(isUncertainInstallError(businessError), false)
  assert.equal(isUncertainInstallError('not an object'), false)
  assert.equal(isUncertainInstallError(null), false)
})

test('轮询：服务端登记依赖等待标志后立即收敛', async () => {
  let snapshot: DependencyChainRow[] = [
    row({ state: 2, operation_pending: 1 }),
    row({ state: 4, composer_dependent_wait_install: 1 })
  ]
  let refreshes = 0
  const outcome = await pollInstallOutcome({
    app: 'sand-ai',
    refreshRows: async () => {
      refreshes += 1
    },
    readRows: () => snapshot.slice(refreshes > 0 ? 1 : 0),
    sleep: async () => {},
    intervalMs: 1,
    budgetMs: 1000
  })
  assert.equal(outcome.status, 'wait_dependencies')
  assert.equal(outcome.row?.composer_dependent_wait_install, 1)
})

test('轮询：进行中状态持续到预算耗尽返回 pending', async () => {
  const outcome = await pollInstallOutcome({
    app: 'sand-ai',
    refreshRows: async () => {},
    readRows: () => [row({ state: 2, operation_pending: 1 })],
    sleep: async () => {},
    intervalMs: 1,
    budgetMs: 3
  })
  assert.equal(outcome.status, 'pending')
  assert.equal(outcome.row?.state, 2)
})

test('轮询：服务端报失败即收敛，不继续等待', async () => {
  const outcome = await pollInstallOutcome({
    app: 'sand-ai',
    refreshRows: async () => {},
    readRows: () => [row({ state: 8, last_error: '数据库安装失败' })],
    sleep: async () => {},
    intervalMs: 1,
    budgetMs: 1000
  })
  assert.equal(outcome.status, 'failed')
  assert.equal(outcome.row?.last_error, '数据库安装失败')
})
