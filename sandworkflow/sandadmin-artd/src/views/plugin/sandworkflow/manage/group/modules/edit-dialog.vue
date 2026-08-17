<template>
  <el-dialog
    v-model="visible"
    :title="dialogType === 'add' ? '新增分组' : '编辑分组'"
    width="520px"
    :close-on-click-modal="false"
  >
    <el-form ref="formRef" :model="formData" :rules="rules" label-width="90px">
      <el-form-item label="分组名称" prop="name">
        <el-input
          v-model="formData.name"
          placeholder="请输入分组名称"
          clearable
        />
      </el-form-item>
      <el-form-item label="分组说明" prop="description">
        <el-input
          v-model="formData.description"
          type="textarea"
          placeholder="请输入分组说明"
        />
      </el-form-item>
      <el-form-item label="排序" prop="sort">
        <el-input-number v-model="formData.sort" :min="0" :max="9999" />
      </el-form-item>
      <el-form-item label="状态" prop="status">
        <el-switch v-model="enabled" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="submitting" @click="submit"
        >保存</el-button
      >
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from "element-plus";
import api, { type FlowGroup } from "../../../api";

export type GroupDialogType = "add" | "edit";

const props = defineProps<{
  modelValue: boolean;
  dialogType: GroupDialogType;
  data?: Partial<FlowGroup>;
}>();
const emit = defineEmits<{
  (e: "update:modelValue", value: boolean): void;
  (e: "success"): void;
}>();

interface GroupForm {
  id?: number;
  name: string;
  description: string;
  sort: number;
  status: number;
}

const formRef = ref<FormInstance>();
const submitting = ref(false);
const formData = reactive<GroupForm>({
  name: "",
  description: "",
  sort: 100,
  status: 1,
});
const rules: FormRules<GroupForm> = {
  name: [{ required: true, message: "请输入分组名称", trigger: "blur" }],
};
const visible = computed({
  get: () => props.modelValue,
  set: (val) => emit("update:modelValue", val),
});
const enabled = computed({
  get: () => formData.status === 1,
  set: (val: boolean) => {
    formData.status = val ? 1 : 2;
  },
});

watch(
  () => [props.modelValue, props.data],
  async () => {
    Object.assign(
      formData,
      { id: undefined, name: "", description: "", sort: 100, status: 1 },
      props.data || {},
    );
    formRef.value?.clearValidate();
    if (props.modelValue && props.dialogType === "edit" && props.data?.id) {
      Object.assign(formData, await api.group.read(props.data.id));
    }
  },
  { immediate: true },
);

async function submit() {
  if (!(await formRef.value?.validate().catch(() => false))) return;
  submitting.value = true;
  try {
    const payload: Record<string, unknown> = { ...formData };
    if (props.dialogType === "add") {
      delete payload.id;
      await api.group.save(payload);
    } else {
      await api.group.update(payload);
    }
    ElMessage.success("保存成功");
    visible.value = false;
    emit("success");
  } finally {
    submitting.value = false;
  }
}
</script>
