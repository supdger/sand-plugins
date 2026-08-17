<template>
  <ElDialog
    v-model="visible"
    width="720px"
    top="4vh"
    :show-close="false"
    :close-on-click-modal="false"
    destroy-on-close
    class="flow-launch-dialog"
  >
    <template #header>
      <div class="launch-header">
        <div>
          <strong>{{ definition?.name || '发起审批' }}</strong
          ><span>{{ definition?.description || '请填写审批内容后提交' }}</span>
        </div>
        <ElButton text @click="visible = false">关闭</ElButton>
      </div>
    </template>
    <main class="launch-main">
      <section class="launch-sheet">
        <div class="section-title"><span>申请信息</span></div>
        <ElForm ref="formRef" :model="formModel" label-position="top" class="launch-form">
          <ElFormItem
            label="审批标题"
            required
            prop="title"
            :rules="[{ required: true, message: '请输入审批标题', trigger: 'blur' }]"
          >
            <ElInput v-model="title" maxlength="150" show-word-limit placeholder="请输入审批标题" />
          </ElFormItem>
          <template v-for="widget in widgets" :key="widget.name">
            <ElDivider v-if="widget.type === WidgetType.Describe" content-position="left">{{
              widget.placeholder
            }}</ElDivider>
            <ElFormItem
              v-else
              :label="widget.label"
              :prop="widget.name"
              :required="widget.required"
              :rules="
                widget.required
                  ? [
                      {
                        required: true,
                        message: `请填写${widget.label}`,
                        trigger: 'change'
                      }
                    ]
                  : []
              "
            >
              <ElInput
                v-if="isTextWidget(widget.type)"
                :model-value="stringValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                :placeholder="widget.placeholder"
              />
              <ElInput
                v-else-if="
                  widget.type === WidgetType.Multiline || widget.type === WidgetType.RichText
                "
                :model-value="stringValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                type="textarea"
                :rows="4"
                :placeholder="widget.placeholder"
              />
              <ElInputNumber
                v-else-if="
                  widget.type === WidgetType.Number ||
                  widget.type === WidgetType.Money ||
                  widget.type === WidgetType.Formula
                "
                :model-value="numberValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                class="full-width"
              />
              <ElRadioGroup
                v-else-if="widget.type === WidgetType.SingleChoice"
                :model-value="stringValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                ><ElRadio v-for="option in widget.options" :key="option" :value="option">{{
                  option
                }}</ElRadio></ElRadioGroup
              >
              <ElCheckboxGroup
                v-else-if="widget.type === WidgetType.MultiChoice"
                :model-value="stringArrayValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                ><ElCheckbox v-for="option in widget.options" :key="option" :value="option">{{
                  option
                }}</ElCheckbox></ElCheckboxGroup
              >
              <ElDatePicker
                v-else-if="widget.type === WidgetType.Date"
                :model-value="stringValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                :type="widget.format === 'YYYY-MM-DD HH:mm' ? 'datetime' : 'date'"
                value-format="YYYY-MM-DD HH:mm:ss"
                class="full-width"
              />
              <ElDatePicker
                v-else-if="widget.type === WidgetType.DateRange"
                :model-value="dateRangeValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                :type="widget.format === 'YYYY-MM-DD HH:mm' ? 'datetimerange' : 'daterange'"
                value-format="YYYY-MM-DD HH:mm:ss"
                class="full-width"
              />
              <ElRate
                v-else-if="widget.type === WidgetType.Rate"
                :model-value="numberValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                allow-half
              />
              <ElSelect
                v-else-if="widget.type === WidgetType.Employee"
                :model-value="stringValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                :placeholder="widget.placeholder || '请选择员工'"
                filterable
                clearable
                class="full-width"
              >
                <ElOption
                  v-for="user in users"
                  :key="user.id"
                  :label="userLabel(user)"
                  :value="String(user.id)"
                />
              </ElSelect>
              <ElSelect
                v-else-if="widget.type === WidgetType.Department"
                :model-value="stringValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                :placeholder="widget.placeholder || '请选择部门'"
                filterable
                clearable
                class="full-width"
              >
                <ElOption
                  v-for="dept in depts"
                  :key="dept.id"
                  :label="dept.name"
                  :value="String(dept.id)"
                />
              </ElSelect>
              <ElCascader
                v-else-if="widget.type === WidgetType.Area"
                :model-value="stringArrayValue(widget.name)"
                @update:model-value="setValue(widget.name, $event)"
                :options="[]"
                placeholder="省市区数据待接入"
                class="full-width"
                disabled
              />
              <ElUpload
                v-else-if="
                  widget.type === WidgetType.Picture || widget.type === WidgetType.Attachment
                "
                action="#"
                :auto-upload="false"
                :on-change="(file) => setUploadValue(widget.name, file.name)"
                ><ElButton>选择文件</ElButton></ElUpload
              >
              <ElButton v-else-if="widget.type === WidgetType.FlowInstance" disabled
                >选择关联审批</ElButton
              >
            </ElFormItem>
          </template>
          <ElEmpty v-if="widgets.length === 0" description="该流程未配置表单字段" />
        </ElForm>

        <template v-if="choiceFields.length">
          <div class="section-title choice-title"><span>选择审批人</span></div>
          <ElForm label-position="top" class="launch-form choice-form">
            <ElFormItem
              v-for="field in choiceFields"
              :key="field.choiceKey"
              :label="`${field.nodeName}（发起时选择）`"
              required
            >
              <ElSelect
                v-model="assigneeChoices[field.choiceKey]"
                multiple
                filterable
                collapse-tags
                collapse-tags-tooltip
                :multiple-limit="25"
                placeholder="请选择 1–25 名成员"
                class="full-width"
              >
                <ElOption
                  v-for="user in users"
                  :key="user.id"
                  :label="userLabel(user)"
                  :value="user.id"
                />
              </ElSelect>
            </ElFormItem>
          </ElForm>
        </template>

        <div class="section-title approval-title"><span>审批流程</span></div>
        <ApprovalFlowTimeline
          :items="approvalItems"
          variant="preview"
          class="launch-approval-flow"
        />
      </section>
    </main>
    <template #footer
      ><ElButton @click="visible = false">取消</ElButton
      ><ElButton type="primary" :loading="submitting" @click="submit">提交审批</ElButton></template
    >
  </ElDialog>
