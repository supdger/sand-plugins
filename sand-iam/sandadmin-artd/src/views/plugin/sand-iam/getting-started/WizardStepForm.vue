<script setup lang="ts">
  import { computed, reactive, watch } from 'vue'
  import { describeSandIamObjectCodeError } from '../api/uxContracts'
  import type { WizardRecord, WizardStep } from './wizardState'

  interface Props {
    readonly step: WizardStep
    readonly record: WizardRecord | null
    readonly parentLabel: string
    readonly saving: boolean
  }

  const props = defineProps<Props>()
  const emit = defineEmits<{
    submit: [payload: Readonly<Record<string, string | number>>]
  }>()

  const form = reactive({ name: '', code: '', status: '1' })
  const validationMessage = computed(() => {
    if (form.name.trim() === '') return '请填写名称。'
    return describeSandIamObjectCodeError(form.code)
  })
  const isEditing = computed(() => props.record !== null)
  const parentTitle = computed(() =>
    props.step === 'application' ? '所属客户主体' : '所属接入应用'
  )
  const title = computed(() => {
    if (props.step === 'organization') return '客户主体'
    if (props.step === 'application') return '接入应用'
    return '应用环境'
  })

  function resetForm(): void {
    form.name = props.record?.name ?? ''
    form.code = props.record?.code ?? ''
    form.status = String(props.record?.status ?? 1)
  }

  function submit(): void {
    if (validationMessage.value !== null || props.saving) return
    emit('submit', {
      name: form.name.trim(),
      code: form.code.trim(),
      status: form.status === '2' ? 2 : 1
    })
  }

  watch(() => props.record, resetForm, { immediate: true })
</script>

<template>
  <ElForm label-position="top" @submit.prevent="submit">
    <ElAlert
      v-if="step !== 'organization'"
      class="mb-4"
      type="info"
      :closable="false"
      :title="`${parentTitle}：${parentLabel}`"
      description="此归属由本次向导的上一步确定。需要更换时，请返回上一步重新选择或创建。"
    />
    <ElFormItem :label="`${title}名称`" required>
      <ElInput v-model="form.name" :disabled="saving" autocomplete="off" />
    </ElFormItem>
    <ElFormItem label="系统代码（用于接口配置）" required>
      <ElInput v-model="form.code" :disabled="saving || isEditing" autocomplete="off" />
      <p class="mb-0 mt-1 text-xs text-gray-500">
        创建后不可修改；只能使用小写字母、数字、短横线和下划线。
      </p>
    </ElFormItem>
    <ElFormItem label="状态">
      <ElRadioGroup v-model="form.status" :disabled="saving">
        <ElRadio value="1">已启用</ElRadio>
        <ElRadio value="2">已停用</ElRadio>
      </ElRadioGroup>
    </ElFormItem>
    <ElAlert
      v-if="validationMessage !== null"
      class="mb-4"
      type="warning"
      :closable="false"
      :title="validationMessage"
    />
    <ElButton
      native-type="submit"
      type="primary"
      :loading="saving"
      :disabled="saving || validationMessage !== null"
    >
      {{ isEditing ? '保存修改' : `创建${title}` }}
    </ElButton>
  </ElForm>
</template>
