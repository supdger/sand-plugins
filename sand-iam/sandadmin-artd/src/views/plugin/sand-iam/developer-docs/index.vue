<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { getSandIamAdmin } from '../api/write'
  import type { SandIamRequestError } from '../api/types'

  interface CatalogEvent {
    readonly code: string
    readonly name: string
  }

  const { hasAuth } = useAuth()
  const canOpenApi = computed(() => hasAuth('sand_iam:developer:openapi'))
  const canEvents = computed(() => hasAuth('sand_iam:developer:events'))
  const openApiHint = ref('尚未加载。')
  const openApiDocument = ref<unknown>(null)
  const events = ref<CatalogEvent[]>([])
  const eventCatalog = ref<unknown>(null)
  const openApiError = ref<SandIamRequestError | null>(null)
  const eventsError = ref<SandIamRequestError | null>(null)
  const openApiLoading = ref(false)
  const eventsLoading = ref(false)
  let disposed = false
  let openApiVersion = 0
  let eventsVersion = 0
  watch(canOpenApi, () => {
    openApiVersion++
    openApiDocument.value = null
    openApiError.value = null
    openApiLoading.value = false
    openApiHint.value = canOpenApi.value ? '尚未加载。' : '当前账号无权查看管理 API 文档。'
  }, { flush: 'sync' })
  watch(canEvents, () => {
    eventsVersion++
    events.value = []
    eventCatalog.value = null
    eventsError.value = null
    eventsLoading.value = false
  }, { flush: 'sync' })

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function unwrap(value: unknown): unknown {
    return isRecord(value) && 'data' in value ? value.data : value
  }

  async function loadCatalogs(): Promise<void> {
    await Promise.all([loadOpenApi(), loadEvents()])
  }

  async function loadOpenApi(): Promise<void> {
    if (disposed) return
    const version = ++openApiVersion
    openApiDocument.value = null
    openApiError.value = null
    if (canOpenApi.value) {
      openApiLoading.value = true
      try {
        const openApi = unwrap(await getSandIamAdmin('developer/openapi'))
        if (disposed || version !== openApiVersion || !canOpenApi.value) return
        if (!isRecord(openApi) || !isRecord(openApi.paths)) throw new Error('管理 API 文档格式无效。')
        openApiDocument.value = openApi
        const paths =
          isRecord(openApi) && isRecord(openApi.paths) ? Object.keys(openApi.paths).length : 0
        openApiHint.value =
          paths > 0
            ? `管理接口说明已加载，约 ${String(paths)} 条路径。完整定义可在下方开发者详情中下载。`
            : '管理 API 文档已返回，但没有可展示的路径摘要。'
      } catch (error: unknown) {
        if (disposed || version !== openApiVersion || !canOpenApi.value) return
        openApiError.value = describeSandIamError(error)
        openApiHint.value = '无法加载管理 API 文档。'
      } finally {
        if (!disposed && version === openApiVersion) openApiLoading.value = false
      }
    } else {
      openApiHint.value = '当前账号无权查看管理 API 文档。'
    }
  }

  async function loadEvents(): Promise<void> {
    if (disposed) return
    const version = ++eventsVersion
    eventCatalog.value = null
    events.value = []
    eventsError.value = null
    if (!canEvents.value) {
      return
    }
    eventsLoading.value = true
    try {
      const catalog = unwrap(await getSandIamAdmin('developer/events'))
      if (disposed || version !== eventsVersion || !canEvents.value) return
      if (!isRecord(catalog) || !Array.isArray(catalog.events)) throw new Error('事件目录格式无效。')
      eventCatalog.value = catalog
      const list = isRecord(catalog) && Array.isArray(catalog.events) ? catalog.events : []
      events.value = list
        .map((item) => {
          if (!isRecord(item) || typeof item.code !== 'string') return null
          const name =
            typeof item.name === 'string'
              ? item.name
              : typeof item.title === 'string'
                ? item.title
                : item.code
          return { code: item.code, name }
        })
        .filter((item): item is CatalogEvent => item !== null)
    } catch (error: unknown) {
      if (!disposed && version === eventsVersion && canEvents.value) eventsError.value = describeSandIamError(error)
    } finally {
      if (!disposed && version === eventsVersion) eventsLoading.value = false
    }
  }

  /**
   * 只下载后端已返回的 OpenAPI / 事件目录，不在浏览器编造路径或事件。
   */
  function downloadJson(filename: string, value: unknown): void {
    if (disposed || value === null) return
    if (filename === 'sand-iam-openapi.json') {
      if (!canOpenApi.value || openApiLoading.value || value !== openApiDocument.value) return
    } else if (filename === 'sand-iam-events.json') {
      if (!canEvents.value || eventsLoading.value || value !== eventCatalog.value) return
    } else return
    const blob = new Blob([JSON.stringify(value, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  }

  onMounted(() => {
    void loadCatalogs()
  })
  onScopeDispose(() => {
    disposed = true
    openApiVersion++
    eventsVersion++
    openApiDocument.value = null
    eventCatalog.value = null
    events.value = []
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <h2 class="m-0 text-lg font-semibold">开发者接入</h2>
      <p class="mb-4 mt-2 text-sm text-gray-500">
        这里提供给应用开发人员：先登记接口和业务动作，再接入登录、授权和审计。普通管理员不需要在此页配置业务系统。
      </p>
      <ElAlert
        v-if="openApiError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="openApiError.title"
        :description="openApiError.detail"
      />
      <ElAlert v-if="eventsError" class="mb-4" type="error" :closable="false"
        :title="eventsError.title" :description="eventsError.detail" />
      <ElButton :disabled="!canOpenApi && !canEvents" :loading="openApiLoading || eventsLoading"
        @click="loadCatalogs">重新加载目录</ElButton>
      <ElAlert
        class="mb-4"
        :type="canOpenApi ? 'info' : 'warning'"
        :closable="false"
        :title="canOpenApi ? '管理 API 文档' : '没有权限'"
        :description="openApiHint"
      />
      <ElButton
        class="mb-4"
        :disabled="!canOpenApi || openApiLoading || openApiDocument === null"
        @click="downloadJson('sand-iam-openapi.json', openApiDocument)"
      >
        下载接口定义
      </ElButton>
      <ElButton
        class="mb-4"
        :disabled="!canEvents || eventsLoading || eventCatalog === null"
        @click="downloadJson('sand-iam-events.json', eventCatalog)"
      >
        下载事件目录
      </ElButton>
      <ElAlert
        v-if="!canEvents"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看事件目录。"
      />
      <ElAlert
        v-else-if="events.length === 0"
        class="mb-4"
        type="info"
        :closable="false"
        title="当前没有事件"
        description="事件目录为空。这与没有权限不同。"
      />

      <ElTable :data="events" border stripe empty-text="暂无可见事件">
        <ElTableColumn label="事件名称" min-width="200">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
      </ElTable>

      <ElCollapse class="mt-6">
        <ElCollapseItem
          data-developer-details="true"
          title="开发者详情：事件标识、代码示例与接口定义"
          name="developer-details"
        >
          <p class="text-sm text-gray-500"
            >业务对象要在处理请求前完成识别；找不到对象或没有访问范围时，业务操作不会执行。</p
          >
          <ElTable :data="events" border stripe empty-text="暂无可见事件">
            <ElTableColumn label="事件名称" min-width="200">
              <template #default="scope">{{ scope.row.name }}</template>
            </ElTableColumn>
            <ElTableColumn label="事件标识" min-width="220">
              <template #default="scope">{{ scope.row.code }}</template>
            </ElTableColumn>
          </ElTable>
          <h3 class="mt-4 text-base font-semibold">业务对象识别</h3>
          <pre class="overflow-auto rounded bg-gray-50 p-3 text-xs">
Route::post('/api/customer/v1/orders/{id}/archive', [OrderController::class, 'archive'])
    ->setParams(['sand_iam' => [
        'organization_code' => 'sand',
        'application_code' => 'customer-service',
        'entity_scope' => [
            'mode' => 'entity',
            'resolver' => static fn (Request $request): Order => Order::findOrFail((int) $request->route->param('id')),
        ],
    ]])
    ->middleware([ApplicationAuthorizationMiddleware::class]);</pre
          >
          <h3 class="text-base font-semibold">TypeScript SDK</h3>
          <p>先安装对应 SDK 并导入 SandIamClient；session.accessToken / $accessToken 来自当前应用用户会话。授权拒绝或请求异常时停止业务操作，不得捕获异常后继续执行。返回的数据范围仍须用于业务查询。</p>
          <pre class="overflow-auto rounded bg-gray-50 p-3 text-xs">
const iam = new SandIamClient({
  baseUrl: "https://iam.example.com",
  organizationCode: "sand",
  applicationCode: "customer-service",
  accessToken: () => session.accessToken,
})
const decision = await iam.authorize({ apiCode: "order.detail", attributes: { organization_id: 42 } })
// 仅在 authorize 正常返回后，按 decision.scope 约束业务查询。</pre
          >
          <h3 class="text-base font-semibold">PHP SDK</h3>
          <pre class="overflow-auto rounded bg-gray-50 p-3 text-xs">
$iam = new \Sand\Iam\Sdk\SandIamClient(
  baseUrl: 'https://iam.example.com',
  organizationCode: 'sand',
  applicationCode: 'customer-service',
);
$decision = $iam->decide($accessToken, 'order.detail', ['organization_id' => 42]);
if ($decision['allowed'] !== true) {
  throw new \RuntimeException('访问被拒绝');
}
// 仅在允许后，按 $decision['scope'] 约束业务查询。</pre
          >
          <h3 class="text-base font-semibold">Dart / Flutter SDK</h3>
          <pre class="overflow-auto rounded bg-gray-50 p-3 text-xs">
final iam = SandIamClient(
  baseUrl: 'https://iam.example.com',
  organizationCode: 'sand',
  applicationCode: 'customer-service',
  accessToken: () => session.accessToken,
);
final decision = await iam.authorize(apiCode: 'order.detail', attributes: {'organization_id': 42});
// 仅在 authorize 正常返回后，按 decision.scope 约束业务查询。</pre
          >
          <ElDivider />
          <h3 class="text-base font-semibold">三条接入路径</h3>
          <ol class="text-sm text-gray-600">
            <li>接口登记 → 业务动作 → 接入清单变更预览 → 保存 → 允许或拒绝与审计。</li>
            <li>独立应用门户注册 → 登录 → MFA → 业务 API。</li>
            <li>服务调用身份凭证 → 语义动作 → 放行 / 拒绝 / 撤销。</li>
          </ol>
        </ElCollapseItem>
      </ElCollapse>
    </ElCard>
  </div>
</template>
