<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    lifecycleStateLabel,
    parseSandIamIdentities,
    parseSandIamIdentityGroupMembers,
    parseSandIamIdentityGroupRoles,
    parseSandIamIdentityGroups,
    parseSandIamRoleOptions,
    selectableGroupRoles,
    selectableGroupIdentities,
    type SandIamIdentityGroupMember,
    type SandIamIdentityGroupRole,
    type SandIamIdentityGroupRow,
    type SandIamIdentityRow,
    type SandIamRoleOption
  } from '../api/identityLifecycleContracts'
  import { listSandIamResource } from '../api/resource'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:identity_group:index'))
  const canSave = computed(() => hasAuth('sand_iam:identity_group:save'))
  const canDisable = computed(() => hasAuth('sand_iam:identity_group:disable'))
  const canMemberIndex = computed(() => hasAuth('sand_iam:identity_group_member:index'))
  const canMemberAdd = computed(() => hasAuth('sand_iam:identity_group_member:add'))
  const canMemberRemove = computed(() => hasAuth('sand_iam:identity_group_member:remove'))
  const canRoleIndex = computed(() => hasAuth('sand_iam:identity_group_role:index'))
  const canRoleGrant = computed(() => hasAuth('sand_iam:identity_group_role:grant'))
  const canRoleRevoke = computed(() => hasAuth('sand_iam:identity_group_role:revoke'))
  const canRoleResourceIndex = computed(() => hasAuth('sand_iam:role:index'))
  const canIdentityIndex = computed(() => hasAuth('sand_iam:identity:index'))

  const loading = ref(false)
  const applications = ref<SandIamResourceRow[]>([])
  const groups = ref<SandIamIdentityGroupRow[]>([])
  const identities = ref<SandIamIdentityRow[]>([])
  const members = ref<SandIamIdentityGroupMember[]>([])
  const roles = ref<SandIamRoleOption[]>([])
  const groupRoles = ref<SandIamIdentityGroupRole[]>([])
  const applicationId = ref('')
  const name = ref('')
  const code = ref('')
  const parentId = ref('')
  const selectedGroupId = ref('')
  const memberIdentityId = ref('')
  const roleId = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const groupsReadError = ref<SandIamRequestError | null>(null)
  const identitiesReadError = ref<SandIamRequestError | null>(null)
  const rolesReadError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')

  const memberCandidates = computed(() => selectableGroupIdentities(identities.value))
  const roleCandidates = computed(() => {
    const application = selectedId(applicationId.value)
    return application === null ? [] : selectableGroupRoles(roles.value, application)
  })
  const selectedGroup = computed(() => {
    const group = selectedId(selectedGroupId.value)
    return group === null ? null : (groups.value.find((item) => item.id === group) ?? null)
  })

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

  function groupStatusLabel(status: number): string {
    return status === 1 ? '正常' : '已停用'
  }

  function groupRoleStatusLabel(binding: SandIamIdentityGroupRole): string {
    if (binding.status !== 1) return '已撤销'
    return binding.role_status === 1 ? '已授予' : '角色已停用'
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
   * 用户组接口要求 application_id；成员候选只加载当前应用，从根上拒绝跨应用选择。
   */
  async function loadGroups(): Promise<void> {
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
    groupsReadError.value = null
    identitiesReadError.value = null
    rolesReadError.value = null
    members.value = []
    groupRoles.value = []
    selectedGroupId.value = ''
    memberIdentityId.value = ''
    roleId.value = ''

    try {
      await Promise.all([
        (async (): Promise<void> => {
          try {
            groups.value = parseSandIamIdentityGroups(
              await getSandIamAdmin('identity-group/index', { application_id: application })
            )
            viewState.value = groups.value.length === 0 ? 'empty' : 'ready'
          } catch (error: unknown) {
            groups.value = []
            viewState.value = 'idle'
            groupsReadError.value = describeSandIamError(error)
          }
        })(),
        (async (): Promise<void> => {
          if (!canIdentityIndex.value) {
            identities.value = []
            return
          }
          try {
            identities.value = parseSandIamIdentities(
              await listSandIamResource('identity', {
                page: 1,
                limit: 100,
                application_id: application
              })
            )
          } catch (error: unknown) {
            identities.value = []
            identitiesReadError.value = describeSandIamError(error)
          }
        })(),
        (async (): Promise<void> => {
          if (!canRoleResourceIndex.value) {
            roles.value = []
            return
          }
          try {
            roles.value = parseSandIamRoleOptions(
              await listSandIamResource('role', {
                page: 1,
                limit: 100,
                application_id: application
              })
            )
          } catch (error: unknown) {
            roles.value = []
            rolesReadError.value = describeSandIamError(error)
          }
        })()
      ])
    } finally {
      loading.value = false
    }
  }

  async function createGroup(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null || name.value.trim() === '' || code.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写用户组名称和系统代码')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const parent = selectedId(parentId.value)
      await postSandIamAction('identity-group/save', {
        application_id: application,
        name: name.value.trim(),
        code: code.value.trim(),
        parent_id: parent
      })
      ElMessage.success('已保存')
      name.value = ''
      code.value = ''
      await loadGroups()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableGroup(row: SandIamIdentityGroupRow): Promise<void> {
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？如仍有关联内容，页面会提示下一步。`,
        '停用用户组',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/disable', { id: row.id })
      ElMessage.success('已停用')
      await loadGroups()
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function loadMembers(row: SandIamIdentityGroupRow): Promise<void> {
    selectedGroupId.value = String(row.id)
    groupRoles.value = []
    roleId.value = ''
    if (!canMemberIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      members.value = parseSandIamIdentityGroupMembers(
        await getSandIamAdmin('identity-group/members', { id: row.id })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function loadGroupRoles(row: SandIamIdentityGroupRow): Promise<void> {
    selectedGroupId.value = String(row.id)
    members.value = []
    memberIdentityId.value = ''
    roleId.value = ''
    if (!canRoleIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      groupRoles.value = parseSandIamIdentityGroupRoles(
        await getSandIamAdmin('identity-group-role/index', {
          identity_group_id: row.id
        })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      groupRoles.value = []
    } finally {
      loading.value = false
    }
  }

  async function grantRole(): Promise<void> {
    const group = selectedId(selectedGroupId.value)
    const role = selectedId(roleId.value)
    const application = selectedId(applicationId.value)
    const selectedRole = roles.value.find((item) => item.id === role)
    const selectedGroupRow = groups.value.find((item) => item.id === group)
    if (
      group === null ||
      role === null ||
      application === null ||
      selectedGroupRow === undefined ||
      selectedRole === undefined
    ) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择用户组和角色')
      )
      return
    }
    if (
      selectedGroupRow.application_id !== application ||
      selectedRole.application_id !== application
    ) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 用户组和角色必须属于当前接入应用')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group-role/grant', {
        identity_group_id: group,
        role_id: role
      })
      ElMessage.success('已授予角色')
      roleId.value = ''
      await loadGroupRoles(selectedGroupRow)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function revokeRole(binding: SandIamIdentityGroupRole): Promise<void> {
    const group = selectedGroup.value
    if (group === null) return
    try {
      await ElMessageBox.confirm(
        `撤销「${binding.role_name}」后，该用户组成员将不再通过此用户组获得该角色。确认撤销吗？`,
        '撤销用户组角色',
        { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group-role/revoke', { id: binding.id })
      ElMessage.success('已撤销角色')
      await loadGroupRoles(group)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function addMember(): Promise<void> {
    const group = selectedId(selectedGroupId.value)
    const identity = selectedId(memberIdentityId.value)
    if (group === null || identity === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择用户组和本应用用户')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/member/add', {
        id: group,
        identity_id: identity
      })
      ElMessage.success('已保存')
      const current = groups.value.find((item) => item.id === group)
      if (current !== undefined) await loadMembers(current)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function removeMember(member: SandIamIdentityGroupMember): Promise<void> {
    const group = selectedId(selectedGroupId.value)
    if (group === null) return
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/member/remove', {
        id: group,
        identity_id: member.identity_id
      })
      ElMessage.success('已停用')
      const current = groups.value.find((item) => item.id === group)
      if (current !== undefined) await loadMembers(current)
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
        <h2 class="m-0 text-lg font-semibold">用户组</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          按接入应用管理用户组层级、成员和角色。所有选择都按名称完成，用户组、成员和角色不能跨应用关联。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号暂时不能查看用户组。请联系管理员开通用户组管理范围后再试。"
      />
      <ElAlert
        v-if="groupsReadError"
        class="mb-4"
        type="error"
        :closable="false"
        title="暂时无法读取用户组"
        description="请检查网络后重试；持续失败时联系管理员。其他页面数据不会因此丢失。"
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
        description="该接入应用还没有用户组。这与没有权限不同。"
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
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadGroups"
            >加载用户组</ElButton
          >
        </ElFormItem>
        <ElFormItem label="用户组名称">
          <ElInput v-model="name" />
        </ElFormItem>
        <ElFormItem label="系统代码">
          <ElInput v-model="code" />
        </ElFormItem>
        <ElFormItem label="上级用户组">
          <ElSelect v-model="parentId" filterable clearable placeholder="可选，按名称选择">
            <ElOption
              v-for="row in groups"
              :key="String(row.id)"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="!canSave" :loading="loading" @click="createGroup">
            新建用户组
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="groups" border stripe empty-text="暂无可见用户组">
        <ElTableColumn label="用户组名称" min-width="160">
          <template #default="scope">{{ scope.row.name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="160">
          <template #default>{{ selectedApplicationName() }}</template>
        </ElTableColumn>
        <ElTableColumn label="上级用户组" min-width="140">
          <template #default="scope">{{ scope.row.parent_name || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="成员数量" min-width="90">
          <template #default="scope">{{ scope.row.member_count }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" min-width="90">
          <template #default="scope">{{ groupStatusLabel(scope.row.status) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canMemberIndex" @click="loadMembers(scope.row)">
              成员
            </ElButton>
            <ElButton size="small" :disabled="!canRoleIndex" @click="loadGroupRoles(scope.row)">
              角色
            </ElButton>
            <ElButton
              v-if="scope.row.status === 1"
              size="small"
              type="warning"
              :disabled="!canDisable"
              @click="disableGroup(scope.row)"
            >
              停用
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <h3 class="mt-8 text-base">组成员</h3>
      <ElAlert
        v-if="!canIdentityIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="暂时无法读取可选用户"
        description="当前账号不能读取本应用的用户，不能按名称添加成员。请联系管理员开通用户查看范围后再试。"
      />
      <ElAlert
        v-else-if="identitiesReadError"
        class="mb-4"
        type="warning"
        :closable="false"
        title="暂时无法读取可选用户"
        description="请检查网络后重试。已加载的用户组不会受影响。"
      />
      <ElForm label-width="160px" class="mb-4">
        <ElFormItem label="本应用用户">
          <ElSelect
            v-model="memberIdentityId"
            filterable
            clearable
            placeholder="按名称选择，不可跨应用"
          >
            <ElOption
              v-for="row in memberCandidates"
              :key="String(row.id)"
              :label="row.display_name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton
            :disabled="!canMemberAdd || !canIdentityIndex || identitiesReadError !== null"
            :loading="loading"
            @click="addMember"
            >加入</ElButton
          >
        </ElFormItem>
      </ElForm>
      <ElTable :data="members" border stripe empty-text="先选择一个用户组查看成员">
        <ElTableColumn label="应用用户" min-width="160">
          <template #default="scope">{{ scope.row.display_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="账号状态" min-width="120">
          <template #default="scope">{{ lifecycleStateLabel(scope.row.lifecycle_state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="100" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canMemberRemove" @click="removeMember(scope.row)">
              移出
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <h3 class="mt-8 text-base">用户组角色</h3>
      <ElAlert
        v-if="selectedGroup === null"
        class="mb-4"
        type="info"
        :closable="false"
        title="先选择一个用户组"
        description="在上方用户组列表中点击“角色”，再按名称选择要授予的角色。"
      />
      <template v-else>
        <p class="mb-4 mt-2 text-sm text-gray-500">
          正在管理「{{
            selectedGroup.name
          }}」的角色。只可授予当前接入应用内启用的角色；撤销后成员立即不再通过该用户组获得该角色。
        </p>
        <ElAlert
          v-if="!canRoleResourceIndex"
          class="mb-4"
          type="warning"
          :closable="false"
          title="暂时无法读取可授予角色"
          description="当前账号可以查看用户组，但没有读取角色列表的权限。请联系管理员补齐角色查看权限后再授予角色。"
        />
        <ElAlert
          v-else-if="rolesReadError"
          class="mb-4"
          type="warning"
          :closable="false"
          title="暂时无法读取可授予角色"
          description="请检查网络后重试。已授予的角色不会受影响。"
        />
        <ElForm label-width="160px" class="mb-4">
          <ElFormItem label="角色">
            <ElSelect v-model="roleId" filterable clearable placeholder="按名称选择当前应用角色">
              <ElOption
                v-for="row in roleCandidates"
                :key="String(row.id)"
                :label="`${row.name}（${row.code}）`"
                :value="String(row.id)"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton
              type="primary"
              :disabled="
                !canRoleGrant ||
                !canRoleResourceIndex ||
                rolesReadError !== null ||
                selectedGroup.status !== 1
              "
              :loading="loading"
              @click="grantRole"
            >
              授予角色
            </ElButton>
          </ElFormItem>
        </ElForm>
        <ElTable :data="groupRoles" border stripe empty-text="该用户组尚未获授角色">
          <ElTableColumn label="角色名称" min-width="180">
            <template #default="scope">{{ scope.row.role_name }}</template>
          </ElTableColumn>
          <ElTableColumn label="系统代码" min-width="160">
            <template #default="scope">{{ scope.row.role_code }}</template>
          </ElTableColumn>
          <ElTableColumn label="状态" min-width="120">
            <template #default="scope">{{ groupRoleStatusLabel(scope.row) }}</template>
          </ElTableColumn>
          <ElTableColumn label="操作" min-width="100" fixed="right">
            <template #default="scope">
              <ElButton
                size="small"
                type="danger"
                :disabled="!canRoleRevoke || scope.row.status !== 1"
                @click="revokeRole(scope.row)"
              >
                撤销
              </ElButton>
            </template>
          </ElTableColumn>
        </ElTable>
      </template>
    </ElCard>
  </div>
</template>
