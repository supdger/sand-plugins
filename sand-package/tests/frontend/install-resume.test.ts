/**
 * 一键安装依赖链「会话级断点续跑」的单元测试（node:test + Node 原生 type stripping）：
 *
 *   node --test tests/frontend/install-resume.test.ts
 *
 * 覆盖整页 reload 语义：sessionStorage 预置断点记录 + 重建编排依赖（等价新建
 * store 实例）→ resumeInstallOrchestration 按服务端行快照裁决断点续跑；
 * 在途依赖任务匹配（attach 而非重复入队的判定谓词）、断点记录读写/清理、
 * 无记录不触发、终态收敛清理；不挂载 Vue、不请求网络。
 */
import test from 'node:test'
import assert from 'node:assert/strict'
import {
  ORCHESTRATION_SESSION_STORAGE_KEY,
  dependencyTaskExtend,
  dependencyTaskMatches,
  readOrchestrationSessions,
  removeOrchestrationSession,
  resumeInstallOrchestration,
  runInstallOrchestration,
  upsertOrchestrationSession,
  type DependencyChainRow,
  type DependencyKind,
  type DependencyOrchestratorDeps,
  type InstallOrchestrationPhase,
  type InstallOrchestrationSession,
  type OrchestrationSessionStorage
} from '../../sandadmin-artd/src/views/plugin/sandpackage/install/dependency-chain.ts'

const row = (overrides: Partial<DependencyChainRow> = {}): DependencyChainRow => ({
  app: 'sand-ai',
  state: 2,
  ...overrides
})

const baseDeps = (overrides: Partial<DependencyOrchestratorDeps> = {}): DependencyOrchestratorDeps => ({
  fetchRows: async () => [],
  runDependencyTask: async () => true,
  sleep: async () => {},
  pollIntervalMs: 1,
  pollBudgetMs: 50,
  ...overrides
})

/** 测试替身：sessionStorage 最小形状。 */
const memoryStorage = (): OrchestrationSessionStorage & { map: Map<string, string> } => {
  const map = new Map<string, string>()
  return {
    map,
    getItem: (key) => (map.has(key) ? (map.get(key) as string) : null),
    setItem: (key, value) => {
      map.set(key, value)
    },
    removeItem: (key) => {
      map.delete(key)
    }
  }
}

const session = (
  overrides: Partial<InstallOrchestrationSession> = {}
): InstallOrchestrationSession => ({
  app: 'sand-ai',
  title: 'SandAI',
  phase: 'install',
  startedAt: 1,
  ...overrides
})

// ========== 断点记录读写 ==========

test('断点记录：upsert/read/remove 往返，移除最后一条后键被清除', () => {
  const storage = memoryStorage()
  upsertOrchestrationSession(storage, session({ startedAt: 100 }))
  upsertOrchestrationSession(storage, session({ app: 'sand-iam', title: 'SandIAM', phase: 'chain' }))
  let sessions = readOrchestrationSessions(storage)
  assert.equal(sessions.length, 2)
  assert.equal(sessions[0].app, 'sand-ai')
  assert.equal(sessions[1].phase, 'chain')

  // 同 app upsert 覆盖而非追加（重排到末尾）
  upsertOrchestrationSession(storage, session({ phase: 'chain', startedAt: 200 }))
  sessions = readOrchestrationSessions(storage)
  assert.equal(sessions.length, 2)
  assert.equal(sessions[1].app, 'sand-ai')
  assert.equal(sessions[1].phase, 'chain')
  assert.equal(sessions[1].startedAt, 200)

  removeOrchestrationSession(storage, 'sand-ai')
  sessions = readOrchestrationSessions(storage)
  assert.equal(sessions.length, 1)
  assert.equal(sessions[0].app, 'sand-iam')

  removeOrchestrationSession(storage, 'sand-iam')
  assert.equal(readOrchestrationSessions(storage).length, 0)
  assert.equal(storage.map.has(ORCHESTRATION_SESSION_STORAGE_KEY), false)
})

test('断点记录：JSON 损坏/非数组/坏条目一律按空或过滤，不抛错', () => {
  const storage = memoryStorage()
  storage.map.set(ORCHESTRATION_SESSION_STORAGE_KEY, '{not json')
  assert.deepEqual(readOrchestrationSessions(storage), [])

  storage.map.set(ORCHESTRATION_SESSION_STORAGE_KEY, '{"app":"sand-ai"}')
  assert.deepEqual(readOrchestrationSessions(storage), [])

  storage.map.set(
    ORCHESTRATION_SESSION_STORAGE_KEY,
    JSON.stringify([
      { app: 'sand-ai', title: 'SandAI', phase: 'chain', startedAt: 5 },
      { app: '' }, // 坏条目：app 空
      'garbage',
      { app: 'sand-iam', phase: 'bogus-phase' } // phase 非法 → 归一 install、title 兜底 app
    ])
  )
  const sessions = readOrchestrationSessions(storage)
  assert.equal(sessions.length, 2)
  assert.equal(sessions[0].phase, 'chain')
  assert.equal(sessions[1].phase, 'install')
  assert.equal(sessions[1].title, 'sand-iam')
})

