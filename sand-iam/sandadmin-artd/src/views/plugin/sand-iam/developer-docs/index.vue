<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
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
  const requestError = ref<SandIamRequestError | null>(null)

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function unwrap(value: unknown): unknown {
    return isRecord(value) && 'data' in value ? value.data : value
  }

  async function loadCatalogs(): Promise<void> {
    requestError.value = null
    if (canOpenApi.value) {
      try {
        const openApi = unwrap(await getSandIamAdmin('developer/openapi'))
        openApiDocument.value = openApi
        const paths =
          isRecord(openApi) && isRecord(openApi.paths) ? Object.keys(openApi.paths).length : 0
        openApiHint.value =
          paths > 0
            ? `管理接口说明已加载，约 ${String(paths)} 条路径。完整定义可在下方开发者详情中下载。`
            : '管理 API 文档已返回，但没有可展示的路径摘要。'
      } catch (error: unknown) {
        requestError.value = describeSandIamError(error)
        openApiHint.value = '无法加载管理 API 文档。'
      }
    } else {
      openApiHint.value = '当前账号无权查看管理 API 文档。'
    }
    if (!canEvents.value) {
      return
    }
    try {
      const catalog = unwrap(await getSandIamAdmin('developer/events'))
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
      requestError.value = describeSandIamError(error)
    }
  }

  /**
   * 只下载后端已返回的 OpenAPI / 事件目录，不在浏览器编造路径或事件。
   */
  function downloadJson(filename: string, value: unknown): void {
    if (value === null) return
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
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <h2 class="m-0 text-lg font-semibold">开发者接入</h2>
      <p class="mb-4 mt-2 text-sm text-gray-500">
        这里提供给应用开发人员：先登记接口和业务动作，再接入登录、授权和审计。普通管理员不需要在此页配置业务系统。
      </p>
      <ElAlert
        v-if="requestError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="requestError.detail"
      />
      <ElAlert
        class="mb-4"
        :type="canOpenApi ? 'info' : 'warning'"
        :closable="false"
        :title="canOpenApi ? '管理 API 文档' : '没有权限'"
        :description="openApiHint"
      />
      <ElButton
        class="mb-4"
        :disabled="openApiDocument === null"
        @click="downloadJson('sand-iam-openapi.json', openApiDocument)"
      >
        下载接口定义
      </ElButton>
      <ElButton
        class="mb-4"
        :disabled="eventCatalog === null"
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
          <pre class="overflow-auto rounded bg-gray-50 p-3 text-xs">
const iam = new SandIamClient({
  baseUrl: "https://iam.example.com",
  organizationCode: "sand",
  applicationCode: "customer-service",
  accessToken: () => session.accessToken,
})
await iam.authorize({ apiCode: "order.detail", attributes: { organization_id: 42 } })</pre
          >
          <h3 class="text-base font-semibold">PHP SDK</h3>
          <pre class="overflow-auto rounded bg-gray-50 p-3 text-xs">
$iam = new SandIamClient([
  'base_url' => 'https://iam.example.com',
  'organization_code' => 'sand',
  'application_code' => 'customer-service',
]);
$iam->authorize('order.detail', ['organization_id' => 42]);</pre
          >
          <h3 class="text-base font-semibold">Dart / Flutter SDK</h3>
          <pre class="overflow-auto rounded bg-gray-50 p-3 text-xs">
final iam = SandIamClient(
  baseUrl: 'https://iam.example.com',
  organizationCode: 'sand',
  applicationCode: 'customer-service',
  accessToken: () => session.accessToken,
);
await iam.authorize(apiCode: 'order.detail', attributes: {'organization_id': 42});</pre
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
