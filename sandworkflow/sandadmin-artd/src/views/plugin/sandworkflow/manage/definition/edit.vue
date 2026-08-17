<template>
  <section v-loading="publishing" class="art-full-height flow-edit-page">
    <header class="fd-nav">
      <div class="fd-nav-left">
        <ElButton circle @click="back">
          <ArtSvgIcon icon="ri:arrow-left-line" />
        </ElButton>
        <div class="name-desc">
          <strong>{{ form.name || "新增流程" }}</strong>
          <span>{{ form.description || "未填写流程说明" }}</span>
        </div>
      </div>

      <ElSteps :active="step - 1" class="fd-nav-mid" finish-status="process">
        <ElStep title="基础信息" @click="changeStep(1)" />
        <ElStep title="表单设计" @click="changeStep(2)" />
        <ElStep title="流程设计" @click="changeStep(3)" />
      </ElSteps>

      <div class="fd-nav-right">
        <ElButton type="primary" @click="publish">发布</ElButton>
      </div>
    </header>

    <main class="fd-main">
      <section v-if="step === 1" class="fd-base-box">
        <ElForm
          ref="baseFormRef"
          :model="form"
          :rules="baseRules"
          label-position="top"
          class="base-setting-panel"
        >
          <ElFormItem label="图标" prop="icon">
            <div class="flow-icon-preview">◎</div>
            <ElButton text type="primary">修改</ElButton>
          </ElFormItem>
          <ElFormItem label="名称" prop="name">
            <ElInput v-model="form.name" maxlength="16" show-word-limit />
          </ElFormItem>
          <ElFormItem label="说明">
            <ElInput
              v-model="form.description"
              type="textarea"
              maxlength="64"
              show-word-limit
            />
          </ElFormItem>
          <ElFormItem label="分组" prop="group_id">
            <ElSelect v-model="form.group_id" placeholder="选择流程分组">
              <ElOption
                v-for="group in groups"
                :key="group.id"
                :label="group.name"
                :value="group.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="流程管理员" prop="adminIds">
            <ElSelect
              v-model="form.adminIds"
              multiple
              filterable
              placeholder="请选择流程管理员"
            >
              <ElOption
                v-for="user in users"
                :key="user.id"
                :label="user.nickname || user.username"
                :value="user.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="流程状态">
            <sa-switch v-model="form.status" />
          </ElFormItem>
          <ElFormItem label="允许撤销审批中的申请">
            <sa-switch v-model="form.is_cancelable" />
          </ElFormItem>
        </ElForm>
      </section>

      <FormMake v-else-if="step === 2" ref="formMakeRef" />
      <div v-else class="fd-main-box">
        <FlowDesigner />
      </div>
    </main>
  </section>
</template>

