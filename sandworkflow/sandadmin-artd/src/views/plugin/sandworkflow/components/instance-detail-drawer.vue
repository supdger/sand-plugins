<template>
  <section class="instance-detail-view">
    <div class="detail-window-header no-print">
      <span>编号：{{ detail?.id || instanceId || '-' }}</span>
      <div class="detail-window-header__actions">
        <span>{{ detail?.definition?.name || '审批详情' }}</span>
        <ElButton v-if="detail" link type="primary" @click="printDetail">打印</ElButton>
      </div>
    </div>

    <div v-loading="loading" class="detail-window">
      <template v-if="detail">
        <div class="print-area">
        <section class="detail-hero">
          <div class="detail-heading">
            <div class="detail-title-row">
              <h2>{{ detail.name }}</h2>
              <ElTag :type="statusTag(detail.flow_status)" effect="light">
                {{ statusText(detail.flow_status) }}
              </ElTag>
            </div>
            <div class="submitter-line">
              <span class="submitter-avatar">{{ initials(initiatorName) }}</span>
              <strong>{{ initiatorName }}</strong>
              <span>{{ detail.create_time || '-' }} 提交</span>
            </div>
          </div>
          <FlowStatusStamp class="approval-stamp" :status="detail.flow_status" :size="92" />
        </section>

        <section class="business-summary">
          <div class="summary-title">表单详情</div>
          <p v-if="formAccessHint" class="form-access-hint">{{ formAccessHint }}</p>
          <FlowFormDetail :widgets="formWidgets" :form-value="formValues" />
        </section>

        <div class="content-divider" />

        <section class="chart-section">
          <div class="summary-title">流程图</div>
          <ProcessChart :items="chartItems" />
        </section>

        <div class="content-divider" />

        <section class="history-section">
          <div class="summary-title">审批记录</div>
          <ApprovalFlowTimeline
            v-if="timelineItems.length"
            :items="timelineItems"
            variant="detail"
            class="detail-timeline"
          />
          <ElEmpty v-else description="暂无审批记录" :image-size="72" />
        </section>
        </div>

          <div v-if="canComment" class="timeline-comment no-print">
            <ElInput
              v-model="discussionText"
              type="textarea"
              :rows="3"
              maxlength="1000"
              show-word-limit
              placeholder="添加评论（不会改变审批状态）"
              :disabled="submittingComment"
            />
            <div class="timeline-comment__actions">
              <ElButton
                type="primary"
                :loading="submittingComment"
                :disabled="!discussionText.trim() || submittingComment"
                @click="submitComment"
              >
                评论
              </ElButton>
            </div>
          </div>
      </template>
      <ElEmpty v-else-if="!loading" description="未找到流程实例" :image-size="80" />
    </div>

    <div v-if="canProcess || canCancel" class="approval-action-bar no-print">
      <template v-if="canProcess">
        <ElInput
          v-model="opinion"
          maxlength="500"
          show-word-limit
          placeholder="请输入审批意见（拒绝时必填）"
          class="approval-comment"
          @keyup.enter="handleAction('approve')"
        />
        <ElButton
          type="primary"
          :loading="processingAction === 'approve'"
          :disabled="Boolean(processingAction)"
          @click="handleAction('approve')"
        >
          ✓ 同意
        </ElButton>
        <ElButton
          type="danger"
          :loading="processingAction === 'reject'"
          :disabled="Boolean(processingAction)"
          @click="handleAction('reject')"
        >
          × 拒绝
        </ElButton>
        <ElDropdown trigger="click">
          <ElButton>更多</ElButton>
          <template #dropdown>
            <ElDropdownMenu>
              <ElDropdownItem v-if="canTransfer" @click="openTransferDialog">转办</ElDropdownItem>
              <ElDropdownItem v-else disabled>转交（仅当前待办处理人）</ElDropdownItem>
              <ElDropdownItem v-if="canBack" @click="openBackDialog">回退</ElDropdownItem>
              <ElDropdownItem v-else disabled>{{ backDisabledReason }}</ElDropdownItem>
              <ElDropdownItem v-if="canAddSign" @click="openSignDialog('add')">加签</ElDropdownItem>
              <ElDropdownItem v-else disabled>加签（节点未启用或不可操作）</ElDropdownItem>
              <ElDropdownItem v-if="canDelSign" @click="openSignDialog('del')">减签</ElDropdownItem>
              <ElDropdownItem v-else disabled>减签（无可移除处理人）</ElDropdownItem>
            </ElDropdownMenu>
          </template>
        </ElDropdown>
      </template>
      <ElButton v-else type="warning" plain @click="cancelInstance">撤销申请</ElButton>
    </div>

    <ElDialog
      v-model="transferVisible"
      title="转办"
      width="480px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <ElForm label-position="top">
        <ElFormItem label="转办给" required>
          <ElSelect
            v-model="transferAssigneeId"
            filterable
            clearable
            placeholder="选择处理人"
            class="full-width"
            :disabled="submittingTransfer"
          >
            <ElOption
              v-for="user in transferCandidates"
              :key="user.id"
              :label="userLabel(user)"
              :value="user.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="转办说明" required>
          <ElInput
            v-model="transferComment"
            type="textarea"
            :rows="3"
            maxlength="1000"
            show-word-limit
            placeholder="请填写转办说明"
            :disabled="submittingTransfer"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton :disabled="submittingTransfer" @click="transferVisible = false">取消</ElButton>
        <ElButton
          type="primary"
          :loading="submittingTransfer"
          :disabled="submittingTransfer"
          @click="submitTransfer"
        >
          确认转办
        </ElButton>
      </template>
    </ElDialog>

    <ElDialog
      v-model="signVisible"
      :title="signMode === 'add' ? '加签' : '减签'"
      width="480px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <ElForm label-position="top">
        <ElFormItem :label="signMode === 'add' ? '加签给' : '减签对象'" required>
          <ElSelect
            v-model="signAssigneeId"
            filterable
            clearable
            :placeholder="signMode === 'add' ? '选择加签处理人' : '选择要移除的待办处理人'"
            class="full-width"
            :disabled="submittingSign"
          >
            <ElOption
              v-for="option in signCandidates"
              :key="option.id"
              :label="option.label"
              :value="option.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="signMode === 'add' ? '加签说明' : '减签说明'" required>
          <ElInput
            v-model="signComment"
            type="textarea"
            :rows="3"
            maxlength="1000"
            show-word-limit
            placeholder="请填写说明"
            :disabled="submittingSign"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton :disabled="submittingSign" @click="signVisible = false">取消</ElButton>
        <ElButton
          type="primary"
          :loading="submittingSign"
          :disabled="submittingSign"
          @click="submitSign"
        >
          {{ signMode === 'add' ? '确认加签' : '确认减签' }}
        </ElButton>
      </template>
    </ElDialog>
    <ElDialog
      v-model="backVisible"
      title="回退"
      width="480px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <ElForm label-position="top">
        <ElFormItem label="回退到" required>
          <ElSelect
            v-model="backTargetNodeId"
            filterable
            clearable
            placeholder="选择历史节点"
            class="full-width"
            :disabled="submittingBack || loadingBackNodes"
          >
            <ElOption
              v-for="node in backNodes"
              :key="node.id"
              :label="backNodeLabel(node)"
              :value="node.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="回退说明" required>
          <ElInput
            v-model="backComment"
            type="textarea"
            :rows="3"
            maxlength="1000"
            show-word-limit
            placeholder="请填写回退说明"
            :disabled="submittingBack"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton :disabled="submittingBack" @click="backVisible = false">取消</ElButton>
        <ElButton
          type="primary"
          :loading="submittingBack"
          :disabled="submittingBack || loadingBackNodes || backNodes.length === 0"
          @click="submitBack"
        >
          确认回退
        </ElButton>
      </template>
    </ElDialog>
    <FlowPrintPreview
      v-model:visible="printPreviewVisible"
      :title="detail?.name || '审批详情'"
      :instance-id="detail?.id"
      :initiator-name="initiatorName"
      :create-time="detail?.create_time"
      :status="detail?.flow_status ?? 0"
      :widgets="formWidgets"
      :form-value="formValues"
      :timeline-items="timelineItems"
    />
  </section>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { computed, ref, watch } from 'vue'
  import { useUserStore } from '@/store/modules/user'
  import api, {
    type FlowBackNode,
    type FlowInstanceDetail,
    type FlowLog,
    type FlowUser,
    type QueryParams,
    type WorkflowRuntimeId
  } from '../api'
  import { SandWorkflowFeatures } from '../workflow/feature-flags'
  import {
    buildInstanceTimeline,
    buildRuntimeProcessChart,
    isCurrentTaskBackable,
    isCurrentTaskSignable
  } from './approval-flow'
  import ApprovalFlowTimeline from './approval-flow-timeline.vue'
  import FlowFormDetail from './flow-form-detail.vue'
  import FlowPrintPreview from './flow-print-preview.vue'
  import FlowStatusStamp from './flow-status-stamp.vue'
  import ProcessChart from './process-chart.vue'

  type ApprovalAction = 'approve' | 'reject'
  type SignMode = 'add' | 'del'

  const props = defineProps<{
    instanceId?: WorkflowRuntimeId
    taskId?: WorkflowRuntimeId
    cancelable?: boolean
  }>()
  const emit = defineEmits<{
    processed: []
  }>()

  const userStore = useUserStore()
  const detail = ref<FlowInstanceDetail>()
  const loading = ref(false)
  const opinion = ref('')
  const discussionText = ref('')
  const submittingComment = ref(false)
  const processingAction = ref<ApprovalAction>()
  const userNames = ref<ReadonlyMap<string, string>>(new Map())
  const allUsers = ref<FlowUser[]>([])
  const transferVisible = ref(false)
  const transferAssigneeId = ref<number>()
  const transferComment = ref('')
  const submittingTransfer = ref(false)
  const signVisible = ref(false)
  const signMode = ref<SignMode>('add')
  const signAssigneeId = ref<number>()
  const signComment = ref('')
  const submittingSign = ref(false)
  const backVisible = ref(false)
  const backNodes = ref<FlowBackNode[]>([])
  const backNodesLoaded = ref(false)
  const loadingBackNodes = ref(false)
  const backTargetNodeId = ref<string>()
  const backComment = ref('')
  const submittingBack = ref(false)
  const printPreviewVisible = ref(false)

  const formWidgets = computed(() => {
    const snapshot = detail.value?.definition_snapshot
    if (!snapshot || typeof snapshot !== 'object' || Array.isArray(snapshot)) return []
    const widgets = (snapshot as { flowWidgets?: unknown }).flowWidgets
    if (!Array.isArray(widgets)) return []
    return widgets
      .filter(
        (widget): widget is { name: string; label: string; type: number; details?: unknown } =>
          typeof widget === 'object' &&
          widget !== null &&
          typeof (widget as { name?: unknown }).name === 'string' &&
          typeof (widget as { label?: unknown }).label === 'string' &&
          typeof (widget as { type?: unknown }).type === 'number'
      )
      .map((widget) => ({
        name: widget.name,
        label: widget.label,
        type: widget.type,
        details: Array.isArray(widget.details)
          ? widget.details.filter(
              (
                child
              ): child is { name: string; label: string; type: number } =>
                typeof child === 'object' &&
                child !== null &&
                typeof (child as { name?: unknown }).name === 'string' &&
                typeof (child as { label?: unknown }).label === 'string' &&
                typeof (child as { type?: unknown }).type === 'number'
            )
          : undefined
      }))
  })
  const formValues = computed<Record<string, unknown>>(() => {
    const value = detail.value?.form_value
    return value && typeof value === 'object' && !Array.isArray(value)
      ? (value as Record<string, unknown>)
      : {}
  })
  const formAccessHint = computed(() => {
    const access = detail.value?.form_access
    if (!access) return ''
    if (access.legacy_fallback) return '历史流程按兼容模式展示（未配置节点表单权限时全部可读）'
    if (access.source === 'initiator') return '当前以发起人身份查看完整表单'
    if (access.source === 'assignee' || access.source === 'task') return '当前按任务节点表单权限展示可读字段'
    if (access.source === 'copy') return '当前按抄送节点表单权限展示可读字段'
    return ''
  })
  const timelineItems = computed(() =>
    detail.value ? buildInstanceTimeline(detail.value, userNames.value) : []
  )
  const chartItems = computed(() =>
    detail.value ? buildRuntimeProcessChart(detail.value) : []
  )
  const initiatorName = computed(() => timelineItems.value[0]?.people[0]?.name || '发起人')
  const canProcess = computed(() => Boolean(props.taskId && detail.value?.flow_status === 0))
  const canCancel = computed(() => Boolean(props.cancelable && detail.value?.flow_status === 0))
  const currentAccountId = computed(() => {
    const id = userStore.info.id
    return typeof id === 'number' && Number.isFinite(id) ? String(id) : ''
  })
  const currentTask = computed(() =>
    detail.value?.tasks.find((item) => item.id === props.taskId)
  )
  const isPendingAssignee = computed(() => {
    const accountId = currentAccountId.value
    const task = currentTask.value
    if (!accountId || !task || task.task_status !== 0) return false
    return (task.assignees || []).some(
      (assignee) =>
        (assignee.account_id === accountId || String(assignee.user_id ?? '') === accountId) &&
        assignee.action_status === 0
    )
  })
  const canComment = computed(() => {
    if (!SandWorkflowFeatures.comment || !detail.value || !currentAccountId.value) return false
    const accountId = currentAccountId.value
    const instance = detail.value
    const isInitiator =
      instance.account_id === accountId || String(instance.created_by ?? '') === accountId
    if (isInitiator) return true
    return instance.tasks.some((task) =>
      (task.assignees || []).some(
        (assignee) =>
          assignee.account_id === accountId || String(assignee.user_id ?? '') === accountId
      )
    )
  })
  const canTransfer = computed(
    () =>
      Boolean(SandWorkflowFeatures.assign && canProcess.value && isPendingAssignee.value)
  )
  const canSignBase = computed(() => {
    if (!SandWorkflowFeatures.addSign || !canProcess.value || !isPendingAssignee.value) return false
    if (!detail.value || !currentTask.value) return false
    return isCurrentTaskSignable(detail.value, currentTask.value)
  })
  const canAddSign = computed(() => canSignBase.value)
  const canDelSign = computed(() => {
    if (!canSignBase.value || !currentTask.value) return false
    const accountId = currentAccountId.value
    const pendingOthers = (currentTask.value.assignees || []).filter(
      (assignee) =>
        assignee.action_status === 0 &&
        (assignee.account_id || String(assignee.user_id ?? '')) !== accountId
    )
    return pendingOthers.length > 0
  })
  const canBackBase = computed(() => {
    if (!SandWorkflowFeatures.jump || !canProcess.value || !isPendingAssignee.value) return false
    if (!detail.value || !currentTask.value) return false
    return isCurrentTaskBackable(detail.value, currentTask.value)
  })
  const canBack = computed(
    () => canBackBase.value && backNodesLoaded.value && backNodes.value.length > 0
  )
  const backDisabledReason = computed(() => {
    if (!SandWorkflowFeatures.jump) return '回退（待流程能力接入）'
    if (!isPendingAssignee.value) return '回退（仅当前待办处理人）'
    if (!canBackBase.value) return '回退（节点未启用）'
    if (!backNodesLoaded.value) return '回退（加载历史节点中）'
    if (backNodes.value.length === 0) return '回退（无可回退历史节点）'
    return '回退'
  })
  const transferCandidates = computed(() => {
    const accountId = currentAccountId.value
    const task = currentTask.value
    const existing = new Set(
      (task?.assignees || []).map((assignee) => assignee.account_id || String(assignee.user_id ?? ''))
    )
    return allUsers.value.filter((user) => {
      const id = String(user.id)
      return id !== accountId && !existing.has(id)
    })
  })
  const signCandidates = computed(() => {
    const accountId = currentAccountId.value
    const task = currentTask.value
    if (!task) return [] as Array<{ id: number; label: string }>
    if (signMode.value === 'add') {
      const existing = new Set(
        (task.assignees || []).map(
          (assignee) => assignee.account_id || String(assignee.user_id ?? '')
        )
      )
      return allUsers.value
        .filter((user) => {
          const id = String(user.id)
          return id !== accountId && !existing.has(id)
        })
        .map((user) => ({ id: user.id, label: userLabel(user) }))
    }
    return (task.assignees || [])
      .filter(
        (assignee) =>
          assignee.action_status === 0 &&
          (assignee.account_id || String(assignee.user_id ?? '')) !== accountId
      )
      .map((assignee) => {
        const id = Number(assignee.account_id || assignee.user_id || 0)
        const name = userNames.value.get(String(id)) || `用户 ${id}`
        return { id, label: `${name}（${id}）` }
      })
      .filter((option) => Number.isFinite(option.id) && option.id > 0)
  })

  watch(
    () => props.instanceId,
    async (instanceId) => {
      if (!instanceId) {
        detail.value = undefined
        backNodes.value = []
        backNodesLoaded.value = false
        return
      }
      opinion.value = ''
      discussionText.value = ''
      detail.value = undefined
      backNodes.value = []
      backNodesLoaded.value = false
      loading.value = true
      try {
        const [instanceDetail, users] = await Promise.all([
          api.instance.detail(instanceId),
          api.organization.users()
        ])
        allUsers.value = users
        userNames.value = buildUserNames(users)
        detail.value = instanceDetail
      } catch {
        detail.value = undefined
      } finally {
        loading.value = false
      }
    },
    { immediate: true }
  )

  watch(
    [() => props.taskId, canBackBase, () => detail.value?.id],
    async () => {
      if (!canBackBase.value || !props.taskId) {
        backNodes.value = []
        backNodesLoaded.value = false
        return
      }
      loadingBackNodes.value = true
      try {
        const nodes = await api.instance.backNodeList({ task_id: props.taskId })
        backNodes.value = Array.isArray(nodes) ? nodes : []
        backNodesLoaded.value = true
      } catch {
        backNodes.value = []
        backNodesLoaded.value = true
      } finally {
        loadingBackNodes.value = false
      }
    },
    { immediate: true }
  )

  function openTransferDialog(): void {
    transferAssigneeId.value = undefined
    transferComment.value = ''
    transferVisible.value = true
  }

  function openSignDialog(mode: SignMode): void {
    signMode.value = mode
    signAssigneeId.value = undefined
    signComment.value = ''
    signVisible.value = true
  }

  function openBackDialog(): void {
    backTargetNodeId.value = undefined
    backComment.value = ''
    backVisible.value = true
  }

  function backNodeLabel(node: FlowBackNode): string {
    const time = node.completed_time ? ` · ${node.completed_time}` : ''
    return `${node.name}${time}`
  }

  async function submitBack(): Promise<void> {
    if (!props.taskId || submittingBack.value) return
    if (!backTargetNodeId.value) {
      ElMessage.warning('请选择回退节点')
      return
    }
    const text = backComment.value.trim()
    if (!text) {
      ElMessage.warning('请填写回退说明')
      return
    }
    submittingBack.value = true
    try {
      const refreshed = await api.instance.back({
        task_id: props.taskId,
        target_node_id: backTargetNodeId.value,
        comment: text,
        request_id: crypto.randomUUID()
      })
      ElMessage.success('已回退')
      backVisible.value = false
      detail.value = refreshed
      emit('processed')
    } catch {
      // 失败保留表单
    } finally {
      submittingBack.value = false
    }
  }

  async function submitTransfer(): Promise<void> {
    if (!props.taskId || submittingTransfer.value) return
    if (transferAssigneeId.value === undefined) {
      ElMessage.warning('请选择转办对象')
      return
    }
    const text = transferComment.value.trim()
    if (!text) {
      ElMessage.warning('请填写转办说明')
      return
    }
    submittingTransfer.value = true
    try {
      await api.instance.transfer({
        task_id: props.taskId,
        assignee_id: transferAssigneeId.value,
        comment: text,
        request_id: crypto.randomUUID()
      })
      ElMessage.success('已转办')
      transferVisible.value = false
      emit('processed')
      if (props.instanceId) {
        detail.value = await api.instance.detail(props.instanceId)
      }
    } catch {
      // 失败保留表单；错误由 http 层提示
    } finally {
      submittingTransfer.value = false
    }
  }

  async function submitSign(): Promise<void> {
    if (!props.taskId || submittingSign.value) return
    if (signAssigneeId.value === undefined) {
      ElMessage.warning(signMode.value === 'add' ? '请选择加签对象' : '请选择减签对象')
      return
    }
    const text = signComment.value.trim()
    if (!text) {
      ElMessage.warning('请填写说明')
      return
    }
    submittingSign.value = true
    try {
      const payload = {
        task_id: props.taskId,
        assignee_id: signAssigneeId.value,
        comment: text,
        request_id: crypto.randomUUID()
      }
      if (signMode.value === 'add') await api.instance.addSign(payload)
      else await api.instance.delSign(payload)
      ElMessage.success(signMode.value === 'add' ? '已加签' : '已减签')
      signVisible.value = false
      if (props.instanceId) {
        detail.value = await api.instance.detail(props.instanceId)
      }
    } catch {
      // 失败保留表单
    } finally {
      submittingSign.value = false
    }
  }

  function userLabel(user: FlowUser): string {
    const name = user.nickname?.trim() || user.username
    return `${name}（${user.id}）`
  }

  async function submitComment(): Promise<void> {
    if (!props.instanceId || !detail.value || submittingComment.value) return
    const text = discussionText.value.trim()
    if (!text) {
      ElMessage.warning('请填写评论内容')
      return
    }
    submittingComment.value = true
    try {
      const payload: {
        instance_id: WorkflowRuntimeId
        comment: string
        task_id?: WorkflowRuntimeId
      } = {
        instance_id: props.instanceId,
        comment: text
      }
      if (props.taskId) payload.task_id = props.taskId
      const log = await api.instance.comment(payload)
      if (!isFlowLog(log)) {
        ElMessage.error('评论响应异常')
        return
      }
      detail.value = {
        ...detail.value,
        logs: [...detail.value.logs, log]
      }
      discussionText.value = ''
      ElMessage.success('评论已添加')
    } catch {
      // 失败保留输入；错误提示由 http 层处理
    } finally {
      submittingComment.value = false
    }
  }

  function isFlowLog(value: unknown): value is FlowLog {
    if (typeof value !== 'object' || value === null) return false
    const id = Reflect.get(value, 'id')
    const operationType = Reflect.get(value, 'operation_type')
    return typeof id === 'string' && typeof operationType === 'number'
  }

  async function handleAction(action: ApprovalAction): Promise<void> {
    if (!props.taskId || processingAction.value) return
    if (action === 'reject' && !opinion.value.trim()) {
      ElMessage.warning('请填写拒绝原因')
      return
    }

    processingAction.value = action
    try {
      const payload: QueryParams = {
        task_id: props.taskId,
        comment: opinion.value.trim(),
        request_id: crypto.randomUUID()
      }
      if (action === 'approve') await api.instance.approve(payload)
      else await api.instance.reject(payload)
      ElMessage.success(action === 'approve' ? '审批已同意' : '审批已拒绝')
      emit('processed')
    } finally {
      processingAction.value = undefined
    }
  }

  async function cancelInstance(): Promise<void> {
    if (!props.instanceId) return
    await ElMessageBox.confirm('撤销后无法恢复，确定撤销该流程吗？', '撤销流程', {
      confirmButtonText: '撤销',
      cancelButtonText: '取消',
      type: 'warning'
    })
    await api.instance.cancel(props.instanceId)
    ElMessage.success('流程已撤销')
    emit('processed')
  }

  function printDetail(): void {
    printPreviewVisible.value = true
  }

  function statusText(status: number): string {
    return ['审批进行中', '审批已通过', '审批已拒绝', '流程已撤销'][status] || '未知状态'
  }

  function statusTag(status: number): 'primary' | 'success' | 'danger' | 'warning' | 'info' {
    if (status === 0) return 'primary'
    if (status === 1) return 'success'
    if (status === 2) return 'danger'
    if (status === 3) return 'warning'
    return 'info'
  }

  function initials(name: string): string {
    return name.trim().slice(0, 2) || '人'
  }

  function buildUserNames(users: FlowUser[]): ReadonlyMap<string, string> {
    return new Map(
      users.map((user) => [String(user.id), user.nickname?.trim() || user.username] as const)
    )
  }
