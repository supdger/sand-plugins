import { onMounted, onScopeDispose, ref, watch, type Ref } from 'vue'
import { useRoute } from 'vue-router'
import { parseTaskContext, verifyTaskContext } from './taskContext'
import { readSandIamResource } from './write'
import type { SandIamResourceRow } from './types'

/** Restore a visible application without overwriting a user's later selection. */
export function useTaskApplication(
  applicationId: Ref<string>,
  applications: Ref<SandIamResourceRow[]>
): Ref<string> {
  const route = useRoute()
  const error = ref('')
  let version = 0
  let disposed = false
  async function restore(): Promise<void> {
    const attempt = ++version
    const original = applicationId.value
    error.value = ''
    try {
      const verified = await verifyTaskContext(parseTaskContext(route.query), readSandIamResource)
      if (disposed || attempt !== version || original !== applicationId.value) return
      const application = verified.rows.application_id
      if (!application || verified.context.application_id === undefined) return
      applications.value = [application, ...applications.value.filter(row => row.id !== application.id)]
      applicationId.value = String(verified.context.application_id)
    } catch {
      if (!disposed && attempt === version && original === applicationId.value) {
        applicationId.value = ''
        error.value = '无法确认链接中的应用归属，请按名称重新选择有权管理的应用。'
      }
    }
  }
  onMounted(() => { void restore() })
  watch(() => route.fullPath, () => { void restore() })
  onScopeDispose(() => { disposed = true; version++ })
  return error
}
