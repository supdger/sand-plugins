<script setup lang="ts">
  import { reactive, watch } from 'vue'
  import { isRecord, parseConditionOrScope } from '../api/policyJson'

  interface ConditionEntry {
    key: string
    value: string
  }

  interface Props {
    readonly modelValue: string
    readonly label: string
  }

  const props = defineProps<Props>()
  const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

  const equalsEntries = reactive<ConditionEntry[]>([])
  const inEntries = reactive<ConditionEntry[]>([])

  function scalarText(value: unknown): string {
    if (value === null) return ''
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      return String(value)
    }
    return ''
  }

  function reset(raw: string): void {
    equalsEntries.splice(0)
    inEntries.splice(0)
    try {
      const value = parseConditionOrScope(raw, props.label)
      const equals = value.equals
      if (isRecord(equals)) {
        for (const [key, item] of Object.entries(equals)) {
          equalsEntries.push({ key, value: scalarText(item) })
        }
      }
      const inValues = value.in
      if (isRecord(inValues)) {
        for (const [key, item] of Object.entries(inValues)) {
          const text = Array.isArray(item) ? item.map(scalarText).filter(Boolean).join(', ') : ''
          inEntries.push({ key, value: text })
        }
      }
    } catch {
      return
    }
  }

  function emitValue(): void {
    const equals: Record<string, string> = {}
    const inValues: Record<string, string[]> = {}
    for (const entry of equalsEntries) {
      if (entry.key.trim() !== '' && entry.value.trim() !== '') {
        equals[entry.key.trim()] = entry.value.trim()
      }
    }
    for (const entry of inEntries) {
      const values = entry.value
        .split(',')
        .map((value) => value.trim())
        .filter((value) => value !== '')
      if (entry.key.trim() !== '' && values.length > 0) inValues[entry.key.trim()] = values
    }
    const value: Record<string, unknown> = {}
    if (Object.keys(equals).length > 0) value.equals = equals
    if (Object.keys(inValues).length > 0) value.in = inValues
    emit('update:modelValue', JSON.stringify(value))
  }

  function addEquals(): void {
    equalsEntries.push({ key: '', value: '' })
  }

  function addIn(): void {
    inEntries.push({ key: '', value: '' })
  }

  function remove(entries: ConditionEntry[], index: number): void {
    entries.splice(index, 1)
    emitValue()
  }

  watch(
    () => props.modelValue,
    (value) => reset(value),
    { immediate: true }
  )
</script>

<template>
  <div class="w-full space-y-3">
    <div>
      <div class="mb-2 flex items-center justify-between text-sm">
        <span>等于（equals）</span>
        <ElButton text type="primary" @click="addEquals">添加条件</ElButton>
      </div>
      <div v-for="(entry, index) in equalsEntries" :key="`equals-${index}`" class="mb-2 flex gap-2">
        <ElInput
          v-model="entry.key"
          placeholder="字段，例如 order_status（业务状态）"
          @change="emitValue"
        />
        <ElInput v-model="entry.value" placeholder="值，例如 active" @change="emitValue" />
        <ElButton text type="danger" @click="remove(equalsEntries, index)">删除</ElButton>
      </div>
    </div>
    <div>
      <div class="mb-2 flex items-center justify-between text-sm">
        <span>包含任一值（in）</span>
        <ElButton text type="primary" @click="addIn">添加条件</ElButton>
      </div>
      <div v-for="(entry, index) in inEntries" :key="`in-${index}`" class="mb-2 flex gap-2">
        <ElInput
          v-model="entry.key"
          placeholder="字段，例如 region（业务区域）"
          @change="emitValue"
        />
        <ElInput
          v-model="entry.value"
          placeholder="值，例如 beijing, shanghai"
          @change="emitValue"
        />
        <ElButton text type="danger" @click="remove(inEntries, index)">删除</ElButton>
      </div>
    </div>
    <p class="m-0 text-xs text-gray-500">
      例如 case_status equals active，或 region in beijing,
      shanghai。字段和值来自接入应用的业务属性；留空即不添加条件，只支持 equals 和
      in，无需编写格式化内容。
    </p>
  </div>
</template>
