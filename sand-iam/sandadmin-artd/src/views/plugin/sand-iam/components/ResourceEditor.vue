<script setup lang="ts">
  import { computed, reactive, watch } from 'vue'
  import { ElMessage } from 'element-plus'
  import { parseConditionOrScope, parseJsonObject } from '../api/policyJson'
  import type { SandIamFormField, SandIamResourceRow, SandIamWriteMode } from '../api/types'

  interface Props {
    readonly modelValue: boolean
    readonly title: string
    readonly fields: readonly SandIamFormField[]
    readonly creating: boolean
    readonly row: SandIamResourceRow | null
    readonly writeMode: SandIamWriteMode
  }

  const props = defineProps<Props>()
  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    submit: [payload: Readonly<Record<string, unknown>>]
  }>()

  const form = reactive<Record<string, string>>({})

  const visibleFields = computed(() =>
    props.fields.filter((field) => {
      if (props.creating) return field.updateOnly !== true
      return field.createOnly !== true
    })
  )

  function fieldString(row: SandIamResourceRow | null, key: string): string {
    if (row === null) return key === 'status' ? '1' : key === 'condition' || key === 'scope' || key === 'quota_policy' || key === 'network_policy' ? '{}' : ''
    const value = row[key]
    if (value === null || value === undefined) {
      return key === 'condition' || key === 'scope' || key === 'quota_policy' || key === 'network_policy' ? '{}' : ''
    }
    if (typeof value === 'object') return JSON.stringify(value)
    return String(value)
  }

  function resetForm(): void {
    for (const key of Object.keys(form)) delete form[key]
    for (const field of props.fields) {
      form[field.key] = fieldString(props.creating ? null : props.row, field.key)
    }
  }

  watch(
    () => [props.modelValue, props.creating, props.row] as const,
    () => {
      if (props.modelValue) resetForm()
    }
  )

  function parseNumber(raw: string, label: string, required: boolean): number | undefined {
    if (raw.trim() === '') {
      if (required) throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label} is required`)
      return undefined
    }
    const parsed = Number(raw)
    if (!Number.isInteger(parsed) || parsed < 0) {
      throw new Error(`SAND_IAM_VALIDATION_ERROR: ${label} 必须是正整数`)
    }
    return parsed
  }

  function buildPayload(): Readonly<Record<string, unknown>> {
    const payload: Record<string, unknown> = {}
    if (!props.creating && props.row !== null) {
      const id = props.row.id
      if (typeof id === 'number') payload.id = id
    }
    for (const field of visibleFields.value) {
      const raw = form[field.key] ?? ''
      if (field.kind === 'status') {
        payload[field.key] = raw === '2' ? 2 : 1
        continue
      }
      if (field.kind === 'number') {
        const parsed = parseNumber(raw, field.key, field.required === true)
        if (parsed !== undefined) payload[field.key] = parsed
        continue
      }
      if (field.kind === 'json') {
        if (field.key === 'condition' || field.key === 'scope') {
          payload[field.key] = parseConditionOrScope(raw, field.key)
        } else {
          payload[field.key] = parseJsonObject(raw, field.key)
        }
        continue
      }
      if (field.required === true && raw.trim() === '') {
        throw new Error(`SAND_IAM_VALIDATION_ERROR: ${field.key} is required`)
      }
      if (raw.trim() !== '') payload[field.key] = raw.trim()
    }
    if (props.writeMode === 'policy') {
      const roleId = typeof payload.role_id === 'number' ? payload.role_id : 0
      const identityId = typeof payload.identity_id === 'number' ? payload.identity_id : 0
      if ((roleId > 0) === (identityId > 0)) {
        throw new Error('SAND_IAM_VALIDATION_ERROR: exactly one policy subject is required')
      }
    }
    if (props.writeMode === 'binding' && !props.creating) {
      return { id: payload.id, status: payload.status }
    }
    return payload
  }

  function submit(): void {
    try {
      emit('submit', buildPayload())
    } catch (error: unknown) {
      ElMessage.error(error instanceof Error ? error.message : 'SAND_IAM_VALIDATION_ERROR')
    }
  }
</script>

<template>
  <ElDialog
    :model-value="modelValue"
    :title="title"
    width="640px"
    destroy-on-close
    @close="emit('update:modelValue', false)"
  >
    <ElForm label-width="160px">
      <ElFormItem v-for="field in visibleFields" :key="field.key" :label="field.label">
        <ElSelect v-if="field.kind === 'status'" v-model="form[field.key]" style="width: 100%">
          <ElOption label="启用 (1)" value="1" />
          <ElOption label="停用 (2)" value="2" />
        </ElSelect>
        <ElSelect
          v-else-if="field.kind === 'select' && field.options"
          v-model="form[field.key]"
          style="width: 100%"
        >
          <ElOption v-for="option in field.options" :key="option.value" :label="option.label" :value="option.value" />
        </ElSelect>
        <ElInput
          v-else-if="field.kind === 'json'"
          v-model="form[field.key]"
          type="textarea"
          :rows="4"
          placeholder='{"equals":{},"in":{}}'
        />
        <ElInput v-else v-model="form[field.key]" :placeholder="field.kind === 'number' ? '正整数' : ''" />
      </ElFormItem>
    </ElForm>
    <template #footer>
      <ElButton @click="emit('update:modelValue', false)">取消</ElButton>
      <ElButton type="primary" @click="submit">提交</ElButton>
    </template>
  </ElDialog>
</template>
