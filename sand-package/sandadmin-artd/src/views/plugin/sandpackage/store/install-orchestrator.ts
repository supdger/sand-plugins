/**
 * 一键安装依赖链编排器 - sandpackage插件
 *
 * 应用级宿主（pinia store），不依附安装视图组件生命周期：安装请求、
 * 服务端状态轮询与依赖任务入队全部在这里持有，页面被 keep-alive 停用、
 * 重激活或动态路由重建都不影响链推进。安装视图只渲染这里暴露的状态
 * （busy/failure/rows），并在失败时按 panelTick 弹出终端面板。
 *
 * 读通道（fetchRows）刻意不传 AbortSignal、不检查视图 readsActive——
 * 0.2.18 的缺陷正是停用窗口内 getList 静默空转导致轮询失能。安装
 * 请求同样由本 store 发起，视图停用无法中止它。
 *
 * @module store/install-orchestrator
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import sandpackageApi from '../api/index'
import { TaskStatus, useTerminalStore } from './terminal'
import {
  runDependencyChainLoop,
  runInstallOrchestration,
  type DependencyChainRow,
  type DependencyKind,
  type DependencyOrchestratorDeps,
  type InstallChainFailure,
  type InstallOrchestrationInput,
  type InstallOrchestrationOutcome
} from '../install/dependency-chain'

export const useInstallOrchestratorStore = defineStore('sandpackageInstallOrchestrator', () => {
  const terminalStore = useTerminalStore()

  /** 最近一次服务端登记快照；编排器自有读通道的落点，视图可做交叉校验。 */
  const rows = ref<DependencyChainRow[]>([])
  /** 正在编排的插件标识；空串表示空闲。 */
  const runningApp = ref('')
  /** 最近一次依赖链失败；终端面板据此展示通俗原因与一键重试。 */
  const failure = ref<InstallChainFailure | null>(null)
  /**
   * 失败面板弹出请求计数：依赖链失败时自增。视图监听它打开终端面板；
   * 视图处于 keep-alive 停用期间 watcher 暂停，重激活后补开——配合
   * onActivated 中对 failure 的检查，保证失败面板不丢。
   */
  const panelTick = ref(0)

  const busy = computed(() => runningApp.value !== '')

  /**
   * 编排器自有读通道：直接请求服务端登记快照，不携带 AbortSignal，
   * 不经视图 localRead/cancelRepositoryReads，也不检查 readsActive。
   * 读失败保留上次快照——轮询预算与人工兜底不变，不中断链。
   */
  const fetchRows = async (): Promise<DependencyChainRow[]> => {
    try {
      const resp = await sandpackageApi.getAppList({ showErrorMessage: false })
      rows.value = resp?.data ?? []
    } catch {
      // 保留上次快照；连续不可读时由轮询预算收敛为 unconfirmed。
    }
    return rows.value
  }

  /**
   * 依赖任务入队并等待终态。终端任务队列与 fetch-SSE 都在
   * sandpackageTerminal store 内运行，不依附任何组件。
   */
  const runDependencyTask = (kind: DependencyKind, app: string): Promise<boolean> =>
    new Promise<boolean>((resolve) => {
      const extend = 'module-install:' + app
      const done = (status: TaskStatus): void => {
        resolve(status === TaskStatus.SUCCESS)
      }
      if (kind === 'composer') {
        terminalStore.addTask('composer.update', extend, done)
      } else {
        terminalStore.addNodeTask('web-install', extend, done)
      }
    })

  /** 依赖任务失败后从终端任务输出里归纳通俗原因（与原视图内实现一致）。 */
  const failureReason = (app: string, kind: DependencyKind): string => {
    const extend = 'module-install:' + app
    const prefix = kind === 'composer' ? 'composer.' : 'web-install.'
    const task = [...terminalStore.taskList]
      .reverse()
      .find(
        (item) =>
          item.extend === extend &&
          item.command.startsWith(prefix) &&
          item.status === TaskStatus.FAILED
      )
    const messages = (task?.message ?? [])
      .map((line) => line.trim())
      .filter((line) => line !== '')
    const hinted = messages.filter((line) => line.includes('【原因】') || line.includes('【详情】'))
    const last = hinted.length > 0 ? hinted[hinted.length - 1] : messages[messages.length - 1]
    return last && last !== '' ? last : '请查看终端输出中的具体错误'
  }

  const chainDeps = (
    install?: DependencyOrchestratorDeps['install']
  ): DependencyOrchestratorDeps => ({
    install,
    fetchRows,
    runDependencyTask,
    failureReason
  })

  /** 收敛收尾：依赖失败登记 failure 并请求弹出面板；其余 outcome 原样返回。 */
  const settle = (outcome: InstallOrchestrationOutcome): InstallOrchestrationOutcome => {
    if (outcome.kind === 'chain-failed') {
      failure.value = outcome.failure
      panelTick.value += 1
    }
    return outcome
  }

  /**
   * 「直接安装/直接升级/继续安装」确认后的编排入口。安装请求由本 action
   * 持有，视图停用无法中止；结果未知时按服务端快照轮询，依赖等待则
   * 自动入队依赖链。业务拒绝原样抛给调用方（http 层已提示）。
   */
  const start = async (input: InstallOrchestrationInput): Promise<InstallOrchestrationOutcome> => {
    if (runningApp.value !== '') {
      return {
        kind: 'unconfirmed',
        reason: '已有插件安装或依赖任务进行中，请稍后重试',
        row: null
      }
    }
    failure.value = null
    runningApp.value = input.app
    try {
      const outcome = await runInstallOrchestration(
        chainDeps((app, confirmation) => sandpackageApi.installApp({ appName: app, confirmation })),
        input
      )
      return settle(outcome)
    } finally {
      runningApp.value = ''
    }
  }

  /** 终端面板「一键重试」：仅续跑依赖链，不重发安装请求。 */
  const retryChain = async (): Promise<InstallOrchestrationOutcome | null> => {
    const current = failure.value
    if (!current || runningApp.value !== '') return null
    runningApp.value = current.app
    try {
      const outcome = await runDependencyChainLoop(
        chainDeps(),
        current.app,
        current.title,
        rows.value
      )
      const settled = settle(outcome)
      if (settled.kind === 'installed') failure.value = null
      return settled
    } finally {
      runningApp.value = ''
    }
  }

  return {
    rows,
    runningApp,
    busy,
    failure,
    panelTick,
    fetchRows,
    start,
    retryChain
  }
})

export default useInstallOrchestratorStore
