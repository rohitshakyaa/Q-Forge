<script setup lang="ts">
type Variant = 'cyan' | 'indigo' | 'success' | 'warn' | 'danger' | 'ai' | 'neutral';

withDefaults(
  defineProps<{
    variant?: Variant;
    dot?: boolean;
  }>(),
  {
    variant: 'neutral',
    dot: false,
  },
);
</script>

<template>
  <span :class="['qf-badge', `qf-badge-${variant}`]">
    <span v-if="dot" class="qf-dot" style="background: currentColor" />
    <slot />
  </span>
</template>
