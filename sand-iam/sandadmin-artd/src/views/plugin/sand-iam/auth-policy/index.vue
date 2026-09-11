<script setup lang="ts">
  import ResourceListPage from '../components/ResourceListPage.vue'
  import { authPolicyFields } from '../api/fields'
  import type { SandIamFilterKey, SandIamResourceColumn } from '../api/types'

  const columns: SandIamResourceColumn[] = [
    { key: 'application_id', label: '所属接入应用', minWidth: 200 },
    { key: 'registration_enabled', label: '公开注册' },
    { key: 'password_min_length', label: '密码最短位数' },
    { key: 'max_login_failures', label: '失败锁定次数' },
    { key: 'require_captcha', label: '登录验证码' },
    { key: 'status', label: '状态' }
  ]
  const filters: SandIamFilterKey[] = ['organization_id', 'application_id', 'status']
</script>

<template>
  <ResourceListPage
    title="应用认证设置"
    create-title="新建认证策略"
    object-hint="每个接入应用只能有一条认证策略。已存在时请直接编辑，不要重复新建。"
    description="同一条策略同时管理公开注册、密码规则、邮箱/手机/登录验证码开关和失败锁定。令牌有效期和通行密钥配置在高级设置中；敏感内容不会显示在列表中。"
    endpoint="auth-policy"
    index-permission="sand_iam:auth_policy:index"
    permission-prefix="sand_iam:auth_policy"
    disable-impact="停用后，该应用不再使用这条认证策略做新的注册和登录校验。已有用户不会自动删除；需要恢复时请重新启用。"
    :columns="columns"
    :filters="filters"
    :form-fields="authPolicyFields"
  />
</template>
