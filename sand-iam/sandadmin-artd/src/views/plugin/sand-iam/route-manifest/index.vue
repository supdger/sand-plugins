<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    describeOnboardingManifestError,
    parseOnboardingPreview,
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
  const previewChanges = computed(() =>
    preview.value === null ? [] : preview.value.changes.map((change) => ({ ...change }))
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
    issuedCredential.value = ''
    lastHint.value = ''
  }

  async function runPreview(): Promise<void> {
    const manifest = parseManifestJson()
    if (manifest === null) {
      preview.value = null
      applied.value = false
      return
    }
    acting.value = true
    requestError.value = null
    resetApplyState()
    try {
      const result = await postSandIamAction('developer/onboarding/preview', {
        manifest
      })
      const parsed = parseOnboardingPreview(result)
      if (parsed === null) {
        throw new Error('服务器没有返回可确认的变更预览，请检查清单后重试。')
      }
      preview.value = parsed
      lastHint.value = '这是保存前的变更预览，尚未写入。有冲突或未关联项时不能继续保存。'
    } catch (error: unknown) {
      preview.value = null
      requestError.value = describeSandIamError(error)
    } finally {
      acting.value = false
    }
  }

  async function confirmApply(): Promise<void> {
    if (preview.value === null || preview.value.dryRun !== true) {
      requestError.value = describeSandIamError(new Error('请先生成变更预览，再确认保存。'))
      return
    }
    const manifest = parseManifestJson()
    if (manifest === null) return
    try {
      await ElMessageBox.confirm(
        '确认按刚才的变更预览保存吗？修改清单后请重新预览。一次性调用凭证只会显示一次。',
        '确认应用清单',
        { type: 'warning', confirmButtonText: '确认保存', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    acting.value = true
    requestError.value = null
    try {
      const result = await postSandIamAction('developer/onboarding/apply', {
        manifest,
        preview_hash: preview.value.previewHash,
        apply: true
      })
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      if (isRecord(payload) && typeof payload.credential === 'string') {
        issuedCredential.value = payload.credential
      }
      applied.value = true
      lastHint.value = '接入已完成。不要把这次成功理解成路由扫描已经覆盖全部宿主路由。'
      ElMessage.success('已保存')
    } catch (error: unknown) {
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
            <ElFormItem>
              <ElSpace>
                <ElButton type="primary" :disabled="!canPreview" @click="runPreview">
                  查看变更
                </ElButton>
                <ElButton
                  type="warning"
                  :disabled="!canApply || preview === null"
                  @click="confirmApply"
                >
                  确认保存
                </ElButton>
              </ElSpace>
            </ElFormItem>
          </ElForm>
        </ElCollapseItem>
      </ElCollapse>

      <ElDescriptions v-if="preview !== null" :column="2" border class="mb-4">
        <ElDescriptionsItem label="变更条数">{{ preview.changeCount }}</ElDescriptionsItem>
        <ElDescriptionsItem label="核对结果">变更内容已核对，可在保存前继续修改</ElDescriptionsItem>
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
      </ElFormItem>
    </ElCard>
  </div>
</template>
