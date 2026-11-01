<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { policyEditorFields, policyPublicationLabel, policySaveMessage, policyPublishMessage } from '../api/policyPublication'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'application_id', label: '所属接入应用' },
    { key: 'resource_id', label: 'resource_id' },
    { key: 'role_id', label: 'role_id' },
    { key: 'identity_id', label: '应用身份' },
    { key: 'action', label: '业务动作' },
    { key: 'effect', label: '授权结果' },
    { key: 'published_version_id', label: '运行版本', minWidth: 230, format: policyPublicationLabel },
    { key: 'status', label: '状态' },
    { key: 'priority', label: 'priority' }
  ]
  const filters: SandIamFilterKey[] = ['status', 'application_id']
</script>

<template>
  <ResourceListPage
    title="策略"
    create-title="新建策略草稿"
    description="定义谁能对哪类业务资源做什么。保存后请点击行内“发布”，生成运行版本；在“版本历史”核对当前指针，再模拟并验证真实允许与拒绝。"
    object-hint="已有发布版本时，保存编辑内容不会替换当前运行版本，必须重新发布；启停设置立即影响已发布版本。运行版本列依据服务端发布指针显示，不以表单配置值判断。"
    endpoint="policy"
    index-permission="sand_iam:policy:index"
    permission-prefix="sand_iam:policy"
    write-mode="policy"
    :columns="columns"
    :filters="filters"
    :form-fields="policyEditorFields"
    :save-success-message="policySaveMessage"
    :publish-success-message="policyPublishMessage"
  />
</template>
