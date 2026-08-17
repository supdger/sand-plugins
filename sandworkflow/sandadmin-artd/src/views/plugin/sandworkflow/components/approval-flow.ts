import type { FlowInstanceDetail, FlowLog, FlowTask, FlowTaskAssignee } from '../api'

export type ApprovalVisualState = 'waiting' | 'active' | 'done' | 'rejected' | 'skipped'

export interface ApprovalVisualPerson {
  id?: string
  name: string
  state: ApprovalVisualState
}

export interface ApprovalVisualItem {
  key: string
  title: string
  kind:
    | 'start'
    | 'approve'
    | 'transact'
    | 'comment'
    | 'transfer'
    | 'add-sign'
    | 'del-sign'
    | 'back'
    | 'copy'
    | 'end'
  state: ApprovalVisualState
  people: ApprovalVisualPerson[]
  time?: string
  comment?: string
}

/** FlowConstant::CMD.COMMENT */
const OPERATION_COMMENT = 14
/** FlowConstant::CMD.TRANSFER */
const OPERATION_TRANSFER = 16
/** FlowConstant::CMD.ADD_SIGN / DEL_SIGN */
const OPERATION_ADD_SIGN = 8
const OPERATION_DEL_SIGN = 9
/** FlowConstant::CMD.BACK */
const OPERATION_BACK = 7
/** FlowConstant::CMD.COPY */
const OPERATION_COPY = 12

export interface ApprovalFormSummaryItem {
  key: string
  label: string
  value: string
}

export interface InitiatorChoiceField {
  choiceKey: string
  nodeId: string
  nodeName: string
  nodeType: 'approve' | 'transact'
}

interface DefinitionNode {
  id?: string
  nodeId?: string
  name?: string
  type?: number
  signable?: number | boolean
  backable?: number | boolean
  assignees?: unknown[]
  transactors?: unknown[]
  childNode?: unknown
  conditionNodes?: unknown[]
}

interface DefinitionWidget {
  name: string
  label: string
  type: number
}

export function buildDefinitionPreview(value: unknown): ApprovalVisualItem[] {
  const source = isRecord(value) ? value : {}
  const root = toDefinitionNode(source.nodeConfig)
  if (!root) return defaultPreview()

  const result: ApprovalVisualItem[] = []
  const visited = new Set<string>()
  collectDefinitionNodes(root, result, visited)
  if (!result.some((item) => item.kind === 'start')) {
    result.unshift(previewStart())
  }
  if (!result.some((item) => item.kind === 'end')) {
    result.push(previewEnd())
  }
  return result
}

/** 从已发布定义收集发起人自选节点（按 choiceKey 去重保序） */
export function collectInitiatorChoiceFields(value: unknown): InitiatorChoiceField[] {
  const source = isRecord(value) ? value : {}
  const root = toDefinitionNode(source.nodeConfig)
  if (!root) return []
  const fields: InitiatorChoiceField[] = []
  const seen = new Set<string>()
  const visited = new Set<string>()
  walkInitiatorChoices(root, fields, seen, visited)
  return fields
}

/** 存在发起人自选规则但缺少合法 choiceKey 的节点名（需重新发布） */
export function collectMissingInitiatorChoiceKeys(value: unknown): string[] {
  const source = isRecord(value) ? value : {}
  const root = toDefinitionNode(source.nodeConfig)
  if (!root) return []
  const missing: string[] = []
  const visited = new Set<string>()
  walkMissingInitiatorChoiceKeys(root, missing, visited)
  return missing
}

