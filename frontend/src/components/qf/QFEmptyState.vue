<script setup lang="ts">
withDefaults(
  defineProps<{
    icon?: string;
    title?: string;
    desc?: string;
  }>(),
  {
    icon: '📄',
  },
);
</script>

<template>
  <div
    style="
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 32px;
      gap: 12px;
      text-align: center;
    "
  >
    <div style="font-size: 40px; opacity: 0.3">{{ icon }}</div>
    <div
      style="
        font-family: var(--font-head);
        font-size: 16px;
        font-weight: 600;
        color: var(--text2);
      "
    >
      {{ title }}
    </div>
    <div
      v-if="desc"
      style="font-size: 13.5px; color: var(--text3); max-width: 360px"
    >
      {{ desc }}
    </div>
    <slot name="action" />
  </div>
</template>
