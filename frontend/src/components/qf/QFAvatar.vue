<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
  defineProps<{
    name?: string;
    size?: number;
    color?: string;
  }>(),
  {
    name: '?',
    size: 32,
    color: 'var(--cyan)',
  },
);

const initials = computed(() =>
  props.name
    .split(' ')
    .map((w) => w[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase(),
);
</script>

<template>
  <div
    class="qf-avatar"
    :style="{
      width: `${size}px`,
      height: `${size}px`,
      background: `color-mix(in oklab, ${color} 15%, transparent)`,
      color,
      fontSize: `${size * 0.38}px`,
      border: `1.5px solid color-mix(in oklab, ${color} 25%, transparent)`,
    }"
  >
    {{ initials }}
  </div>
</template>
