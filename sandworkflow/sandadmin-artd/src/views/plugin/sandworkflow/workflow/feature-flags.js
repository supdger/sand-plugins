/**
 * Sand 设计器/工作台能力开关。
 * 仅当 docs/sandworkflow/STATUS.md 中对应能力验收通过后改为 true。
 * 未验收能力不得在设计器开放配置（见 COLLAB.md）。
 */
export const SandWorkflowFeatures = {
  /** P1 评论：独立评论动作（非审批意见字段） */
  comment: true,
  /** P1 转办 */
  assign: true,
  /** P1 加签/减签 */
  addSign: true,
  /** P1 跳转/回退 */
  jump: true,
  /** P2 抄送节点与抄送箱 */
  copy: true,
  /** P2 办理节点（transact）完整运行 */
  transact: false,
  /** P2 发起人自选（INITIATOR_CHOICE=7） */
  initiatorChoice: true,
  /** P2 部门负责人 / 连续多级部门负责人（类型 2/6） */
  departmentLeader: true,
  /** P2 主管/上级/连续上级（类型 1/5，仍未开放） */
  advancedAssignee: false,
  /** P2 表单节点字段权限运行时执行 */
  formAuthRuntime: true,
  /** P2 打印 / 签名 */
  print: true,
  signature: false,
}
