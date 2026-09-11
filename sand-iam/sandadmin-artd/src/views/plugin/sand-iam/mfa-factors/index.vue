<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onUnmounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import {
    confirmSandIamTotp,
    describeRuntimeAuthError,
    listSandIamRuntimeFactors,
    regenerateSandIamRecoveryCodes,
    renameSandIamRuntimeFactor,
    revokeSandIamRuntimeFactor,
    startSandIamTotp,
    type SandIamRuntimeFactor
  } from '../api/runtimeAuth'
  import type { SandIamRequestError } from '../api/types'

  type ViewState = 'idle' | 'loading' | 'empty' | 'ready' | 'forbidden' | 'error'

  const accessToken = ref('')
  const currentPassword = ref('')
  const totpName = ref('验证器')
  const totpCode = ref('')
  const factors = ref<SandIamRuntimeFactor[]>([])
  const viewState = ref<ViewState>('idle')
  const requestError = ref<SandIamRequestError | null>(null)
  const lastRequestId = ref('')
  const acting = ref(false)
  const oneTimeSecret = ref('')
  const oneTimeOtpauth = ref('')
  const pendingFactorId = ref<number | null>(null)
  const oneTimeCodes = ref<string[]>([])
  const tokenReady = computed(() => accessToken.value.trim() !== '')

  function factorTypeLabel(type: SandIamRuntimeFactor['type']): string {
    return type === 'passkey' ? '通行密钥' : '验证器'
  }

  function factorStatusLabel(status: number): string {
    return status === 1 ? '正常' : '已停用'
  }

  function clearSensitive(): void {
    oneTimeSecret.value = ''
    oneTimeOtpauth.value = ''
    oneTimeCodes.value = []
    totpCode.value = ''
    currentPassword.value = ''
  }

  async function loadFactors(): Promise<void> {
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
      const result = await listSandIamRuntimeFactors(accessToken.value)
      lastRequestId.value = result.requestId
      factors.value = result.data
      viewState.value = result.data.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      const described = describeRuntimeAuthError(error)
      requestError.value = described
      factors.value = []
      viewState.value = described.http === 403 ? 'forbidden' : 'error'
    }
  }

  async function startTotp(): Promise<void> {
    acting.value = true
    requestError.value = null
    try {
      const result = await startSandIamTotp(
        accessToken.value,
        totpName.value,
        currentPassword.value
      )
      lastRequestId.value = result.requestId
      pendingFactorId.value = result.data.factorId
      oneTimeSecret.value = result.data.secret
      oneTimeOtpauth.value = result.data.otpauthUri
      ElMessage.success('已保存')
    } catch (error: unknown) {
      requestError.value = describeRuntimeAuthError(error)
    } finally {
      acting.value = false
    }
  }

  async function confirmTotp(): Promise<void> {
    if (pendingFactorId.value === null) {
      requestError.value = describeRuntimeAuthError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先完成添加验证器第一步')
      )
      return
    }
    acting.value = true
    requestError.value = null
    try {
      const result = await confirmSandIamTotp(
        accessToken.value,
        pendingFactorId.value,
        totpCode.value
      )
      lastRequestId.value = result.requestId
      oneTimeCodes.value = result.data
      oneTimeSecret.value = ''
      oneTimeOtpauth.value = ''
      pendingFactorId.value = null
      ElMessage.success('已保存')
      await loadFactors()
    } catch (error: unknown) {
      requestError.value = describeRuntimeAuthError(error)
    } finally {
      acting.value = false
    }
  }

  async function rename(factor: SandIamRuntimeFactor): Promise<void> {
    try {
      const name = await ElMessageBox.prompt(
        `为「${factor.name}」填写新的显示名称。`,
        '重命名认证方式',
        { confirmButtonText: '保存', cancelButtonText: '取消', inputValue: factor.name }
      )
      acting.value = true
      const result = await renameSandIamRuntimeFactor(
        accessToken.value,
        factor.id,
        factor.type,
        String(name.value ?? '')
      )
      lastRequestId.value = result.requestId
      ElMessage.success('已保存')
      await loadFactors()
    } catch (error: unknown) {
      if (error === 'cancel' || error === 'close') return
      requestError.value = describeRuntimeAuthError(error)
    } finally {
      acting.value = false
    }
  }

  async function revoke(factor: SandIamRuntimeFactor): Promise<void> {
    try {
      const password = await ElMessageBox.prompt(
        `撤销「${factor.name}」后，该${factorTypeLabel(factor.type)}立即失效。请输入当前应用用户密码确认。验证器密钥和恢复码不会回显。`,
        '撤销认证方式',
        {
          confirmButtonText: '确认撤销',
          cancelButtonText: '取消',
          inputType: 'password'
        }
      )
      acting.value = true
      const result = await revokeSandIamRuntimeFactor(
        accessToken.value,
        factor.id,
        factor.type,
        String(password.value ?? '')
      )
      lastRequestId.value = result.requestId
      ElMessage.success('已撤销')
      await loadFactors()
    } catch (error: unknown) {
      if (error === 'cancel' || error === 'close') return
      requestError.value = describeRuntimeAuthError(error)
    } finally {
      acting.value = false
    }
  }

  async function regenerate(): Promise<void> {
    try {
      const password = await ElMessageBox.prompt(
        '重新生成后，旧恢复码全部作废。请输入当前应用用户密码。新恢复码只展示一次。',
        '重新生成恢复码',
        { confirmButtonText: '重新生成', cancelButtonText: '取消', inputType: 'password' }
      )
      acting.value = true
      const result = await regenerateSandIamRecoveryCodes(
        accessToken.value,
        String(password.value ?? '')
      )
      lastRequestId.value = result.requestId
      oneTimeCodes.value = result.data
      ElMessage.success('已保存')
    } catch (error: unknown) {
      if (error === 'cancel' || error === 'close') return
      requestError.value = describeRuntimeAuthError(error)
    } finally {
      acting.value = false
    }
  }

  onUnmounted(() => {
    accessToken.value = ''
    clearSensitive()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">MFA 与通行密钥</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          此处只管理当前应用用户自己的验证方式。默认列表只显示名称、类型、状态和最近使用时间；一次性密钥和恢复码只在成功后展示一次。通行密钥策略在「认证设置」高级区；本机添加通行密钥请前往应用门户。
        </p>
      </div>

      <ElForm label-width="170px" class="mb-4">
        <ElFormItem label="应用用户访问凭据">
          <ElInput v-model="accessToken" type="password" show-password autocomplete="off" />
        </ElFormItem>
        <ElFormItem>
          <ElButton
            type="primary"
            :disabled="!tokenReady"
            :loading="viewState === 'loading'"
            @click="loadFactors"
          >
            加载认证方式
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElAlert
        v-if="viewState === 'idle'"
        class="mb-4"
        type="info"
        :closable="false"
        title="尚未加载"
        description="填写当前应用用户访问凭据后再加载。没有凭据时不请求，避免把空列表误认为没有认证方式。"
      />
      <ElAlert
        v-if="viewState === 'forbidden'"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        :description="requestError?.detail ?? '当前令牌无权查看这些认证方式。'"
      />
      <ElAlert
        v-else-if="requestError !== null && viewState === 'error'"
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
        title="当前没有认证方式"
        description="该应用用户还没有验证器或通行密钥。这与没有权限不同。"
      />

      <p v-if="lastRequestId !== ''" class="mb-3 text-xs text-gray-400"
        >请求编号：{{ lastRequestId }}</p
      >

      <ElTable
        v-loading="viewState === 'loading' || acting"
        :data="factors"
        border
        stripe
        empty-text="暂无可见认证方式"
      >
        <ElTableColumn label="名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="类型" min-width="120">
          <template #default="scope">{{ factorTypeLabel(scope.row.type) }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ factorStatusLabel(scope.row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn label="最近使用" min-width="180">
          <template #default="scope">{{ scope.row.last_used_time ?? '尚未使用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180" fixed="right">
          <template #default="scope">
            <ElSpace>
              <ElButton size="small" @click="rename(scope.row)">重命名</ElButton>
              <ElButton size="small" type="warning" @click="revoke(scope.row)">撤销</ElButton>
            </ElSpace>
          </template>
        </ElTableColumn>
      </ElTable>

      <ElDivider />
      <h3 class="mt-0 text-base font-semibold">添加验证器</h3>
      <ElForm label-width="170px">
        <ElFormItem label="显示名称">
          <ElInput v-model="totpName" />
        </ElFormItem>
        <ElFormItem label="当前密码">
          <ElInput v-model="currentPassword" type="password" show-password autocomplete="off" />
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!tokenReady" @click="startTotp">开始添加</ElButton>
        </ElFormItem>
        <ElFormItem v-if="oneTimeSecret !== ''" label="一次性密钥">
          <ElInput :model-value="oneTimeSecret" type="textarea" readonly />
          <p class="mb-0 mt-1 text-xs text-gray-500"
            >关闭或刷新后无法再看到。不要写入日志或截图文件名。</p
          >
        </ElFormItem>
        <ElFormItem v-if="oneTimeOtpauth !== ''" label="验证器扫码链接">
          <ElInput :model-value="oneTimeOtpauth" type="textarea" readonly />
        </ElFormItem>
        <ElFormItem v-if="pendingFactorId !== null" label="6 位验证码">
          <ElInput v-model="totpCode" maxlength="6" />
        </ElFormItem>
        <ElFormItem v-if="pendingFactorId !== null">
          <ElButton type="primary" @click="confirmTotp">确认绑定</ElButton>
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!tokenReady" @click="regenerate">重新生成恢复码</ElButton>
        </ElFormItem>
        <ElFormItem v-if="oneTimeCodes.length > 0" label="一次性恢复码">
          <ElInput :model-value="oneTimeCodes.join('\n')" type="textarea" :rows="6" readonly />
        </ElFormItem>
      </ElForm>
    </ElCard>
  </div>
</template>