<script setup lang="ts">
import { ElMessage, type FormInstance, type FormRules } from "element-plus";
import { getCurrentInstance, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api, {
  type FlowDefinition,
  type FlowGroup,
  type FlowUser,
  type QueryParams,
} from "../../api";
import FlowDesigner from "../../workflow/flow/index.vue";
import FormMake from "../../workflow/form-make/index.vue";
import { installArco } from "../../workflow/install-arco";
import { useFlowStore, useOrganStore } from "../../workflow/store";

type EditorStep = 1 | 2 | 3;

interface DefinitionForm {
  id?: number;
  name: string;
  description: string;
  group_id?: number;
  icon: string;
  status: number;
  is_cancelable: number;
  adminIds: number[];
}

interface FormMakeExpose {
  validate(): boolean;
}

interface WorkFlowDefDraft {
  id?: number;
  icon: string;
  name: string;
  remark: string;
  groupId?: number;
  cancelable: number;
  flowAdminIds: number[];
}

interface EditorDefinition {
  workFlowDef: WorkFlowDefDraft;
  flowWidgets: unknown[];
  nodeConfig: Record<string, unknown>;
  flowPermission: Record<string, unknown>;
}

const router = useRouter();
const route = useRoute();
const currentInstance = getCurrentInstance();
if (currentInstance) installArco(currentInstance.appContext.app);
const flowStore = useFlowStore();
const baseFormRef = ref<FormInstance>();
const formMakeRef = ref<FormMakeExpose>();
const step = ref<EditorStep>(1);
const publishing = ref(false);
const groups = ref<FlowGroup[]>([]);
const users = ref<FlowUser[]>([]);
let loadGeneration = 0;
const form = reactive<DefinitionForm>({
  name: "",
  description: "",
  icon: "approval",
  status: 1,
  is_cancelable: 1,
  adminIds: [],
});

const baseRules: FormRules<DefinitionForm> = {
  name: [{ required: true, message: "请填写流程名称", trigger: "blur" }],
  group_id: [{ required: true, message: "请选择流程分组", trigger: "change" }],
  adminIds: [
    {
      type: "array",
      required: true,
      min: 1,
      message: "请选择流程管理员",
      trigger: "change",
    },
  ],
};

function back() {
  router.push("/sandworkflow/manage/definition");
}

async function changeStep(next: EditorStep) {
  if (next === step.value) return;
  if (step.value === 1 && !(await validateBase())) return;
  if (step.value === 2 && next === 3 && !formMakeRef.value?.validate()) return;
  syncBaseToEditor();
  step.value = next;
}

async function validateBase(): Promise<boolean> {
  if (!baseFormRef.value) return true;
  return baseFormRef.value
    .validate()
    .then(() => true)
    .catch(() => false);
}

async function load() {
  const generation = ++loadGeneration;
  resetEditor();
  flowStore.setFlowDef(createEditorDefinition());
  const [groupData, userData, roleData, deptData] = await Promise.all([
    api.group.all(),
    api.organization.users(),
    api.organization.roles(),
    api.organization.depts(),
  ]);
  if (generation !== loadGeneration) return;
  const organStore = useOrganStore();
  organStore.setUsers(userData);
  organStore.setRoles(roleData);
  organStore.setDepts(deptData);
  groups.value = groupData;
  users.value = userData;

  const idValue = route.query.id;
  const id = typeof idValue === "string" ? Number(idValue) : 0;
  if (!Number.isInteger(id) || id <= 0) return;
  const definition = await api.definition.read(id);
  if (generation !== loadGeneration) return;
  assignDefinition(definition);
}

function resetEditor() {
  Object.assign(form, {
    name: "",
    description: "",
    icon: "approval",
    status: 1,
    is_cancelable: 1,
    adminIds: [],
  });
  delete form.id;
  delete form.group_id;
  step.value = 1;
}

function assignDefinition(definition: FlowDefinition) {
  const editorDefinition = normalizeEditorDefinition(definition);
  Object.assign(form, {
    id: definition.id,
    name: definition.name,
    description: definition.description || "",
    group_id: definition.group_id,
    icon: definition.icon || "approval",
    status: definition.status,
    is_cancelable: definition.is_cancelable ?? 1,
    adminIds: editorDefinition.workFlowDef.flowAdminIds,
  });
  flowStore.setFlowDef(editorDefinition);
}

function createEditorDefinition(): EditorDefinition {
  const unique = Date.now().toString(36);
  return {
    workFlowDef: {
      icon: "approval",
      name: "",
      remark: "",
      cancelable: 1,
      flowAdminIds: [],
    },
    flowWidgets: [],
    nodeConfig: {
      id: `node_${unique}`,
      nodeId: `SE_${unique}`,
      name: "发起人",
      type: 0,
      childNode: null,
    },
    flowPermission: { type: 0, flowInitiators: [] },
  };
}

function normalizeEditorDefinition(definition: FlowDefinition): EditorDefinition {
  const fallback = createEditorDefinition();
  const source = isRecord(definition.definition_json)
    ? definition.definition_json
    : {};
  const sourceWorkFlowDef = isRecord(source.workFlowDef)
    ? source.workFlowDef
    : {};
  return {
    workFlowDef: {
      id: definition.id,
      icon: definition.icon || stringValue(sourceWorkFlowDef.icon, "approval"),
      name: definition.name,
      remark: definition.description || stringValue(sourceWorkFlowDef.remark, ""),
      groupId: definition.group_id,
      cancelable: definition.is_cancelable ?? numberValue(sourceWorkFlowDef.cancelable, 1),
      flowAdminIds: numberArray(sourceWorkFlowDef.flowAdminIds),
    },
    flowWidgets: Array.isArray(source.flowWidgets) ? source.flowWidgets : [],
    nodeConfig: isRecord(source.nodeConfig) ? source.nodeConfig : fallback.nodeConfig,
    flowPermission: isRecord(source.flowPermission)
      ? source.flowPermission
      : fallback.flowPermission,
  };
}

function syncBaseToEditor() {
  const editorDefinition = flowStore.flowDefinition;
  editorDefinition.workFlowDef = {
    id: form.id,
    icon: form.icon,
    name: form.name,
    remark: form.description,
    groupId: form.group_id,
    cancelable: form.is_cancelable,
    flowAdminIds: [...form.adminIds],
  };
}

function buildDefinition(): Record<string, unknown> {
  syncBaseToEditor();
  const editorDefinition = cleanEditorState(flowStore.getFlowDef());
  return {
    workFlowDef: editorDefinition.workFlowDef,
    flowWidgets: editorDefinition.flowWidgets,
    nodeConfig: editorDefinition.nodeConfig,
    flowPermission: editorDefinition.flowPermission,
  };
}

async function publish() {
  if (!(await validateBase())) {
    step.value = 1;
    return;
  }
  if (step.value === 2 && !formMakeRef.value?.validate()) return;
  publishing.value = true;
  try {
    const payload: QueryParams = {
      id: form.id,
      name: form.name,
      description: form.description,
      group_id: form.group_id,
      icon: form.icon,
      status: form.status,
      is_cancelable: form.is_cancelable,
      definition_json: buildDefinition(),
    };
    await api.definition.publish(payload);
    ElMessage.success("发布成功");
    back();
  } finally {
    publishing.value = false;
  }
}

function cleanEditorState(value: EditorDefinition): EditorDefinition {
  return stripFocus(structuredClone(value)) as EditorDefinition;
}

function stripFocus(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(stripFocus);
  if (!isRecord(value)) return value;
  return Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => key !== "focus" && key !== "error")
      .map(([key, item]) => [key, stripFocus(item)]),
  );
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function stringValue(value: unknown, fallback: string): string {
  return typeof value === "string" ? value : fallback;
}

