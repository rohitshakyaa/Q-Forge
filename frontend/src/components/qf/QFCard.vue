<script setup lang="ts">
withDefaults(
  defineProps<{
    glow?: boolean;
  }>(),
  {
    glow: false,
  },
);
</script>

<template>
  <div :class="['qf-card', glow && 'glow']">
    <slot />
  </div>
</template>
