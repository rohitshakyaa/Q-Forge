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

const style = computed(() => ({
  width: `${props.size}px`,
  height: `${props.size}px`,
  background: `color-mix(in oklab, ${props.color} 15%, transparent)`,
  color: props.color,
  fontSize: `${props.size * 0.38}px`,
  border: `1.5px solid color-mix(in oklab, ${props.color} 25%, transparent)`,
}));
</script>

<template>
  <div class="qf-avatar" :style="style">
    {{ initials }}
  </div>
</template>
