<script setup lang="ts">
const props = withDefaults(
  defineProps<{
    open: boolean;
    title?: string;
    width?: number;
  }>(),
  {
    width: 520,
  },
);

const emit = defineEmits<{ close: [] }>();

const handleOverlay = (e: MouseEvent) => {
  if (e.target === e.currentTarget) emit('close');
};
</script>

<template>
  <div v-if="open" class="qf-modal-overlay" @click="handleOverlay">
    <div class="qf-modal qf-anim-in" :style="{ width: `${width}px` }">
      <div class="qf-modal-header">
        <span style="font-family: var(--font-head); font-weight: 600; font-size: 16px">{{ title }}</span>
        <button
          class="qf-btn qf-btn-ghost qf-btn-icon"
          style="font-size: 18px; line-height: 1"
          @click="emit('close')"
        >
          ×
        </button>
      </div>
      <div class="qf-modal-body">
        <slot />
      </div>
      <div v-if="$slots.footer" class="qf-modal-footer">
        <slot name="footer" />
      </div>
    </div>
  </div>
</template>