</script>

<style scoped>
  .instance-detail-view {
    display: grid;
    grid-template-rows: 42px minmax(0, 1fr) auto;
    width: 100%;
    height: 100%;
    overflow: hidden;
    background: #fff;
  }

  .detail-window-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 42px;
    padding: 0 18px;
    color: var(--el-text-color-placeholder);
    font-size: 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .detail-window-header__actions {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .chart-section {
    width: min(720px, calc(100% - 56px));
    padding: 18px 28px 8px;
  }

  .detail-window {
    min-height: 0;
    overflow: auto;
    background: #fff;
  }

  .detail-hero {
    position: relative;
    min-height: 98px;
    padding: 20px 150px 14px 28px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }

  .detail-title-row {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .detail-title-row h2 {
    margin: 0;
    color: var(--el-text-color-primary);
    font-size: 21px;
    font-weight: 600;
  }

  .submitter-line {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 13px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  .submitter-line strong {
    color: var(--el-text-color-regular);
    font-weight: 500;
  }

  .submitter-avatar {
    display: grid;
    width: 28px;
    height: 28px;
    color: #fff;
    font-size: 10px;
    background: linear-gradient(135deg, #f59e0b, #fb7185);
    border-radius: 50%;
    place-items: center;
  }

  .approval-stamp {
    position: absolute;
    top: 8px;
    right: 36px;
    opacity: 0.92;
    transform: rotate(-12deg);
  }

  .business-summary {
    width: min(610px, calc(100% - 56px));
    padding: 18px 28px 22px;
  }

  .summary-title {
    margin-bottom: 15px;
    color: var(--el-text-color-primary);
    font-size: 15px;
    font-weight: 600;
  }

  .form-access-hint {
    margin: -6px 0 14px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    line-height: 1.5;
  }

  .summary-grid {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr);
    gap: 11px 16px;
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
  }

  .summary-grid dt {
    color: var(--el-text-color-placeholder);
    text-align: right;
  }

  .summary-grid dd {
    min-width: 0;
    margin: 0;
    color: var(--el-text-color-primary);
    overflow-wrap: anywhere;
  }

  .content-divider {
    height: 1px;
    margin: 0 28px;
    background: var(--el-border-color-lighter);
  }

  .history-section {
    width: min(720px, calc(100% - 56px));
    min-height: 260px;
    padding: 20px 28px 40px;
  }

  .detail-timeline {
    max-width: 100%;
  }

  .timeline-comment {
    display: grid;
    gap: 10px;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px dashed var(--el-border-color-lighter);
  }

  .timeline-comment__actions {
    display: flex;
    justify-content: flex-end;
  }

  .full-width {
    width: 100%;
  }

  .approval-action-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    min-height: 64px;
    padding: 10px 18px;
    background: #fff;
    border-top: 1px solid var(--el-border-color-lighter);
    box-shadow: 0 -8px 20px rgb(15 23 42 / 4%);
  }

  .approval-comment {
    width: min(440px, 45vw);
    margin-right: auto;
  }

  @media (max-width: 720px) {
    .detail-hero {
      padding-right: 28px;
    }

    .approval-stamp {
      position: relative;
      top: auto;
      right: auto;
      margin-top: 18px;
      transform: none;
    }

    .business-summary,
    .history-section {
      width: auto;
      padding-right: 18px;
      padding-left: 18px;
    }

    .summary-grid {
      grid-template-columns: 90px minmax(0, 1fr);
    }

    .approval-action-bar {
      flex-wrap: wrap;
    }

    .approval-comment {
      width: 100%;
    }
  }

  @media print {
    .no-print,
    .approval-action-bar,
    .timeline-comment,
    .el-overlay,
    .el-dialog__wrapper {
      display: none !important;
    }

    .instance-detail-view {
      display: block;
      height: auto;
      overflow: visible;
    }

    .detail-window {
      overflow: visible;
    }

    .print-area {
      padding: 0;
    }

    .approval-stamp {
      opacity: 1;
    }
  }
</style>
