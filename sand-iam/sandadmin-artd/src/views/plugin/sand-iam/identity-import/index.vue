<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, ref } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import { describeSandIamError } from '../api/errors'
  import {
    importAccountStateLabel,
    importJobCanConfirm,
    importJobStateLabel,
    importModeLabel,
    importRowStateLabel,
    parseSandIamImportConfirm,
    parseSandIamImportJobs,
    parseSandIamImportPreview,
    parseSandIamImportRows,
    sandIamImportTemplateCsv,
    type SandIamImportJobRow,
    type SandIamImportMode,
    type SandIamImportPreview,
    type SandIamImportRow
  } from '../api/importContracts'
  import { listSandIamResource } from '../api/resource'
  import {
    downloadSandIamAdminBlob,
    getSandIamAdmin,
    postSandIamAction,
    postSandIamForm
  } from '../api/write'
  import type { SandIamRequestError, SandIamResourceRow } from '../api/types'

  const { hasAuth } = useAuth()
  const canIndex = computed(() => hasAuth('sand_iam:identity_import:index'))
  const canPreview = computed(() => hasAuth('sand_iam:identity_import:preview'))
  const canConfirm = computed(() => hasAuth('sand_iam:identity_import:confirm'))
  const canRead = computed(() => hasAuth('sand_iam:identity_import:read'))
  const canExportMasked = computed(() => hasAuth('sand_iam:identity_export:masked'))
  const canExportSensitive = computed(() => hasAuth('sand_iam:identity_export:sensitive'))

  const loading = ref(false)
  const applications = ref<SandIamResourceRow[]>([])
  const jobs = ref<SandIamImportJobRow[]>([])
  const rows = ref<SandIamImportRow[]>([])
  const preview = ref<SandIamImportPreview | null>(null)
  const applicationId = ref('')
  const mode = ref<SandIamImportMode>('create')
  const selectedFile = ref<File | null>(null)
  const selectedJobId = ref('')
  const requestError = ref<SandIamRequestError | null>(null)
  const viewState = ref<'idle' | 'empty' | 'ready'>('idle')

  function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
  }

  function listRows(value: unknown): SandIamResourceRow[] {
    if (Array.isArray(value)) return value.filter(isRecord)
    if (!isRecord(value)) return []
    if (Array.isArray(value.data)) return value.data.filter(isRecord)
    return []
  }

  function selectedId(raw: string): number | null {
    const parsed = Number(raw)
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null
  }

  function selectedApplicationName(): string {
    const id = selectedId(applicationId.value)
    if (id === null) return '请先选择接入应用'
    const row = applications.value.find((item) => item.id === id)
    return typeof row?.name === 'string' ? row.name : '关联应用名称暂不可用'
  }

  function downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  }

  function downloadTemplate(): void {
    downloadBlob(
      new Blob([sandIamImportTemplateCsv()], { type: 'text/csv;charset=utf-8' }),
      'sand-iam-import-template.csv'
    )
    ElMessage.success('已保存')
  }

  function onFileChange(file: unknown): void {
    if (!isRecord(file) || !(file.raw instanceof File)) {
      selectedFile.value = null
      return
    }
    selectedFile.value = file.raw
  }

  async function loadApplications(): Promise<void> {
    try {
      applications.value = listRows(
        await listSandIamResource('application', { page: 1, limit: 100 })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    }
  }

  async function loadJobs(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    if (!canIndex.value) return
    loading.value = true
    requestError.value = null
    try {
      jobs.value = parseSandIamImportJobs(
        await getSandIamAdmin('identity-import/index', { application_id: application })
      )
      viewState.value = jobs.value.length === 0 ? 'empty' : 'ready'
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
      jobs.value = []
    } finally {
      loading.value = false
    }
  }

  /**
   * CSV 只走 multipart，不把行内容放进 URL 或普通 JSON。
   */
  async function previewImport(): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null || selectedFile.value === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请选择接入应用并上传 UTF-8 CSV')
      )
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const data = new FormData()
      data.append('application_id', String(application))
      data.append('mode', mode.value)
      data.append('file', selectedFile.value)
      const parsed = parseSandIamImportPreview(
        await postSandIamForm('identity-import/preview', data)
      )
      if (parsed === null) {
        requestError.value = describeSandIamError(new Error('预检响应不符合已冻结约定'))
        return
      }
      preview.value = parsed
      ElMessage.success('已预检')
      await loadJobs()
      const job = jobs.value.find((item) => item.id === parsed.id)
      if (job !== undefined) await loadRows(job)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function loadRows(job: SandIamImportJobRow): Promise<void> {
    selectedJobId.value = String(job.id)
    if (!canRead.value) return
    loading.value = true
    requestError.value = null
    try {
      rows.value = parseSandIamImportRows(
        await getSandIamAdmin('identity-import/rows', { id: job.id })
      )
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function confirmJob(job: SandIamImportJobRow): Promise<void> {
    if (!importJobCanConfirm(job)) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_IMPORT_HAS_INVALID_ROWS: 有错误行时不能确认，请先修正 CSV 后重新预检')
      )
      return
    }
    try {
      await ElMessageBox.confirm(
        `将按「${importModeLabel(job.mode)}」执行 ${String(job.valid_count)} 行。预检尚未创建或修改任何应用用户。重复确认不会重复创建已完成任务。`,
        '确认导入',
        { type: 'warning', confirmButtonText: '确认执行', cancelButtonText: '取消' }
      )
    } catch {
      return
    }
    loading.value = true
    requestError.value = null
    try {
      const result = parseSandIamImportConfirm(
        await postSandIamAction('identity-import/confirm', {
          id: job.id,
          digest: job.digest
        })
      )
      ElMessage.success(
        result === null
          ? '已保存'
          : `已保存：成功 ${String(result.success)}，警告 ${String(result.warning)}，失败 ${String(result.failure)}`
      )
      await loadJobs()
      const latest = jobs.value.find((item) => item.id === job.id)
      if (latest !== undefined) await loadRows(latest)
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  async function exportUsers(sensitive: boolean): Promise<void> {
    const application = selectedId(applicationId.value)
    if (application === null) {
      requestError.value = describeSandIamError(
        new Error('SAND_IAM_VALIDATION_ERROR: 请先按名称选择接入应用')
      )
      return
    }
    if (sensitive) {
      try {
        await ElMessageBox.confirm(
          '将导出完整邮箱和手机号。此操作需要独立权限，并会写入审计。默认脱敏导出不会包含完整联系方式。',
          '导出完整联系方式',
          { type: 'warning', confirmButtonText: '确认导出', cancelButtonText: '取消' }
        )
      } catch {
        return
      }
    }
    loading.value = true
    requestError.value = null
    try {
      const blob = await downloadSandIamAdminBlob(
        sensitive ? 'identity-export/sensitive' : 'identity-export/masked',
        { application_id: application }
      )
      downloadBlob(blob, sensitive ? 'sand-iam-users-sensitive.csv' : 'sand-iam-users-masked.csv')
      ElMessage.success('已保存')
    } catch (error: unknown) {
      requestError.value = describeSandIamError(error)
    } finally {
      loading.value = false
    }
  }

  onMounted(() => {
    void loadApplications()
  })
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <div class="mb-4">
        <h2 class="m-0 text-lg font-semibold">用户导入导出</h2>
        <p class="mb-0 mt-2 text-sm text-gray-500">
          先下载模板并预检，有错误行时不能确认。默认只导出脱敏联系方式；完整邮箱/手机号需要独立权限和二次确认。
        </p>
      </div>

      <ElAlert
        v-if="!canIndex"
        class="mb-4"
        type="warning"
        :closable="false"
        title="没有权限"
        description="当前账号无权查看导入任务。请联系平台管理员开通导入任务查看权限。"
      />
      <ElAlert
        v-if="requestError"
        class="mb-4"
        type="error"
        :closable="false"
        :title="requestError.title"
        :description="requestError.detail"
      />
      <ElAlert
        v-else-if="viewState === 'empty'"
        class="mb-4"
        type="info"
        :closable="false"
        title="当前没有数据"
        description="该接入应用还没有导入任务。这与没有权限不同。"
      />
      <ElAlert
        v-if="preview && preview.invalid > 0"
        class="mb-4"
        type="warning"
        :closable="false"
        title="预检有错误行"
        :description="`总行 ${String(preview.total)}，可执行 ${String(preview.valid)}，错误 ${String(preview.invalid)}。有错误行时禁止确认。`"
      />

      <ElForm label-width="180px" class="mb-4">
        <ElFormItem label="接入应用">
          <ElSelect v-model="applicationId" filterable clearable placeholder="按名称选择">
            <ElOption
              v-for="row in applications"
              :key="String(row.id)"
              :label="typeof row.name === 'string' ? row.name : '未命名接入应用'"
              :value="String(row.id)"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="导入方式">
          <ElSelect v-model="mode">
            <ElOption label="新增邀请" value="create" />
            <ElOption label="更新已有用户" value="update" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="CSV 文件">
          <ElUpload
            :auto-upload="false"
            :limit="1"
            accept=".csv,text/csv"
            :on-change="onFileChange"
          >
            <ElButton>选择文件</ElButton>
          </ElUpload>
        </ElFormItem>
        <ElFormItem>
          <ElButton @click="downloadTemplate">下载模板</ElButton>
          <ElButton :disabled="!canPreview" :loading="loading" @click="previewImport"
            >上传预检</ElButton
          >
          <ElButton :disabled="!canIndex" :loading="loading" @click="loadJobs">加载任务</ElButton>
          <ElButton :disabled="!canExportMasked" :loading="loading" @click="exportUsers(false)">
            导出脱敏用户
          </ElButton>
          <ElButton
            type="warning"
            :disabled="!canExportSensitive"
            :loading="loading"
            @click="exportUsers(true)"
          >
            导出完整联系方式
          </ElButton>
        </ElFormItem>
      </ElForm>

      <ElTable v-loading="loading" :data="jobs" border stripe empty-text="暂无导入任务">
        <ElTableColumn label="文件名称" min-width="180">
          <template #default="scope">{{ scope.row.original_name }}</template>
        </ElTableColumn>
        <ElTableColumn label="所属应用" min-width="140">
          <template #default>{{ selectedApplicationName() }}</template>
        </ElTableColumn>
        <ElTableColumn label="导入方式" min-width="120">
          <template #default="scope">{{ importModeLabel(scope.row.mode) }}</template>
        </ElTableColumn>
        <ElTableColumn label="任务状态" min-width="110">
          <template #default="scope">{{ importJobStateLabel(scope.row.state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="预检摘要" min-width="220">
          <template #default="scope">
            总行 {{ scope.row.total_count }} · 可执行 {{ scope.row.valid_count }} · 错误
            {{ scope.row.invalid_count }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="执行结果" min-width="200">
          <template #default="scope">
            成功 {{ scope.row.success_count }} · 警告 {{ scope.row.warning_count }} · 失败
            {{ scope.row.failure_count }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" min-width="180" fixed="right">
          <template #default="scope">
            <ElButton size="small" :disabled="!canRead" @click="loadRows(scope.row)"
              >行报告</ElButton
            >
            <ElButton
              size="small"
              type="primary"
              :disabled="!canConfirm || !importJobCanConfirm(scope.row)"
              @click="confirmJob(scope.row)"
            >
              确认
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <h3 class="mt-8 text-base">逐行预检</h3>
      <ElTable :data="rows" border stripe empty-text="先选择一个任务查看逐行报告">
        <ElTableColumn label="行号" min-width="70">
          <template #default="scope">{{ scope.row.row_number }}</template>
        </ElTableColumn>
        <ElTableColumn label="显示名称" min-width="140">
          <template #default="scope">{{ scope.row.summary?.display_name || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="脱敏目标" min-width="160">
          <template #default="scope">{{ scope.row.summary?.target_masked || '—' }}</template>
        </ElTableColumn>
        <ElTableColumn label="账号状态" min-width="110">
          <template #default="scope">
            {{ importAccountStateLabel(scope.row.summary?.account_state ?? '') }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="用户组" min-width="160">
          <template #default="scope">
            {{ scope.row.summary?.group_names.join('、') || '—' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="行状态" min-width="120">
          <template #default="scope">{{ importRowStateLabel(scope.row.state) }}</template>
        </ElTableColumn>
        <ElTableColumn label="错误" min-width="220">
          <template #default="scope">
            {{ scope.row.validation_errors.join('；') || scope.row.error_code || '—' }}
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>
  </div>
</template>
