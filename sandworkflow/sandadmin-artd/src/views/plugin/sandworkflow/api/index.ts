import request from '@/utils/http'

export type QueryParams = Record<string, unknown>
export type WorkflowRuntimeId = string

export interface GroupSearch {
  keywords?: string
  status?: number
}

export interface DefinitionSearch {
  keywords?: string
  group_id?: number
  status?: number
}

export interface FlowGroup {
  id: number
  name: string
  description?: string
  sort?: number
  status: number
}

export interface FlowDefinition {
  id: number
  group_id?: number
  group_name?: string
  name: string
  icon?: string
  description?: string
  definition_json?: unknown
  status: number
  is_cancelable?: number
  current_version?: number
}

export interface FlowDefinitionVersion {
  id: WorkflowRuntimeId
  definition_id: number
  version_no: number
  definition_json: unknown
  published_by?: number
  publish_time: string
}

export interface FlowInstance {
  id: WorkflowRuntimeId
  definition_id: number
  definition_version_id?: WorkflowRuntimeId
  account_space: string
  account_id: string
  name: string
  act_node_id?: string
  flow_status: number
  definition_snapshot?: unknown
  form_value?: unknown
  participants?: unknown
  definition?: FlowDefinition
  created_by?: number
  create_time?: string
  ended_time?: string
  update_time?: string
}

export interface FlowTask {
  id: WorkflowRuntimeId
  instance_id: WorkflowRuntimeId
  node_id?: string
  node_name?: string
  node_type: number
  sign_type?: 0 | 1 | 2 | 3
  task_status: number
  operation_type?: number
  comment?: string
  create_time?: string
  ended_time?: string
  assignees?: FlowTaskAssignee[]
  instance?: FlowInstance
}

export interface FlowTaskAssignee {
  id: WorkflowRuntimeId
  account_space: string
  account_id: string
  user_id?: number
  sequence_no?: number
  action_status: 0 | 1 | 2
  operation_type?: number
  comment?: string
  ended_time?: string
}

export interface FlowLog {
  id: WorkflowRuntimeId
  task_id?: WorkflowRuntimeId
  account_space: string
  account_id: string
  node_name?: string
  operation_type: number
  auditor_id?: number
  comment?: string
  create_time?: string
  assignee_snapshot?:
    | FlowActorSnapshot[]
    | {
        from?: FlowActorSnapshot
        to?: FlowActorSnapshot
        operator?: FlowActorSnapshot
        target?: FlowActorSnapshot
        from_node?: { id?: string; name?: string }
        to_node?: { id?: string; name?: string }
      }
}

export interface FlowActorSnapshot {
  account_space?: string
  account_id?: string
  snapshot?: { name?: string }
}

export interface FlowInstanceDetail extends FlowInstance {
  tasks: FlowTask[]
  logs: FlowLog[]
  form_access?: FlowFormAccess
}

export interface FlowFormAccess {
  source?: string
  node_id?: string | null
  readable_fields?: string[]
  legacy_fallback?: boolean
}

export interface FlowUser {
  id: number
  username: string
  nickname?: string
  dept_id?: number
}

export interface FlowRole {
  id: number
  name: string
  code?: string
}
export interface FlowDept {
  id: number
  parent_id?: number
  name: string
}

export interface FlowBackNode {
  id: string
  name: string
  node_type: number
  completed_time?: string | null
}

