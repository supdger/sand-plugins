<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { computed, onMounted, onScopeDispose, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { ElMessage } from 'element-plus'
  import { useAuth } from '@/hooks/core/useAuth'
  import TaskPath from '../components/TaskPath.vue'
  import GoalGuide from '../components/GoalGuide.vue'
  import { sandIamTaskPathCanOpen, sandIamTaskPaths } from '../api/taskPaths'
  import {
    LVXU_GRANT_PRESET,
    activeCredential,
    fetchAccessMatrix,
    grantAccessMatrix,
    grantActive,
    matrixClientFor,
    type SandIamAccessMatrix,
    type SandIamMatrixApplication,
    type SandIamMatrixAction,
    type SandIamIssuedCredential,
  } from '../api/accessMatrix'

  const router = useRouter()
  const { hasAuth } = useAuth()

  const matrix = ref<SandIamAccessMatrix | null>(null)
  const loading = ref(true)
  const loadError = ref('')
  const taskGrid = ref<HTMLElement | null>(null)
  const gridReady = ref(false)
  let cardObserver: ResizeObserver | undefined
  let layoutFrame = 0

  // --- permissions ---
  const canRead = computed(() => hasAuth('sand_iam:grant:index'))
  const canGrant = computed(() => hasAuth('sand_iam:grant:save'))
  const canIssue = computed(() => hasAuth('sand_iam:credential:issue'))

  /** Primary capabilities shown as grid columns (others live in advanced pages). */
  const PRIMARY_ACTION_CODES = [
    'sand_ai.chat.complete',
    'sand_ai.document_parse',
    'sand_ai.audio.transcription',
    'sand_ai.workflow.run',
  ]

  const columns = computed<SandIamMatrixAction[]>(() => {
    const services = matrix.value?.services ?? []
    const primary = services
      .flatMap((s) => s.actions)
      .filter((a) => PRIMARY_ACTION_CODES.includes(a.code))
    return primary.length > 0 ? primary : services.flatMap((s) => s.actions)
  })

  /** Pending decisions: 律序 not onboarded, or missing default capabilities. */
  const dismissedPending = ref(false)
  const lvxuApp = computed(() =>
    (matrix.value?.applications ?? []).find((a) => a.code === LVXU_GRANT_PRESET.application.code))
  const lvxuClient = computed(() => (lvxuApp.value ? matrixClientFor(lvxuApp.value) : null))
  const lvxuMissingDefault = computed(() => {
    if (lvxuApp.value === undefined) return true
    const actionIds = new Map(
      (matrix.value?.services ?? [])
        .flatMap((s) => s.actions)
        .map((a) => [a.code, a.id] as const),
    )
    return LVXU_GRANT_PRESET.action_codes.some((code) => {
      const id = actionIds.get(code)
      return id !== undefined && !grantActive(lvxuClient.value, id)
    })
  })
  const showLvxuPending = computed(() => canGrant.value && !dismissedPending.value && lvxuMissingDefault.value)

  // --- pending actions ---
  const granting = ref(false)

  async function allowLvxu(): Promise<void> {
    granting.value = true
    try {
      await grantAccessMatrix({ ...LVXU_GRANT_PRESET })
      ElMessage.success('已允许律序使用选中的能力')
      await reload()
    } catch {
      ElMessage.error('授权失败，请稍后重试')
    } finally {
      granting.value = false
    }
  }

  async function grantSingleAction(app: SandIamMatrixApplication, actionId: number): Promise<void> {
    if (!canGrant.value || granting.value) return
    const action = columns.value.find((a) => a.id === actionId)
    if (action === undefined) return
    granting.value = true
    try {
      await grantAccessMatrix({
        application: { id: app.id },
        service_code: 'sand_ai',
        action_codes: [action.code],
      })
      ElMessage.success(`已为「${app.name}」开启「${action.name}」`)
      await reload()
    } catch {
      ElMessage.error('授权失败，请稍后重试')
    } finally {
      granting.value = false
    }
  }

  const issuingFor = ref<number | null>(null)
  async function issueCredential(app: SandIamMatrixApplication): Promise<void> {
    if (!canIssue.value || issuingFor.value !== null) return
    issuingFor.value = app.id
    try {
      const result = await grantAccessMatrix({
        application: { id: app.id },
        service_code: 'sand_ai',
        action_codes: LVXU_GRANT_PRESET.action_codes,
        issue_credential: true,
      })
      if (result.credential) {
        issuedCredential.value = result.credential
        secretDialogOpen.value = true
      }
      ElMessage.success('调用密钥已生成，请立即安全保存')
      await reload()
    } catch {
      ElMessage.error('生成密钥失败，请稍后重试')
    } finally {
      issuingFor.value = null
    }
  }

  // --- secret dialog (one-time plaintext) ---
  const secretDialogOpen = ref(false)
  const issuedCredential = ref<SandIamIssuedCredential | null>(null)

  async function copySecret(): Promise<void> {
    const secret = issuedCredential.value?.credential ?? ''
    if (secret === '') return
    try {
      await navigator.clipboard.writeText(secret)
      ElMessage.success('密钥已复制，请立即交给应用安全保存')
    } catch {
      ElMessage.error('无法复制密钥，请手动复制后安全保存')
    }
  }

  function closeSecretDialog(): void {
    secretDialogOpen.value = false
    issuedCredential.value = null
  }

  // --- new application dialog ---
  const newAppDialogOpen = ref(false)
  const newAppName = ref('')
  const newAppCode = ref('')
  const newAppActions = ref<string[]>([...LVXU_GRANT_PRESET.action_codes])
  const newAppIssue = ref(false)
  const newAppSaving = ref(false)

  function openNewApp(): void {
    newAppName.value = ''
    newAppCode.value = ''
    newAppActions.value = [...LVXU_GRANT_PRESET.action_codes]
    newAppIssue.value = false
    newAppDialogOpen.value = true
  }

  const newAppCodeValid = computed(() => /^[a-z0-9][a-z0-9_-]{1,63}$/.test(newAppCode.value))

  async function saveNewApp(): Promise<void> {
    if (newAppName.value.trim() === '') {
      ElMessage.error('请填写应用名称')
      return
    }
    if (!newAppCodeValid.value) {
      ElMessage.error('系统代码须为 2–64 位小写字母、数字、短横线或下划线')
      return
    }
    if (newAppActions.value.length === 0) {
      ElMessage.error('请选择至少一个能力')
      return
    }
    newAppSaving.value = true
    try {
      const result = await grantAccessMatrix({
        application: { code: newAppCode.value.trim(), name: newAppName.value.trim() },
        service_code: 'sand_ai',
        action_codes: newAppActions.value,
        issue_credential: newAppIssue.value,
      })
      if (result.credential) {
        issuedCredential.value = result.credential
        secretDialogOpen.value = true
      }
      ElMessage.success(`已接入「${newAppName.value.trim()}」`)
      newAppDialogOpen.value = false
      await reload()
    } catch {
      ElMessage.error('接入失败，请检查应用信息或稍后重试')
    } finally {
      newAppSaving.value = false
    }
  }

  // --- data ---
  async function reload(): Promise<void> {
    try {
      matrix.value = await fetchAccessMatrix()
      loadError.value = ''
    } catch {
      loadError.value = '无法加载授权矩阵，请检查网络或权限后重试'
    }
  }

  function clientFor(app: SandIamMatrixApplication) {
    return matrixClientFor(app)
  }

  function credentialFor(app: SandIamMatrixApplication) {
    return activeCredential(clientFor(app))
  }

  function actionGranted(app: SandIamMatrixApplication, actionId: number): boolean {
    return grantActive(clientFor(app), actionId)
  }

  function openApplications(): void {
    void router.push('/sand-iam/application')
  }

  const canOpenGettingStarted = () =>
    sandIamTaskPathCanOpen(sandIamTaskPaths.connection, hasAuth) ||
    sandIamTaskPathCanOpen(sandIamTaskPaths['people-access'], hasAuth) ||
    sandIamTaskPathCanOpen(sandIamTaskPaths['auth-session'], hasAuth) ||
    sandIamTaskPathCanOpen(sandIamTaskPaths['api-governance'], hasAuth) ||
    sandIamTaskPathCanOpen(sandIamTaskPaths['admin-scope'], hasAuth) ||
    sandIamTaskPathCanOpen(sandIamTaskPaths['event-notification'], hasAuth) ||
    sandIamTaskPathCanOpen(sandIamTaskPaths['audit-troubleshooting'], hasAuth)

  function openGettingStarted(): void {
    void router.push('/sand-iam/getting-started')
  }

  onMounted(() => {
    void reload().finally(() => {
      loading.value = false
    })
    // 以卡片实际高度占用网格行，避免多栏平衡算法留下整列空白。
    cardObserver = new ResizeObserver(() => {
      cancelAnimationFrame(layoutFrame)
      layoutFrame = requestAnimationFrame(() => {
        for (const item of Array.from(taskGrid.value?.children ?? [])) {
          if (!(item instanceof HTMLElement)) continue
          const height = item.getBoundingClientRect().height
          if (height <= 0) continue
          item.style.gridRowEnd = `span ${Math.ceil(height) + 16}`
          gridReady.value = true
        }
      })
    })
    for (const item of Array.from(taskGrid.value?.children ?? [])) {
      cardObserver.observe(item)
    }
  })
  onScopeDispose(() => {
    cardObserver?.disconnect()
    cancelAnimationFrame(layoutFrame)
  })
</script>

<template>
  <div class="sand-iam-page sand-iam-overview">
    <GoalGuide />

    <ElCard shadow="never" class="mt-4">
      <div class="sand-iam-home__header">
        <div>
          <h2 class="m-0 text-lg font-semibold">身份与授权</h2>
          <p class="mb-0 mt-2 text-sm text-gray-500">
            管理“哪些应用能用哪些服务”。安装后已自动配好默认设置，你通常只需要决定：哪个应用能用 AI，以及它能调用哪些能力。
          </p>
        </div>
      </div>

      <!-- 待你决定 -->
      <div v-if="showLvxuPending" class="sand-iam-home__pending">
        <div class="sand-iam-home__pending-info">
          <span class="sand-iam-home__pending-dot">!</span>
          <div>
            <p class="m-0 font-medium">「律序」还没有获得 AI 能力的使用许可</p>
            <p class="m-0 mt-1 text-sm text-gray-500">默认开启：AI 对话、文档读取。可以随时在下方矩阵里调整。</p>
          </div>
        </div>
        <div class="sand-iam-home__pending-actions">
          <ElButton type="primary" :loading="granting" @click="allowLvxu">允许律序使用</ElButton>
          <ElButton text @click="dismissedPending = true">暂不</ElButton>
        </div>
      </div>

      <ElAlert v-if="loadError !== ''" class="mt-4" type="error" :closable="false" :title="loadError" />

      <!-- 应用 × 服务 网格 -->
      <div v-if="canRead" class="sand-iam-home__matrix">
        <h3 class="m-0 mb-3 text-base font-semibold">应用 × 服务</h3>

        <div v-if="loading" class="py-10 text-center text-gray-400">正在加载…</div>

        <template v-else>
          <div v-if="(matrix?.applications.length ?? 0) === 0" class="sand-iam-home__empty">
            <p class="m-0 text-gray-500">还没有应用接入</p>
            <ElButton v-if="canGrant" class="mt-4" type="primary" @click="openNewApp">接入新应用</ElButton>
          </div>

          <div v-else class="sand-iam-home__table-wrap">
            <table class="sand-iam-home__table">
              <thead>
                <tr>
                  <th>应用</th>
                  <th v-for="col in columns" :key="col.id">{{ col.name }}</th>
                  <th>调用密钥</th>
                  <th v-if="canGrant || canIssue"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="app in matrix!.applications" :key="app.id">
                  <td>
                    <span class="font-medium">{{ app.name }}</span>
                    <span v-if="app.status !== 1" class="sand-iam-home__badge">已停用</span>
                  </td>
                  <td v-for="col in columns" :key="col.id" class="sand-iam-home__cell">
                    <button
                      v-if="actionGranted(app, col.id)"
                      class="sand-iam-home__mark sand-iam-home__mark--on"
                      :disabled="true"
                      title="已允许"
                    >✔</button>
                    <button
                      v-else-if="canGrant"
                      class="sand-iam-home__mark sand-iam-home__mark--off"
                      :disabled="granting"
                      title="点击允许"
                      @click="grantSingleAction(app, col.id)"
                    >–</button>
                    <span v-else class="sand-iam-home__mark sand-iam-home__mark--off">–</span>
                  </td>
                  <td class="sand-iam-home__cell">
                    <template v-if="credentialFor(app)">
                      <code class="sand-iam-home__key">{{ credentialFor(app)!.key_prefix }}…</code>
                    </template>
                    <span v-else class="text-gray-400">没有密钥</span>
                  </td>
                  <td v-if="canGrant || canIssue" class="sand-iam-home__cell">
                    <ElButton
                      v-if="canIssue"
                      size="small"
                      :loading="issuingFor === app.id"
                      @click="issueCredential(app)"
                    >{{ credentialFor(app) ? '生成新密钥' : '生成调用密钥' }}</ElButton>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="canGrant && (matrix?.applications.length ?? 0) > 0" class="mt-4">
            <ElButton @click="openNewApp">+ 接入新应用</ElButton>
            <ElButton text type="primary" @click="openApplications">管理应用</ElButton>
          </div>
        </template>
      </div>
      <div v-else-if="!loading" class="mt-4 text-sm text-gray-500">
        当前账号没有查看服务授权的权限。
      </div>
    </ElCard>

    <!-- 全部功能（原入口，折叠） -->
    <details class="sand-iam-overview__all"><summary>全部功能：日常管理、委派、通知与排错</summary>
    <ElCard shadow="never">
      <div class="sand-iam-overview__heading">
        <div>
          <h2 class="m-0 text-lg font-semibold">SandIAM 管理入口</h2>
          <p class="mb-0 mt-2 text-sm text-gray-500">
            熟悉功能时可直接进入。首次办理或继续配置，请使用上方目标引导。
          </p>
        </div>
        <ElButton v-if="canOpenGettingStarted()" type="primary" @click="openGettingStarted"
          >登记公司与应用</ElButton
        >
        <span v-else class="text-sm text-gray-500">当前账号没有可管理的 SandIAM 范围。</span>
      </div>
      <div ref="taskGrid" class="sand-iam-overview__grid" :class="{ 'is-measured': gridReady }">
        <div class="sand-iam-overview__item"><TaskPath path="connection" summary /></div>
        <div class="sand-iam-overview__item"><TaskPath path="people-access" summary /></div>
        <div class="sand-iam-overview__item"><TaskPath path="auth-session" summary /></div>
        <div class="sand-iam-overview__item"><TaskPath path="api-governance" summary /></div>
        <div class="sand-iam-overview__item"><TaskPath path="admin-scope" summary /></div>
        <div class="sand-iam-overview__item"><TaskPath path="event-notification" summary /></div>
        <div class="sand-iam-overview__item"><TaskPath path="audit-troubleshooting" summary /></div>
      </div>
      <ElAlert
        class="mt-4"
        type="info"
        :closable="false"
        title="应用用户在接入应用中完成个人安全操作"
        description="注册、登录、验证器、通行密钥、会话管理和个人资料由应用用户在接入应用中使用，不使用后台账号。"
      />
    </ElCard>
    </details>

    <!-- 一次性密钥弹窗 -->
    <ElDialog
      v-model="secretDialogOpen"
      title="请安全保存调用密钥"
      width="620px"
      :close-on-click-modal="false"
      @close="issuedCredential = null"
    >
      <ElAlert
        type="warning"
        :closable="false"
        title="明文仅在当前窗口展示一次"
        description="请复制并交给应用安全保存；不要写入文档、聊天或截图。关闭后无法从页面恢复。"
      />
      <ElInput
        v-if="issuedCredential"
        class="mt-4"
        :model-value="issuedCredential.credential"
        readonly
        type="textarea"
        :rows="3"
      />
      <template #footer>
        <ElButton v-if="issuedCredential" type="primary" @click="copySecret">复制密钥</ElButton>
        <ElButton @click="closeSecretDialog">已安全保存，关闭</ElButton>
      </template>
    </ElDialog>

    <!-- 接入新应用 -->
    <ElDialog v-model="newAppDialogOpen" title="接入新应用" width="560px">
      <ElForm label-position="top">
        <ElFormItem label="应用名称" required>
          <ElInput v-model="newAppName" placeholder="例如：律序" />
        </ElFormItem>
        <ElFormItem label="系统代码（自动生成标识，一般用拼音或英文小写）" required>
          <ElInput v-model="newAppCode" placeholder="例如：lvxu" />
        </ElFormItem>
        <ElFormItem label="允许使用的能力">
          <ElCheckboxGroup v-model="newAppActions">
            <ElCheckbox
              v-for="col in (matrix?.services ?? []).flatMap((s) => s.actions)"
              :key="col.id"
              :value="col.code"
            >{{ col.name }}</ElCheckbox>
          </ElCheckboxGroup>
        </ElFormItem>
        <ElFormItem v-if="canIssue">
          <ElCheckbox v-model="newAppIssue">同时生成调用密钥（明文只显示一次）</ElCheckbox>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="newAppDialogOpen = false">取消</ElButton>
        <ElButton type="primary" :loading="newAppSaving" @click="saveNewApp">接入并授权</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<style scoped lang="scss">
  .sand-iam-home__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 16px;
  }

  .sand-iam-home__pending {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 14px 16px;
    margin-bottom: 18px;
    border: 1px solid var(--el-color-warning-light-5, #e6a23c);
    border-radius: 8px;
    background: var(--el-color-warning-light-9, #fdf6ec);
  }
  .sand-iam-home__pending-info { display: flex; align-items: flex-start; gap: 12px; }
  .sand-iam-home__pending-dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--el-color-warning, #e6a23c);
    color: #fff;
    font-weight: 700;
    flex-shrink: 0;
  }
  .sand-iam-home__pending-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

  .sand-iam-home__table-wrap { overflow-x: auto; }
  .sand-iam-home__table { width: 100%; border-collapse: collapse; }
  .sand-iam-home__table th,
  .sand-iam-home__table td {
    padding: 10px 12px;
    text-align: left;
    border-bottom: 1px solid var(--el-border-color-lighter, #ebeef5);
    white-space: nowrap;
  }
  .sand-iam-home__table th { font-size: 13px; color: var(--el-text-color-secondary, #909399); font-weight: 600; }

  .sand-iam-home__cell { text-align: center; }
  .sand-iam-home__mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 6px;
    border: 1px solid transparent;
    background: transparent;
    font-size: 15px;
    padding: 0;
  }
  .sand-iam-home__mark--on { color: var(--el-color-success, #67c23a); font-weight: 700; }
  .sand-iam-home__mark--off { color: var(--el-text-color-placeholder, #c0c4cc); }
  button.sand-iam-home__mark--off {
    cursor: pointer;
    border-color: var(--el-border-color, #dcdfe6);
  }
  button.sand-iam-home__mark--off:hover { color: var(--el-color-primary, #409eff); border-color: var(--el-color-primary, #409eff); }

  .sand-iam-home__key {
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 4px;
    background: var(--el-fill-color-light, #f5f7fa);
    color: var(--el-text-color-secondary, #909399);
  }
  .sand-iam-home__badge {
    margin-left: 8px;
    font-size: 12px;
    padding: 1px 8px;
    border-radius: 4px;
    background: var(--el-color-danger-light-9, #fef0f0);
    color: var(--el-color-danger, #f56c6c);
  }
  .sand-iam-home__empty { padding: 32px 0; text-align: center; }

  .sand-iam-overview__all { margin-top: 20px; }
  .sand-iam-overview__all > summary { cursor: pointer; padding: 16px; }
  .sand-iam-overview {
    padding-bottom: 0;
  }

  .sand-iam-overview__heading {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 20px;
  }

  .sand-iam-overview__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;

    &.is-measured {
      grid-auto-rows: 1px;
      grid-auto-flow: dense;
      row-gap: 0;
    }
  }

  .sand-iam-overview__item {
    min-width: 0;
    align-self: start;
  }

  @media (max-width: 1180px) {
    .sand-iam-overview__grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 640px) {
    .sand-iam-overview__grid {
      grid-template-columns: minmax(0, 1fr);
    }
    .sand-iam-home__pending { flex-direction: column; align-items: stretch; }
    .sand-iam-home__pending-actions { justify-content: flex-end; }
  }

  @media (max-width: 600px) {
    .sand-iam-overview__heading {
      align-items: stretch;
      flex-direction: column;
    }
  }
</style>