export function buildInstanceTimeline(
  detail: FlowInstanceDetail,
  externalUserNames: ReadonlyMap<string, string> = new Map()
): ApprovalVisualItem[] {
  const userNames = extractDefinitionUserNames(detail.definition_snapshot)
  for (const [id, name] of externalUserNames) userNames.set(id, name)
  const startLog = detail.logs.find((log) => log.operation_type === 0)
  const result: ApprovalVisualItem[] = [
    {
      key: `start-${detail.id}`,
      title: '提交申请',
      kind: 'start',
      state: 'done',
      people: [
        {
          id: detail.account_id || optionalString(detail.created_by),
          name: userName(
            detail.account_id || optionalString(detail.created_by),
            userNames,
            '发起人'
          ),
          state: 'done'
        }
      ],
      time: startLog?.create_time || detail.create_time
    }
  ]

  const middle: ApprovalVisualItem[] = []
  const tasks = [...detail.tasks].sort((left, right) => {
    const timeComparison = (left.create_time || '').localeCompare(right.create_time || '')
    return timeComparison || left.id.localeCompare(right.id)
  })
  for (const task of tasks) middle.push(taskToVisualItem(task, userNames))
  for (const log of detail.logs) {
    if (log.operation_type === OPERATION_COMMENT) {
      middle.push(commentLogToVisualItem(log, userNames))
    } else if (log.operation_type === OPERATION_TRANSFER) {
      middle.push(transferLogToVisualItem(log, userNames))
    } else if (log.operation_type === OPERATION_ADD_SIGN) {
      middle.push(signLogToVisualItem(log, userNames, 'add-sign', '加签'))
    } else if (log.operation_type === OPERATION_DEL_SIGN) {
      middle.push(signLogToVisualItem(log, userNames, 'del-sign', '减签'))
    } else if (log.operation_type === OPERATION_BACK) {
      middle.push(backLogToVisualItem(log, userNames))
    } else if (log.operation_type === OPERATION_COPY) {
      middle.push(copyLogToVisualItem(log, userNames))
    }
  }

  if (detail.flow_status !== 0) {
    const terminal = terminalState(detail.flow_status)
    middle.push({
      key: `end-${detail.id}`,
      title: terminal.title,
      kind: 'end',
      state: terminal.state,
      people: [],
      time: detail.ended_time
    })
  }

  middle.sort((left, right) => {
    const timeComparison = (left.time || '').localeCompare(right.time || '')
    return timeComparison || left.key.localeCompare(right.key)
  })
  return result.concat(middle)
}

function commentLogToVisualItem(
  log: FlowLog,
  userNames: Map<string, string>
): ApprovalVisualItem {
  const actorId = log.account_id || optionalString(log.auditor_id)
  return {
    key: `comment-${log.id}`,
    title: '评论',
    kind: 'comment',
    state: 'done',
    people: [
      {
        id: actorId,
        name: userName(actorId, userNames, '参与人'),
        state: 'done'
      }
    ],
    time: log.create_time,
    comment: log.comment
  }
}

function transferLogToVisualItem(
  log: FlowLog,
  userNames: Map<string, string>
): ApprovalVisualItem {
  const actorId = log.account_id || optionalString(log.auditor_id)
  const snapshot = isRecord(log.assignee_snapshot) ? log.assignee_snapshot : {}
  const fromRef = isRecord(snapshot.from) ? snapshot.from : undefined
  const toRef = isRecord(snapshot.to) ? snapshot.to : undefined
  const fromId = optionalString(isRecord(fromRef) ? fromRef.account_id : undefined)
  const toId = optionalString(isRecord(toRef) ? toRef.account_id : undefined)
  const fromName =
    stringValue(
      isRecord(fromRef?.snapshot) ? fromRef.snapshot.name : undefined
    ) || userName(fromId, userNames, '原处理人')
  const toName =
    stringValue(isRecord(toRef?.snapshot) ? toRef.snapshot.name : undefined) ||
    userName(toId, userNames, '新处理人')
  return {
    key: `transfer-${log.id}`,
    title: '转办',
    kind: 'transfer',
    state: 'done',
    people: [
      { id: actorId, name: userName(actorId, userNames, '转办人'), state: 'done' },
      { id: fromId, name: fromName, state: 'skipped' },
      { id: toId, name: toName, state: 'active' }
    ],
    time: log.create_time,
    comment: log.comment || `${fromName} → ${toName}`
  }
}

function signLogToVisualItem(
  log: FlowLog,
  userNames: Map<string, string>,
  kind: 'add-sign' | 'del-sign',
  title: string
): ApprovalVisualItem {
  const actorId = log.account_id || optionalString(log.auditor_id)
  const snapshot = isRecord(log.assignee_snapshot) ? log.assignee_snapshot : {}
  const targetRef = isRecord(snapshot.target) ? snapshot.target : undefined
  const targetId = optionalString(targetRef?.account_id)
  const targetName =
    stringValue(isRecord(targetRef?.snapshot) ? targetRef.snapshot.name : undefined) ||
    userName(targetId, userNames, '目标处理人')
  return {
    key: `${kind}-${log.id}`,
    title,
    kind,
    state: 'done',
    people: [
      { id: actorId, name: userName(actorId, userNames, '操作人'), state: 'done' },
      {
        id: targetId,
        name: targetName,
        state: kind === 'add-sign' ? 'active' : 'skipped'
      }
    ],
    time: log.create_time,
    comment: log.comment || targetName
  }
}

