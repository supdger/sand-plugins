<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    invitationCanResend,
    invitationCanRevoke,
    invitationStateLabel,
    parseSandIamInvitations,
    type SandIamInvitationRow
  } from '../api/invitationContracts'
  import {
    parseSandIamIdentities,
    parseSandIamIdentityGroups,
    type SandIamIdentityGroupRow,
    type SandIamIdentityRow
  } from '../api/identityLifecycleContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:identity_invitation:index'))
  const canSend = computed(() => hasAuth('sand_iam:identity_invitation:send'))
  const canResend = computed(() => hasAuth('sand_iam:identity_invitation:resend'))
  const canRevoke = computed(() => hasAuth('sand_iam:identity_invitation:revoke'))

  const loading = ref(false)
  const applications = ref<SandIamResourceRow[]>([])
  const groups = ref<SandIamIdentityGroupRow[]>([])
  const guests = ref<SandIamIdentityRow[]>([])
  const invitations = ref<SandIamInvitationRow[]>([])
  const applicationId = ref('')
  const targetType = ref<'email' | 'phone'>('email')
  const target = ref('')
  const ttlHours = ref('72')
  const selectedGroupIds = ref<string[]>([])
  const guestIdentityId = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
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

  function selectedApplicationName(): string {
    const id = selectedId(applicationId.value)
    if (id === null) return '请先选择接入应用'
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
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
   * 发送表单的用户组和访客都按本应用名称加载，禁止手填 ID。
   */
  async function loadContext(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null) return
    try {
      groups.value = parseSandIamIdentityGroups(
        await getSandIamAdmin('identity-group/index', { application_id: application })
      )
      guests.value = parseSandIamIdentities(
        await listSandIamResource('identity', {
          page: 1,
          limit: 100,
          application_id: application
        })
      ).filter((row) => row.lifecycle_state === 'guest')
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  async function loadInvitations(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      invitations.value = parseSandIamInvitations(
        await getSandIamAdmin('identity-invitation/index', { application_id: application })
      )
      viewState.value = invitations.value.length === 0 ? 'empty' : 'ready'
      await loadContext()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      invitations.value = []
    } finally {
      loading.value = false
    }
  }

  async function sendInvitation(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null || target.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写邮箱或手机号')
      )
      return
    }
    const hours = Number(ttlHours.value)
    if (!Number.isInteger(hours) || hours < 1 || hours > 168) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 有效期须为 1 至 168 小时')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const guest = selectedId(guestIdentityId.value)
      await postSandIamAction('identity-invitation/send', {
        application_id: application,
        target_type: targetType.value,
        target: target.value.trim(),
        ttl_hours: hours,
        initial_group_ids: selectedGroupIds.value
          .map((item) => Number(item))
          .filter((item) => Number.isInteger(item) && item > 0),
        ...(guest === null ? {} : { guest_identity_id: guest })
      })
      ElMessage.success('已保存')
      target.value = ''
      guestIdentityId.value = ''
      await loadInvitations()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function resendInvitation(row: SandIamInvitationRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认重发给「${row.target_masked}」吗？旧邀请链接将立即失效。`,
        '重发邀请',
        { type: 'warning', confirmButtonText: '确认重发', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-invitation/resend', { id: row.id })
      ElMessage.success('已保存')
      await loadInvitations()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function revokeInvitation(row: SandIamInvitationRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认撤销「${row.target_masked}」的邀请吗？撤销后不能重放。`,
        '撤销邀请',
        { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-invitation/revoke', { id: row.id })
      ElMessage.success('已停用')
      await loadInvitations()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
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
        <h2 class="m-0 text-lg font-semibold">用户邀请</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          按接入应用邀请邮箱或手机号。列表只显示脱敏目标和用户组名称，不展示完整联系方式、token
          或密文。接受后用户需自行登录，不会获得后台会话。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看邀请。请联系平台管理员开通邀请查看权限。"
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
        description="该接入应用还没有邀请。这与没有权限不同。"
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
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadInvitations">
            加载邀请
          </ElButton>
        </ElFormItem>
        <ElFormItem label="接收方式">
          <ElSelect v-model="targetType">
            <ElOption label="邮箱" value="email" />
            <ElOption label="手机号" value="phone" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="targetType === 'phone' ? '手机号' : '邮箱'">
          <ElInput v-model="target" autocomplete="off" />
        </ElFormItem>
        <ElFormItem label="有效小时">
          <ElInput v-model="ttlHours" />
        </ElFormItem>
        <ElFormItem label="初始用户组">
          <ElSelect
            v-model="selectedGroupIds"
            multiple
            filterable
            clearable
            placeholder="按名称选择"
          >
            <ElOption
              v-for="row in groups"
              :key="String(row.id)"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="升级访客">
          <ElSelect
            v-model="guestIdentityId"
            filterable
            clearable
            placeholder="可选，按名称选择本应用访客"
          >
            <ElOption
              v-for="row in guests"
              :key="String(row.id)"
              :label="row.display_name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canSend" :loading="loading" @click="sendInvitation">
            发送邀请
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="invitations" border stripe empty-text="暂无可见邀请">
        <ElTableColumn label="脱敏接收目标" min-width="180">
          <template #default="scope">{{ scope.row.target_masked }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="140">
          <template #default>{{ selectedApplicationName() }}</template>
        </ElTableColumn>
        <ElTableColumn label="用户组" min-width="160">
          <template #default="scope">
            {{ scope.row.initial_group_names.join('、') || '未指定用户组' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="邀请状态" min-width="110">
          <template #default="scope">{{ invitationStateLabel(scope.row.state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="到期时间" min-width="160">
          <template #default="scope">{{ scope.row.expire_time ?? '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="invitationCanResend(scope.row.state)"
              size="small"
              :disabled="!canResend"
              @click="resendInvitation(scope.row)"
            >
              重发
            </ElButton>
            <ElButton
              v-if="invitationCanRevoke(scope.row.state)"
              size="small"
              type="warning"
              :disabled="!canRevoke"
              @click="revokeInvitation(scope.row)"
            >
              撤销
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>
