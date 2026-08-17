<template>
  <div class="art-full-height">
    <TableSearch
      v-model="searchForm"
      :groups="groups"
      @search="handleSearch"
      @reset="resetSearchParams"
    />

    <ElCard class="art-table-card" shadow="never">
      <ArtTableHeader v-model:columns="columnChecks" :loading="loading" @refresh="refreshData">
        <template #left>
          <ElSpace wrap>
            <ElButton
              v-permission="'sandworkflow:definition:save'"
              @click="navigateEditor()"
              v-ripple
            >
              <template #icon><ArtSvgIcon icon="ri:add-fill" /></template>
              新增流程
            </ElButton>
            <ElButton
              v-permission="'sandworkflow:definition:destroy'"
              :disabled="selectedRows.length === 0"
              @click="deleteSelectedRows(api.definition.delete, refreshData)"
              v-ripple
            >
              <template #icon>
                <ArtSvgIcon icon="ri:delete-bin-5-line" />
              </template>
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
          <div class="flex gap-2">
            <SaButton
              v-permission="'sandworkflow:definition:update'"
              type="secondary"
              toolTip="编辑流程"
              @click="navigateEditor(row.id)"
            />
            <SaButton
              v-permission="'sandworkflow:definition:versions'"
              type="primary"
              icon="ri:history-line"
              toolTip="版本历史"
              @click="openVersions(row)"
            />
            <SaButton
              v-permission="'sandworkflow:definition:destroy'"
              type="error"
              toolTip="删除流程"
              @click="deleteRow(row, api.definition.delete, refreshData)"
            />
          </div>
        </template>
      </ArtTable>
    </ElCard>

    <ElDrawer v-model="versionVisible" size="720px" title="发布版本历史">
      <ElTable :data="versions" v-loading="versionLoading">
        <ElTableColumn prop="version_no" label="版本" width="90" />
        <ElTableColumn prop="published_by" label="发布人ID" width="120" />
        <ElTableColumn prop="publish_time" label="发布时间" width="180" />
        <ElTableColumn label="定义">
          <template #default="{ row }">
            <ElButton link type="primary" @click="previewVersion(row)"> 查看 JSON </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
      <ElInput
        v-if="versionPreview"
        :model-value="versionPreview"
        type="textarea"
        :rows="18"
        readonly
      />
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { onMounted, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { useTable } from '@/hooks/core/useTable'
  import { useSandAdmin } from '@/composables/useSandAdmin'
  import api, {
    type DefinitionSearch,
    type FlowDefinition,
    type FlowDefinitionVersion,
    type FlowGroup
  } from '../../api'
  import TableSearch from './modules/table-search.vue'

  const router = useRouter()
  const groups = ref<FlowGroup[]>([])
  const searchForm = ref<DefinitionSearch>({})
  const versionVisible = ref(false)
  const versionLoading = ref(false)
  const versions = ref<FlowDefinitionVersion[]>([])
  const versionPreview = ref('')

  const {
    columns,
    columnChecks,
    data,
    loading,
    pagination,
    getData,
    searchParams,
    resetSearchParams,
    refreshData,
    handleSizeChange,
    handleCurrentChange
  } = useTable({
    core: {
      apiFn: api.definition.list,
      columnsFactory: () => [
        { type: 'selection' },
        { prop: 'id', label: '编号', width: 90, align: 'center' },
        { prop: 'name', label: '流程名称', minWidth: 180 },
        { prop: 'group_name', label: '分组', width: 140 },
        {
          prop: 'description',
          label: '说明',
          minWidth: 220,
          showOverflowTooltip: true
        },
        {
          prop: 'status',
          label: '状态',
          saiType: 'dict',
          saiDict: 'data_status',
          width: 100
        },
        {
          prop: 'current_version',
          label: '当前版本',
          width: 100,
          align: 'center'
        },
        { prop: 'publish_time', label: '发布时间', width: 180 },
        {
          prop: 'operation',
          label: '操作',
          width: 140,
          fixed: 'right',
          useSlot: true
        }
      ]
    }
  })

  const { deleteRow, deleteSelectedRows, handleSelectionChange, selectedRows } = useSandAdmin()

  function handleSearch(params: DefinitionSearch) {
    Object.assign(searchParams, params)
    getData()
  }

  async function navigateEditor(id?: number) {
    await router.push({
      path: '/sandworkflow/manage/definition/edit',
      query: id ? { id: String(id) } : {}
    })
  }

  async function loadGroups() {
    groups.value = await api.group.all()
  }

  async function openVersions(row: FlowDefinition) {
    versionVisible.value = true
    versionPreview.value = ''
    versionLoading.value = true
    try {
      versions.value = await api.definition.versions(row.id)
    } finally {
      versionLoading.value = false
    }
  }

  function previewVersion(row: FlowDefinitionVersion) {
    versionPreview.value = JSON.stringify(row.definition_json, null, 2)
  }

  onMounted(loadGroups)
</script>