function numberValue(value: unknown, fallback: number): number {
  return typeof value === "number" ? value : fallback;
}

function numberArray(value: unknown): number[] {
  return Array.isArray(value)
    ? value.filter((item): item is number => typeof item === "number")
    : [];
}

watch(
  () => route.query.id,
  () => {
    void load();
  },
  { immediate: true },
);
</script>

<style scoped lang="less">
.flow-edit-page {
  min-width: 1080px;
  overflow: hidden;
  background: #f2f3f5;
  border-radius: var(--custom-radius);
}

.fd-nav {
  flex: 0 0 70px;
  z-index: 10;
  display: grid;
  grid-template-columns: 1fr minmax(560px, 860px) 1fr;
  gap: 20px;
  align-items: center;
  height: 70px;
  padding: 0 16px;
  background: #fff;
  box-shadow: 0 1px 6px rgb(0 0 0 / 4%);
}

.fd-nav-left,
.fd-nav-right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.fd-nav-right {
  justify-content: flex-end;
}

.name-desc {
  display: grid;
  gap: 3px;
  max-width: 260px;
}

.name-desc span {
  overflow: hidden;
  color: #8f959e;
  font-size: 12px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fd-nav-mid {
  cursor: pointer;
}

.fd-main {
  position: relative;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

.fd-main-box {
  height: 100%;
  overflow: auto;
}

.fd-base-box {
  display: flex;
  justify-content: center;
  height: 100%;
  padding: 16px;
  overflow: auto;
}

.base-setting-panel {
  width: 600px;
  height: fit-content;
  min-height: 100%;
  padding: 24px 40px;
  background: #fff;
  border-radius: 8px;
}

.flow-icon-preview {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  margin-right: 12px;
  color: #fff;
  font-size: 28px;
  background: #3370ff;
  border-radius: 50%;
}
</style>