// ========== reload 续跑：按服务端行快照裁决 ==========

test('reload 于安装进行中：state=4 无等待标志 → 轮询至依赖标志 → 自动入队 composer→npm', async () => {
  const snapshots: DependencyChainRow[][] = [
    [row({ state: 4 })], // reload 后首次读：安装收尾中
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 轮询裁决：依赖等待
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 链第 1 步：composer 待装
    [row({ state: 4, npm_dependent_wait_install: 1 })], // 链第 2 步：npm 待装
    [row({ state: 1, version: '0.3.1' })] // 链第 3 步：依赖清完已安装
  ]
  let fetches = 0
  const tasks: DependencyKind[] = []
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => snapshots[Math.min(fetches++, snapshots.length - 1)],
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(tasks, ['composer', 'npm'])
})

test('reload 于依赖链执行中：断连已回收依赖进程、等待标志保留 → 重入队并续跑至收敛', async () => {
  // 服务端在客户端断连时终止依赖进程，*_dependent_wait_install 保留——
  // 快照仍显示 composer 标志即重入队，不当作「已在跑」而等待。
  const snapshots: DependencyChainRow[][] = [
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 轮询裁决：依赖等待
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 链第 1 步：composer 待装
    [row({ state: 4, npm_dependent_wait_install: 1 })], // 链第 2 步：npm 待装
    [row({ state: 1, version: '0.3.1' })] // 链第 3 步：依赖清完已安装
  ]
  let fetches = 0
  const tasks: DependencyKind[] = []
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => snapshots[Math.min(fetches++, snapshots.length - 1)],
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(tasks, ['composer', 'npm'])
})

test('reload 落回时安装事务仍在锁内（operation_pending=1）：判 pending 继续轮询，解锁后自动入队收敛', async () => {
  // WP-PRE2 真实现场：deployFreshOrUpgrade 在锁内先登记 state=4+等待标志
  // 再写载荷，载荷写入触发整页 reload；续跑读到 operation_pending=1 时
  // 立即入队必撞安装操作锁（dependency_begin「已有安装操作正在执行」）。
  // 正确行为：按在途继续轮询，事务提交解锁后才放行入队。
  const snapshots: DependencyChainRow[][] = [
    [row({ state: 4, composer_dependent_wait_install: 1, operation_pending: 1 })], // 落回首次读：事务在途
    [row({ state: 4, composer_dependent_wait_install: 1, operation_pending: 1 })], // 仍在途：继续轮询
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 事务提交解锁：等待标志可入队
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 链第 1 步：composer 待装
    [row({ state: 1, version: '0.3.1' })]
  ]
  let fetches = 0
  const tasks: DependencyKind[] = []
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => snapshots[Math.min(fetches++, snapshots.length - 1)],
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(tasks, ['composer'])
  assert.ok(fetches >= 4, `在途期应轮询等待而非直接入队（实际读 ${fetches} 次）`)
})

test('续跑撞上解锁残余窗口：dependency_begin 锁冲突按瞬态在预算内重试入队', async () => {
  // 行快照 operation_pending 已清除但操作锁尚未释放的残余窗口（或其他
  // 插件的在途事务持有宿主锁）：服务端把锁冲突映射为【锁冲突】记号，
  // 编排器在预算内重试入队同一环节而非判 chain-failed。
  const snapshots: DependencyChainRow[][] = [
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 轮询裁决：依赖等待
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 链第 1 步：composer 待装
    [row({ state: 4, composer_dependent_wait_install: 1 })], // 锁冲突后重试前读
    [row({ state: 1, version: '0.3.1' })]
  ]
  let fetches = 0
  let taskCalls = 0
  let conflicts = 0
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => snapshots[Math.min(fetches++, snapshots.length - 1)],
      runDependencyTask: async () => {
        taskCalls += 1
        return taskCalls > 1 // 第一次入队撞锁被拒，重试成功
      },
      lockConflict: () => {
        conflicts += 1
        return conflicts <= 1 // 仅第一次失败归类为锁冲突
      },
      lockWaitBudgetMs: 50
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.equal(taskCalls, 2)
  assert.equal(conflicts, 1)
})

