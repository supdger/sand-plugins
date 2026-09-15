/**
 * Identity.lifecycle_state 与 IdentityGroupController::payload 已冻结。
 * identity 列表没有登录标识脱敏或用户组摘要字段，页面不得编造这两列。
 */

export type SandIamLifecycleState = 'pending' | 'active' | 'disabled' | 'guest' | 'deleted'

export interface SandIamIdentityRow {
  readonly id: number
  readonly application_id: number
  readonly display_name: string
  readonly code: string
  readonly status: number
  readonly lifecycle_state: SandIamLifecycleState | null
}

export interface SandIamIdentityGroupRow {
  readonly id: number
  readonly application_id: number
  readonly code: string
  readonly name: string
  readonly parent_name: string
  readonly parent_id: number | null
  readonly description: string
  readonly member_count: number
  readonly status: number
}

export interface SandIamIdentityGroupMember {
  readonly identity_id: number
  readonly display_name: string
  readonly code: string
  readonly lifecycle_state: string
}

export interface SandIamRoleOption {
  readonly id: number
  readonly application_id: number
  readonly name: string
  readonly code: string
  readonly status: number
}

export interface SandIamIdentityGroupRole {
  readonly id: number
  readonly identity_group_id: number
  readonly role_id: number
  readonly status: number
  readonly role_name: string
  readonly role_code: string
  readonly role_status: number
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function unwrapList(value: unknown): unknown[] {
  if (Array.isArray(value)) return value
  if (isRecord(value) && Array.isArray(value.data)) return value.data
  if (isRecord(value) && isRecord(value.data) && Array.isArray(value.data.data)) {
    return value.data.data
  }
  return []
}

function readPositiveInt(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function readString(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

export function lifecycleStateLabel(state: string | null): string {
  if (state === 'pending') return '等待邀请'
  if (state === 'active') return '正常'
  if (state === 'disabled') return '已停用'
  if (state === 'guest') return '访客'
  if (state === 'deleted') return '已删除'
  return '未提供生命周期状态'
}

function readLifecycle(value: unknown): SandIamLifecycleState | null {
  if (
    value === 'pending' ||
    value === 'active' ||
    value === 'disabled' ||
    value === 'guest' ||
    value === 'deleted'
  ) {
    return value
  }
  return null
}

export function parseSandIamIdentity(value: unknown): SandIamIdentityRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const displayName = readString(value.display_name)
  const code = readString(value.code)
  const status = value.status
  if (
    id === null ||
    applicationId === null ||
    displayName === null ||
    code === null ||
    typeof status !== 'number'
  ) {
    return null
  }
  return {
    id,
    application_id: applicationId,
    display_name: displayName,
    code,
    status,
    lifecycle_state: readLifecycle(value.lifecycle_state)
  }
}

export function parseSandIamIdentities(value: unknown): SandIamIdentityRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamIdentity(item))
    .filter((item): item is SandIamIdentityRow => item !== null)
}

export function parseSandIamIdentityPage(value: unknown): {
  rows: SandIamIdentityRow[]
  total: number
} {
  const rows = parseSandIamIdentities(value)
  const page = isRecord(value) && isRecord(value.data) ? value.data : value
  const total = isRecord(page) && typeof page.total === 'number' &&
    Number.isInteger(page.total) && page.total >= 0 ? page.total : rows.length
  return { rows, total }
}

export function parseSandIamIdentityGroup(value: unknown): SandIamIdentityGroupRow | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const code = readString(value.code)
  const name = readString(value.name)
  const memberCount = value.member_count
  const status = value.status
  if (
    id === null ||
    applicationId === null ||
    code === null ||
    name === null ||
    typeof memberCount !== 'number' ||
    typeof status !== 'number'
  ) {
    return null
  }
  return {
    id,
    application_id: applicationId,
    code,
    name,
    parent_name: typeof value.parent_name === 'string' ? value.parent_name : '',
    parent_id: readPositiveInt(value.parent_id),
    description: typeof value.description === 'string' ? value.description : '',
    member_count: memberCount,
    status
  }
}

export function parseSandIamIdentityGroups(value: unknown): SandIamIdentityGroupRow[] {
  return unwrapList(value)
    .map((item) => parseSandIamIdentityGroup(item))
    .filter((item): item is SandIamIdentityGroupRow => item !== null)
}

export function parseSandIamIdentityGroupMember(value: unknown): SandIamIdentityGroupMember | null {
  if (!isRecord(value)) return null
  const identityId = readPositiveInt(value.identity_id)
  const displayName = readString(value.display_name)
  const code = readString(value.code)
  if (identityId === null || displayName === null || code === null) return null
  return {
    identity_id: identityId,
    display_name: displayName,
    code,
    lifecycle_state: typeof value.lifecycle_state === 'string' ? value.lifecycle_state : ''
  }
}

export function parseSandIamIdentityGroupMembers(value: unknown): SandIamIdentityGroupMember[] {
  return unwrapList(value)
    .map((item) => parseSandIamIdentityGroupMember(item))
    .filter((item): item is SandIamIdentityGroupMember => item !== null)
}

