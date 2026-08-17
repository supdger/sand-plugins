export interface SandWorkflowFeatureFlags {
  comment: boolean
  assign: boolean
  addSign: boolean
  jump: boolean
  copy: boolean
  transact: boolean
  initiatorChoice: boolean
  departmentLeader: boolean
  advancedAssignee: boolean
  formAuthRuntime: boolean
  print: boolean
  signature: boolean
}

export const SandWorkflowFeatures: Readonly<SandWorkflowFeatureFlags>
