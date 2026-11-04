/**
 * 依赖自动升级计划的单元测试（node:test + Node 原生 type stripping）：
 *
 *   node --test tests/frontend/dependency-plan.test.ts
 *
 * 覆盖：inspectDependencies 响应 → 编排队列的归一（顺序、satisfied/bundled
 * 剔除、blocked 归类、market 形状收窄）、依赖消解环（声明序执行、依赖环
 * 去重、深度上限、blocked→plan 失败、dep 安装业务拒绝不重发目标安装、
 * resume 项续依赖链）；不挂载 Vue、不请求网络。
 */
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  DEPENDENCY_RESOLUTION_DEPTH_LIMIT,
  dependencyPlanItemsOf,
  runInstallOrchestration,
  type DependencyChainRow,
  type DependencyKind,
  type DependencyOrchestratorDeps,
  type DependencyPlanItem
} from '../../sandadmin-artd/src/views/plugin/sandpackage/install/dependency-chain.ts'

const row = (overrides: Partial<DependencyChainRow> = {}): DependencyChainRow => ({
  app: 'sand-ai',
  state: 2,
  ...overrides
})

const baseDeps = (overrides: Partial<DependencyOrchestratorDeps> = {}): DependencyOrchestratorDeps => ({
  install: async () => null,
  fetchRows: async () => [],
  runDependencyTask: async () => true,
  sleep: async () => {},
  pollIntervalMs: 1,
  pollBudgetMs: 50,
  ...overrides
})

const depItem = (overrides: Partial<DependencyPlanItem> = {}): DependencyPlanItem => ({
  app: 'sand-iam',
  title: 'SandIAM',
  action: 'upgrade',
  market: { version: '0.8.6', sha256: 'a'.repeat(64) },
  ...overrides
})

// ========== 计划响应归一 ==========

test('计划归一：satisfied/bundled 剔除、install/upgrade/resume 保留、unavailable 归 blocked', () => {
  const items = dependencyPlanItemsOf({
    dependencies: [
      { name: 'a-ok', action: 'satisfied' },
      { name: 'b-packed', action: 'bundled' },
      { name: 'c-new', title: 'C 插件', action: 'install', market: { version: '1.0.0', sha256: 'c'.repeat(64) } },
      { name: 'd-up', action: 'upgrade', market: { version: '2.0.0', sha256: 'd'.repeat(64) } },
      { name: 'e-wait', action: 'resume' },
      { name: 'f-miss', action: 'unavailable', reason: '依赖插件 f-miss 需要 1.0.0 或更高版本，插件仓库暂无满足条件的版本' },
      { name: 'g-bad', action: 'mystery-action' }
    ]
  })
  assert.equal(items.length, 5)
  assert.deepEqual(
    items.map((item) => [item.app, item.action]),
    [
      ['c-new', 'install'],
      ['d-up', 'upgrade'],
      ['e-wait', 'resume'],
      ['f-miss', 'blocked'],
      ['g-bad', 'blocked']
    ]
  )
  assert.equal(items[0].title, 'C 插件')
  assert.deepEqual(items[0].market, { version: '1.0.0', sha256: 'c'.repeat(64) })
  assert.equal(items[2].market, null)
  assert.match(items[3].reason ?? '', /暂无满足条件的版本/)
  assert.equal(items[4].reason, '')
})

test('计划归一：market 形状不全按无候选处理；缺名/坏项跳过', () => {
  const items = dependencyPlanItemsOf({
    dependencies: [
      { name: 'half', action: 'upgrade', market: { version: '1.2.3' } },
      { action: 'install' },
      'garbage',
      { name: '', action: 'install' }
    ] as never[]
  })
  assert.equal(items.length, 1)
  assert.equal(items[0].app, 'half')
  assert.equal(items[0].market, null)
})

test('计划归一：空计划/缺失字段一律空队列', () => {
  assert.deepEqual(dependencyPlanItemsOf(null), [])
  assert.deepEqual(dependencyPlanItemsOf({}), [])
  assert.deepEqual(dependencyPlanItemsOf({ dependencies: [] }), [])
})

// ========== 依赖消解环 ==========

