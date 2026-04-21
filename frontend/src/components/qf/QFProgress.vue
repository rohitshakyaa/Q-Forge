<script setup lang="ts">
withDefaults(
  defineProps<{
    value?: number;
    ai?: boolean;
    label?: string;
  }>(),
  {
    value: 0,
    ai: false,
  },
);
</script>

<template>
  <div>
    <div
      v-if="label"
      style="display: flex; justify-content: space-between; margin-bottom: 6px"
    >
      <span style="font-size: 12.5px; color: var(--text2)">{{ label }}</span>
      <span style="font-size: 12px; color: var(--text3); font-family: var(--font-mono)">{{ value }}%</span>
    </div>
    <div class="qf-progress-track">
      <div :class="['qf-progress-bar', ai && 'ai']" :style="{ width: `${value}%` }" />
    </div>
  </div>
</template>
