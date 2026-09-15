<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
  import { ElMessage } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import {
    parseSandIamWebhook,
    parseSandIamWebhookDeliveryPage,
    parseSandIamWebhookDelivery,
    webhookDeliveryRetryable,
    webhookDeliveryStatusLabel,
    webhookEventLabel,
    type SandIamWebhookDeliveryRow
  } from '../api/delegationContracts'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:webhook_delivery:index'))
  const canRead = computed(() => hasAuth('sand_iam:webhook_delivery:read'))
  const canRetry = computed(() => hasAuth('sand_iam:webhook_delivery:retry'))

  const canNames = computed(() => hasAuth('sand_iam:webhook:index'))
  const loading = ref(false)
  const retrying = ref(false)
  const detailLoading = ref(false)
  const applicationLoading = ref(false)
  const applicationError = ref('')
  const successHint = ref('')
  const status = ref('')
  let applicationRequest = 0
  let detailRequest = 0
  const currentPage = ref(1)
  const pageSize = ref(20)
  const total = ref(0)
  let requestId = 0
  let disposed = false
  const applications = ref<SandIamResourceRow[]>([])
  const deliveries = ref<SandIamWebhookDeliveryRow[]>([])
  const webhookNames = ref<Record<number, string>>({})
  const applicationId = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')
  const detailOpen = ref(false)
  const detail = ref<SandIamWebhookDeliveryRow | null>(null)
  const payloadOpen = ref(false)

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function webhookName(endpointId: number): string {
    return webhookNames.value[endpointId] ?? `通知 #${endpointId}`
  }

  function retryHint(row: SandIamWebhookDeliveryRow): string {
    const code = row.last_error_code ?? ''
    if (code.includes('HTTP') || code.includes('DELIVERY_FAILED')) {
      return '请检查接收服务是否返回 2xx，以及网络是否可达。'
    }
    if (code.includes('ENDPOINT') || code.includes('URL')) {
      return '请检查接收地址是否仍为可用的 HTTPS 地址。'
    }
    if (code.includes('SECRET') || code.includes('SIGN')) {
      return '请核对接收方是否使用当前密钥版本验签。'
    }
    return '请检查接收地址、签名密钥或接收服务后重试。'
  }

  async function loadApplications(keywords = ''): Promise<void> {
    const attempt = ++applicationRequest
    if (disposed || !canIndex.value) return
    applicationLoading.value = true
    applicationError.value = ''
    try {
      const result = await listSandIamResource('application', { page: 1, limit: 100, keywords })
      if (!disposed && canIndex.value && attempt === applicationRequest) applications.value = listRows(result)
    } catch (error: unknown) {
      if (!disposed && attempt === applicationRequest) applicationError.value = describeSandIamError(error).detail
    } finally {
      if (!disposed && attempt === applicationRequest) applicationLoading.value = false
    }
  }

  async function loadWebhookNames(application: number, attempt: number): Promise<void> {
    if (!canNames.value) return
    try {
      const result = await getSandIamAdmin('webhook/index', { application_id: application, page: 1, limit: 100 })
      const names: Record<number, string> = {}
      for (const item of listRows(result)) {
        const webhook = parseSandIamWebhook(item)
        if (webhook !== null) names[webhook.id] = webhook.name
      }
      if (!disposed && canNames.value && canIndex.value && attempt === requestId) webhookNames.value = names
    } catch {
      // Optional display names have a separate permission; delivery IDs remain usable.
    }
  }

  function clearDetail(): void {
    detailRequest++
    detail.value = null
    detailOpen.value = false
    payloadOpen.value = false
    detailLoading.value = false
  }

  async function loadDeliveries(): Promise<void> {
    if (disposed || !canIndex.value) return
    clearDetail()
    const attempt = ++requestId
    const requestedPage = currentPage.value
    const requestedSize = pageSize.value
    const application = selectedId(applicationId.value)
    if (application === null) {
      loading.value = false
      requestError.value = describeSandIamError(new Error('请先按名称选择接入应用'))
      return
    }
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      void loadWebhookNames(application, attempt)
      const result = await getSandIamAdmin('webhook/delivery/index', {
        application_id: application, page: requestedPage, limit: requestedSize,
        ...(status.value === '' ? {} : { status: Number(status.value) })
      })
      if (disposed || !canIndex.value || attempt !== requestId) return
      const page = parseSandIamWebhookDeliveryPage(result)
      currentPage.value = page.currentPage
      pageSize.value = page.pageSize
      deliveries.value = page.data
      total.value = page.total
      loading.value = false
      viewState.value = page.data.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (disposed || !canIndex.value || attempt !== requestId) return
      requestError.value = describeSandIamError(error)
      deliveries.value = []
      total.value = 0
      if (successHint.value !== '') successHint.value = '已重新排队，但列表刷新失败，请重新加载。'
    } finally {
      if (!disposed && attempt === requestId) loading.value = false
    }
  }

  async function openDetail(row: SandIamWebhookDeliveryRow): Promise<void> {
    if (disposed || loading.value || !canIndex.value || !canRead.value || !deliveries.value.includes(row)) return
    clearDetail()
    const attempt = detailRequest
    const list = requestId
    detailLoading.value = true
    detailOpen.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin('webhook/delivery/read', { id: row.id })
      if (disposed || !canRead.value || !canIndex.value || attempt !== detailRequest || list !== requestId) return
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const parsed = parseSandIamWebhookDelivery(payload, true)
      if (parsed === null || parsed.id !== row.id || parsed.application_id !== row.application_id) throw new Error('投递详情返回格式不符合已冻结约定')
      detail.value = parsed
    } catch (error: unknown) {
      if (!disposed && attempt === detailRequest && list === requestId) requestError.value = describeSandIamError(error)
    } finally {
      if (!disposed && attempt === detailRequest) detailLoading.value = false
    }
  }

  async function retry(row: SandIamWebhookDeliveryRow): Promise<void> {
    if (disposed || loading.value || retrying.value || !canIndex.value || !canRetry.value || !deliveries.value.includes(row) || !webhookDeliveryRetryable(row.status)) return
    const attempt = requestId
    retrying.value = true
    requestError.value = null
    successHint.value = ''
    try {
      await postSandIamAction('webhook/delivery/retry', { id: row.id })
      if (disposed || !canIndex.value || attempt !== requestId) return
      ElMessage.success('已重新排队')
      successHint.value = '投递已重新排队，尚未确认送达。'
      await loadDeliveries()
    } catch (error: unknown) {
      if (!disposed && attempt === requestId) requestError.value = describeSandIamError(error)
    } finally {
      retrying.value = false
    }
  }

  watch([applicationId, status, canIndex], () => {
    requestId++
    currentPage.value = 1
    deliveries.value = []
    total.value = 0
    webhookNames.value = {}
    clearDetail()
    successHint.value = ''
    if (!canIndex.value) { applicationRequest++; applications.value = []; applicationLoading.value = false; applicationError.value = '' }
    requestError.value = null
    loading.value = false
    viewState.value = 'idle'
  }, { flush: 'sync' })

  watch([currentPage, pageSize], () => {
    requestId++; deliveries.value = []; loading.value = false; successHint.value = ''; requestError.value = null; clearDetail()
  }, { flush: 'sync' })
  watch(detailOpen, open => { if (!open) clearDetail() }, { flush: 'sync' })
  watch(canRead, allowed => { if (!allowed) clearDetail() }, { flush: 'sync' })
  watch(canNames, allowed => { if (!allowed) webhookNames.value = {} }, { flush: 'sync' })
  onUnmounted(() => { disposed = true; requestId++; applicationRequest++; clearDetail() })

  function formatPayload(payload: Readonly<Record<string, unknown>> | null): string {
    if (payload === null) return '详情未包含 payload'
    try {
      return JSON.stringify(payload, null, 2)
    } catch {
      return 'payload 无法格式化'
    }
  }

  onMounted(() => {
    void loadApplications()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">投递记录</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          查看事件投递结果并重试失败任务。默认列表只显示处理结果；需要时可在详情中查看只读内容。可重试的失败任务会显示重试操作。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号暂时不能查看投递记录。请联系平台管理员开通投递记录管理范围。"
      />
      <ElAlert
        v-if="requestError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="requestError.detail"
      />
      <ElAlert
        v-else-if="viewState === 'empty'"
        class="mb-4"
        type="info"
        :closable="false"
        title="当前没有数据"
        description="该接入应用还没有投递记录。这与没有权限不同。"
      />

      <ElAlert v-if="successHint" class="mb-4" type="success" :closable="false" :title="successHint" />
      <ElAlert v-if="applicationError" class="mb-4" type="error" :closable="false" :title="applicationError" />
      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable remote :remote-method="loadApplications" :loading="applicationLoading" clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="状态">
          <ElSelect v-model="status" clearable placeholder="全部状态">
            <ElOption v-for="value in [1, 2, 3, 4]" :key="value" :value="String(value)" :label="webhookDeliveryStatusLabel(value)" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canIndex" :loading="loading" @click="loadDeliveries">
            加载投递
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="deliveries" border stripe empty-text="暂无可见投递">
        <ElTableColumn label="事件" min-width="180">
          <template #default="scope">{{ webhookEventLabel(scope.row.event_type) }}</template>
        </ElTableColumn>
        <ElTableColumn label="事件标识" min-width="180">
          <template #default="scope">{{ scope.row.event_id }}</template>
        </ElTableColumn>
        <ElTableColumn label="通知名称" min-width="160">
          <template #default="scope">{{ webhookName(scope.row.webhook_endpoint_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="结果" min-width="140">
          <template #default="scope">{{ webhookDeliveryStatusLabel(scope.row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn label="尝试次数" min-width="90">
          <template #default="scope">{{ scope.row.attempt_count }}</template>
        </ElTableColumn>
        <ElTableColumn label="下次重试/完成" min-width="180">
          <template #default="scope">
            {{ scope.row.delivered_time ?? scope.row.next_attempt_time ?? '—' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="处理提示" min-width="180">
          <template #default="scope">{{
            scope.row.last_error_code ? retryHint(scope.row) : '—'
          }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canIndex || !canRead || loading" @click="openDetail(scope.row)">
              详情
            </ElButton>
            <ElButton
              v-if="webhookDeliveryRetryable(scope.row.status)"
              size="small"
              :disabled="!canIndex || !canRetry || loading || retrying"
              @click="retry(scope.row)"
            >
              重试
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElPagination
        v-if="total > 0"
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next"
        @current-change="loadDeliveries"
        @size-change="currentPage = 1; loadDeliveries()"
      />
    </ElCard>

    <ElDrawer v-model="detailOpen" title="投递详情" size="480px">
      <p v-if="detailLoading">正在加载详情…</p>
      <template v-if="detail">
        <p>事件：{{ webhookEventLabel(detail.event_type) }}</p>
        <p>事件标识：{{ detail.event_id }}</p>
        <p>通知：{{ webhookName(detail.webhook_endpoint_id) }}</p>
        <p>结果：{{ webhookDeliveryStatusLabel(detail.status) }}</p>
        <p>尝试次数：{{ detail.attempt_count }}</p>
        <p>接收端 HTTP 状态：{{ detail.response_status ?? '无响应' }}</p>
        <p v-if="detail.last_error_code">处理建议：{{ retryHint(detail) }}</p>
        <ElButton class="mb-3" @click="payloadOpen = !payloadOpen">
          {{ payloadOpen ? '收起事件内容' : '查看只读事件内容' }}
        </ElButton>
        <pre v-if="payloadOpen" class="overflow-auto text-xs">{{
          formatPayload(detail.payload)
        }}</pre>
      </template>
    </ElDrawer>
  </div>
</template>