</template>

<script setup lang="ts">
  import { ElMessage, type FormInstance } from 'element-plus'
  import { computed, reactive, ref, watch } from 'vue'
  import api, { type FlowDefinition, type FlowDept, type FlowUser, type QueryParams } from '../../api'
  import ApprovalFlowTimeline from '../../components/approval-flow-timeline.vue'
  import {
    buildDefinitionPreview,
    collectInitiatorChoiceFields,
    collectMissingInitiatorChoiceKeys
  } from '../../components/approval-flow'

  enum WidgetType {
    SingleLine = 0,
    Multiline = 1,
    Describe = 2,
    Number = 3,
    Money = 4,
    SingleChoice = 5,
    MultiChoice = 6,
    Date = 7,
    DateRange = 8,
    Detail = 9,
    Picture = 10,
    Attachment = 11,
    Department = 12,
    Employee = 13,
    FlowInstance = 15,
    Area = 16,
    Mailbox = 17,
    Mobile = 18,
    IdCard = 19,
    Website = 20,
    Rate = 21,
    RichText = 22,
    Formula = 23
  }
  interface LaunchWidget {
    name: string
    type: WidgetType
    label: string
    placeholder: string
    required: boolean
    options: string[]
    format: 'YYYY-MM-DD' | 'YYYY-MM-DD HH:mm'
  }

  const props = defineProps<{
    modelValue: boolean
    definition?: FlowDefinition
  }>()
  const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void
    (event: 'success'): void
  }>()
  const visible = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value)
  })
  const formRef = ref<FormInstance>()
  const title = ref('')
  const formValue = reactive<Record<string, unknown>>({})
  const formModel = computed<Record<string, unknown>>(() => ({
    title: title.value,
    ...formValue
  }))
  const submitting = ref(false)
  const users = ref<FlowUser[]>([])
  const depts = ref<FlowDept[]>([])
  const assigneeChoices = reactive<Record<string, number[]>>({})
  const widgets = computed(() => extractWidgets(props.definition?.definition_json))
  const choiceFields = computed(() =>
    collectInitiatorChoiceFields(props.definition?.definition_json)
  )
  const approvalItems = computed(() => buildDefinitionPreview(props.definition?.definition_json))

  watch(
    () => [props.modelValue, props.definition] as const,
    async ([isVisible, definition]) => {
      if (!isVisible || !definition) return
      title.value = definition.name
      for (const key of Object.keys(formValue)) delete formValue[key]
      for (const key of Object.keys(assigneeChoices)) delete assigneeChoices[key]
      for (const widget of extractWidgets(definition.definition_json))
        formValue[widget.name] =
          widget.type === WidgetType.MultiChoice || widget.type === WidgetType.DateRange
            ? []
            : undefined
      for (const field of collectInitiatorChoiceFields(definition.definition_json)) {
        assigneeChoices[field.choiceKey] = []
      }
      await ensureOrganOptions()
    },
    { immediate: true }
  )

  async function ensureOrganOptions() {
    const tasks: Promise<void>[] = []
    if (users.value.length === 0) {
      tasks.push(
        api.organization
          .users()
          .then((list) => {
            users.value = list
          })
          .catch(() => {
            users.value = []
          })
      )
    }
    if (depts.value.length === 0) {
      tasks.push(
        api.organization
          .depts()
          .then((list) => {
            depts.value = list
          })
          .catch(() => {
            depts.value = []
          })
      )
    }
    if (tasks.length) await Promise.all(tasks)
  }

  function extractWidgets(value: unknown): LaunchWidget[] {
    if (!isRecord(value) || !Array.isArray(value.flowWidgets)) return []
    return value.flowWidgets.filter(isLaunchWidget)
  }
  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }
  function isLaunchWidget(value: unknown): value is LaunchWidget {
    return (
      isRecord(value) &&
      typeof value.name === 'string' &&
      typeof value.type === 'number' &&
      typeof value.label === 'string' &&
      typeof value.placeholder === 'string' &&
      typeof value.required === 'boolean' &&
      Array.isArray(value.options) &&
      value.options.every((item) => typeof item === 'string') &&
      (value.format === 'YYYY-MM-DD' || value.format === 'YYYY-MM-DD HH:mm')
    )
  }
  function isTextWidget(type: WidgetType): boolean {
    return [
      WidgetType.SingleLine,
      WidgetType.Mailbox,
      WidgetType.Mobile,
      WidgetType.IdCard,
      WidgetType.Website
    ].includes(type)
  }
  function setValue(name: string, value: unknown) {
    formValue[name] = value
  }
  function stringValue(name: string): string | undefined {
    const value = formValue[name]
    return typeof value === 'string' ? value : undefined
  }
  function numberValue(name: string): number | undefined {
    const value = formValue[name]
    return typeof value === 'number' ? value : undefined
  }
  function stringArrayValue(name: string): string[] {
    const value = formValue[name]
    return Array.isArray(value) && value.every((item) => typeof item === 'string') ? value : []
  }
  function dateRangeValue(name: string): [string, string] | undefined {
    const value = stringArrayValue(name)
    return value.length >= 2 ? [value[0], value[1]] : undefined
  }
  function setUploadValue(name: string, fileName: string) {
    formValue[name] = fileName
  }
  function userLabel(user: FlowUser): string {
    const name = user.nickname?.trim() || user.username?.trim()
    return name ? `${name}` : `用户 ${user.id}`
  }
  function buildAssigneeChoicesPayload(): Record<string, number[]> | undefined {
    if (choiceFields.value.length === 0) return undefined
    const payload: Record<string, number[]> = {}
    for (const field of choiceFields.value) {
      const selected = assigneeChoices[field.choiceKey] || []
      const unique = [...new Set(selected.filter((id) => Number.isFinite(id) && id > 0))]
      payload[field.choiceKey] = unique
    }
    return payload
  }
  function validateAssigneeChoices(): string | undefined {
    const missingKeys = collectMissingInitiatorChoiceKeys(props.definition?.definition_json)
    if (missingKeys.length > 0) {
      return `节点「${missingKeys.join('、')}」缺少 choiceKey，请重新发布流程定义`
    }
    for (const field of choiceFields.value) {
      const selected = assigneeChoices[field.choiceKey] || []
      if (selected.length === 0) {
        return `请为「${field.nodeName}」选择至少 1 名处理人`
      }
      if (selected.length > 25) {
        return `「${field.nodeName}」最多选择 25 名处理人`
      }
    }
    return undefined
  }
  async function submit() {
    if (!title.value.trim()) {
      ElMessage.warning('请输入审批标题')
      return
    }
    if (!(await formRef.value?.validate().catch(() => false)) || !props.definition) return
    const choiceError = validateAssigneeChoices()
    if (choiceError) {
      ElMessage.warning(choiceError)
      return
    }
    submitting.value = true
    try {
      const assignee_choices = buildAssigneeChoicesPayload()
      const payload: QueryParams = {
        definition_id: props.definition.id,
        name: title.value,
        form_value: { ...formValue },
        request_id: crypto.randomUUID(),
        ...(assignee_choices ? { assignee_choices } : {})
      }
      await api.instance.start(payload)
      ElMessage.success('发起成功')
      visible.value = false
      emit('success')
    } finally {
      submitting.value = false
    }
  }