export default {
  organization: {
    users() {
      return request.get<FlowUser[]>({
        url: '/admin/index/listUsers'
      })
    },
    roles() {
      return request.get<FlowRole[]>({ url: '/admin/index/listRoles' })
    },
    depts() {
      return request.get<FlowDept[]>({ url: '/admin/index/listDepts' })
    }
  },
  group: {
    list(params: QueryParams) {
      return request.get<Api.Common.ApiPage>({
        url: '/admin/group/index',
        params
      })
    },
    all() {
      return request.get<FlowGroup[]>({
        url: '/admin/group/all'
      })
    },
    read(id: number) {
      return request.get<FlowGroup>({
        url: '/admin/group/read',
        params: { id }
      })
    },
    save(data: QueryParams) {
      return request.post<Api.Common.ApiData>({
        url: '/admin/group/save',
        data
      })
    },
    update(data: QueryParams) {
      return request.put<Api.Common.ApiData>({
        url: '/admin/group/update',
        data
      })
    },
    delete(data: QueryParams) {
      return request.del<Api.Common.ApiData>({
        url: '/admin/group/destroy',
        data
      })
    }
  },
  definition: {
    list(params: QueryParams) {
      return request.get<Api.Common.ApiPage>({
        url: '/admin/definition/index',
        params
      })
    },
    available(params: QueryParams) {
      return request.get<Api.Common.ApiPage<FlowDefinition>>({
        url: '/admin/definition/available',
        params
      })
    },
    read(id: number) {
      return request.get<FlowDefinition>({
        url: '/admin/definition/read',
        params: { id }
      })
    },
    versions(id: number) {
      return request.get<FlowDefinitionVersion[]>({
        url: '/admin/definition/versions',
        params: { id }
      })
    },
    save(data: QueryParams) {
      return request.post<Api.Common.ApiData>({
        url: '/admin/definition/save',
        data
      })
    },
    publish(data: QueryParams) {
      return request.post<Api.Common.ApiData>({
        url: '/admin/definition/publish',
        data
      })
    },
    update(data: QueryParams) {
      return request.put<Api.Common.ApiData>({
        url: '/admin/definition/update',
        data
      })
    },
    delete(data: QueryParams) {
      return request.del<Api.Common.ApiData>({
        url: '/admin/definition/destroy',
        data
      })
    }
  },
  instance: {
    list(params: QueryParams) {
      return request.get<Api.Common.ApiPage<FlowInstance>>({
        url: '/admin/instance/index',
        params
      })
    },
    pending(params: QueryParams) {
      return request.get<Api.Common.ApiPage<FlowTask>>({
        url: '/admin/instance/pendingList',
        params
      })
    },
    mine(params: QueryParams) {
      return request.get<Api.Common.ApiPage<FlowInstance>>({
        url: '/admin/instance/myList',
        params
      })
    },
    processed(params: QueryParams) {
      return request.get<Api.Common.ApiPage<FlowTask>>({
        url: '/admin/instance/processedList',
        params
      })
    },
    copyList(params: QueryParams) {
      return request.get<Api.Common.ApiPage<FlowInstance>>({
        url: '/admin/instance/copyList',
        params
      })
    },
    start(data: QueryParams) {
      return request.post<Api.Common.ApiData>({
        url: '/admin/instance/start',
        data
      })
    },
    detail(id: WorkflowRuntimeId) {
      return request.get<FlowInstanceDetail>({
        url: '/admin/instance/getDetail',
        params: { id }
      })
    },
    approve(data: QueryParams) {
      return request.post<Api.Common.ApiData>({
        url: '/admin/instance/approve',
        data
      })
    },
    reject(data: QueryParams) {
      return request.post<Api.Common.ApiData>({
        url: '/admin/instance/reject',
        data
      })
    },
    cancel(id: WorkflowRuntimeId) {
      return request.post<Api.Common.ApiData>({
        url: '/admin/instance/cancel',
        data: { id }
      })
    },
    comment(data: { instance_id: WorkflowRuntimeId; comment: string; task_id?: WorkflowRuntimeId }) {
      return request.post<FlowLog>({
        url: '/admin/instance/comment',
        data
      })
    },
    transfer(data: {
      task_id: WorkflowRuntimeId
      assignee_id: number
      comment?: string
      request_id?: string
    }) {
      return request.post<FlowTask>({
        url: '/admin/instance/transfer',
        data
      })
    },
    addSign(data: {
      task_id: WorkflowRuntimeId
      assignee_id: number
      comment: string
      request_id?: string
    }) {
      return request.post<FlowTask>({
        url: '/admin/instance/addSign',
        data
      })
    },
    delSign(data: {
      task_id: WorkflowRuntimeId
      assignee_id: number
      comment: string
      request_id?: string
    }) {
      return request.post<FlowTask>({
        url: '/admin/instance/delSign',
        data
      })
    },
    backNodeList(data: { task_id: WorkflowRuntimeId }) {
      return request.post<FlowBackNode[]>({
        url: '/admin/instance/backNodeList',
        data
      })
    },
    back(data: {
      task_id: WorkflowRuntimeId
      target_node_id: string
      comment: string
      request_id?: string
    }) {
      return request.post<FlowInstanceDetail>({
        url: '/admin/instance/back',
        data
      })
    }
  }
}
