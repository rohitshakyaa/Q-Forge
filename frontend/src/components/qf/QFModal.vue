<script setup lang="ts">
withDefaults(
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
    <div class="qf-modal qf-anim-in" :style="{ maxWidth: `${width}px` }">
      <div class="qf-modal-header">
        <span class="font-head font-semibold text-base">{{ title }}</span>
        <button
          class="qf-btn qf-btn-ghost qf-btn-icon text-lg leading-none"
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
