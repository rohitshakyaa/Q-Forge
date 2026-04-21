<script setup lang="ts">
defineProps<{
  title: string;
  subtitle?: string;
  back?: string;
}>();

const emit = defineEmits<{ back: [] }>();
</script>

<template>
  <div style="margin-bottom: 24px">
    <button
      v-if="back"
      class="qf-btn qf-btn-ghost qf-btn-sm"
      style="margin-bottom: 10px; padding-left: 0; color: var(--text3)"
      @click="emit('back')"
    >
      ← {{ back }}
    </button>
    <div
      style="
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
      "
    >
      <div>
        <h1
          style="
            font-family: var(--font-head);
            font-size: 22px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.2;
          "
        >
          {{ title }}
        </h1>
        <p
          v-if="subtitle"
          style="color: var(--text3); font-size: 13.5px; margin-top: 4px"
        >
          {{ subtitle }}
        </p>
      </div>
      <div
        v-if="$slots.actions"
        style="display: flex; gap: 8px; flex-shrink: 0"
      >
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>
