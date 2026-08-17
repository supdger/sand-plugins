interface OrganizationStore {
  depts: unknown[]
  roles: unknown[]
  users: unknown[]
  setDepts(items: unknown[]): void
  setRoles(items: unknown[]): void
  setUsers(items: unknown[]): void
  getDepts(): unknown[]
  getRoles(): unknown[]
  getUsers(): unknown[]
  getAll(): { depts: unknown[]; roles: unknown[]; users: unknown[] }
}

interface WorkFlowDefDraft {
  id?: number
  icon: string
  name: string
  remark: string
  groupId?: number
  cancelable: number
  flowAdminIds: number[]
}

interface EditorDefinition {
  workFlowDef: WorkFlowDefDraft
  flowWidgets: unknown[]
  nodeConfig: Record<string, unknown>
  flowPermission: Record<string, unknown>
}

interface FlowStore {
  flowDefinition: EditorDefinition
  flowDefId: number | null
  setFlowDef(value: EditorDefinition): void
  getFlowDef(): EditorDefinition
  setFlowDefId(value: number): void
  [key: string]: unknown
}

export function useOrganStore(): OrganizationStore
export function useFlowStore(): FlowStore
