/**
 * 一键安装依赖链编排器的单元测试（node:test + Node 原生 type stripping）：
 *
 *   node --test tests/frontend/install-orchestrator.test.ts
 *
 * 覆盖：安装即完成快速收敛、安装请求被中止/超时后仍按服务端快照裁决、
 * 视图停用窗口内（编排器自有读通道，无生命周期门禁）轮询并自动入队
 * composer→npm 依赖链、依赖失败→面板弹出语义（chain-failed）、快照丢失、
 * 轮询预算耗尽与业务拒绝不轮询；不挂载 Vue、不请求网络。
 */
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  dependencyTaskLabel,
  runDependencyChainLoop,
  runInstallOrchestration,
  type DependencyChainRow,
  type DependencyKind,
  type DependencyOrchestratorDeps
} from '../../sandadmin-artd/src/views/plugin/sandpackage/install/dependency-chain.ts'

const row = (overrides: Partial<DependencyChainRow> = {}): DependencyChainRow => ({
  app: 'sand-ai',
  state: 2,
  ...overrides
})

const cancelledError = Object.assign(new Error('aborted'), { transport: 'cancelled' })

const baseDeps = (overrides: Partial<DependencyOrchestratorDeps> = {}): DependencyOrchestratorDeps => ({
  install: async () => null,
  fetchRows: async () => [],
  runDependencyTask: async () => true,
  sleep: async () => {},
  pollIntervalMs: 1,
  pollBudgetMs: 50,
  ...overrides
})

test('安装请求直接返回已安装：不轮询、不入队', async () => {
  let fetches = 0
  const tasks: DependencyKind[] = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      install: async () => row({ state: 1, version: '0.3.1' }),
      fetchRows: async () => {
        fetches += 1
        return []
      },
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.equal(fetches, 0)
  assert.deepEqual(tasks, [])
})

test('安装请求被中止（ERR_ABORTED/取消）仍按服务端快照裁决并入队依赖链', async () => {
  // 模拟 WP-D 现场：install POST 在路由重建/服务重载窗口被 net::ERR_ABORTED；
  // 编排器自有读通道（不挂组件 readsActive）继续轮询，按快照驱动链推进。
  const snapshots: DependencyChainRow[][] = [
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 轮询第 1 次：等待依赖
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 链第 1 步：composer 待装
    [row({ state: 4, npm_dependent_wait_install: 1 })], // 链第 2 步：npm 待装
    [row({ state: 1, version: '0.3.1' })] // 链第 3 步：依赖清完已安装
  ]
  let fetches = 0
  const tasks: DependencyKind[] = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      install: async () => {
        throw cancelledError
      },
      fetchRows: async () => {
        const snap = snapshots[Math.min(fetches, snapshots.length - 1)]
        fetches += 1
        return snap
      },
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(tasks, ['composer', 'npm'])
  assert.ok(fetches >= 4, `轮询应在停用窗口内持续推进（实际 ${fetches} 次）`)
})

test('安装请求超时（transport=timeout）同样按快照裁决', async () => {
  const timeoutError = Object.assign(new Error('timeout'), { transport: 'timeout' })
  const outcome = await runInstallOrchestration(
    baseDeps({
      install: async () => {
        throw timeoutError
      },
      fetchRows: async () => [row({ state: 1, version: '0.3.1' })]
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
})

test('业务拒绝（无 transport 标记）不轮询，原样抛出', async () => {
  let fetches = 0
  await assert.rejects(
    runInstallOrchestration(
      baseDeps({
        install: async () => {
          throw new Error('插件不处于等待安装状态')
        },
        fetchRows: async () => {
          fetches += 1
          return []
        }
      }),
      { app: 'sand-ai', title: 'SandAI' }
    ),
    /插件不处于等待安装状态/
  )
  assert.equal(fetches, 0)
})

test('依赖任务失败 → chain-failed，携带面板所需的通俗原因与环节标签', async () => {
  const outcome = await runInstallOrchestration(
    baseDeps({
      install: async () => {
        throw cancelledError
      },
      fetchRows: async () => [row({ state: 4, composer_dependent_wait_install: 1 })],
      runDependencyTask: async () => false,
      failureReason: () => '【原因】无法连接 Composer 依赖仓库或镜像，请检查网络后重试'
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'chain-failed')
  if (outcome.kind !== 'chain-failed') return
  assert.equal(outcome.failure.label, '后端依赖（composer）')
  assert.equal(outcome.failure.app, 'sand-ai')
  assert.match(outcome.failure.reason, /Composer 依赖仓库/)
})

test('链执行中快照丢行 → chain-failed 提示读取状态失败', async () => {
  const outcome = await runDependencyChainLoop(
    baseDeps({ fetchRows: async () => [row({ app: 'other', state: 1 })] }),
    'sand-ai',
    'SandAI'
  )
  assert.equal(outcome.kind, 'chain-failed')
  if (outcome.kind !== 'chain-failed') return
  assert.match(outcome.failure.reason, /无法读取插件当前状态/)
})

test('读失败保留上次快照：initialRows 兜底，首轮读失败仍可入队', async () => {
  let fetches = 0
  const tasks: DependencyKind[] = []
  const outcome = await runDependencyChainLoop(
    baseDeps({
      fetchRows: async () => {
        fetches += 1
        if (fetches === 1) throw new Error('read failed')
        return [row({ state: 1, version: '0.3.1' })]
      },
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    'sand-ai',
    'SandAI',
    [row({ state: 4, composer_dependent_wait_install: 1 })]
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(tasks, ['composer'])
})

test('轮询预算耗尽仍无依赖标志与终态 → unconfirmed，不当成功', async () => {
  const outcome = await runInstallOrchestration(
    baseDeps({
      install: async () => {
        throw cancelledError
      },
      fetchRows: async () => [row({ state: 2, operation_pending: 1 })],
      pollBudgetMs: 3,
      pollIntervalMs: 1
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'unconfirmed')
  assert.match(
    outcome.kind === 'unconfirmed' ? outcome.reason : '',
    /插件管理/
  )
})

test('服务端登记安装失败 → install-failed 携带行内原因', async () => {
  const outcome = await runInstallOrchestration(
    baseDeps({
      install: async () => {
        throw cancelledError
      },
      fetchRows: async () => [row({ state: 8, last_error: '数据库安装失败' })]
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'install-failed')
  assert.equal(outcome.kind === 'install-failed' ? outcome.reason : '', '数据库安装失败')
})

test('链轮次上限耗尽 → chain-failed 轮次异常，不死循环', async () => {
  let taskCalls = 0
  const outcome = await runDependencyChainLoop(
    baseDeps({
      maxChainSteps: 3,
      fetchRows: async () => [row({ state: 4, composer_dependent_wait_install: 1 })],
      runDependencyTask: async () => {
        taskCalls += 1
        return true
      }
    }),
    'sand-ai',
    'SandAI'
  )
  assert.equal(outcome.kind, 'chain-failed')
  assert.equal(taskCalls, 3)
  if (outcome.kind !== 'chain-failed') return
  assert.match(outcome.failure.reason, /轮次异常/)
})

test('依赖环节标签：composer 优先、npm 兜底', () => {
  assert.equal(dependencyTaskLabel('composer'), '后端依赖（composer）')
  assert.equal(dependencyTaskLabel('npm'), '前端依赖（npm/pnpm）')
})