test('续跑轮询中登记转失败（state=8 + operation_pending=1）→ install-failed，不入队', async () => {
  // 安装失败现场保留 operation_pending=1：失败终态优先于在途判定，
  // 不把失败吞成「继续等待」或入队依赖任务。
  const tasks: DependencyKind[] = []
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => [
        row({ state: 8, operation_pending: 1, last_error: '数据库安装失败' })
      ],
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'install-failed')
  assert.equal(outcome.kind === 'install-failed' ? outcome.reason : '', '数据库安装失败')
  assert.deepEqual(tasks, [])
})

test('reload 于已安装：state=1 → installed，不入队不重发安装', async () => {
  const tasks: DependencyKind[] = []
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => [row({ state: 1, version: '0.3.1' })],
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      },
      // resume 不接受 install 依赖；提供即违反契约，传入以断言不被调用。
      install: async () => {
        throw new Error('resume must not issue install requests')
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'installed')
  assert.deepEqual(tasks, [])
})

test('sessionStorage 有记录但服务端从未登记（行缺失）→ unconfirmed 终态收敛，不入队', async () => {
  const tasks: DependencyKind[] = []
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => [row({ app: 'other-plugin', state: 1 })],
      runDependencyTask: async (kind) => {
        tasks.push(kind)
        return true
      }
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'unconfirmed')
  assert.deepEqual(tasks, [])
})

test('reload 于安装失败：state=8 → install-failed 携带行内原因，不入队', async () => {
  const outcome = await resumeInstallOrchestration(
    baseDeps({ fetchRows: async () => [row({ state: 8, last_error: '数据库安装失败' })] }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'install-failed')
  assert.equal(outcome.kind === 'install-failed' ? outcome.reason : '', '数据库安装失败')
})

test('续跑中依赖任务失败 → chain-failed（面板弹出语义与首跑一致）', async () => {
  const outcome = await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => [row({ state: 4, composer_dependent_wait_install: 1 })],
      runDependencyTask: async () => false,
      failureReason: () => '【原因】无法连接 Composer 依赖仓库或镜像，请检查网络后重试'
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.equal(outcome.kind, 'chain-failed')
  if (outcome.kind !== 'chain-failed') return
  assert.equal(outcome.failure.label, '后端依赖（composer）')
  assert.match(outcome.failure.reason, /Composer 依赖仓库/)
})

// ========== 阶段回调（sessionStorage 断点字段写入依据） ==========

test('onPhase：首跑 install→链前 chain；续跑只发 chain', async () => {
  const firstPhases: InstallOrchestrationPhase[] = []
  const cancelled = Object.assign(new Error('aborted'), { transport: 'cancelled' })
  await runInstallOrchestration(
    baseDeps({
      install: async () => {
        throw cancelled
      },
      fetchRows: async () => [row({ state: 1, version: '0.3.1' })],
      onPhase: (phase) => firstPhases.push(phase)
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.deepEqual(firstPhases, ['install'])

  const resumePhases: InstallOrchestrationPhase[] = []
  await resumeInstallOrchestration(
    baseDeps({
      fetchRows: async () => [row({ state: 4, composer_dependent_wait_install: 1 })],
      onPhase: (phase) => resumePhases.push(phase)
    }),
    { app: 'sand-ai', title: 'SandAI' }
  )
  assert.deepEqual(resumePhases, ['chain'])
})

// ========== 幂等：在途依赖任务匹配谓词 ==========

test('在途依赖任务匹配：同 app 同环节才命中', () => {
  assert.equal(dependencyTaskExtend('sand-ai'), 'module-install:sand-ai')
  const extend = dependencyTaskExtend('sand-ai')
  assert.equal(dependencyTaskMatches({ command: 'composer.update', extend }, 'composer', 'sand-ai'), true)
  assert.equal(dependencyTaskMatches({ command: 'web-install.pnpm', extend }, 'npm', 'sand-ai'), true)
  assert.equal(dependencyTaskMatches({ command: 'web-install.npm', extend }, 'npm', 'sand-ai'), true)
  // 环节错配 / 其他插件 / 无关命令不命中
  assert.equal(dependencyTaskMatches({ command: 'web-install.pnpm', extend }, 'composer', 'sand-ai'), false)
  assert.equal(
    dependencyTaskMatches({ command: 'composer.update', extend: 'module-install:sand-iam' }, 'composer', 'sand-ai'),
    false
  )
  assert.equal(dependencyTaskMatches({ command: 'composer.install', extend }, 'composer', 'sand-ai'), false)
})