function backLogToVisualItem(
  log: FlowLog,
  userNames: Map<string, string>
): ApprovalVisualItem {
  const actorId = log.account_id || optionalString(log.auditor_id)
  const snapshot = isRecord(log.assignee_snapshot) ? log.assignee_snapshot : {}
  const fromName = stringValue(isRecord(snapshot.from_node) ? snapshot.from_node.name : undefined) || '原节点'
  const toName = stringValue(isRecord(snapshot.to_node) ? snapshot.to_node.name : undefined) || '目标节点'
  return {
    key: `back-${log.id}`,
    title: '回退',
    kind: 'back',
    state: 'done',
    people: [{ id: actorId, name: userName(actorId, userNames, '操作人'), state: 'done' }],
    time: log.create_time,
    comment: log.comment || `${fromName} → ${toName}`
  }
}

function copyLogToVisualItem(
  log: FlowLog,
  userNames: Map<string, string>
): ApprovalVisualItem {
  const recipients = Array.isArray(log.assignee_snapshot) ? log.assignee_snapshot : []
  const people = recipients.map((recipient) => {
    const id = optionalString(recipient.account_id)
    const name =
      stringValue(recipient.snapshot?.name) || userName(id, userNames, '抄送人')
    return { id, name, state: 'done' as const }
  })
  return {
    key: `copy-${log.id}`,
    title: log.node_name || '抄送',
    kind: 'copy',
    state: 'done',
    people: people.length > 0 ? people : [{ name: '抄送人', state: 'done' }],
    time: log.create_time,
    comment: log.comment
  }
}

export function isCurrentTaskSignable(detail: FlowInstanceDetail, task: FlowTask): boolean {
  if (task.sign_type === 3) return false
  const node = findTaskDefinitionNode(detail, task)
  if (!node) return false
  return node.signable === true || node.signable === 1
}

export function isCurrentTaskBackable(detail: FlowInstanceDetail, task: FlowTask): boolean {
  const node = findTaskDefinitionNode(detail, task)
  if (!node) return false
  return node.backable === true || node.backable === 1
}

function findTaskDefinitionNode(
  detail: FlowInstanceDetail,
  task: FlowTask
): DefinitionNode | undefined {
  const snapshot = isRecord(detail.definition_snapshot) ? detail.definition_snapshot : {}
  const root = toDefinitionNode(snapshot.nodeConfig)
  if (!root) return undefined
  return findDefinitionNode(root, task.node_id || detail.act_node_id)
}

function findDefinitionNode(
  node: DefinitionNode,
  targetId: string | undefined,
  visited = new Set<string>()
): DefinitionNode | undefined {
  if (!targetId) return undefined
  const key = node.nodeId || node.id || `${node.type}-${visited.size}`
  if (visited.has(key)) return undefined
  visited.add(key)
  if (node.nodeId === targetId || node.id === targetId) return node
  if (Array.isArray(node.conditionNodes)) {
    for (const branch of node.conditionNodes) {
      const branchNode = toDefinitionNode(branch)
      if (!branchNode) continue
      const found = findDefinitionNode(branchNode, targetId, visited)
      if (found) return found
    }
  }
  const child = toDefinitionNode(node.childNode)
  return child ? findDefinitionNode(child, targetId, visited) : undefined
}

export function buildRuntimeProcessChart(detail: FlowInstanceDetail): ApprovalVisualItem[] {
  const items = buildDefinitionPreview(detail.definition_snapshot)
  const actNodeId = detail.act_node_id || ''
  const taskStateByNode = new Map<string, ApprovalVisualState>()
  for (const task of detail.tasks) {
    const nodeId = task.node_id
    if (!nodeId) continue
    taskStateByNode.set(nodeId, taskChartState(task))
  }
  const activeIndex = items.findIndex((item) => item.key === actNodeId)
  return items.map((item, index) => {
    const fromTask = taskStateByNode.get(item.key)
    if (fromTask) return { ...item, state: fromTask }
    if (actNodeId && item.key === actNodeId) {
      return {
        ...item,
        state: detail.flow_status === 0 ? 'active' : terminalChartState(detail.flow_status)
      }
    }
    if (activeIndex >= 0) {
      if (index < activeIndex) return { ...item, state: 'done' }
      if (index > activeIndex) {
        return { ...item, state: detail.flow_status === 0 ? 'waiting' : 'skipped' }
      }
    }
    if (detail.flow_status !== 0 && item.kind === 'end') {
      return { ...item, state: terminalChartState(detail.flow_status) }
    }
    return { ...item, state: detail.flow_status === 0 ? item.state : 'skipped' }
  })
}

