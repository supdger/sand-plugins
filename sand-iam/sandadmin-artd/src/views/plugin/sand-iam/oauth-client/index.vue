<script setup lang="ts">
  import { onMounted, ref } from 'vue'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import { oauthClientFields } from '../api/fields'
  import { parseOidcSigningStatus } from '../api/governanceContracts'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'
  import { getSandIamAdmin } from '../api/write'
  import ResourceListPage from '../components/ResourceListPage.vue'

  const { hasAuth } = useAuth()
  const signingHint = ref('尚未加载签发密钥状态。')
  const signingForbidden = ref(false)

  const columns: SandIamResourceColumn[] = [
    { key: 'name', label: '客户端名称', minWidth: 180 },
    { key: 'application_id', label: '所属接入应用', minWidth: 180 },
    { key: 'client_type', label: '类型' },
    { key: 'redirect_uris', label: '回调地址摘要', minWidth: 140 },
    { key: 'allowed_scopes', label: '范围摘要' },
    { key: 'frontchannel_logout_uri', label: '前通道登出' },
    { key: 'backchannel_logout_uri', label: '后通道登出' },
    { key: 'secret_version', label: '客户端密钥状态' },
    { key: 'status', label: '状态' },
    { key: 'code', label: '系统代码（用于接口配置）', minWidth: 180, copyable: true }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'organization_id', 'application_id', 'status']

  /**
   * 签发密钥是 issuer 级，不是每个客户端一行；无权与未配置必须分开。
   */
  async function loadSigningStatus(): Promise<void> {
    if (!hasAuth('sand_iam:oauth_client:read')) {
      signingForbidden.value = true
      signingHint.value = '当前账号无权查看签发密钥状态。请联系平台管理员开通客户端详情查看权限。'
      return
    }
    try {
      const status = parseOidcSigningStatus(await getSandIamAdmin('oidc-signing-key/status'))
      if (status === null) {
        signingHint.value = '签发密钥状态返回格式不符合已冻结约定。'
        return
      }
      if (status.activeCount === 0) {
        signingHint.value = status.legacyDeploymentKey
          ? '当前没有独立签发密钥，仍可能使用部署遗留密钥。这不是客户端密钥。'
          : '当前没有生效的签发密钥。'
        return
      }
      signingHint.value = `签发范围：整个 issuer。当前生效密钥状态：${
        status.activeState ?? '正常'
      }。私钥和 JWK 原文不在本页展示。`
    } catch (error: unknown) {
      const described = describeSandIamError(error)
      signingForbidden.value = described.http === 403
      signingHint.value = described.detail
    }
  }

  onMounted(() => {
    void loadSigningStatus()
  })
</script>

<template>
  <ResourceListPage
    title="OAuth 客户端"
    create-title="新建 OAuth 客户端"
    object-hint="机密客户端的密钥只在创建或轮换成功后展示一次。完整回调和登出地址在表单高级区，不进入默认列表。"
    description="登记接入应用的标准 OAuth/OIDC 客户端。默认列表只显示名称、所属应用、类型、回调摘要、范围摘要、前/后通道是否已配置、密钥是否已配置和状态。公开客户端没有密钥；原生客户端可用本机回环 HTTP。"
    endpoint="oauth-client"
    index-permission="sand_iam:oauth_client:index"
    permission-prefix="sand_iam:oauth_client"
    write-mode="oauth-client"
    disable-impact="停用后，该客户端不能再申请新令牌；已签发的访问令牌仍按各自有效期失效。客户端密钥不会回显。"
    :columns="columns"
    :filters="filters"
    :form-fields="oauthClientFields"
  >
    <template #extra>
      <ElAlert
        class="mt-4"
        :type="signingForbidden ? 'warning' : 'info'"
        :closable="false"
        :title="signingForbidden ? '没有权限' : '签发密钥状态'"
        :description="signingHint"
      />
    </template>
  </ResourceListPage>
</template>
