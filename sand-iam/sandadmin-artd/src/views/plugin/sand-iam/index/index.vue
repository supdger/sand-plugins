<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { onMounted, onScopeDispose, ref } from 'vue'
  import { useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import TaskPath from '../components/TaskPath.vue'
  import GoalGuide from '../components/GoalGuide.vue'
  import { sandIamTaskPathCanOpen, sandIamTaskPaths } from '../api/taskPaths'

  const router = useRouter()
  const { hasAuth } = useAuth()
  const taskGrid = ref<HTMLElement | null>(null)
  const gridReady = ref(false)
  let cardObserver: ResizeObserver | undefined
  let layoutFrame = 0

  onMounted(() => {
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
</script>

<template>
  <div class="sand-iam-page sand-iam-overview">
    <GoalGuide />
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
  </div>
</template>

<style scoped lang="scss">
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
  }

  @media (max-width: 600px) {
    .sand-iam-overview__heading {
      align-items: stretch;
      flex-direction: column;
    }
  }
</style>