function taskChartState(task: FlowTask): ApprovalVisualState {
  if (task.operation_type === 3) return 'rejected'
  if (task.task_status === 1) return 'done'
  if (task.task_status === 2) return 'skipped'
  return 'active'
}

function terminalChartState(flowStatus: number): ApprovalVisualState {
  if (flowStatus === 2) return 'rejected'
  if (flowStatus === 1) return 'done'
  return 'skipped'
}

export function buildFormSummary(detail: FlowInstanceDetail): ApprovalFormSummaryItem[] {
  const values = isRecord(detail.form_value) ? detail.form_value : {}
  const snapshot = isRecord(detail.definition_snapshot) ? detail.definition_snapshot : {}
  const widgets = Array.isArray(snapshot.flowWidgets)
    ? snapshot.flowWidgets.filter(isDefinitionWidget)
    : []

  if (widgets.length > 0) {
    return widgets.map((widget) => ({
      key: widget.name,
      label: widget.label,
      value: displayValue(values[widget.name], widget.type)
    }))
  }

  return Object.entries(values).map(([key, value]) => ({
    key,
    label: key,
    value: displayValue(value)
  }))
}

function collectDefinitionNodes(
  node: DefinitionNode,
  result: ApprovalVisualItem[],
  visited: Set<string>
): void {
  const key = node.nodeId || node.id || `${node.type}-${result.length}`
  if (visited.has(key)) return
  visited.add(key)

  if (node.type === 0) result.push(previewStart(key))
  if (node.type === 1 || node.type === 5) {
    const people = extractRulePeople(node.type === 1 ? node.assignees : node.transactors)
    result.push({
      key,
      title: node.name || (node.type === 1 ? '审批人' : '办理人'),
      kind: node.type === 1 ? 'approve' : 'transact',
      state: 'waiting',
      people: people.length > 0 ? people : [{ name: '待配置处理人', state: 'waiting' }]
    })
  }
  if (node.type === 2) {
    result.push({
      key,
      title: node.name || '抄送',
      kind: 'copy',
      state: 'waiting',
      people: [{ name: '抄送人', state: 'waiting' }]
    })
  }
  if (node.type === 9) result.push(previewEnd(key))

  if (Array.isArray(node.conditionNodes)) {
    for (const branch of node.conditionNodes) {
      const branchNode = toDefinitionNode(branch)
      if (branchNode) collectDefinitionNodes(branchNode, result, visited)
    }
  }
  const child = toDefinitionNode(node.childNode)
  if (child) collectDefinitionNodes(child, result, visited)
}

function walkInitiatorChoices(
  node: DefinitionNode,
  fields: InitiatorChoiceField[],
  seen: Set<string>,
  visited: Set<string>
): void {
  const visitKey = node.nodeId || node.id || `${node.type}-${visited.size}`
  if (visited.has(visitKey)) return
  visited.add(visitKey)

  const rules =
    node.type === 1 ? node.assignees : node.type === 5 ? node.transactors : undefined
  if (Array.isArray(rules)) {
    for (const rule of rules) {
      if (!isRecord(rule)) continue
      const type = numberValue(rule.assigneeType) ?? numberValue(rule.transactorType)
      if (type !== 7) continue
      const choiceKey = stringValue(rule.choiceKey)?.trim()
      if (!choiceKey || seen.has(choiceKey)) continue
      seen.add(choiceKey)
      fields.push({
        choiceKey,
        nodeId: stringValue(node.nodeId) || stringValue(node.id) || visitKey,
        nodeName: stringValue(node.name) || (node.type === 5 ? '办理人' : '审批人'),
        nodeType: node.type === 5 ? 'transact' : 'approve'
      })
    }
  }

  if (Array.isArray(node.conditionNodes)) {
    for (const branch of node.conditionNodes) {
      const branchNode = toDefinitionNode(branch)
      if (branchNode) walkInitiatorChoices(branchNode, fields, seen, visited)
    }
  }
  const child = toDefinitionNode(node.childNode)
  if (child) walkInitiatorChoices(child, fields, seen, visited)
}