</script>

<style scoped>
  :deep(.flow-launch-dialog .el-dialog__body) {
    padding: 0;
    overflow: hidden;
    background: #fff;
  }
  :deep(.flow-launch-dialog) {
    max-width: calc(100vw - 32px);
    border-radius: 4px;
  }
  :deep(.flow-launch-dialog .el-dialog__header) {
    padding: 18px 24px;
    margin: 0;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
  :deep(.flow-launch-dialog .el-dialog__footer) {
    padding: 14px 24px;
    border-top: 1px solid var(--el-border-color-lighter);
  }
  .launch-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
  }
  .launch-header div {
    display: grid;
    gap: 4px;
  }
  .launch-header span {
    color: var(--el-text-color-secondary);
    font-size: 13px;
  }
  .launch-main {
    max-height: calc(92vh - 142px);
    padding: 0 24px;
    overflow: auto;
  }
  .launch-sheet {
    width: min(620px, 100%);
    padding: 20px 0 28px;
    margin: 0 auto;
  }
  .section-title {
    position: relative;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    color: var(--el-text-color-primary);
    font-size: 14px;
    font-weight: 600;
  }
  .section-title::before,
  .section-title::after {
    height: 1px;
    content: '';
    background: var(--el-border-color-lighter);
  }
  .section-title::before {
    width: 20px;
  }
  .section-title::after {
    flex: 1;
  }
  .launch-form :deep(.el-form-item) {
    margin-bottom: 14px;
  }
  .launch-form :deep(.el-form-item__label) {
    height: auto;
    padding-bottom: 6px;
    color: var(--el-text-color-secondary);
    line-height: 1.4;
  }
  .launch-form :deep(.el-input__wrapper),
  .launch-form :deep(.el-textarea__inner),
  .launch-form :deep(.el-input-number),
  .launch-form :deep(.el-select__wrapper) {
    background: var(--el-fill-color-lighter);
    box-shadow: none;
  }
  .launch-form :deep(.el-input__wrapper.is-focus),
  .launch-form :deep(.el-select__wrapper.is-focused) {
    box-shadow: 0 0 0 1px var(--el-color-primary) inset;
  }
  .approval-title {
    margin-top: 26px;
  }
  .choice-title {
    margin-top: 26px;
  }
  .choice-form {
    margin-bottom: 8px;
  }
  .launch-approval-flow {
    max-width: 360px;
    margin-left: 4px;
  }
  .full-width {
    width: 100%;
  }

  @media (max-width: 720px) {
    .launch-main {
      padding: 0 16px;
    }
  }
</style>