test('依赖链升级全流程：0.8.4 依赖先升 0.8.6（restart=false），再升目标（restart=true）', async () => {
  // 场景：sand-iam 0.8.4 已装、sand-ai 0.3.1→0.3.5 升级要求 iam≥0.8.6。
  const installs: Array<{ app: string; confirmation?: string; restart?: boolean }> = []
  const prepared: string[] = []
  const snapshots: DependencyChainRow[][] = [
    [row({ app: 'sand-iam', state: 1, version: '0.8.6' })], // dep 装完
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 目标轮询：composer 待装
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 链第 1 步
    [row({ state: 4, npm_dependent_wait_install: 1 })], // 链第 2 步
    [row({ state: 1, version: '0.3.5' })]
  ]
  let fetches = 0
  const tasks: Array<[DependencyKind, string]> = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async (app) => (app === 'sand-ai' ? [depItem()] : []),
      prepareDep: async (item) => {
        prepared.push(item.app)
        return row({ app: item.app, state: 2, update: 1, upgrade_from_version: '0.8.4', version: '0.8.6' })
      },
      install: async (app, confirmation, restart) => {
        installs.push({ app, confirmation, restart })
        return null // 结果未知 → 快照轮询裁决
      },
      fetchRows: async () => snapshots[Math.min(fetches++, snapshots.length - 1)],
      runDependencyTask: async (kind, app) => {
        tasks.push([kind, app])
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI', confirmation: 'UPGRADE sand-ai@0.3.1->0.3.5' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(prepared, ['sand-iam'])
  assert.deepEqual(installs, [
    { app: 'sand-iam', confirmation: 'UPGRADE sand-iam@0.8.4->0.8.6', restart: false },
    { app: 'sand-ai', confirmation: 'UPGRADE sand-ai@0.3.1->0.3.5', restart: true }
  ])
  assert.deepEqual(tasks, [
    ['composer', 'sand-ai'],
    ['npm', 'sand-ai']
  ])
})

test('依赖按声明序执行；重复项与指向目标的环项被跳过', async () => {
  const installs: string[] = []
  const plan: DependencyPlanItem[] = [
    depItem({ app: 'dep-a', title: 'A', action: 'install', market: { version: '1.0.0', sha256: 'a'.repeat(64) } }),
    depItem({ app: 'dep-b', title: 'B', action: 'install', market: { version: '2.0.0', sha256: 'b'.repeat(64) } }),
    depItem({ app: 'dep-a', title: 'A', action: 'install', market: null }), // 重复项去重
    depItem({ app: 'sand-ai', title: 'SandAI', action: 'install', market: null }) // 环项跳过
  ]
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async (app) => (app === 'sand-ai' ? plan : []),
      prepareDep: async (item) => row({ app: item.app, state: 2, version: item.market?.version ?? '1.0.0' }),
      install: async (app) => {
        installs.push(app)
        return row({ app, state: 1, version: '1.0.0' })
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(installs, ['dep-a', 'dep-b', 'sand-ai'])
})

test('blocked 计划项 → chain-failed（plan 标记），不发安装请求', async () => {
  let installCalls = 0
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async () => [
        depItem({
          action: 'blocked',
          market: null,
          reason: '依赖插件 sand-iam 需要 0.8.6 或更高版本，插件仓库暂无满足条件的版本'
        })
      ],
      install: async () => {
        installCalls += 1
        return null
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'chain-failed')
  assert.equal(installCalls, 0)
  if (outcome.kind !== 'chain-failed') return
  assert.equal(outcome.failure.plan, true)
  assert.equal(outcome.failure.label, '准备依赖 SandIAM')
  assert.match(outcome.failure.reason, /暂无满足条件的版本/)
})

test('dep 安装业务拒绝 → chain-failed（plan 标记），不重发目标安装', async () => {
  const installs: string[] = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async () => [depItem()],
      prepareDep: async () => row({ app: 'sand-iam', state: 2, update: 1, upgrade_from_version: '0.8.4', version: '0.8.6' }),
      install: async (app) => {
        installs.push(app)
        if (app === 'sand-iam') throw new Error('插件依赖 sand-iam 不兼容（最低版本 0.8.6）')
        return null
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'chain-failed')
  assert.deepEqual(installs, ['sand-iam']) // 目标安装从未发起
  if (outcome.kind !== 'chain-failed') return
  assert.equal(outcome.failure.plan, true)
  assert.equal(outcome.failure.label, '升级依赖 SandIAM')
  assert.match(outcome.failure.reason, /不兼容/)
})

test('dep 登记失败（state=8）→ chain-failed 指路恢复处理', async () => {
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async () => [depItem()],
      prepareDep: async () => row({ app: 'sand-iam', state: 2, update: 1, upgrade_from_version: '0.8.4', version: '0.8.6' }),
      install: async () => null,
      fetchRows: async () => [row({ app: 'sand-iam', state: 8, last_error: '数据库安装失败' })]
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'chain-failed')
  if (outcome.kind !== 'chain-failed') return
  assert.equal(outcome.failure.app, 'sand-ai')
  assert.equal(outcome.failure.label, '升级依赖 SandIAM')
  assert.match(outcome.failure.reason, /请到插件管理处理恢复/)
})

test('dep 的依赖任务失败（dep 自身依赖链断）→ 透传通俗原因，一键重试走重编排', async () => {
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async () => [depItem({ action: 'install' })],
      prepareDep: async () => row({ app: 'sand-iam', state: 2, version: '0.8.6' }),
      install: async () => null,
      fetchRows: async () => [row({ app: 'sand-iam', state: 4, composer_dependent_wait_install: 1 })],
      runDependencyTask: async () => false,
      failureReason: () => '【原因】无法连接 Composer 依赖仓库或镜像，请检查网络后重试'
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'chain-failed')
  if (outcome.kind !== 'chain-failed') return
  assert.equal(outcome.failure.app, 'sand-iam') // 失败定位到 dep 自身
  assert.equal(outcome.failure.label, '后端依赖（composer）')
  assert.equal(outcome.failure.plan, true)
  assert.match(outcome.failure.reason, /Composer 依赖仓库/)
})

