<script setup lang="ts">
  import './sandIamPage.css'
  defineProps<{
    title: string
    detail: string
  }>()
</script>

<template>
  <div class="sand-iam-page">
    <ElCard class="sand-iam-page-card" shadow="never">
      <ElResult icon="info" :title="title" :sub-title="detail" />
    </ElCard>
  </div>
</template>
