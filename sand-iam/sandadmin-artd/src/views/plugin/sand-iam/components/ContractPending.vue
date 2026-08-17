<script setup lang="ts">
defineProps<{
  title: string
  detail: string
}>()
</script>

<template>
  <div class="art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <ElResult icon="info" :title="title" :sub-title="detail" />
    </ElCard>
  </div>
</template>
