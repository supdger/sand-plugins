<script setup lang="ts">
  import { computed, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import request from '@/utils/http'
  import { describeSandIamError } from '../api/errors'
  import { createSandIamRequestId } from '../api/requestId'
  import { getSandIamAdmin, SAND_IAM_ADMIN_PREFIX } from '../api/write'
  import {
    logoutDeliveryRecoverable,
    logoutRecoveryError,
    parseLogoutDeliveryPage,
    parseLogoutRecovery,
    type LogoutDelivery
  } from '../api/logoutDeliveryContracts'

  const props = defineProps<{ clientId: number; clientName: string }>()
  const emit = defineEmits<{ close: [] }>()
  const { hasAuth } = useAuth()
  const canRead = computed(() => hasAuth('sand_iam:oauth_client:read'))
  const canRecover = computed(() => hasAuth('sand_iam:oauth_client:update'))
  const rows = ref<LogoutDelivery[]>([])
  const total = ref(0)
  const page = ref(1)
  const loading = ref(false)
  const recovering = ref(false)
  const errorMessage = ref('')
  const failedRequest = ref<{ deliveryId: number; requestId: string } | null>(null)
  let loadSequence = 0

  async function load(): Promise<void> {
    if (!canRead.value) return
    const sequence = ++loadSequence
    loading.value = true
    errorMessage.value = ''
    try {
      const result = parseLogoutDeliveryPage(
        await getSandIamAdmin('oauth-client/logout-delivery/index', {
          id: props.clientId,
          state: 'dead',
          page: page.value,
          limit: 20
        })
      )
      if (sequence !== loadSequence) return
      rows.value = result.data
      total.value = result.total
    } catch (error: unknown) {
      if (sequence === loadSequence) errorMessage.value = describeSandIamError(error).detail
    } finally {
      if (sequence === loadSequence) loading.value = false
    }
  }

  async function sendRecovery(deliveryId: number, requestId: string): Promise<void> {
    if (!canRecover.value || recovering.value) return
    recovering.value = true
    errorMessage.value = ''
    try {
      const result = parseLogoutRecovery(
        await request.post<unknown>({
          url: `${SAND_IAM_ADMIN_PREFIX}/oauth-client/logout-delivery/reissue`,
          data: { id: props.clientId, delivery_id: deliveryId },
          headers: { 'X-Request-Id': requestId },
          showErrorMessage: false
        })
      )
      failedRequest.value = null
      ElMessage.success(result.already_reissued ? '该失败投递已有恢复任务' : '已创建恢复任务')
      await load()
    } catch (error: unknown) {
      const described = describeSandIamError(error)
      const message = error instanceof Error ? error.message : ''
      errorMessage.value = logoutRecoveryError(
        `${described.code ?? ''} ${message} ${described.detail}`
      )
      failedRequest.value = { deliveryId, requestId }
    } finally {
      recovering.value = false
    }
  }

  async function recover(row: LogoutDelivery): Promise<void> {
    if (!canRecover.value || !logoutDeliveryRecoverable(row) || recovering.value) return
    try {
      await ElMessageBox.confirm(
        '将保留原失败记录，签发新的登出令牌并创建新投递任务。请先确认接收端已修复；这不是重发旧令牌。',
        '重新签发登出通知',
        {
          type: 'warning',
          confirmButtonText: '创建恢复任务',
          cancelButtonText: '取消'
        }
      )
    } catch {
      return
    }
    await sendRecovery(row.id, createSandIamRequestId())
  }

  function retryFailed(): void {
    const failed = failedRequest.value
    if (failed !== null) void sendRecovery(failed.deliveryId, failed.requestId)
  }

  watch(
    () => props.clientId,
    () => {
      rows.value = []
      total.value = 0
      page.value = 1
      failedRequest.value = null
      void load()
    },
    { immediate: true }
  )
</script>

<template>
  <ElDialog
    :model-value="true"
    :title="`${clientName} · 失败登出通知`"
    width="min(960px, 94vw)"
    :close-on-click-modal="!recovering"
    :close-on-press-escape="!recovering"
    :show-close="!recovering"
    @close="emit('close')"
  >
    <p> 查看达到重试上限的登出通知。修复接收端后，可为失败记录创建新的恢复任务。 </p>
    <ElAlert
      v-if="!canRead"
      type="warning"
      title="没有查看登出投递的权限，请联系管理员。"
      :closable="false"
    />
    <template v-else>
      <ElAlert
        v-if="errorMessage"
        type="error"
        :title="errorMessage"
        :closable="false"
        class="mb-4"
      />
      <div v-if="failedRequest" class="mb-4">
        <p>本次请求编号：{{ failedRequest.requestId }}</p>
        <ElButton :disabled="!canRecover || loading" :loading="recovering" @click="retryFailed">
          重试本次请求
        </ElButton>
      </div>
      <ElButton class="mb-4" :disabled="recovering" :loading="loading" @click="load"
        >刷新列表</ElButton
      >
      <ElTable
        v-loading="loading"
        :data="rows"
        row-key="id"
        border
        empty-text="当前没有失败登出通知"
      >
        <ElTableColumn prop="event_id" label="事件编号" min-width="200" />
        <ElTableColumn label="投递状态" min-width="120">
          <template #default="scope">{{
            scope.row.state === 'dead' ? '已停止重试' : scope.row.state
          }}</template>
        </ElTableColumn>
        <ElTableColumn prop="attempt_count" label="尝试次数" min-width="100" />
        <ElTableColumn prop="last_error_code" label="错误码" min-width="220" />
        <ElTableColumn prop="update_time" label="最后更新时间" min-width="180" />
        <ElTableColumn v-if="canRecover" label="操作" min-width="120" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="logoutDeliveryRecoverable(scope.row)"
              size="small"
              :disabled="loading || recovering"
              @click="recover(scope.row)"
              >重新签发</ElButton
            >
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination
        v-model:current-page="page"
        class="mt-4"
        layout="total, prev, pager, next"
        :total="total"
        :page-size="20"
        :disabled="loading || recovering"
        @current-change="load"
      />
    </template>
    <template #footer
      ><ElButton :disabled="recovering" @click="emit('close')">关闭</ElButton></template
    >
  </ElDialog>
</template>
