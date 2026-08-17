<template>
  <div class="flow-status-stamp" aria-hidden="true">
    <img v-if="src" :src="src" :style="imgStyle" :alt="alt" />
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import approvedSvg from '../assets/stamp/approved.svg'
  import rejectedSvg from '../assets/stamp/rejected.svg'
  import underwaySvg from '../assets/stamp/underway.svg'
  import canceledSvg from '../assets/stamp/canceled.svg'

  const props = withDefaults(
    defineProps<{
      status: number
      size?: number
    }>(),
    { size: 92 }
  )

  const src = computed(() => {
    if (props.status === 1) return approvedSvg
    if (props.status === 2) return rejectedSvg
    if (props.status === 0) return underwaySvg
    if (props.status === 3) return canceledSvg
    return canceledSvg
  })

  const alt = computed(() => {
    return ['审批进行中', '审批已通过', '审批已拒绝', '流程已撤销'][props.status] || '流程状态'
  })

  const imgStyle = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`
  }))
</script>

<style scoped>
  .flow-status-stamp {
    pointer-events: none;
    user-select: none;
  }

  .flow-status-stamp img {
    display: block;
    background: transparent;
  }
</style>
