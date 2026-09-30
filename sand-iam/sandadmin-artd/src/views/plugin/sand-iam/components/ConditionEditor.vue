<script setup lang="ts">
  import { reactive, ref, watch } from 'vue'
  import { isRecord, parseConditionOrScope } from '../api/policyJson'
  import { conditionScalar, conditionValueDraft, isConditionScalar } from '../api/conditionValues'
  import type { ConditionValueDraft } from '../api/conditionValues'

  interface ConditionEntry { key: string; values: ConditionValueDraft[] }
  const props = defineProps<{ readonly modelValue: string; readonly label: string }>()
  const emit = defineEmits<{
    'update:modelValue': [value: string]
    validation: [message: string]
  }>()
  const groups = reactive<{ equals: ConditionEntry[]; in: ConditionEntry[] }>({ equals: [], in: [] })
  const error = ref('')
  let lastEmitted: string | null = null
  const operators = [
    { key: 'equals', label: '等于', action: '添加相等条件' },
    { key: 'in', label: '等于以下任一值', action: '添加多值条件' }
  ] as const

  function reset(raw: string): void {
    if (raw === lastEmitted) return
    groups.equals.splice(0)
    groups.in.splice(0)
    error.value = ''
    try {
      const parsed = parseConditionOrScope(raw, props.label)
      if (isRecord(parsed.equals)) {
        for (const [key, value] of Object.entries(parsed.equals)) {
          if (isConditionScalar(value)) groups.equals.push({ key, values: [conditionValueDraft(value)] })
        }
      }
      if (isRecord(parsed.in)) {
        for (const [key, value] of Object.entries(parsed.in)) {
          if (Array.isArray(value)) groups.in.push({
            key, values: value.filter(isConditionScalar).map(conditionValueDraft)
          })
        }
      }
    } catch {
      error.value = '已有规则无法读取，请联系接入开发者核对规则格式；修正前不能保存。'
    }
    emit('validation', error.value)
  }

  function change(): void {
    try {
      const result: Record<string, unknown> = {}
      for (const operator of operators) {
        const fields: Record<string, unknown> = {}
        for (const entry of groups[operator.key]) {
          const key = entry.key.trim()
          if (key === '') throw new Error('请填写开发者提供的业务字段名，或删除这条条件。')
          if (Object.hasOwn(fields, key)) throw new Error('同一种条件不能重复填写同一个字段。')
          if (entry.values.length === 0) throw new Error('请至少填写一个值，或删除这条条件。')
          const values = entry.values.map(conditionScalar)
          fields[key] = operator.key === 'equals' ? values[0] : values
        }
        if (Object.keys(fields).length > 0) result[operator.key] = fields
      }
      error.value = ''
      lastEmitted = JSON.stringify(result)
      emit('update:modelValue', lastEmitted)
    } catch (cause: unknown) {
      error.value = cause instanceof Error ? cause.message : '请检查条件和值。'
    }
    emit('validation', error.value)
  }

  function add(operator: 'equals' | 'in'): void {
    groups[operator].push({ key: '', values: [conditionValueDraft('')] })
    change()
  }
  watch(() => props.modelValue, reset, { immediate: true })
</script>

<template>
  <div class="w-full space-y-3">
    <p class="m-0 text-xs text-gray-500">
      向应用开发者取得字段名、值和类型。例如组织编号 12 应选“数字”，业务状态 active
      应选“文字”。留空表示不限制这一项；这里不支持自动把“本部门”替换成员工部门。
      数据范围仍需接入应用用于列表查询和单条访问检查。
    </p>
    <section v-for="operator in operators" :key="operator.key">
      <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm">
        <span>{{ operator.label }}</span>
        <ElButton text type="primary" @click="add(operator.key)">{{ operator.action }}</ElButton>
      </div>
      <div v-for="(entry, index) in groups[operator.key]" :key="index" class="mb-3 space-y-2">
        <div class="flex gap-2">
          <ElInput v-model="entry.key" aria-label="业务字段名" placeholder="开发者提供的字段，例如 organization_id" @input="change" />
          <ElButton text type="danger" @click="groups[operator.key].splice(index, 1); change()">删除条件</ElButton>
        </div>
        <div v-for="(value, valueIndex) in entry.values" :key="valueIndex" class="flex flex-wrap gap-2">
          <ElSelect v-model="value.type" aria-label="值类型" style="width: 112px" @change="change">
            <ElOption label="文字" value="text" />
            <ElOption label="数字" value="number" />
            <ElOption label="是／否" value="boolean" />
            <ElOption label="空值" value="null" />
          </ElSelect>
          <ElSelect v-if="value.type === 'boolean'" v-model="value.text" aria-label="条件值" style="width: 160px" @change="change">
            <ElOption label="是（true）" value="true" />
            <ElOption label="否（false）" value="false" />
          </ElSelect>
          <ElInput v-else-if="value.type !== 'null'" v-model="value.text" aria-label="条件值"
            :placeholder="value.type === 'number' ? '例如 12' : '例如 active'"
            style="flex: 1; min-width: 140px" @input="change" />
          <span v-else>空值（null），与空文字不同</span>
          <ElButton v-if="operator.key === 'in'" text @click="entry.values.splice(valueIndex, 1); change()">移除此值</ElButton>
        </div>
        <ElButton v-if="operator.key === 'in'" text type="primary"
          @click="entry.values.push(conditionValueDraft('')); change()">添加一个值</ElButton>
      </div>
    </section>
    <p v-if="error" role="alert" class="m-0 text-sm text-red-500">{{ error }}</p>
  </div>
</template>
