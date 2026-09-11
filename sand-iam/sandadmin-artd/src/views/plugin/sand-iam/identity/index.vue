<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    IDENTITY_LOGIN_IDENTIFIER_UNAVAILABLE,
    identityCanDelete,
    identityCanDisable,
    identityCanEnable,
    identityCanRestore,
    identityDeleteImpact,
    identityDisableImpact,
    identityGroupNamesByMember,
    identityGroupSummaryLabel,
    lifecycleStateLabel,
    parseSandIamIdentities,
    parseSandIamIdentityGroupMembers,
    parseSandIamIdentityGroups,
    type SandIamIdentityGroupMember,
    type SandIamIdentityRow
  } from '../api/identityLifecycleContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction, saveSandIamResource } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:identity:index'))
  const canSave = computed(() => hasAuth('sand_iam:identity:save'))
  const canDisable = computed(() => hasAuth('sand_iam:identity:disable'))
  const canEnable = computed(() => hasAuth('sand_iam:identity:enable'))
  const canDelete = computed(() => hasAuth('sand_iam:identity:delete'))
  const canRestore = computed(() => hasAuth('sand_iam:identity:restore'))
  const canGroupIndex = computed(() => hasAuth('sand_iam:identity_group:index'))
  const canMemberIndex = computed(() => hasAuth('sand_iam:identity_group_member:index'))

  const loading = ref(false)
  const applications = ref<SandIamResourceRow[]>([])
  const identities = ref<SandIamIdentityRow[]>([])
  const groupNamesByIdentity = ref<ReadonlyMap<number, readonly string[]>>(new Map())
  const groupSummaryAvailable = ref(false)
  const applicationId = ref('')
  const displayName = ref('')
  const code = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const groupSummaryError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')

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

  function applicationName(id: number): string {
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  function groupSummary(row: SandIamIdentityRow): string {
    if (selectedId(applicationId.value) === null) return '请先选择接入应用'
    return identityGroupSummaryLabel(
      row.id,
      groupNamesByIdentity.value,
      groupSummaryAvailable.value
    )
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
   * 组摘要来自 identity-group/members，不是 identity 列表字段。
   * 无权或生命周期未启用必须单独提示，不能把失败写成“未加入用户组”。
   */
  async function loadGroupSummaries(application: number): Promise<void> {
    groupSummaryError.value = null
    groupNamesByIdentity.value = new Map()
    groupSummaryAvailable.value = false
    if (!canGroupIndex.value || !canMemberIndex.value) return
    try {
      const groups = parseSandIamIdentityGroups(
        await getSandIamAdmin('identity-group/index', { application_id: application })
      )
      const membersByGroupId: Record<number, SandIamIdentityGroupMember[]> = {}
      for (const group of groups) {
        membersByGroupId[group.id] = parseSandIamIdentityGroupMembers(
          await getSandIamAdmin('identity-group/members', { id: group.id })
        )
      }
      groupNamesByIdentity.value = identityGroupNamesByMember(groups, membersByGroupId)
      groupSummaryAvailable.value = true
    } catch (error: unknown) {
      groupSummaryError.value = describeSandIamError(error)
    }
  }

  async function loadIdentities(): Promise<void> {
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const application = selectedId(applicationId.value)
      const result = await listSandIamResource('identity', {
        page: 1,
        limit: 100,
        ...(application === null ? {} : { application_id: application })
      })
      const rows = parseSandIamIdentities(result)
      identities.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
      if (application !== null) await loadGroupSummaries(application)
      else groupSummaryAvailable.value = false
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      identities.value = []
    } finally {
      loading.value = false
    }
  }

  async function createIdentity(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null || displayName.value.trim() === '' || code.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择接入应用并填写显示名称和系统代码')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await saveSandIamResource('identity', {
        application_id: application,
        display_name: displayName.value.trim(),
        code: code.value.trim(),
        status: 1
      })
      ElMessage.success('已保存')
      displayName.value = ''
      code.value = ''
      await loadIdentities()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  /**
   * 危险操作确认框写清会撤销什么、恢复不会恢复什么；成功文案不用“操作成功”。
   */
  async function act(
    row: SandIamIdentityRow,
    path: string,
    title: string,
    impact: string,
    success: string
  ): Promise<void> {
    try {
      await ElMessageBox.confirm(impact, title, {
        type: 'warning',
        confirmButtonText: '确认',
        cancelButtonText: '取消'
      })
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction(path, { id: row.id })
      ElMessage.success(success)
      await loadIdentities()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadApplications()
    void loadIdentities()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">应用用户</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          账号状态显示为等待邀请/正常/已停用/访客/已删除，不把 status=1/2
          直接给人看。当前列表尚未提供主要登录标识的脱敏值，因此本列不显示猜测内容。用户组摘要仅在按名称选中接入应用后，用用户组成员信息组合。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看应用用户。请联系平台管理员开通应用用户查看权限。"
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
        description="所选接入应用还没有应用用户。这与没有权限不同。"
      />
      <ElAlert
        v-if="groupSummaryError"
        class="mb-4"
        type="warning"
        :closable="false"
        :title="groupSummaryError.title"
        :description="groupSummaryError.detail"
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
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadIdentities"
            >加载用户</ElButton
          >
        </ElFormItem>
        <ElFormItem label="显示名称">
          <ElInput v-model="displayName" />
        </ElFormItem>
        <ElFormItem label="系统代码">
          <ElInput v-model="code" />
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canSave" :loading="loading" @click="createIdentity">
            新建用户
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="identities" border stripe empty-text="暂无可见用户">
        <ElTableColumn label="应用用户名称" min-width="160">
          <template #default="scope">{{ scope.row.display_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="160">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="账号状态" min-width="120">
          <template #default="scope">{{ lifecycleStateLabel(scope.row.lifecycle_state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="主要登录标识" min-width="180">
          <template #default>{{ IDENTITY_LOGIN_IDENTIFIER_UNAVAILABLE }}</template>
        </ElTableColumn>
        <ElTableColumn label="用户组摘要" min-width="180">
          <template #default="scope">{{ groupSummary(scope.row) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="260" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="identityCanDisable(scope.row)"
              size="small"
              type="warning"
              :disabled="!canDisable"
              @click="
                act(
                  scope.row,
                  'identity/disable',
                  '停用应用用户',
                  identityDisableImpact(scope.row.lifecycle_state),
                  '已停用'
                )
              "
            >
              停用
            </ElButton>
            <ElButton
              v-if="identityCanEnable(scope.row)"
              size="small"
              :disabled="!canEnable"
              @click="
                act(
                  scope.row,
                  'identity/enable',
                  '启用应用用户',
                  '启用后该用户可以重新登录，但停用前的会话不会恢复。',
                  '已保存'
                )
              "
            >
              启用
            </ElButton>
            <ElButton
              v-if="identityCanDelete(scope.row)"
              size="small"
              type="danger"
              :disabled="!canDelete"
              @click="
                act(scope.row, 'identity/delete', '删除应用用户', identityDeleteImpact(), '已保存')
              "
            >
              删除
            </ElButton>
            <ElButton
              v-if="identityCanRestore(scope.row)"
              size="small"
              :disabled="!canRestore"
              @click="
                act(
                  scope.row,
                  'identity/restore',
                  '恢复应用用户',
                  '恢复后旧会话、旧令牌和已移除的授权关系不会恢复。',
                  '已保存'
                )
              "
            >
              恢复
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>
