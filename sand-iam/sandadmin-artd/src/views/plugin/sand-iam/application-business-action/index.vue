<script setup lang="ts">
  import { ref } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { businessActionFields } from '../api/fields'
  import { listSandIamResource } from '../api/resource'
  import type {
    SandIamFilterKey,
    SandIamRequestError,
    SandIamResourceColumn,
    SandIamResourceRow
  } from '../api/types'
  import { getSandIamAdmin } from '../api/write'
  import ResourceListPage from '../components/ResourceListPage.vue'

  interface PendingClaim {
    readonly code: string
    readonly sources: readonly string[]
  }

  const { hasAuth } = useAuth()
  const claims = ref<PendingClaim[]>([])
  const claimError = ref<SandIamRequestError | null>(null)
  const claimHint = ref('先按名称选择接入应用，再查看待认领的历史动作。')
  const applications = ref<SandIamResourceRow[]>([])
  const applicationId = ref('')
  const loadingClaims = ref(false)

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '动作名称', minWidth: 160 },
    { key: 'code', label: '稳定代码', minWidth: 180, copyable: true },
    { key: 'description', label: '说明', minWidth: 220 },
    { key: 'state', label: '发布状态' },
    { key: 'status', label: '启停' },
    { key: 'application_id', label: '所属接入应用', minWidth: 180 }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'organization_id', 'application_id', 'status']

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (isRecord(value) && Array.isArray(value.data)) {
      return value.data.filter(isRecord)
    }
    return []
  }

  function parsePendingClaim(value: unknown): PendingClaim | null {
    if (!isRecord(value) || typeof value.code !== 'string') return null
    const sources = Array.isArray(value.sources)
      ? value.sources.filter((source): source is string => typeof source === 'string')
      : []
    return { code: value.code, sources }
  }

  /**
   * 待认领报告只列出历史旧值，不会自动写成正式声明。
   */
  async function loadClaims(): Promise<void> {
    const parsed = Number(applicationId.value)
    if (!Number.isInteger(parsed) || parsed <= 0) {
      claimError.value = describeSandIamError(new Error('请先按名称选择接入应用。'))
      return
    }
    loadingClaims.value = true
    claimError.value = null
    try {
      const result = await getSandIamAdmin('application-business-action/pending-claims', {
        application_id: parsed
      })
      const payload = isRecord(result) && isRecord(result.data) ? result.data : result
      const items = isRecord(payload) && Array.isArray(payload.items) ? payload.items : []
      claims.value = items.flatMap((item) => {
        const claim = parsePendingClaim(item)
        return claim === null ? [] : [claim]
      })
      claimHint.value =
        claims.value.length === 0
          ? '当前应用没有待认领的历史动作。这与没有权限不同。'
          : '这些旧值还不是正式声明。请核对中文名称后新建业务动作，不要猜测迁移。'
    } catch (error: unknown) {
      claimError.value = describeSandIamError(error)
      claims.value = []
    } finally {
      loadingClaims.value = false
    }
  }

  async function loadApplications(): Promise<void> {
    try {
      applications.value = listRows(
        await listSandIamResource('application', { page: 1, limit: 100 })
      )
    } catch (error: unknown) {
      claimError.value = describeSandIamError(error)
    }
  }

  void loadApplications()
</script>

<template>
  <ResourceListPage
    title="应用业务动作"
    create-title="新建业务动作"
    object-hint="先声明已启用动作，再到接口目录中引用。"
    description="应用自己的稳定语义词典，和技术服务动作不是同一套。默认列表只显示名称、代码、说明、发布状态和启停。"
    endpoint="application-business-action"
    index-permission="sand_iam:api_resource:index"
    permission-prefix="sand_iam:api_resource"
    write-mode="business-action"
    disable-impact="停用后，引用该动作的接口授权按拒绝处理；代码不能改，需要新语义时请新建声明。"
    :columns="columns"
    :filters="filters"
    :form-fields="businessActionFields"
  >
    <template #extra>
      <ElDivider />
      <h3 class="mt-0 text-base font-semibold">历史待认领</h3>
      <p class="mb-3 text-sm text-gray-500">
        pending_claim 只出现在本报告。新建、变更和路由同步都不会接受未声明动作。
      </p>
      <ElAlert
        v-if="!hasAuth('sand_iam:api_resource:read')"
        class="mb-3"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看待认领报告。"
      />
      <ElAlert
        v-if="claimError"
        class="mb-3"
        type="error"
        :closable="false"
        :title="claimError.title"
        :description="claimError.detail"
      />
      <ElAlert
        v-else
        class="mb-3"
        type="info"
        :closable="false"
        title="待认领说明"
        :description="claimHint"
      />
      <ElSpace class="mb-3">
        <ElSelect
          v-model="applicationId"
          filterable
          clearable
          placeholder="按名称选择接入应用"
          style="width: 280px"
        >
          <ElOption
            v-for="row in applications"
            :key="String(row.id)"
            :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
            :value="String(row.id)"
          />
        </ElSelect>
        <ElButton :loading="loadingClaims" @click="loadClaims">加载待认领</ElButton>
      </ElSpace>
      <ElTable :data="claims" border stripe empty-text="暂无待认领动作">
        <ElTableColumn label="历史代码" min-width="180">
          <template #default="scope">{{ scope.row.code }}</template>
        </ElTableColumn>
        <ElTableColumn label="来源" min-width="180">
          <template #default="scope">{{ scope.row.sources.join('、') || '未标注' }}</template>
        </ElTableColumn>
      </ElTable>
    </template>
  </ResourceListPage>
</template>
