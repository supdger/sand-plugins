import { policyFields } from './fields'
import { normalizeReferenceValue } from './referenceValues'
import type { SandIamResourceRow } from './types'

/** Editable `state` is not evidence that a runtime snapshot has been published. */
export const policyEditorFields = policyFields.filter(field => field.key !== 'state')
export const policySaveMessage = '编辑内容已保存。请点击“发布”生成运行版本；保存不会替换已有运行版本，启停设置即时生效。'
export const policyPublishMessage = '策略已发布。请打开“版本历史”核对当前版本，再验证真实允许与拒绝结果。'

export function policyPublicationLabel(row: SandIamResourceRow): string {
  const pointer = row.published_version_id
  if (pointer === null || pointer === 0 || pointer === '0') {
    return '尚未发布（请点击发布）'
  }
  if (normalizeReferenceValue(pointer) === null) {
    return '运行版本未知（查看版本历史）'
  }
  if (row.status === 1 || row.status === '1') return '已发布（启用）'
  if (row.status === 2 || row.status === '2') return '已发布（暂停）'
  return '已发布（启停状态未知）'
}
