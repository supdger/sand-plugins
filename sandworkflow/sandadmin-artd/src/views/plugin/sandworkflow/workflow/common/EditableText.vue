<template>
  <div class="editable-text" @click="startEditing">
    <a-input v-if="editing" ref="inputRef" v-model:model-value="text" :max-length="16" @blur="finishEditing" />
    <template v-else>
      <span>{{ value }}</span>
      <icon-edit v-if="hoverable" />
    </template>
  </div>
</template>

<script setup>
import { nextTick, ref } from 'vue'

const props = defineProps({
  value: { type: String, default: '' },
  hoverable: { type: Boolean, default: false }
})
const emit = defineEmits(['change'])
const editing = ref(false)
const inputRef = ref()
const text = ref('')

function startEditing() {
  if (editing.value) return
  editing.value = true
  text.value = props.value
  nextTick(() => inputRef.value?.focus())
}

function finishEditing() {
  editing.value = false
  emit('change', text.value)
}
</script>

<style lang="less" scoped>
.editable-text { display: flex; align-items: center; gap: 8px; min-width: 200px; min-height: 32px; cursor: pointer; }
</style>
