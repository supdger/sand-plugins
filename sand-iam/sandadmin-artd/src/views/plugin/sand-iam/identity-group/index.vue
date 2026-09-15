<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref, watch } from 'vue'
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
  const canUpdate = computed(() => hasAuth('sand_iam:identity_group:update'))
  const editingGroup = ref<SandIamIdentityGroupRow | null>(null)
  const description = ref('')
  const groupStatus = ref(1)

  const loading = ref(false)
  const groupsLoading = ref(false)
  const identitiesLoading = ref(false)
  const rolesLoading = ref(false)
  const relationLoading = ref(false)
  const relationSaving = ref(false)
  const relationMode = ref<'members' | 'roles' | null>(null)
  let disposed = false
  let appVersion = 0
  let listVersion = 0
  let relationVersion = 0
  let identityVersion = 0
  let roleVersion = 0

  function resetApplication(): void {
    appVersion++
    listVersion++
    relationVersion++
    identityVersion++
    roleVersion++
    identitiesLoading.value = false
    rolesLoading.value = false
    groups.value = []
    identities.value = []
    roles.value = []
    members.value = []
    groupRoles.value = []
    selectedGroupId.value = ''
    memberIdentityId.value = ''
    roleId.value = ''
    parentId.value = ''
    name.value = ''
    code.value = ''
    description.value = ''
    editingGroup.value = null
    groupStatus.value = 1
    relationMode.value = null
    relationLoading.value = false
    groupsLoading.value = false
    requestError.value = null
    groupsReadError.value = null
    identitiesReadError.value = null
    rolesReadError.value = null
    viewState.value = 'idle'
  }
  onScopeDispose(() => { disposed = true; listVersion++; relationVersion++; identityVersion++; roleVersion++ })
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
  watch(applicationId, resetApplication, { flush: 'sync' })

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

  const applicationLoading = ref(false)
  const applicationError = ref('')
  const applicationKeywords = ref('')
  let applicationRequest = 0

  async function loadApplications(keywords = ''): Promise<void> {
    const attempt = ++applicationRequest
    if (disposed) return
    applicationKeywords.value = keywords
    applicationLoading.value = true
    applicationError.value = ''
    try {
      const result = await listSandIamResource('application', { page: 1, limit: 100, keywords })
      if (disposed || attempt !== applicationRequest) return
      const selected = applications.value.find(row => String(row.id) === applicationId.value)
      const rows = listRows(result)
      applications.value = selected && !rows.some(row => row.id === selected.id) ? [selected, ...rows] : rows
    } catch (error: unknown) {
      if (!disposed && attempt === applicationRequest) applicationError.value = describeSandIamError(error).detail
    } finally {
      if (!disposed && attempt === applicationRequest) applicationLoading.value = false
    }
  }


  /**
   * 用户组接口要求 application_id；成员候选只加载当前应用，从根上拒绝跨应用选择。
   */
  async function loadGroups(): Promise<void> {
    const version = ++listVersion
    const app = appVersion
    const current = (): boolean => !disposed && version === listVersion && app === appVersion
    relationVersion++
    relationMode.value = null
    relationLoading.value = false
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    if (!canIndex.value) return
    groupsLoading.value = true
    groups.value = []
    identities.value = []
    roles.value = []
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
            const result = parseSandIamIdentityGroups(
              await getSandIamAdmin('identity-group/index', { application_id: application })
            )
            if (!current()) return
            groups.value = result
            viewState.value = groups.value.length === 0 ? 'empty' : 'ready'
          } catch (error: unknown) {
            if (!current()) return
            groups.value = []
            viewState.value = 'idle'
            groupsReadError.value = describeSandIamError(error)
          }
        })(),
        searchIdentities(''),
        searchRoles('')
      ])
    } finally {
      if (current()) groupsLoading.value = false
    }
  }

  async function searchIdentities(keywords: string): Promise<void> {
    const version = ++identityVersion
    const app = appVersion
    const application = selectedId(applicationId.value)
    const current = (): boolean => !disposed && app === appVersion && version === identityVersion
    // 搜索改变时清除旧选择，避免把已不在候选中的账号提交到当前用户组。
    memberIdentityId.value = ''
    identities.value = []
    identitiesReadError.value = null
    identitiesLoading.value = false
    if (disposed || application === null || !canIdentityIndex.value) return
    identitiesLoading.value = true
    try {
      const result = parseSandIamIdentities(await listSandIamResource('identity', {
        page: 1,
        limit: 100,
        application_id: application,
        keywords: keywords.trim()
      }))
      if (current()) identities.value = result.filter(row => row.application_id === application)
    } catch (error: unknown) {
      if (current()) identitiesReadError.value = describeSandIamError(error)
    } finally {
      if (current()) identitiesLoading.value = false
    }
  }

  async function searchRoles(keywords: string): Promise<void> {
    const version = ++roleVersion
    const app = appVersion
    const application = selectedId(applicationId.value)
    const current = (): boolean => !disposed && app === appVersion && version === roleVersion
    roleId.value = ''
    roles.value = []
    rolesReadError.value = null
    rolesLoading.value = false
    if (disposed || application === null || !canRoleResourceIndex.value) return
    rolesLoading.value = true
    try {
      const result = parseSandIamRoleOptions(await listSandIamResource('role', {
        page: 1,
        limit: 100,
        application_id: application,
        keywords: keywords.trim()
      }))
      if (current()) roles.value = result.filter(row => row.application_id === application)
    } catch (error: unknown) {
      if (current()) rolesReadError.value = describeSandIamError(error)
    } finally {
      if (current()) rolesLoading.value = false
    }
  }

  function editGroup(row: SandIamIdentityGroupRow): void {
    if (!canUpdate.value || loading.value || relationSaving.value || !validGroup(row)) return
    editingGroup.value = row
    name.value = row.name
    code.value = row.code
    description.value = row.description
    parentId.value = row.parent_id === null ? '' : String(row.parent_id)
    groupStatus.value = row.status
    requestError.value = null
  }

  function cancelEdit(): void {
    if (loading.value) return
    clearGroupDraft()
  }

  function clearGroupDraft(): void {
    editingGroup.value = null
    name.value = ''
    code.value = ''
    description.value = ''
    parentId.value = ''
    groupStatus.value = 1
  }

  async function createGroup(): Promise<void> {
    const editing = editingGroup.value
    if (disposed || loading.value || relationSaving.value ||
      (editing === null ? !canSave.value : !canUpdate.value || !validGroup(editing))) return
    const application = selectedId(applicationId.value)
    if (application === null || name.value.trim() === '' || code.value.trim() === '') {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并填写用户组名称和系统代码')
      )
      return
    }
    const parent = selectedId(parentId.value)
    if (parentId.value !== '' && (parent === null ||
      parent === editing?.id ||
      !groups.value.some(row => row.id === parent && row.application_id === application))) {
      requestError.value = describeSandIamError(new Error('SAND_IAM_VALIDATION_ERROR: 请选择当前应用的上级用户组'))
      return
    }
    const app = appVersion
    const current = (): boolean => !disposed && app === appVersion && editingGroup.value === editing
    const fields = {
      name: name.value.trim(),
      description: description.value,
      parent_id: parent
    }
    const payload = editing === null ? {
      ...fields,
      application_id: application,
      code: code.value.trim()
    } : { ...fields, id: editing.id, status: groupStatus.value }
    loading.value = true
    requestError.value = null
    try {
      await postSandIamAction(editing === null ? 'identity-group/save' : 'identity-group/update', payload)
      if (!current()) return
      ElMessage.success('已保存')
      clearGroupDraft()
      await loadGroups()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function disableGroup(row: SandIamIdentityGroupRow): Promise<void> {
    if (loading.value || relationSaving.value || !canDisable.value || !validGroup(row) || row.status !== 1) return
    const app = appVersion
    const current = (): boolean => !disposed && app === appVersion && validGroup(row)
    loading.value = true
    try {
      await ElMessageBox.confirm(
        `确认停用「${row.name}」吗？如仍有关联内容，页面会提示下一步。`,
        '停用用户组',
        { type: 'warning', confirmButtonText: '确认停用', cancelButtonText: '取消' }
      )
    } catch {
      loading.value = false
      return
    }
    if (!current() || !canDisable.value || row.status !== 1) {
      loading.value = false
      return
    }
    requestError.value = null
    try {
      await postSandIamAction('identity-group/disable', { id: row.id })
      if (!current()) return
      ElMessage.success('已停用')
      await loadGroups()
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function loadMembers(row: SandIamIdentityGroupRow): Promise<void> {
    if (!canMemberIndex.value || !validGroup(row)) return
    ++relationVersion
    selectedGroupId.value = String(row.id)
    relationMode.value = 'members'
    const current = relationContext()
    members.value = []
    memberIdentityId.value = ''
    groupRoles.value = []
    roleId.value = ''
    relationLoading.value = true
    requestError.value = null
    try {
      const result = parseSandIamIdentityGroupMembers(
        await getSandIamAdmin('identity-group/members', { id: row.id })
      )
      if (current()) members.value = result
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      if (current()) relationLoading.value = false
    }
  }

  async function loadGroupRoles(row: SandIamIdentityGroupRow): Promise<void> {
    if (!canRoleIndex.value || !validGroup(row)) return
    ++relationVersion
    selectedGroupId.value = String(row.id)
    relationMode.value = 'roles'
    const current = relationContext()
    groupRoles.value = []
    members.value = []
    memberIdentityId.value = ''
    roleId.value = ''
    relationLoading.value = true
    requestError.value = null
    try {
      const result = parseSandIamIdentityGroupRoles(
        await getSandIamAdmin('identity-group-role/index', {
          identity_group_id: row.id
        })
      )
      if (current()) groupRoles.value = result
    } catch (error: unknown) {
      if (!current()) return
      requestError.value = describeSandIamError(error)
      groupRoles.value = []
    } finally {
      if (current()) relationLoading.value = false
    }
  }

  function validGroup(group: SandIamIdentityGroupRow): boolean {
    return !disposed && groups.value.includes(group) &&
      group.application_id === selectedId(applicationId.value)
  }

  function relationContext(): () => boolean {
    const app = appVersion
    const version = relationVersion
    const group = selectedGroup.value
    const mode = relationMode.value
    return () => !disposed && app === appVersion && version === relationVersion &&
      group !== null && validGroup(group) && selectedGroup.value === group &&
      relationMode.value === mode
  }

  async function grantRole(): Promise<void> {
    if (disposed || loading.value || rolesLoading.value || relationSaving.value || relationLoading.value || relationMode.value !== 'roles' ||
      !canRoleGrant.value || !canRoleResourceIndex.value || rolesReadError.value !== null) return
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
    if (selectedGroupRow.status !== 1 || !roleCandidates.value.includes(selectedRole)) return
    const current = relationContext()
    relationSaving.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group-role/grant', {
        identity_group_id: group,
        role_id: role
      })
      if (!current()) return
      ElMessage.success('已授予角色')
      roleId.value = ''
      await loadGroupRoles(selectedGroupRow)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
    }
  }

  async function revokeRole(binding: SandIamIdentityGroupRole): Promise<void> {
    const group = selectedGroup.value
    if (group === null || !validGroup(group) || loading.value || relationSaving.value || relationLoading.value ||
      relationMode.value !== 'roles' || !canRoleRevoke.value ||
      !groupRoles.value.includes(binding) || binding.identity_group_id !== group.id || binding.status !== 1) return
    const current = relationContext()
    relationSaving.value = true
    try {
      await ElMessageBox.confirm(
        `撤销「${binding.role_name}」后，该用户组成员将不再通过此用户组获得该角色。确认撤销吗？`,
        '撤销用户组角色',
        { type: 'warning', confirmButtonText: '确认撤销', cancelButtonText: '取消' }
      )
    } catch {
      relationSaving.value = false
      return
    }
    if (!current() || !canRoleRevoke.value || !groupRoles.value.includes(binding)) {
      relationSaving.value = false
      return
    }
    requestError.value = null
    try {
      await postSandIamAction('identity-group-role/revoke', { id: binding.id })
      if (!current()) return
      ElMessage.success('已撤销角色')
      await loadGroupRoles(group)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
    }
  }

  async function addMember(): Promise<void> {
    if (disposed || loading.value || identitiesLoading.value || relationSaving.value || relationLoading.value || relationMode.value !== 'members' ||
      !canMemberAdd.value || !canIdentityIndex.value || identitiesReadError.value !== null) return
    const group = selectedId(selectedGroupId.value)
    const identity = selectedId(memberIdentityId.value)
    const row = selectedGroup.value
    const candidate = memberCandidates.value.find(item => item.id === identity)
    if (row === null || !validGroup(row) || candidate === undefined ||
      candidate.application_id !== selectedId(applicationId.value)) return
    if (group === null || identity === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请按名称选择用户组和本应用用户')
      )
      return
    }
    const current = relationContext()
    relationSaving.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/member/add', {
        id: group,
        identity_id: identity
      })
      if (!current()) return
      ElMessage.success('已保存')
      await loadMembers(row)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
    }
  }

  async function removeMember(member: SandIamIdentityGroupMember): Promise<void> {
    const group = selectedId(selectedGroupId.value)
    const row = selectedGroup.value
    if (group === null || row === null || !validGroup(row) || loading.value || relationSaving.value ||
      relationLoading.value || relationMode.value !== 'members' || !canMemberRemove.value ||
      !members.value.includes(member)) return
    const current = relationContext()
    relationSaving.value = true
    requestError.value = null
    try {
      await postSandIamAction('identity-group/member/remove', {
        id: group,
        identity_id: member.identity_id
      })
      if (!current()) return
      ElMessage.success('已停用')
      await loadMembers(row)
    } catch (error: unknown) {
      if (current()) requestError.value = describeSandIamError(error)
    } finally {
      relationSaving.value = false
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
        <ElFormItem v-if="applicationError" label="应用搜索失败">
          <span role="alert">{{ applicationError }}</span>
          <ElButton :loading="applicationLoading" @click="loadApplications(applicationKeywords)">重试搜索</ElButton>
        </ElFormItem>
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable remote :remote-method="loadApplications" :loading="applicationLoading" clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton :disabled="!canIndex" :loading="groupsLoading" @click="loadGroups"
            >加载用户组</ElButton
          >
        </ElFormItem>
        <ElFormItem label="用户组名称">
          <ElInput v-model="name" />
        </ElFormItem>
        <ElFormItem label="系统代码">
          <ElInput v-model="code" :disabled="editingGroup !== null" />
        </ElFormItem>
        <ElFormItem label="描述">
          <ElInput v-model="description" type="textarea" />
        </ElFormItem>
        <ElFormItem v-if="editingGroup !== null" label="状态">
          <ElSelect v-model="groupStatus">
            <ElOption :value="1" label="启用" />
            <ElOption :value="2" label="停用" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="上级用户组">
          <ElSelect v-model="parentId" filterable clearable placeholder="可选，按名称选择">
            <ElOption
              v-for="row in groups.filter(item => item.id !== editingGroup?.id)"
              :key="String(row.id)"
              :label="row.name"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem>
          <ElButton type="primary" :disabled="(editingGroup === null ? !canSave : !canUpdate) || relationSaving" :loading="loading" @click="createGroup">
            {{ editingGroup === null ? '新建用户组' : '保存修改' }}
          </ElButton>
          <ElButton v-if="editingGroup !== null" :disabled="loading" @click="cancelEdit">取消编辑</ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading || groupsLoading" :data="groups" border stripe empty-text="暂无可见用户组">
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
            <ElButton size="small" :disabled="!canUpdate || loading || relationSaving" @click="editGroup(scope.row)">编辑</ElButton>
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
              :disabled="!canDisable || loading || relationSaving"
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
            remote
            :remote-method="searchIdentities"
            :loading="identitiesLoading"
            :disabled="!canIdentityIndex"
            clearable
            placeholder="输入用户名称搜索当前应用"
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
            :disabled="!canMemberAdd || !canIdentityIndex || identitiesReadError !== null || identitiesLoading || relationMode !== 'members' || relationLoading || relationSaving || loading"
            :loading="relationSaving"
            @click="addMember"
            >加入</ElButton
          >
        </ElFormItem>
      </ElForm>
      <ElTable v-loading="relationMode === 'members' && relationLoading" :data="members" border stripe empty-text="先选择一个用户组查看成员">
        <ElTableColumn label="应用用户" min-width="160">
          <template #default="scope">{{ scope.row.display_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="账号状态" min-width="120">
          <template #default="scope">{{ lifecycleStateLabel(scope.row.lifecycle_state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="100" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canMemberRemove || relationSaving || relationLoading || loading" @click="removeMember(scope.row)">
              移出
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <h3 class="mt-8 text-base">用户组角色</h3>
      <ElAlert
        v-if="selectedGroup === null || relationMode !== 'roles'"
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
            <ElSelect
              v-model="roleId"
              filterable
              remote
              :remote-method="searchRoles"
              :loading="rolesLoading"
              :disabled="!canRoleResourceIndex"
              clearable
              placeholder="输入角色名称搜索当前应用"
            >
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
                relationSaving || relationLoading || rolesLoading || loading ||
                !canRoleResourceIndex ||
                rolesReadError !== null ||
                selectedGroup.status !== 1
              "
              :loading="relationSaving"
              @click="grantRole"
            >
              授予角色
            </ElButton>
          </ElFormItem>
        </ElForm>
        <ElTable v-loading="relationLoading" :data="groupRoles" border stripe empty-text="该用户组尚未获授角色">
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
                :disabled="!canRoleRevoke || scope.row.status !== 1 || relationSaving || relationLoading || loading"
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