function walkMissingInitiatorChoiceKeys(
  node: DefinitionNode,
  missing: string[],
  visited: Set<string>
): void {
  const visitKey = node.nodeId || node.id || `${node.type}-${visited.size}`
  if (visited.has(visitKey)) return
  visited.add(visitKey)

  const rules =
    node.type === 1 ? node.assignees : node.type === 5 ? node.transactors : undefined
  if (Array.isArray(rules)) {
    for (const rule of rules) {
      if (!isRecord(rule)) continue
      const type = numberValue(rule.assigneeType) ?? numberValue(rule.transactorType)
      if (type !== 7) continue
      const choiceKey = stringValue(rule.choiceKey)?.trim()
      if (choiceKey) continue
      missing.push(stringValue(node.name) || (node.type === 5 ? '办理人' : '审批人'))
    }
  }

  if (Array.isArray(node.conditionNodes)) {
    for (const branch of node.conditionNodes) {
      const branchNode = toDefinitionNode(branch)
      if (branchNode) walkMissingInitiatorChoiceKeys(branchNode, missing, visited)
    }
  }
  const child = toDefinitionNode(node.childNode)
  if (child) walkMissingInitiatorChoiceKeys(child, missing, visited)
}

function extractRulePeople(value: unknown): ApprovalVisualPerson[] {
  if (!Array.isArray(value)) return []
  const people: ApprovalVisualPerson[] = []
  for (const rule of value) {
    if (!isRecord(rule)) continue
    const directId = optionalString(rule.id)
    const directName = stringValue(rule.name)
    if (directId || directName) {
      people.push({
        id: directId,
        name: directName || `用户 ${directId}`,
        state: 'waiting'
      })
      continue
    }

    const nested = Array.isArray(rule.assignees)
      ? rule.assignees
      : Array.isArray(rule.transactors)
        ? rule.transactors
        : []
    for (const person of nested) {
      if (!isRecord(person)) continue
      const id = optionalString(person.id)
      const name = stringValue(person.name)
      if (id || name) {
        people.push({
          id,
          name: name || `用户 ${id}`,
          state: 'waiting'
        })
      }
    }
    if (nested.length === 0) {
      people.push({ name: ruleLabel(rule), state: 'waiting' })
    }
  }
  return dedupePeople(people)
}

function taskToVisualItem(task: FlowTask, userNames: Map<string, string>): ApprovalVisualItem {
  const people = (task.assignees || []).map((assignee) => ({
    id: assignee.account_id || optionalString(assignee.user_id),
    name: userName(assignee.account_id || optionalString(assignee.user_id), userNames, '审批人'),
    state: assigneeState(assignee)
  }))
  const comments = (task.assignees || [])
    .map((assignee) => assignee.comment?.trim())
    .filter((comment): comment is string => Boolean(comment))
  return {
    key: `task-${task.id}`,
    title: task.node_name || (task.node_type === 5 ? '办理' : '审批'),
    kind: task.node_type === 5 ? 'transact' : 'approve',
    state: taskState(task),
    people,
    time: latestTaskTime(task),
    comment: comments.join('；') || task.comment
  }
}

function taskState(task: FlowTask): ApprovalVisualState {
  if (task.operation_type === 3) return 'rejected'
  if (task.task_status === 1) return 'done'
  if (task.task_status === 2) return 'skipped'
  return 'active'
}

function assigneeState(assignee: FlowTaskAssignee): ApprovalVisualState {
  if (assignee.operation_type === 3) return 'rejected'
  if (assignee.action_status === 1) return 'done'
  if (assignee.action_status === 2) return 'skipped'
  return 'active'
}

function latestTaskTime(task: FlowTask): string | undefined {
  const endedTimes = (task.assignees || [])
    .map((assignee) => assignee.ended_time)
    .filter((time): time is string => Boolean(time))
  return endedTimes.at(-1) || task.ended_time || task.create_time
}

function extractDefinitionUserNames(value: unknown): Map<string, string> {
  const names = new Map<string, string>()
  const source = isRecord(value) ? value : {}
  const root = toDefinitionNode(source.nodeConfig)
  if (!root) return names
  const visited = new Set<string>()
  collectUserNames(root, names, visited)
  return names
}