test('dep 已暂存升级候选（upgrade + market=null）：按候选行生成 UPGRADE 确认串', async () => {
  const installs: Array<{ app: string; confirmation?: string; restart?: boolean }> = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async (app) =>
        app === 'sand-ai' ? [depItem({ action: 'upgrade', market: null })] : [],
      prepareDep: async (item) =>
        row({ app: item.app, state: 2, update: 1, upgrade_from_version: '0.8.4', version: '0.8.6' }),
      install: async (app, confirmation, restart) => {
        installs.push({ app, confirmation, restart })
        return row({ app, state: 1 })
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(installs, [
    { app: 'sand-iam', confirmation: 'UPGRADE sand-iam@0.8.4->0.8.6', restart: false },
    { app: 'sand-ai', confirmation: undefined, restart: true }
  ])
})

test('resume 计划项：直接续跑 dep 的依赖链，不下载不重发安装', async () => {
  const installs: string[] = []
  const prepared: string[] = []
  const tasks: Array<[DependencyKind, string]> = []
  const snapshots: DependencyChainRow[][] = [
    [row({ app: 'sand-iam', state: 4, composer_dependent_wait_install: 1 })], // 链第 1 步：composer 待装
    [row({ app: 'sand-iam', state: 1, version: '0.8.6' })], // dep 装完
    [row({ state: 1, version: '0.3.5' })] // 目标装完
  ]
  let fetches = 0
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async () => [depItem({ action: 'resume', market: null })],
      prepareDep: async (item) => {
        prepared.push(item.app)
        return row({ app: item.app })
      },
      install: async (app) => {
        installs.push(app)
        return null
      },
      fetchRows: async () => snapshots[Math.min(fetches++, snapshots.length - 1)],
      runDependencyTask: async (kind, app) => {
        tasks.push([kind, app])
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(prepared, []) // resume 不准备候选
  assert.deepEqual(tasks, [['composer', 'sand-iam']])
  assert.deepEqual(installs, ['sand-ai']) // 只有目标安装
})

test('依赖环：dep 又依赖目标 → 环项跳过不无限递归', async () => {
  const installs: string[] = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async (app) =>
        app === 'sand-ai'
          ? [depItem({ action: 'install', market: { version: '0.8.6', sha256: 'a'.repeat(64) } })]
          : [depItem({ app: 'sand-ai', title: 'SandAI', action: 'install', market: null })],
      prepareDep: async (item) => row({ app: item.app, state: 2, version: '0.8.6' }),
      install: async (app) => {
        installs.push(app)
        return row({ app, state: 1 })
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(installs, ['sand-iam', 'sand-ai'])
})

test('深度上限：嵌套计划超过上限不再消解，交由服务端断言兜底', async () => {
  let depth = 0
  const installs: string[] = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async () => {
        depth += 1
        return [
          depItem({
            app: `dep-${depth}`,
            title: `Dep${depth}`,
            action: 'install',
            market: { version: '1.0.0', sha256: 'a'.repeat(64) }
          })
        ]
      },
      prepareDep: async (item) => row({ app: item.app, state: 2, version: '1.0.0' }),
      install: async (app) => {
        installs.push(app)
        return row({ app, state: 1 })
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.equal(depth, DEPENDENCY_RESOLUTION_DEPTH_LIMIT) // 达到上限即停
  assert.ok(installs.includes('sand-ai'))
})

test('计划读取失败 → 降级为单插件编排（服务端断言兜底）', async () => {
  const installs: string[] = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async () => {
        throw new Error('依赖检查失败')
      },
      install: async (app) => {
        installs.push(app)
        return row({ app, state: 1, version: '0.3.5' })
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(installs, ['sand-ai'])
})

test('队列进度回调：计划生成与每项开始前通知（session 进度提示）', async () => {
  const notifications: Array<[string[], number]> = []
  const outcome = await runInstallOrchestration(
    baseDeps({
      resolveDeps: async (app) =>
        app === 'sand-ai'
          ? [
              depItem({ app: 'dep-a', title: 'A', action: 'install', market: { version: '1.0.0', sha256: 'a'.repeat(64) } }),
              depItem({ app: 'dep-b', title: 'B', action: 'install', market: { version: '2.0.0', sha256: 'b'.repeat(64) } })
            ]
          : [],
      prepareDep: async (item) => row({ app: item.app, state: 2, version: item.market?.version }),
      install: async (app) => row({ app, state: 1 }),
      onQueue: (queue, index) => notifications.push([queue.map((item) => item.app), index])
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(notifications, [
    [['dep-a', 'dep-b'], 0],
    [['dep-a', 'dep-b'], 1]
  ])
})
