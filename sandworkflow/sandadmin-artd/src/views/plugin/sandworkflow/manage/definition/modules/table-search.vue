<template>
  <sa-search-bar
    ref="searchBarRef"
    v-model="formData"
    label-width="80px"
    @reset="handleReset"
    @search="handleSearch"
  >
    <el-col v-bind="setSpan(6)">
      <el-form-item label="关键词" prop="keywords">
        <el-input
          v-model="formData.keywords"
          placeholder="请输入流程名称"
          clearable
        />
      </el-form-item>
    </el-col>
    <el-col v-bind="setSpan(6)">
      <el-form-item label="流程分组" prop="group_id">
        <el-select
          v-model="formData.group_id"
          placeholder="请选择流程分组"
          clearable
          filterable
        >
          <el-option
            v-for="item in groups"
            :key="item.id"
            :label="item.name"
            :value="item.id"
          />
        </el-select>
      </el-form-item>
    </el-col>
    <el-col v-bind="setSpan(6)">
      <el-form-item label="状态" prop="status">
        <el-select v-model="formData.status" placeholder="请选择状态" clearable>
          <el-option label="启用" :value="1" />
          <el-option label="禁用" :value="2" />
        </el-select>
      </el-form-item>
    </el-col>
  </sa-search-bar>
</template>

<script setup lang="ts">
import type { DefinitionSearch, FlowGroup } from "../../../api";

const props = defineProps<{
  modelValue: DefinitionSearch;
  groups: FlowGroup[];
}>();
const emit = defineEmits<{
  (e: "update:modelValue", value: DefinitionSearch): void;
  (e: "search", params: DefinitionSearch): void;
  (e: "reset"): void;
}>();

interface SearchBarExpose {
  ref?: { resetFields: () => void };
}

const searchBarRef = ref<SearchBarExpose>();
const formData = computed({
  get: () => props.modelValue,
  set: (value) => emit("update:modelValue", value),
});

function handleReset() {
  searchBarRef.value?.ref?.resetFields();
  emit("reset");
}

function handleSearch() {
  emit("search", formData.value);
}

const setSpan = (span: number) => ({
  span,
  xs: 24,
  sm: 12,
  md: 8,
  lg: span,
  xl: span,
});
</script>
