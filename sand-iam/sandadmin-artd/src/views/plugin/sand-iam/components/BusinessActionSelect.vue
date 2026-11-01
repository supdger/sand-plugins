<script setup lang="ts">
  import { computed, onScopeDispose, ref, watch } from 'vue'
  import { useRoute } from 'vue-router'
  import { getSandIamAdmin } from '../api/write'
  import { describeSandIamError } from '../api/errors'
  import { normalizeReferenceValue } from '../api/referenceValues'

  const props = defineProps<{ modelValue: string; applicationId: number | null }>()
  const emit = defineEmits<{
    'update:modelValue': [value: string]
    validation: [message: string]
  }>()
  const route = useRoute()
  interface ActionOption { readonly code: string; readonly name: string; readonly enabled: boolean }
  const actions = ref<ActionOption[]>([])
  const loading = ref(false)
  const error = ref('')
  let generation = 0
  let disposed = false
  const selected = computed(() => actions.value.find(item => item.code === props.modelValue))
  const validation = computed(() => {
    if (props.applicationId === null) return '请先选择接入应用。'
    if (loading.value) return '正在读取业务动作，请稍候。'
    if (error.value) return error.value
    if (!props.modelValue) return '请选择当前应用已声明并启用的业务动作。'
    if (!selected.value) return `当前动作「${props.modelValue}」尚未在此应用声明。请先登记，再刷新选择。`
    if (!selected.value.enabled) return `当前动作「${props.modelValue}」已停用。请由有权管理员核对，或选择其他启用动作。`
    return ''
  })
  watch(validation, value => emit('validation', value), { immediate: true })
  const actionLocation = computed(() => ({
    path: '/sand-iam/application-business-action',
    query: {
      // Derive the organization again from this selected application, never from an old URL.
      application_id: props.applicationId === null ? undefined : String(props.applicationId),
      ...(route.query.goal === 'access' || route.query.goal === 'api'
        ? { goal: route.query.goal, step: 'business-action' }
        : {})
    }
  }))
  function record(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  async function load(): Promise<void> {
    const attempt = ++generation
    const applicationId = props.applicationId
    actions.value = []
    error.value = ''
    loading.value = applicationId !== null
    if (applicationId === null) return
    try {
      const options: ActionOption[] = []
      // Read all pages so existing selections outside the first page remain resolvable.
      for (let page = 1; ; page++) {
        const response = await getSandIamAdmin('application-business-action/index', {
          application_id: applicationId, page, limit: 100
        }, false)
        if (disposed || attempt !== generation) return
        const data = record(response) && record(response.data) ? response.data : response
        if (!record(data) || !Array.isArray(data.data)) throw new Error('业务动作目录返回格式不正确，请刷新重试。')
        for (const row of data.data) {
          if (!record(row) || normalizeReferenceValue(row.application_id) !== applicationId ||
            typeof row.code !== 'string' || typeof row.name !== 'string') continue
          options.push({ code: row.code, name: row.name, enabled: row.status === 1 || row.status === '1' })
        }
        const total = Number(data.total)
        if (data.data.length < 100 || (Number.isFinite(total) && page * 100 >= total)) break
      }
      actions.value = options
    } catch (failure: unknown) {
      if (!disposed && attempt === generation) error.value = describeSandIamError(failure).detail
    } finally {
      if (!disposed && attempt === generation) loading.value = false
    }
  }
  watch(() => props.applicationId, () => { void load() }, { immediate: true, flush: 'sync' })
  onScopeDispose(() => { disposed = true; generation++ })
</script>

<template>
  <div class="w-full">
    <ElSelect
      :model-value="modelValue"
      filterable
      clearable
      :disabled="applicationId === null || loading || !!error"
      :loading="loading"
      placeholder="按名称或代码选择业务动作"
      style="width: 100%"
      @update:model-value="(value: string | undefined) => emit('update:modelValue', value ?? '')"
    >
      <ElOption v-if="modelValue && !selected" :label="`${modelValue}（未声明）`" :value="modelValue" disabled />
      <ElOption
        v-for="action in actions"
        :key="action.code"
        :label="`${action.name}（${action.code}）${action.enabled ? '' : ' · 已停用'}`"
        :value="action.code"
        :disabled="!action.enabled"
      />
    </ElSelect>
    <p v-if="error" role="alert" class="mt-1 text-sm text-red-500">{{ error }}</p>
    <p v-else-if="modelValue && !loading && validation" role="alert" class="mt-1 text-sm text-amber-600">{{ validation }}</p>
    <p v-else-if="!loading && applicationId !== null && !actions.some(item => item.enabled)" class="mt-1 text-sm text-gray-500">
      当前应用没有可用业务动作。请按开发者提供的代码和名称声明并启用，再返回刷新。
    </p>
    <div class="mt-1 flex items-center gap-3">
      <ElButton link :loading="loading" :disabled="applicationId === null" @click="load">刷新动作目录</ElButton>
      <RouterLink v-if="applicationId !== null" :to="actionLocation" target="_blank" rel="noopener">
        打开应用业务动作（新标签页）
      </RouterLink>
    </div>
  </div>
</template>
