<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import {
    parseSandIamWebhook,
    parseSandIamWebhookDeliveries,
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

  const loading = ref(false)
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
    return webhookNames.value[endpointId] ?? '通知已不在当前列表'
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

  async function loadApplications(): Promise<void> {
    try {
      applications.value = listRows(
        await listSandIamResource('application', { page: 1, limit: 100 })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  /**
   * 投递记录没有通知名称；用同一应用的通知记录做名称回显，不编造字段。
   */
  async function loadWebhookNames(application: number): Promise<void> {
    const result = await getSandIamAdmin('webhook/index', {
      application_id: application
    })
    const names: Record<number, string> = {}
    for (const item of listRows(result)) {
      const webhook = parseSandIamWebhook(item)
      if (webhook !== null) names[webhook.id] = webhook.name
    }
    webhookNames.value = names
  }

  async function loadDeliveries(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(new Error('请先按名称选择接入应用'))
      return
    }
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      await loadWebhookNames(application)
      const result = await getSandIamAdmin('webhook/delivery/index', {
        application_id: application
      })
      const rows = parseSandIamWebhookDeliveries(result)
      deliveries.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      deliveries.value = []
    } finally {
      loading.value = false
    }
  }

  async function openDetail(row: SandIamWebhookDeliveryRow): Promise<void> {
    if (!canRead.value) {
      requestError.value = describeSandIamError(
        new Error('当前账号暂时不能查看投递详情。请联系管理员开通查看投递详情的管理范围。')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const result = await getSandIamAdmin('webhook/delivery/read', { id: row.id })
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const parsed = parseSandIamWebhookDelivery(payload, true)
      if (parsed === null) {
        throw new Error('投递详情返回格式不符合已冻结约定')
      }
      detail.value = parsed
      payloadOpen.value = false
      detailOpen.value = true
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function retry(row: SandIamWebhookDeliveryRow): Promise<void> {
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('webhook/delivery/retry', { id: row.id })
      ElMessage.success('已保存')
      await loadDeliveries()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

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

      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
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
            <ElButton size="small" :disabled="!canRead" @click="openDetail(scope.row)">
              详情
            </ElButton>
            <ElButton
              v-if="webhookDeliveryRetryable(scope.row.status)"
              size="small"
              :disabled="!canRetry"
              @click="retry(scope.row)"
            >
              重试
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElDrawer v-model="detailOpen" title="投递详情" size="480px">
      <template v-if="detail">
        <p>事件：{{ webhookEventLabel(detail.event_type) }}</p>
        <p>事件标识：{{ detail.event_id }}</p>
        <p>通知：{{ webhookName(detail.webhook_endpoint_id) }}</p>
        <p>结果：{{ webhookDeliveryStatusLabel(detail.status) }}</p>
        <p>尝试次数：{{ detail.attempt_count }}</p>
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
