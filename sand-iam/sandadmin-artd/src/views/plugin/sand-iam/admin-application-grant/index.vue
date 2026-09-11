<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import {
    parseSandIamAdminOptions,
    parseSandIamApplicationGrants,
    type SandIamAdminOption,
    type SandIamApplicationGrantRow
  } from '../api/delegationContracts'
  import { describeSandIamError } from '../api/errors'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:admin_application_grant:index'))
  const canSave = computed(() => hasAuth('sand_iam:admin_application_grant:save'))
  const canDisable = computed(() => hasAuth('sand_iam:admin_application_grant:disable'))

  const loading = ref(false)
  const grants = ref<SandIamApplicationGrantRow[]>([])
  const applications = ref<SandIamResourceRow[]>([])
  const adminOptions = ref<SandIamAdminOption[]>([])
  const adminKeywords = ref('')
  const selectedAdminId = ref('')
  const selectedApplicationId = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    if (isRecord(value.data) && Array.isArray(value.data.data)) {
      return value.data.data.filter(isRecord)
    }
    return []
  }

  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function applicationName(applicationId: number): string {
    const row = applications.value.find((item) => item.id === applicationId)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  /**
   * 接入应用按名称选择；提交只用 application_id。
   */
  async function loadApplications(): Promise<void> {
    try {
      const result = await listSandIamResource('application', {
        page: 1,
        limit: 100
      })
      applications.value = listRows(result)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  /**
   * 管理员名称至少 2 字才请求 admin-options，禁止手填编号作为最终方案。
   */
  async function searchAdmins(keywords: string): Promise<void> {
    adminKeywords.value = keywords
    if (keywords.trim().length < 2) {
      adminOptions.value = []
      return
    }
    try {
      const result = await getSandIamAdmin('admin-application-grant/admin-options', {
        keywords: keywords.trim()
      })
      adminOptions.value = parseSandIamAdminOptions(result)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  async function loadGrants(): Promise<void> {
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      const result = await listSandIamResource('admin-application-grant', {
        page: 1,
        limit: 100
      })
      const rows = parseSandIamApplicationGrants(result)
      grants.value = rows
      viewState.value = rows.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      grants.value = []
    } finally {
      loading.value = false
    }
  }

  async function saveGrant(): Promise<void> {
    const adminUserId = selectedId(selectedAdminId.value)
    const applicationId = selectedId(selectedApplicationId.value)
    if (adminUserId === null || applicationId === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择后台管理员和接入应用')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('admin-application-grant/save', {
        admin_user_id: adminUserId,
        application_id: applicationId,
        status: 1
      })
      ElMessage.success('已保存')
      selectedAdminId.value = ''
      selectedApplicationId.value = ''
      await loadGrants()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableGrant(row: SandIamApplicationGrantRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.admin_user_name}」对「${applicationName(row.application_id)}」的委派吗？停用后该管理员立即失去此应用管理范围。`,
        '停用应用委派',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('admin-application-grant/disable', { id: row.id })
      ElMessage.success('已停用')
      await loadGrants()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadApplications()
    void loadGrants()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">应用管理员委派</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          把接入应用交给后台管理员。按名称搜索管理员并选择应用；默认列表只显示管理员名称、接入应用和状态。应用管理员不能给自己扩范围。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看应用管理员委派。请联系平台管理员开通应用管理员委派查看权限。"
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
        description="还没有应用管理员委派。这与没有权限不同。"
      />

      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="后台管理员">
          <ElSelect
            v-model="selectedAdminId"
            filterable
            remote
            clearable
            placeholder="输入至少 2 个字按名称搜索"
            :remote-method="searchAdmins"
          >
            <ElOption
              v-for="option in adminOptions"
              :key="String(option.id)"
              :label="`${option.name}（${option.username}）`"
              :value="String(option.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="可管理的接入应用">
          <ElSelect v-model="selectedApplicationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canSave" :loading="loading" @click="saveGrant">
            保存委派
          </ElButton>
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadGrants">刷新</ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="grants" border stripe empty-text="暂无可见委派">
        <ElTableColumn label="后台管理员" min-width="180">
          <template #default="scope">{{ scope.row.admin_user_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="接入应用" min-width="180">
          <template #default="scope">{{ applicationName(scope.row.application_id) }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="100">
          <template #default="scope">{{ scope.row.status === 1 ? '正常' : '已停用' }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="120" fixed="right">
          <template #default="scope">
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable"
              @click="disableGrant(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>
