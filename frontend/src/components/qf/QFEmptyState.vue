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
  <div class="flex flex-col items-center justify-center px-6 py-12 sm:px-8 sm:py-[60px] gap-3 text-center">
    <div class="text-[40px] opacity-30">{{ icon }}</div>
    <div class="font-head text-base font-semibold text-text2">
      {{ title }}
    </div>
    <div v-if="desc" class="text-[13.5px] text-text3 max-w-[360px]">
      {{ desc }}
    </div>
    <slot name="action" />
  </div>
</template>
