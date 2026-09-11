<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { parsePolicySimulation, type SandIamPolicySimulation } from '../api/governanceContracts'
  import { parseJsonObject } from '../api/policyJson'
  import { listSandIamResource } from '../api/resource'
  import { decideSandIamAuthorization, describeRuntimeDecideError } from '../api/runtimeDecide'
  import type { SandIamAuthorizationDecision } from '../api/governanceContracts'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'
  import { postSandIamAction } from '../api/write'

  const { hasAuth } = useAuth()
  const canSimulate = computed(() => hasAuth('sand_iam:policy:read'))

  const applications = ref<SandIamResourceRow[]>([])
  const identities = ref<SandIamResourceRow[]>([])
  const applicationId = ref('')
  const identityId = ref('')
  const resourceCode = ref('')
  const action = ref('')
  const operation = ref('read')
  const organizationScope = ref('')
  const advancedSimulationInput = ref<string[]>([])
  const attributesText = ref('{"organization_id":1}')
  const simulation = ref<SandIamPolicySimulation | null>(null)
  const requestError = ref<SandIamRequestError | null>(null)
  const acting = ref(false)

  const accessToken = ref('')
  const organizationCode = ref('')
  const applicationCode = ref('')
  const apiCode = ref('')
  const apiVersion = ref('v1')
  const decideOrganizationScope = ref('')
  const advancedDecisionInput = ref<string[]>([])
  const decideAttributes = ref('{"organization_id":1}')
  const simulationDetailsOpen = ref<string[]>([])
  const decisionDetailsOpen = ref<string[]>([])
  const decision = ref<SandIamAuthorizationDecision | null>(null)
  const decideRequestId = ref('')
  const decideError = ref<SandIamRequestError | null>(null)

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (isRecord(value) && Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  function nameOf(row: SandIamResourceRow): string {
    return typeof row.name === 'string'
      ? row.name
      : typeof row.display_name === 'string'
        ? row.display_name
        : '未命名'
  }

  async function loadOptions(): Promise<void> {
    try {
      const [appResult, identityResult] = await Promise.all([
        listSandIamResource('application', { page: 1, limit: 100 }),
        listSandIamResource('identity', { page: 1, limit: 100 })
      ])
      applications.value = listRows(appResult)
      identities.value = listRows(identityResult)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  async function runSimulate(): Promise<void> {
    const application = Number(applicationId.value)
    const identity = Number(identityId.value)
    if (
      !Number.isInteger(application) ||
      application <= 0 ||
      !Number.isInteger(identity) ||
      identity <= 0
    ) {
      requestError.value = describeSandIamError(new Error('请按名称选择接入应用和应用身份。'))
      return
    }
    let attributes: Record<string, unknown>
    try {
      attributes = simulationAttributes()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      return
    }
    acting.value = true
    requestError.value = null
    simulation.value = null
    try {
      const result = await postSandIamAction('policy/simulate', {
        application_id: application,
        identity_id: identity,
        resource_code: resourceCode.value.trim(),
        action: action.value.trim(),
        operation: operation.value,
        attributes
      })
      const parsed = parsePolicySimulation(result)
      if (parsed === null) {
        throw new Error('模拟结果不完整，页面不会自行推断允许或拒绝。请稍后重试。')
      }
      simulation.value = parsed
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      acting.value = false
    }
  }

  async function runDecide(): Promise<void> {
    let attributes: Record<string, unknown>
    try {
      attributes = decisionAttributes()
    } catch (error: unknown) {
      decideError.value = describeRuntimeDecideError(error)
      return
    }
    decideError.value = null
    decision.value = null
    try {
      const result = await decideSandIamAuthorization(accessToken.value, {
        organization_code: organizationCode.value.trim(),
        application_code: applicationCode.value.trim(),
        api_code: apiCode.value.trim(),
        api_version: apiVersion.value.trim() || 'v1',
        attributes
      })
      decideRequestId.value = result.requestId
      decision.value = result.data
    } catch (error: unknown) {
      decideError.value = describeRuntimeDecideError(error)
    }
  }

  function optionalOrganizationScope(value: string): Record<string, unknown> {
    const trimmed = value.trim()
    if (trimmed === '') return {}
    const parsed = Number(trimmed)
    if (!Number.isInteger(parsed) || parsed <= 0) {
      throw new Error('客户主体编号应为正整数，或留空不限定范围。')
    }
    return { organization_id: parsed }
  }

  function simulationAttributes(): Record<string, unknown> {
    if (advancedSimulationInput.value.includes('simulation-context')) {
      return parseJsonObject(attributesText.value, '高级授权上下文')
    }
    return optionalOrganizationScope(organizationScope.value)
  }

  function decisionAttributes(): Record<string, unknown> {
    if (advancedDecisionInput.value.includes('decision-context')) {
      return parseJsonObject(decideAttributes.value, '高级授权上下文')
    }
    return optionalOrganizationScope(decideOrganizationScope.value)
  }

  onMounted(() => {
    void loadOptions()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card mb-4" shadow="never" v-loading="acting">
      <h2 class="m-0 text-lg font-semibold">策略模拟</h2>
      <p class="mb-4 mt-2 text-sm text-gray-500">
        先按人员、资源和动作预演授权结果。系统会给出允许或拒绝的原因；业务系统仍需确认自身的数据范围。
      </p>
      <ElAlert
        v-if="!canSimulate"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权模拟策略。请联系平台管理员确认您的管理范围。"
      />
      <ElAlert
        v-if="requestError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="requestError.detail"
      />
      <ElForm label-width="160px">
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="nameOf(row)"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="应用身份">
          <ElSelect v-model="identityId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in identities"
              :key="String(row.id)"
              :label="nameOf(row)"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="业务资源代码">
          <ElInput v-model="resourceCode" placeholder="matter" />
        </ElFormItem>
        <ElFormItem label="语义动作">
          <ElInput v-model="action" placeholder="matter.read" />
        </ElFormItem>
        <ElFormItem label="数据操作">
          <ElSelect v-model="operation">
            <ElOption label="详情" value="read" />
            <ElOption label="列表" value="list" />
            <ElOption label="新增" value="create" />
            <ElOption label="修改" value="update" />
            <ElOption label="删除" value="delete" />
            <ElOption label="导出" value="export" />
            <ElOption label="批量" value="batch" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="客户主体范围（可选）">
          <ElInput v-model="organizationScope" placeholder="输入客户主体编号；留空表示不限定" />
          <p class="mb-0 mt-1 text-xs text-gray-500">
            普通模拟只需填写可访问的客户主体范围。没有范围限制时可以留空。
          </p>
        </ElFormItem>
        <ElCollapse v-model="advancedSimulationInput" class="mb-4">
          <ElCollapseItem
            data-developer-details="true"
            title="高级输入：补充授权条件（供技术人员排查）"
            name="simulation-context"
          >
            <p class="mt-0 text-xs text-gray-500">
              仅在常规范围无法表达时使用。内容会参与授权判断，请勿填写密码、密钥或个人敏感信息。
            </p>
            <ElInput
              v-model="attributesText"
              type="textarea"
              :rows="4"
              placeholder='例如：{"organization_id": 1}'
            />
            <p class="mb-0 mt-1 text-xs text-gray-500">
              请填写对象格式，保存前会检查格式是否正确。
            </p>
          </ElCollapseItem>
        </ElCollapse>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canSimulate" @click="runSimulate">
            向后端模拟
          </ElButton>
        </ElFormItem>
      </ElForm>
      <ElDescriptions v-if="simulation !== null" :column="1" border>
        <ElDescriptionsItem label="后端结果">{{
          simulation.allowed ? '允许' : '拒绝'
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="原因摘要">{{ simulation.finalReason }}</ElDescriptionsItem>
      </ElDescriptions>
      <ElCollapse v-if="simulation !== null" v-model="simulationDetailsOpen" class="mt-3">
        <ElCollapseItem
          data-developer-details="true"
          title="判断详情（供技术人员排查）"
          name="simulation-details"
        >
          <p>结果代码：{{ simulation.code }}</p>
          <p>命中规则数：{{ simulation.matchedCount }}</p>
          <p>缺少的补充条件：{{ simulation.missingContext.join('、') || '无' }}</p>
        </ElCollapseItem>
      </ElCollapse>
    </ElCard>

    <ElCard class="sand-iam-page-card" shadow="never">
      <h2 class="m-0 text-lg font-semibold">应用访问授权判断</h2>
      <p class="mb-4 mt-2 text-sm text-gray-500">
        用当前应用用户的访问凭据，检查一次实际访问是否被允许。凭据仅保留在当前页面，关闭页面后自动丢弃。
      </p>
      <ElAlert
        v-if="decideError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="decideError.title"
        :description="decideError.detail"
      />
      <ElForm label-width="160px">
        <ElFormItem label="应用用户访问凭据">
          <ElInput v-model="accessToken" type="password" show-password autocomplete="off" />
        </ElFormItem>
        <ElFormItem label="客户主体代码">
          <ElInput v-model="organizationCode" />
        </ElFormItem>
        <ElFormItem label="接入应用代码">
          <ElInput v-model="applicationCode" />
        </ElFormItem>
        <ElFormItem label="接口代码">
          <ElInput v-model="apiCode" placeholder="matter.detail" />
        </ElFormItem>
        <ElFormItem label="接口版本">
          <ElInput v-model="apiVersion" />
        </ElFormItem>
        <ElFormItem label="客户主体范围（可选）">
          <ElInput
            v-model="decideOrganizationScope"
            placeholder="输入客户主体编号；留空表示不限定"
          />
        </ElFormItem>
        <ElCollapse v-model="advancedDecisionInput" class="mb-4">
          <ElCollapseItem
            data-developer-details="true"
            title="高级输入：补充授权条件（供技术人员排查）"
            name="decision-context"
          >
            <p class="mt-0 text-xs text-gray-500">
              仅在常规范围无法表达时使用。内容会参与授权判断，请勿填写密码、密钥或个人敏感信息。
            </p>
            <ElInput
              v-model="decideAttributes"
              type="textarea"
              :rows="4"
              placeholder='例如：{"organization_id": 1}'
            />
            <p class="mb-0 mt-1 text-xs text-gray-500">
              请填写对象格式，提交前会检查格式是否正确。
            </p>
          </ElCollapseItem>
        </ElCollapse>
        <ElFormItem>
          <ElButton type="primary" @click="runDecide">向后端询问决策</ElButton>
        </ElFormItem>
      </ElForm>
      <ElDescriptions v-if="decision !== null" :column="1" border>
        <ElDescriptionsItem label="后端结果">{{
          decision.allowed ? '允许' : '拒绝'
        }}</ElDescriptionsItem>
        <ElDescriptionsItem label="接口代码">{{ decision.apiCode }}</ElDescriptionsItem>
        <ElDescriptionsItem label="语义动作">{{ decision.action }}</ElDescriptionsItem>
        <ElDescriptionsItem label="业务资源">{{ decision.resourceCode }}</ElDescriptionsItem>
        <ElDescriptionsItem label="数据操作">{{ decision.operation }}</ElDescriptionsItem>
      </ElDescriptions>
      <ElCollapse v-if="decision !== null" v-model="decisionDetailsOpen" class="mt-3">
        <ElCollapseItem
          data-developer-details="true"
          title="本次判断详情（供技术人员排查）"
          name="decision-details"
        >
          <p>结果代码：{{ decision.code }}</p>
          <p>请求编号：{{ decideRequestId || '—' }}</p>
        </ElCollapseItem>
      </ElCollapse>
    </ElCard>
  </div>
</template>
