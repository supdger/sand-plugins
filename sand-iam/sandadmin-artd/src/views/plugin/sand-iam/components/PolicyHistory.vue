<script setup lang="ts">
  import { computed, onScopeDispose, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { getSandIamAdmin, postSandIamAction } from '../api/write'
  import { describeSandIamError } from '../api/errors'
  import type { SandIamRequestError } from '../api/types'

  const props = defineProps<{ policyId: number | null; busy?: boolean }>()
  const emit = defineEmits<{ close: []; changed: []; busy: [value: boolean] }>()
  const { hasAuth } = useAuth()
  const canRead = computed(() => hasAuth('sand_iam:policy:read'))
  const canPublish = computed(() => hasAuth('sand_iam:policy:publish'))
  interface Version {
    id: number
    version_no: number
    operation: string
    rollback_of_version_id: number | null
    create_time: string
  }
  const rows = ref<Version[]>([])
  const page = ref(1)
  const size = ref(20)
  const total = ref(0)
  const published = ref<number | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref<SandIamRequestError | null>(null)
  let version = 0
  let disposed = false
  function record(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  function positive(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value > 0
  }
  function parseRow(value: unknown): Version | null {
    if (!record(value) || !positive(value.id) || !positive(value.version_no) ||
      typeof value.operation !== 'string' ||
      !(value.rollback_of_version_id === null || positive(value.rollback_of_version_id))) return null
    return { id: value.id, version_no: value.version_no, operation: value.operation,
      rollback_of_version_id: value.rollback_of_version_id,
      create_time: typeof value.create_time === 'string' ? value.create_time : '' }
  }
  async function load(): Promise<void> {
    const request = ++version
    const id = props.policyId
    rows.value = []
    error.value = null
    if (disposed || id === null || !canRead.value) return
    loading.value = true
    try {
      const result = await getSandIamAdmin('policy/versions', { id, page: page.value, limit: size.value })
      if (disposed || request !== version || id !== props.policyId) return
      if (!record(result) || !Array.isArray(result.data) || typeof result.total !== 'number') {
        throw new Error('策略历史响应格式无效')
      }
      rows.value = result.data.map(parseRow).filter((row): row is Version => row !== null)
      total.value = result.total
      published.value = positive(result.published_version_id) ? result.published_version_id : null
    } catch (reason: unknown) {
      if (!disposed && request === version) error.value = describeSandIamError(reason)
    } finally {
      if (!disposed && request === version) loading.value = false
    }
  }
  async function rollback(row: Version): Promise<void> {
    const id = props.policyId
    const request = version
    const current = (): boolean => !disposed && id === props.policyId && request === version
    const allowed = (): boolean => current() && canRead.value && canPublish.value && rows.value.includes(row)
    if (id === null || props.busy || saving.value || loading.value || !allowed()) return
    saving.value = true
    emit('busy', true)
    try {
      try {
        await ElMessageBox.confirm(`将版本 ${row.version_no} 的快照发布为新版本，历史记录不会删除。确认回滚吗？`,
          '回滚发布', { type: 'warning', confirmButtonText: '生成新版本', cancelButtonText: '取消' })
      } catch { return }
      if (!allowed()) return
      await postSandIamAction('policy/rollback', { id, version_id: row.id })
      if (!current()) return
      ElMessage.success('已将历史快照发布为新版本')
      emit('changed')
      page.value = 1
      await load()
    } catch (reason: unknown) {
      if (current()) error.value = describeSandIamError(reason)
    } finally {
      saving.value = false
      emit('busy', false)
    }
  }
  watch(() => props.policyId, () => {
    version++
    page.value = 1
    total.value = 0
    published.value = null
    rows.value = []
    loading.value = false
    void load()
  }, { immediate: true, flush: 'sync' })
  watch([page, size], () => { version++; rows.value = []; loading.value = false }, { flush: 'sync' })
  onScopeDispose(() => { disposed = true; version++ })
</script>

<template>
  <ElDialog :model-value="policyId !== null" title="策略版本历史" width="800px" @close="emit('close')">
    <ElAlert type="info" :closable="false" title="当前发布指针仅表示所选运行快照，不代表策略当前处于启用状态。" />
    <ElAlert v-if="!canRead" type="warning" :closable="false" title="没有查看策略历史的权限" />
    <ElAlert v-if="error" type="error" :closable="false" :title="error.title" :description="error.detail" />
    <ElButton :disabled="!canRead" :loading="loading" @click="load">刷新历史</ElButton>
    <ElTable v-loading="loading" :data="rows" empty-text="暂无历史版本">
      <ElTableColumn prop="version_no" label="版本" />
      <ElTableColumn prop="operation" label="操作" />
      <ElTableColumn prop="create_time" label="创建时间" />
      <ElTableColumn label="发布指针"><template #default="scope">{{ scope.row.id === published ? '当前指针' : '—' }}</template></ElTableColumn>
      <ElTableColumn label="操作"><template #default="scope">
        <ElButton :disabled="!canPublish || !canRead || busy || saving || loading" @click="rollback(scope.row)">回滚为新版本</ElButton>
      </template></ElTableColumn>
    </ElTable>
    <ElPagination v-model:current-page="page" v-model:page-size="size" :total="total" :page-sizes="[20, 50, 100]"
      layout="total, sizes, prev, pager, next" @current-change="load" @size-change="page = 1; load()" />
  </ElDialog>
</template>