export function parseSandIamRoleOption(value: unknown): SandIamRoleOption | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const applicationId = readPositiveInt(value.application_id)
  const name = readString(value.name)
  const code = readString(value.code)
  if (
    id === null ||
    applicationId === null ||
    name === null ||
    code === null ||
    typeof value.status !== 'number'
  ) {
    return null
  }
  return { id, application_id: applicationId, name, code, status: value.status }
}

export function parseSandIamRoleOptions(value: unknown): SandIamRoleOption[] {
  return unwrapList(value)
    .map((item) => parseSandIamRoleOption(item))
    .filter((item): item is SandIamRoleOption => item !== null)
}

export function parseSandIamIdentityGroupRole(value: unknown): SandIamIdentityGroupRole | null {
  if (!isRecord(value)) return null
  const id = readPositiveInt(value.id)
  const groupId = readPositiveInt(value.identity_group_id)
  const roleId = readPositiveInt(value.role_id)
  const roleName = readString(value.role_name)
  const roleCode = readString(value.role_code)
  if (
    id === null ||
    groupId === null ||
    roleId === null ||
    roleName === null ||
    roleCode === null ||
    typeof value.status !== 'number' ||
    typeof value.role_status !== 'number'
  ) {
    return null
  }
  return {
    id,
    identity_group_id: groupId,
    role_id: roleId,
    status: value.status,
    role_name: roleName,
    role_code: roleCode,
    role_status: value.role_status
  }
}

export function parseSandIamIdentityGroupRoles(value: unknown): SandIamIdentityGroupRole[] {
  return unwrapList(value)
    .map((item) => parseSandIamIdentityGroupRole(item))
    .filter((item): item is SandIamIdentityGroupRole => item !== null)
}

/** 仅可把当前应用内、启用中的角色授予启用用户组。 */
export function selectableGroupRoles(
  rows: readonly SandIamRoleOption[],
  applicationId: number
): SandIamRoleOption[] {
  return rows.filter((row) => row.application_id === applicationId && row.status === 1)
}

/**
 * 当前用户记录没有登录标识，页面只能如实说明，不能猜测其他数据字段。
 */
export const IDENTITY_LOGIN_IDENTIFIER_UNAVAILABLE = '当前列表未提供主要登录标识'

/**
 * 停用确认文案：撤销会话/认证方式，但 MFA/绑定/角色/组会保留；旧会话永不恢复。
 */
export function identityDisableImpact(state: string | null): string {
  if (state === 'deleted') return '该用户已删除，停用不会恢复任何内容。'
  return '停用后该用户不能再登录，现有登录和认证方式会被撤销。重新启用时，停用前的会话不会恢复。'
}

/**
 * 删除确认文案：两段式保留期；恢复不恢复旧会话、旧令牌和已移除授权。
 */
export function identityDeleteImpact(): string {
  return '删除后进入保留期，现有登录和授权关系均已撤销。恢复时旧会话、旧令牌和已移除授权不会恢复。'
}

/**
 * pending 只走邀请重发/撤销；lifecycle 未启用时退回 status=1 的兼容停用。
 */
export function identityCanDisable(row: SandIamIdentityRow): boolean {
  if (
    row.lifecycle_state === 'pending' ||
    row.lifecycle_state === 'disabled' ||
    row.lifecycle_state === 'deleted'
  ) {
    return false
  }
  if (row.lifecycle_state === null) return row.status === 1
  return row.lifecycle_state === 'active' || row.lifecycle_state === 'guest'
}

export function identityCanEnable(row: SandIamIdentityRow): boolean {
  return row.lifecycle_state === 'disabled'
}

export function identityCanDelete(row: SandIamIdentityRow): boolean {
  return row.lifecycle_state !== null && row.lifecycle_state !== 'deleted'
}

export function identityCanRestore(row: SandIamIdentityRow): boolean {
  return row.lifecycle_state === 'deleted'
}

/**
 * 用已冻结 identity-group members 反查组名，不发明 identity 列表的 group_summary 字段。
 */
export function identityGroupNamesByMember(
  groups: readonly SandIamIdentityGroupRow[],
  membersByGroupId: Readonly<Record<number, readonly SandIamIdentityGroupMember[]>>
): ReadonlyMap<number, readonly string[]> {
  const map = new Map<number, string[]>()
  for (const group of groups) {
    const members = membersByGroupId[group.id] ?? []
    for (const member of members) {
      const current = map.get(member.identity_id) ?? []
      current.push(group.name)
      map.set(member.identity_id, current)
    }
  }
  return map
}

/**
 * 组摘要列：无权、未选应用、未加入组必须分开，不能把无权显示成“当前没有数据”。
 */
export function identityGroupSummaryLabel(
  identityId: number,
  namesByIdentity: ReadonlyMap<number, readonly string[]>,
  available: boolean
): string {
  if (!available) return '无权查看用户组摘要'
  const names = namesByIdentity.get(identityId)
  if (names === undefined || names.length === 0) return '未加入用户组'
  return names.join('、')
}

/**
 * 组成员选择只允许本应用未删除用户；跨应用用户不会进入这个列表。
 */
export function selectableGroupIdentities(
  rows: readonly SandIamIdentityRow[]
): SandIamIdentityRow[] {
  return rows.filter((row) => row.lifecycle_state !== 'deleted')
}
