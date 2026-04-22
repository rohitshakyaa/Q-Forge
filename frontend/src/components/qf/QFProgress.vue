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
      class="flex justify-between mb-1.5"
    >
      <span class="text-[12.5px] text-text2">{{ label }}</span>
      <span class="text-xs text-text3 font-mono">{{ value }}%</span>
    </div>
    <div class="qf-progress-track">
      <div :class="['qf-progress-bar', ai && 'ai']" :style="{ width: `${value}%` }" />
    </div>
  </div>
</template>
