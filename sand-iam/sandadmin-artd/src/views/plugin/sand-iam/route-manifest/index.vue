<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    describeOnboardingManifestError,
    parseOnboardingPreview,
    parseOpenApiImportPreview,
    parseRouteManifestPreview,
    SAND_IAM_ROUTE_SYNC_FORMAT,
    type SandIamOnboardingPreview
  } from '../api/governanceContracts'
  import type { SandIamRequestError } from '../api/types'
  import { postSandIamAction } from '../api/write'

  const { hasAuth } = useAuth()
  const canPreview = computed(() => hasAuth('sand_iam:onboarding:preview'))
  const canApply = computed(() => hasAuth('sand_iam:onboarding:apply'))

  const manifestText = ref('')
  const preview = ref<SandIamOnboardingPreview | null>(null)
  const requestError = ref<SandIamRequestError | null>(null)
  const lastHint = ref('')
  const applied = ref(false)
  const acting = ref(false)
  const issuedCredential = ref('')
  const disableMissing = ref(false)
  const previewKind = ref<'onboarding' | 'route-manifest' | null>(null)
  const openApiImportText = ref('')
  const openApiImportPreview = ref<SandIamOnboardingPreview | null>(null)
  const openApiImportApplied = ref(false)
  const openApiImportError = ref<SandIamRequestError | null>(null)
  const openApiImportExample = {
    organization_code: 'sand',
    application_code: 'work',
    environment_code: 'production',
    document: {
      openapi: '3.1.0',
      info: { title: '工作项 API', version: '1.0.0' },
      paths: {
        '/work-items/{id}': {
          get: {
            summary: '查看工作项',
            'x-sand-iam': { riskLevel: 'low' },
            responses: { '200': { description: '成功' } }
          }
        }
      }
    },
    mappings: [{
      operation_key: 'GET /work-items/{id}',
      api_code: 'work-item.read',
      api_version: 'v1',
      resource_code: 'work_item',
      action: 'work_item.read',
      audience: 'work-api',
      required_scope: 'work.read'
    }],
    disable_missing: false
  }
  let openApiImportVersion = 0
  let openApiFileVersion = 0
  let inputVersion = 0
  let previewVersion = -1
  let disposed = false
  watch([manifestText, disableMissing], () => {
    inputVersion++
    preview.value = null
    applied.value = false
    requestError.value = null
    lastHint.value = ''
    previewKind.value = null
  }, { flush: 'sync' })
  watch(openApiImportText, () => {
    openApiImportVersion++
    openApiImportPreview.value = null
    openApiImportApplied.value = false
    openApiImportError.value = null
  }, { flush: 'sync' })
  onScopeDispose(() => { disposed = true; inputVersion++; issuedCredential.value = '' })
  const previewChanges = computed(() =>
    preview.value === null ? [] : preview.value.changes.map((change) => ({ ...change }))
  )
  const openApiImportChanges = computed(() =>
    openApiImportPreview.value === null
      ? []
      : openApiImportPreview.value.changes.map((change) => ({ ...change }))
  )

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  /**
   * 此页只处理开发者交接清单；格式有误时停在本地，不把预览当作已保存。
   */
  function parseManifestJson(): Record<string, unknown> | null {
    try {
      const parsed: unknown = JSON.parse(manifestText.value)
      if (!isRecord(parsed)) {
        requestError.value = describeSandIamError(new Error('接入清单必须是对象格式。'))
        return null
      }
      const formatError = describeOnboardingManifestError(parsed)
      if (formatError !== null) {
        requestError.value = describeSandIamError(new Error(formatError))
        return null
      }
      return parsed
    } catch {
      requestError.value = describeSandIamError(new Error('接入清单无法识别，请检查逗号和引号。'))
      return null
    }
  }

  function resetApplyState(): void {
    applied.value = false
    lastHint.value = ''
  }

  function parseOpenApiImportJson(): Record<string, unknown> | null {
    try {
      const parsed: unknown = JSON.parse(openApiImportText.value)
      if (!isRecord(parsed)) throw new Error('OpenAPI 导入包必须是 JSON 对象。')
      if (!isRecord(parsed.document) || !Array.isArray(parsed.mappings)) {
        throw new Error('导入包必须包含 document 对象和 mappings 列表。')
      }
      return parsed
    } catch (error: unknown) {
      openApiImportError.value = describeSandIamError(
        error instanceof Error ? error : new Error('OpenAPI 导入包无法识别。')
      )
      return null
    }
  }

  function fillOpenApiImportExample(): void {
    if (acting.value) return
    openApiImportText.value = JSON.stringify(openApiImportExample, null, 2)
  }

  async function selectOpenApiImportFile(event: Event): Promise<void> {
    const input = event.target
    if (!(input instanceof HTMLInputElement) || input.files === null || input.files.length !== 1) return
    const file = input.files[0]
    if (file === undefined || file.size > 2 * 1024 * 1024) {
      openApiImportError.value = describeSandIamError(new Error('OpenAPI JSON 文件不能超过 2 MB。'))
      input.value = ''
      return
    }
    const fileVersion = ++openApiFileVersion
    const inputVersionAtRead = openApiImportVersion
    input.value = ''
    const text = await file.text()
    if (disposed || fileVersion !== openApiFileVersion || inputVersionAtRead !== openApiImportVersion) return
    openApiImportText.value = text
  }

  async function runOpenApiImportPreview(): Promise<void> {
    if (disposed || acting.value || !canPreview.value) return
    const version = openApiImportVersion
    const input = parseOpenApiImportJson()
    if (input === null) return
    acting.value = true
    openApiImportError.value = null
    openApiImportApplied.value = false
    try {
      const result = await postSandIamAction('developer/openapi-import/preview', { import: input })
      if (disposed || version !== openApiImportVersion) return
      const parsed = parseOpenApiImportPreview(result)
      if (parsed === null) throw new Error('服务器没有返回可确认的 OpenAPI 导入预览。')
      openApiImportPreview.value = parsed
    } catch (error: unknown) {
      if (!disposed && version === openApiImportVersion) {
        openApiImportPreview.value = null
        openApiImportError.value = describeSandIamError(error)
      }
    } finally {
      acting.value = false
    }
  }

  async function confirmOpenApiImportApply(): Promise<void> {
    if (disposed || acting.value || !canApply.value || openApiImportApplied.value) return
    const selected = openApiImportPreview.value
    const input = parseOpenApiImportJson()
    const version = openApiImportVersion
    if (selected === null || !selected.canApply || input === null) {
      openApiImportError.value = describeSandIamError(new Error('请先生成无冲突的 OpenAPI 导入预览。'))
      return
    }
    acting.value = true
    try {
      await ElMessageBox.confirm(
        '确认按当前预览创建或更新接口目录与 OpenAPI 路由绑定吗？',
        '确认导入 OpenAPI',
        { type: 'warning', confirmButtonText: '确认导入', cancelButtonText: '取消' }
      )
    } catch {
      acting.value = false
      return
    }
    if (disposed || version !== openApiImportVersion || openApiImportPreview.value !== selected) {
      acting.value = false
      return
    }
    try {
      await postSandIamAction('developer/openapi-import/apply', {
        import: input,
        preview_hash: selected.previewHash,
        apply: true
      })
      if (disposed || version !== openApiImportVersion) return
      openApiImportApplied.value = true
      ElMessage.success('OpenAPI 接口目录已导入')
    } catch (error: unknown) {
      if (!disposed && version === openApiImportVersion) {
        openApiImportError.value = describeSandIamError(error)
      }
    } finally {
      acting.value = false
    }
  }

  function acknowledgeCredential(): void {
    if (!acting.value) issuedCredential.value = ''
  }

  async function runPreview(): Promise<void> {
    if (disposed || acting.value || !canPreview.value || issuedCredential.value !== '') return
    const version = inputVersion
    const manifest = parseManifestJson()
    if (manifest === null) {
      preview.value = null
      applied.value = false
      return
    }
    acting.value = true
    requestError.value = null
    resetApplyState()
    preview.value = null
    try {
      const routeManifest = manifest.format === SAND_IAM_ROUTE_SYNC_FORMAT
      const result = await postSandIamAction(
        routeManifest ? 'developer/route-manifest/preview' : 'developer/onboarding/preview',
        routeManifest ? { manifest, disable_missing: disableMissing.value } : { manifest }
      )
      if (disposed || version !== inputVersion) return
      const parsed = routeManifest
        ? parseRouteManifestPreview(result)
        : parseOnboardingPreview(result)
      if (parsed === null) {
        throw new Error('服务器没有返回可确认的变更预览，请检查清单后重试。')
      }
      preview.value = parsed
      previewKind.value = routeManifest ? 'route-manifest' : 'onboarding'
      previewVersion = version
      lastHint.value = '这是保存前的变更预览，尚未写入。有冲突或未关联项时不能继续保存。'
    } catch (error: unknown) {
      if (disposed || version !== inputVersion) return
      preview.value = null
      requestError.value = describeSandIamError(error)
    } finally {
      acting.value = false
    }
  }

  async function confirmApply(): Promise<void> {
    if (disposed || acting.value || !canApply.value || issuedCredential.value !== '' || applied.value) return
    if (preview.value === null || preview.value.dryRun !== true || !preview.value.canApply) {
      requestError.value = describeSandIamError(new Error('请先生成变更预览，再确认保存。'))
      return
    }
    const manifest = parseManifestJson()
    if (manifest === null) return
    const selected = preview.value
    const kind = previewKind.value
    const version = inputVersion
    const current = (): boolean => !disposed && version === inputVersion &&
      previewVersion === version && preview.value === selected && previewKind.value === kind
    if (!current()) return
    acting.value = true
    try {
      await ElMessageBox.confirm(
        '确认按刚才的变更预览保存吗？修改清单后请重新预览。一次性调用凭证只会显示一次。',
        '确认应用清单',
        { type: 'warning', confirmButtonText: '确认保存', cancelButtonText: '取消' }
      )
    } catch {
      acting.value = false
      return
    }
    if (!current() || !canApply.value) {
      acting.value = false
      return
    }
    requestError.value = null
    try {
      const result = await postSandIamAction(
        kind === 'route-manifest'
          ? 'developer/route-manifest/apply'
          : 'developer/onboarding/apply',
        kind === 'route-manifest'
          ? {
              manifest,
              disable_missing: disableMissing.value,
              preview_hash: selected.previewHash,
              apply: true
            }
          : { manifest, preview_hash: selected.previewHash, apply: true }
      )
      if (disposed) return
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      if (kind === 'onboarding' && isRecord(payload) && typeof payload.credential === 'string') {
        issuedCredential.value = payload.credential
      }
      if (!current()) return
      applied.value = true
      lastHint.value = kind === 'route-manifest'
        ? '路由清单已应用，请按预检结果验证业务路由。'
        : '清单已保存，请按交接清单继续验证业务接入。'
      ElMessage.success('已保存')
    } catch (error: unknown) {
      if (!current()) return
      applied.value = false
      requestError.value = describeSandIamError(error)
    } finally {
      acting.value = false
    }
  }
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never" v-loading="acting">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">开发者接入清单</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          这是把业务应用接入 SandIAM
          的开发者工具。普通管理员请在「第一次使用」中完成客户主体、应用和环境设置；这里用于核对开发团队准备的接入清单，再决定是否保存。
        </p>
      </div>

      <ElAlert
        v-if="!canPreview"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看或保存接入清单。请联系平台管理员开通开发者接入管理范围。"
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
        v-else-if="applied"
        class="mb-4"
        type="success"
        :closable="false"
        title="已保存"
        :description="lastHint"
      />
      <ElAlert
        v-else-if="preview !== null"
        class="mb-4"
        type="info"
        :closable="false"
        title="变更预览已生成（尚未保存）"
        :description="lastHint"
      />

      <ElAlert
        class="mb-4"
        type="info"
        :closable="false"
        title="先准备这些信息"
        description="现有客户主体、应用初始化包、应用环境、业务动作与接口、路由、服务调用身份和服务授权。缺少其中任一项时，请先与应用开发负责人补齐。"
      />

      <ElCollapse>
        <ElCollapseItem
          data-developer-details="true"
          title="开发者详情：导入接入清单"
          name="manifest"
        >
          <p class="mb-3 text-sm text-gray-500">
            此处只适合维护接入包的开发人员。系统会先显示变更预览；确认后才保存。不要在清单中放入密码、令牌或密钥。
          </p>
          <ElForm label-width="120px">
            <ElFormItem label="接入清单">
              <ElInput
                v-model="manifestText"
                type="textarea"
                :rows="14"
                placeholder="粘贴由开发团队生成的接入清单"
              />
            </ElFormItem>
            <ElFormItem v-if="manifestText.includes(SAND_IAM_ROUTE_SYNC_FORMAT)" label="缺失路由">
              <ElCheckbox v-model="disableMissing">
                停用清单中已删除且来源为 route_scan 的旧路由
              </ElCheckbox>
            </ElFormItem>
            <ElFormItem>
              <ElSpace>
                <ElButton type="primary" :disabled="!canPreview || acting || issuedCredential !== ''" @click="runPreview">
                  查看变更
                </ElButton>
                <ElButton
                  type="warning"
                  :disabled="!canApply || preview === null || !preview.canApply || acting || issuedCredential !== '' || applied"
                  @click="confirmApply"
                >
                  确认保存
                </ElButton>
              </ElSpace>
            </ElFormItem>
          </ElForm>
        </ElCollapseItem>
        <ElCollapseItem
          data-openapi-import="true"
          title="开发者详情：导入 OpenAPI 接口目录"
          name="openapi-import"
        >
          <p class="mb-3 text-sm text-gray-500">
            选择或粘贴 OpenAPI 3.0/3.1 JSON 导入包。每个接口必须明确映射到现有业务资源和已发布业务动作；系统只保存接口目录和路由绑定，不保存原始文档。
          </p>
          <ElAlert
            class="mb-4"
            type="info"
            :closable="false"
            title="先填入完整示例，再替换客户主体、应用、环境、资源和动作代码。operation_key 格式为“大写方法 + 空格 + 原始路径模板”。"
          />
          <ElAlert
            v-if="openApiImportError"
            class="mb-4"
            type="error"
            :closable="false"
            :title="openApiImportError.title"
            :description="openApiImportError.detail"
          />
          <ElAlert
            v-else-if="openApiImportApplied"
            class="mb-4"
            type="success"
            :closable="false"
            title="OpenAPI 接口目录已导入"
          />
          <ElForm label-width="120px">
            <ElFormItem label="JSON 文件">
              <input
                type="file"
                accept=".json,application/json"
                :disabled="acting || !canPreview"
                @change="selectOpenApiImportFile"
              />
            </ElFormItem>
            <ElFormItem label="导入包">
              <ElInput
                v-model="openApiImportText"
                type="textarea"
                :rows="14"
                placeholder="粘贴包含客户主体、应用、环境、OpenAPI document 和显式 mappings 的 JSON 导入包"
              />
              <ElButton class="mt-2" :disabled="acting" @click="fillOpenApiImportExample">
                填入完整示例
              </ElButton>
            </ElFormItem>
            <ElFormItem>
              <ElSpace>
                <ElButton
                  type="primary"
                  :disabled="!canPreview || acting || openApiImportText.trim() === ''"
                  @click="runOpenApiImportPreview"
                >
                  查看 OpenAPI 变更
                </ElButton>
                <ElButton
                  type="warning"
                  :disabled="!canApply || acting || openApiImportPreview === null || !openApiImportPreview.canApply || openApiImportApplied"
                  @click="confirmOpenApiImportApply"
                >
                  确认导入
                </ElButton>
              </ElSpace>
            </ElFormItem>
          </ElForm>
          <ElDescriptions v-if="openApiImportPreview !== null" :column="2" border class="mb-4">
            <ElDescriptionsItem label="变更条数">{{ openApiImportPreview.changeCount }}</ElDescriptionsItem>
            <ElDescriptionsItem label="核对结果">{{
              openApiImportPreview.canApply ? '所有接口已映射，可以导入' : '存在未映射接口或归属冲突，不能导入'
            }}</ElDescriptionsItem>
          </ElDescriptions>
          <ElTable
            v-if="openApiImportPreview !== null"
            :data="openApiImportChanges"
            border
            stripe
            empty-text="预检没有变更项。"
          >
            <ElTableColumn label="对象类型" min-width="160">
              <template #default="scope">{{ scope.row.objectType }}</template>
            </ElTableColumn>
            <ElTableColumn label="对象标识" min-width="180">
              <template #default="scope">{{ scope.row.objectKey }}</template>
            </ElTableColumn>
            <ElTableColumn label="预检操作" min-width="120">
              <template #default="scope">{{ scope.row.operation }}</template>
            </ElTableColumn>
          </ElTable>
        </ElCollapseItem>
      </ElCollapse>

      <ElDescriptions v-if="preview !== null" :column="2" border class="mb-4">
        <ElDescriptionsItem label="变更条数">{{ preview.changeCount }}</ElDescriptionsItem>
        <ElDescriptionsItem label="核对结果">{{
          preview.canApply ? '变更内容已核对，可在保存前继续修改' : '存在冲突或未关联接口，修正清单后重新预检'
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="写入状态">{{
          applied ? '已保存' : '尚未保存'
        }}</ElDescriptionsItem>
      </ElDescriptions>

      <ElTable
        v-if="preview !== null"
        :data="previewChanges"
        border
        stripe
        empty-text="预检没有变更项。这与没有权限不同。"
      >
        <ElTableColumn label="对象类型" min-width="160">
          <template #default="scope">{{ scope.row.objectType }}</template>
        </ElTableColumn>
        <ElTableColumn label="对象标识" min-width="180">
          <template #default="scope">{{ scope.row.objectKey }}</template>
        </ElTableColumn>
        <ElTableColumn label="预检操作" min-width="120">
          <template #default="scope">{{ scope.row.operation }}</template>
        </ElTableColumn>
      </ElTable>

      <ElFormItem v-if="issuedCredential !== ''" label="一次性调用凭证" class="mt-4">
        <ElInput :model-value="issuedCredential" type="textarea" readonly />
        <p class="mb-0 mt-1 text-xs text-gray-500">
          关闭或刷新后无法再看。不要写入日志、URL 或截图文件名。
        </p>
        <ElButton :disabled="acting" @click="acknowledgeCredential">我已安全保存，清除凭证</ElButton>
      </ElFormItem>
    </ElCard>
  </div>
</template>
