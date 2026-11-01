<script setup lang="ts">
  import { computed, reactive, watch } from 'vue'
  import { describeSandIamObjectCodeError } from '../api/uxContracts'
  import type { WizardRecord, WizardStep } from './wizardState'
  import { readWizardDraft } from './wizardEntry'

  interface Props {
    readonly step: WizardStep
    readonly record: WizardRecord | null
    readonly parentLabel: string
    readonly saving: boolean
    readonly draftKey?: string
  }

  const props = defineProps<Props>()
  const emit = defineEmits<{
    submit: [payload: Readonly<Record<string, string | number>>]
  }>()

  const example = computed(() => props.step === 'organization'
    ? { name: '示例公司', code: 'example-company', source: '填使用系统的公司或客户名称；单公司自用填自己公司，不填部门。代码由管理员与应用负责人约定，全局唯一。' }
    : props.step === 'application'
      ? { name: '工作项系统', code: 'work-items', source: '填员工要使用的产品或系统名称，由应用负责人提供。代码由管理员与开发者约定，在所属客户主体内唯一。' }
      : { name: '测试环境', code: 'test', source: '请运维确认本次接入测试还是生产环境。环境代码可用 test 或 production，在所属应用内唯一。' })
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
    let draft = null
    try {
      if (props.draftKey) {
        if (props.record) localStorage.removeItem(props.draftKey)
        else draft = readWizardDraft(localStorage.getItem(props.draftKey), Date.now())
      }
    } catch { /* Browser storage is optional; the current form remains usable. */ }
    form.name = props.record?.name ?? draft?.name ?? ''
    form.code = props.record?.code ?? draft?.code ?? ''
    form.status = String(props.record?.status ?? draft?.status ?? 1)
  }

  function submit(): void {
    if (validationMessage.value !== null || props.saving) return
    emit('submit', {
      name: form.name.trim(),
      code: form.code.trim(),
      status: form.status === '2' ? 2 : 1
    })
  }

  watch(() => [props.record, props.draftKey], resetForm, { immediate: true })
  watch(form, () => {
    if (!props.draftKey || props.record) return
    try { localStorage.setItem(props.draftKey, JSON.stringify({ ...form, savedAt: Date.now() })) } catch { /* optional draft storage */ }
  })
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
      <ElInput v-model="form.name" :disabled="saving" :placeholder="example.name" autocomplete="off" />
      <p class="mb-0 mt-1 text-xs text-gray-500">{{ example.source }}</p>
    </ElFormItem>
    <ElFormItem label="系统代码（用于接口配置）" required>
      <ElInput v-model="form.code" :disabled="saving || isEditing" :placeholder="example.code" autocomplete="off" />
      <p class="mb-0 mt-1 text-xs text-gray-500">
        为这个对象约定一个固定英文简称，例如 {{ example.code }}。创建后不可修改；2–64 位小写字母、数字、短横线和下划线，以字母或数字开头。应用代码会交给开发者配置接口。
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
