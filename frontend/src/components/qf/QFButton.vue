<script setup lang="ts">
import { computed } from 'vue';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'ai';
type Size = 'sm' | 'md' | 'lg' | 'icon';

const props = withDefaults(
  defineProps<{
    variant?: Variant;
    size?: Size;
    disabled?: boolean;
    type?: 'button' | 'submit' | 'reset';
    block?: boolean;
  }>(),
  {
    variant: 'primary',
    size: 'md',
    disabled: false,
    type: 'button',
    block: false,
  },
);

const classes = computed(() => [
  'qf-btn',
  `qf-btn-${props.variant}`,
  props.size === 'sm' && 'qf-btn-sm',
  props.size === 'lg' && 'qf-btn-lg',
  props.size === 'icon' && 'qf-btn-icon',
]);
</script>

<template>
  <button
    :type="type"
    :class="classes"
    :disabled="disabled"
    :style="block ? { width: '100%', justifyContent: 'center' } : undefined"
  >
    <slot name="icon" />
    <slot />
  </button>
</template>
