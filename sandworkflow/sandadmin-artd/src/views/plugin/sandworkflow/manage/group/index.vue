<template>
  <div class="art-full-height">
    <TableSearch
      v-model="searchForm"
      @search="handleSearch"
      @reset="resetSearchParams"
    />
    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader
        v-model:columns="columnChecks"
        :loading="loading"
        @refresh="refreshData"
      >
        <template #left>
          <ElSpace wrap>
            <ElButton
              v-permission="'sandworkflow:group:save'"
              @click="showDialog('add')"
              v-ripple
            >
              <template #icon><ArtSvgIcon icon="ri:add-fill" /></template>
              新增
            </ElButton>
            <ElButton
              v-permission="'sandworkflow:group:destroy'"
              :disabled="selectedRows.length === 0"
              @click="deleteSelectedRows(api.group.delete, refreshData)"
              v-ripple
            >
              <template #icon
                ><ArtSvgIcon icon="ri:delete-bin-5-line"
              /></template>
              删除
            </ElButton>
          </ElSpace>
        </template>
      </ArtTableHeader>
      <ArtTable
        rowKey="id"
        :loading="loading"
        :data="data"
        :columns="columns"
        :pagination="pagination"
        @selection-change="handleSelectionChange"
        @pagination:size-change="handleSizeChange"
        @pagination:current-change="handleCurrentChange"
      >
        <template #operation="{ row }">
          <ElSpace>
            <SaButton
              v-permission="'sandworkflow:group:update'"
              type="secondary"
              @click="showDialog('edit', row)"
            />
            <SaButton
              v-permission="'sandworkflow:group:destroy'"
              type="error"
              @click="deleteRow(row, api.group.delete, refreshData)"
            />
          </ElSpace>
        </template>
      </ArtTable>
    </ElCard>
    <EditDialog
      v-model="dialogVisible"
      :dialog-type="groupDialogType"
      :data="dialogData"
      @success="refreshData"
    />
  </div>
</template>

<script setup lang="ts">
import { useTable } from "@/hooks/core/useTable";
import { useSandAdmin } from "@/composables/useSandAdmin";
import api, { type GroupSearch } from "../../api";
import TableSearch from "./modules/table-search.vue";
import EditDialog, { type GroupDialogType } from "./modules/edit-dialog.vue";

const searchForm = ref<GroupSearch>({});
const handleSearch = (params: GroupSearch) => {
  Object.assign(searchParams, params);
  getData();
};

const {
  columns,
  columnChecks,
  data,
  loading,
  getData,
  searchParams,
  pagination,
  resetSearchParams,
  handleSizeChange,
  handleCurrentChange,
  refreshData,
} = useTable({
  core: {
    apiFn: api.group.list,
    columnsFactory: () => [
      { type: "selection" },
      { prop: "id", label: "编号", width: 90, align: "center" },
      { prop: "name", label: "分组名称", minWidth: 160 },
      {
        prop: "description",
        label: "说明",
        minWidth: 220,
        showOverflowTooltip: true,
      },
      { prop: "sort", label: "排序", width: 100 },
      {
        prop: "status",
        label: "状态",
        saiType: "dict",
        saiDict: "data_status",
        width: 100,
      },
      { prop: "create_time", label: "创建时间", width: 180 },
      {
        prop: "operation",
        label: "操作",
        width: 120,
        fixed: "right",
        useSlot: true,
      },
    ],
  },
});

const {
  dialogType,
  dialogVisible,
  dialogData,
  showDialog,
  deleteRow,
  deleteSelectedRows,
  handleSelectionChange,
  selectedRows,
} = useSandAdmin();
const groupDialogType = computed<GroupDialogType>(() =>
  dialogType.value === "edit" ? "edit" : "add",
);
</script>
