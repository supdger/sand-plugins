<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onUnmounted, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import {
    describeRuntimeAuthError,
    listSandIamRuntimeSessions,
    revokeSandIamRuntimeSession,
    type SandIamRuntimeSession
  } from '../api/runtimeAuth'
  import type { SandIamRequestError } from '../api/types'

  type SessionViewState = 'idle' | 'loading' | 'empty' | 'ready' | 'forbidden' | 'error'

  const accessToken = ref('')
  const sessions = ref<SandIamRuntimeSession[]>([])
  const viewState = ref<SessionViewState>('idle')
  const requestError = ref<SandIamRequestError | null>(null)
  const lastRequestId = ref('')
  const detailOpen = ref(false)
  const detailSession = ref<SandIamRuntimeSession | null>(null)
  const acting = ref(false)
  let disposed = false
  let tokenVersion = 0
  let listVersion = 0

  watch(accessToken, () => {
    tokenVersion++
    listVersion++
    sessions.value = []
    detailSession.value = null
    detailOpen.value = false
    requestError.value = null
    lastRequestId.value = ''
    viewState.value = 'idle'
  }, { flush: 'sync' })

  const tokenReady = computed(() => accessToken.value.trim() !== '')

  /**
   * 离开页面时丢掉内存中的 access token，避免把它写进本地存储或日志。
   */
  function clearToken(): void {
    accessToken.value = ''
  }

  function formatTime(value: string): string {
    return value
  }

  function sessionKind(session: SandIamRuntimeSession): string {
    return session.current ? '当前会话' : '其他设备'
  }

  async function loadSessions(): Promise<void> {
    if (disposed) return
    const token = accessToken.value
    const version = ++listVersion
    const current = () => !disposed && version === listVersion && token === accessToken.value
    detailSession.value = null
    detailOpen.value = false
    if (!tokenReady.value) {
      viewState.value = 'error'
      requestError.value = describeRuntimeAuthError(
        new Error('SAND_IAM_AUTHENTICATION_FAILED: 请先填写当前应用用户的访问凭据')
      )
      return
    }
    viewState.value = 'loading'
    requestError.value = null
    try {
      const result = await listSandIamRuntimeSessions(token)
      if (!current()) return
      lastRequestId.value = result.requestId
      sessions.value = result.data
      viewState.value = result.data.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      if (!current()) return
      const described = describeRuntimeAuthError(error)
      requestError.value = described
      sessions.value = []
      if (described.http === 403) {
        viewState.value = 'forbidden'
        return
      }
      viewState.value = 'error'
    }
  }

  function openDetail(session: SandIamRuntimeSession): void {
    if (disposed || viewState.value === 'loading' || !sessions.value.includes(session)) return
    detailSession.value = session
    detailOpen.value = true
  }

  async function revoke(session: SandIamRuntimeSession): Promise<void> {
    if (disposed || acting.value || !tokenReady.value || viewState.value === 'loading' ||
      !sessions.value.includes(session)) return
    const token = accessToken.value
    const version = tokenVersion
    const query = listVersion
    const current = () => !disposed && version === tokenVersion && token === accessToken.value
    const validRow = () => current() && query === listVersion && sessions.value.includes(session)
    acting.value = true
    const impact = session.current
      ? '这是当前访问凭据对应的会话。撤销后本页凭据立即失效，需要重新取得新的访问凭据。'
      : '撤销后该设备需要重新登录；当前令牌仍可用于继续查看其余会话。'
    try {
      await ElMessageBox.confirm(`确认撤销「${sessionKind(session)}」吗？${impact}`, '撤销会话', {
        type: 'warning',
        confirmButtonText: '确认撤销',
        cancelButtonText: '取消'
      })
    } catch {
      acting.value = false
      return
    }
    if (!validRow()) {
      acting.value = false
      return
    }
    requestError.value = null
    try {
      const result = await revokeSandIamRuntimeSession(token, session.id)
      if (!current()) return
      if (!session.current && !validRow()) return
      lastRequestId.value = result.requestId
      ElMessage.success('已撤销')
      if (session.current) {
        clearToken()
        viewState.value = 'empty'
        return
      }
      if (validRow()) await loadSessions()
    } catch (error: unknown) {
      if (!validRow()) return
      const described = describeRuntimeAuthError(error)
      requestError.value = described
      if (described.http === 403) viewState.value = 'forbidden'
      else viewState.value = 'error'
    } finally {
      acting.value = false
    }
  }

  onUnmounted(() => {
    disposed = true
    listVersion++
    clearToken()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">应用用户会话</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          此处只管理当前应用用户自己的有效会话，不能代替用户管理其他人的设备。访问凭据只留在本页内存，关闭页面即丢弃。
        </p>
      </div>

      <ElAlert
        class="mb-4"
        type="info"
        :closable="false"
        title="下一步"
        description="先到「用户目录」确认应用用户状态，再使用该用户自己的访问凭据查看设备并撤销异常会话。"
      />

      <ElForm label-width="160px" class="mb-4" @submit.prevent="loadSessions">
        <ElFormItem label="应用用户访问凭据">
          <ElInput
            v-model="accessToken"
            type="password"
            show-password
            autocomplete="off"
            placeholder="粘贴当前应用用户的访问凭据，不要填写后台登录凭据"
          />
          <p class="mb-0 mt-1 text-xs text-gray-500">
            不要把访问凭据写入备注、截图文件名或浏览器地址栏。每次操作都会生成新的请求编号。
          </p>
        </ElFormItem>
        <ElFormItem>
          <ElButton
            type="primary"
            :loading="viewState === 'loading'"
            :disabled="!tokenReady"
            @click="loadSessions"
          >
            加载会话
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="viewState === 'idle'"
        class="mb-4"
        type="info"
        :closable="false"
        title="尚未加载"
        description="填写当前应用用户的访问凭据后点击「加载会话」。没有凭据时不会请求，避免把空列表误认为没有会话。"
      />

      <ElAlert
        v-if="viewState === 'forbidden'"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        :description="
          requestError?.detail ??
          '当前访问凭据无权查看这些会话。请确认它属于应用用户，而不是后台登录会话。'
        "
      />

      <ElAlert
        v-else-if="requestError !== null && viewState === 'error'"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="`${requestError.detail}${lastRequestId !== '' ? ` 请求编号：${lastRequestId}` : ''}`"
      />

      <ElAlert
        v-else-if="viewState === 'empty'"
        class="mb-4"
        type="info"
        :closable="false"
        title="当前没有有效会话"
        description="该应用用户没有仍在有效期内的会话，或当前会话刚被撤销。这与「没有权限」不同。"
      />

      <p v-if="lastRequestId !== ''" class="mb-3 text-xs text-gray-400">
        请求编号：{{ lastRequestId }}
      </p>

      <ElTable
        v-loading="viewState === 'loading' || acting"
        :data="sessions"
        border
        stripe
        empty-text="暂无可见会话"
      >
        <ElTableColumn label="会话" min-width="120">
          <template #default="scope">
            {{ sessionKind(scope.row) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="最近活动" min-width="180">
          <template #default="scope">
            {{ formatTime(scope.row.last_used_time) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="登录时间" min-width="180">
          <template #default="scope">
            {{ formatTime(scope.row.create_time) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180" fixed="right">
          <template #default="scope">
            <ElSpace>
              <ElButton size="small" @click="openDetail(scope.row)">详情</ElButton>
              <ElButton size="small" type="warning" :disabled="acting" @click="revoke(scope.row)">撤销</ElButton>
            </ElSpace>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <ElDrawer v-model="detailOpen" title="会话详情" size="420px">
      <template v-if="detailSession !== null">
        <p class="mt-0 text-sm text-gray-600"
          >默认列表只显示是否当前会话和活动时间。编号与过期时间放在这里，不展示 token。</p
        >
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="会话">
            {{ sessionKind(detailSession) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="最近活动">
            {{ formatTime(detailSession.last_used_time) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="登录时间">
            {{ formatTime(detailSession.create_time) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="访问令牌过期">
            {{ formatTime(detailSession.access_expire_time) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="刷新令牌过期">
            {{ formatTime(detailSession.refresh_expire_time) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="会话编号">
            {{ String(detailSession.id) }}
          </ElDescriptionsItem>
        </ElDescriptions>
      </template>
    </ElDrawer>
  </div>
</template>
