<script setup lang="ts">
  import { applicationExperienceFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'
  import ResourceListPage from '../components/ResourceListPage.vue'

  const columns: SandIamResourceColumn[] = [
    { key: 'brand_name', label: '应用显示名称', minWidth: 180 },
    { key: 'application_id', label: '所属接入应用', minWidth: 180 },
    { key: 'registration_mode', label: '注册方式' },
    { key: 'login_methods', label: '登录方式摘要', minWidth: 160 },
    { key: 'theme_mode', label: '默认主题' },
    { key: 'primary_color', label: '品牌主色' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['keywords', 'organization_id', 'application_id', 'status']
</script>

<template>
  <ResourceListPage
    title="登录外观"
    create-title="新建登录外观"
    object-hint="每个接入应用最多一条。完整 Logo 和协议地址只在表单高级区，不进默认列表。"
    description="配置应用用户看到的品牌名称、注册方式和登录方式。应用必须按名称选择。SandAdmin 不承载终端用户登录页。"
    endpoint="application-experience"
    index-permission="sand_iam:application_experience:index"
    permission-prefix="sand_iam:application_experience"
    :columns="columns"
    :filters="filters"
    :form-fields="applicationExperienceFields"
  />
</template>
