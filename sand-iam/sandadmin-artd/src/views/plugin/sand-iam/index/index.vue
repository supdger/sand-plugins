<script setup lang="ts">
  import '../components/sandIamPage.css'
  import { useRouter } from 'vue-router'
  import { useAuth } from '@/hooks/core/useAuth'
  import TaskPath from '../components/TaskPath.vue'
  import { sandIamTaskPathCanOpen, sandIamTaskPaths } from '../api/taskPaths'

  const router = useRouter()
  const { hasAuth } = useAuth()

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
    <ElCard shadow="never">
      <div class="sand-iam-overview__heading">
        <div>
          <h2 class="m-0 text-lg font-semibold">SandIAM 管理入口</h2>
          <p class="mb-0 mt-2 text-sm text-gray-500">
            从下面选择要处理的事项。名称用于日常操作；系统代码只用于接口配置。
          </p>
        </div>
        <ElButton v-if="canOpenGettingStarted()" type="primary" @click="openGettingStarted"
          >第一次使用</ElButton
        >
        <span v-else class="text-sm text-gray-500">当前账号没有可管理的 SandIAM 范围。</span>
      </div>
      <div class="sand-iam-overview__grid">
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
  </div>
</template>

<style scoped lang="scss">
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

  /* ElRow 按行对齐时，矮列会被最高卡片撑出空洞。多栏分列按列填入，后续模块补进空隙。 */
  .sand-iam-overview__grid {
    column-count: 3;
    column-gap: 16px;
  }

  .sand-iam-overview__item {
    display: inline-block;
    width: 100%;
    margin-bottom: 16px;
    break-inside: avoid;
  }

  @media (max-width: 1180px) {
    .sand-iam-overview__grid {
      column-count: 2;
    }
  }

  @media (max-width: 640px) {
    .sand-iam-overview__grid {
      column-count: 1;
    }
  }

  @media (max-width: 600px) {
    .sand-iam-overview__heading {
      align-items: stretch;
      flex-direction: column;
    }
  }
</style>