function collectUserNames(
  node: DefinitionNode,
  names: Map<string, string>,
  visited: Set<string>
): void {
  const key = node.nodeId || node.id || `${node.type}-${visited.size}`
  if (visited.has(key)) return
  visited.add(key)
  for (const person of [
    ...extractRulePeople(node.assignees),
    ...extractRulePeople(node.transactors)
  ]) {
    if (person.id) names.set(person.id, person.name)
  }
  if (Array.isArray(node.conditionNodes)) {
    for (const branch of node.conditionNodes) {
      const branchNode = toDefinitionNode(branch)
      if (branchNode) collectUserNames(branchNode, names, visited)
    }
  }
  const child = toDefinitionNode(node.childNode)
  if (child) collectUserNames(child, names, visited)
}

function userName(id: string | undefined, names: Map<string, string>, fallback: string): string {
  if (!id) return fallback
  return names.get(id) || `用户 ${id}`
}

function terminalState(status: number): {
  title: string
  state: ApprovalVisualState
} {
  if (status === 1) return { title: '审批通过', state: 'done' }
  if (status === 2) return { title: '审批拒绝', state: 'rejected' }
  return { title: '流程已撤销', state: 'skipped' }
}

function previewStart(key = 'preview-start'): ApprovalVisualItem {
  return {
    key,
    title: '开始',
    kind: 'start',
    state: 'done',
    people: []
  }
}

function previewEnd(key = 'preview-end'): ApprovalVisualItem {
  return {
    key,
    title: '结束',
    kind: 'end',
    state: 'waiting',
    people: []
  }
}

function defaultPreview(): ApprovalVisualItem[] {
  return [previewStart(), previewEnd()]
}

function ruleLabel(rule: Record<string, unknown>): string {
  const type = numberValue(rule.assigneeType) ?? numberValue(rule.transactorType)
  if (type === 0) return '发起人本人'
  if (type === 1) return '发起人上级'
  if (type === 2) return '部门负责人'
  if (type === 3) return '指定角色'
  if (type === 5) return '连续多级上级'
  if (type === 6) return '连续多级部门负责人'
  if (type === 7) return '发起时选择'
  return '按规则确定'
}

function dedupePeople(people: ApprovalVisualPerson[]): ApprovalVisualPerson[] {
  const seen = new Set<string>()
  return people.filter((person) => {
    const key = person.id ? `id-${person.id}` : `name-${person.name}`
    if (seen.has(key)) return false
    seen.add(key)
    return true
  })
}

function displayValue(value: unknown, widgetType?: number): string {
  if (value === undefined || value === null || value === '') return '-'
  if (Array.isArray(value)) {
    const values = value.map((item) => String(item))
    return values.join(widgetType === 8 ? ' 至 ' : '、')
  }
  if (widgetType === 4 && (typeof value === 'number' || isNumeric(value))) {
    return Number(value).toLocaleString('zh-CN', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    })
  }
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

function isNumeric(value: unknown): value is string {
  return typeof value === 'string' && value.trim() !== '' && !Number.isNaN(Number(value))
}

function toDefinitionNode(value: unknown): DefinitionNode | undefined {
  if (!isRecord(value)) return undefined
  return {
    id: stringValue(value.id),
    nodeId: stringValue(value.nodeId),
    name: stringValue(value.name),
    type: numberValue(value.type),
    signable:
      typeof value.signable === 'boolean' || typeof value.signable === 'number'
        ? value.signable
        : undefined,
    backable:
      typeof value.backable === 'boolean' || typeof value.backable === 'number'
        ? value.backable
        : undefined,
    assignees: Array.isArray(value.assignees) ? value.assignees : undefined,
    transactors: Array.isArray(value.transactors) ? value.transactors : undefined,
    childNode: value.childNode,
    conditionNodes: Array.isArray(value.conditionNodes) ? value.conditionNodes : undefined
  }
}

function isDefinitionWidget(value: unknown): value is DefinitionWidget {
  return (
    isRecord(value) &&
    typeof value.name === 'string' &&
    typeof value.label === 'string' &&
    typeof value.type === 'number'
  )
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function stringValue(value: unknown): string | undefined {
  return typeof value === 'string' && value.trim() ? value : undefined
}

function optionalString(value: unknown): string | undefined {
  if (typeof value === 'string') return value.trim() || undefined
  if (typeof value === 'number' && Number.isFinite(value)) return String(value)
  return undefined
}

function numberValue(value: unknown): number | undefined {
  return typeof value === 'number' && Number.isFinite(value) ? value : undefined
}
