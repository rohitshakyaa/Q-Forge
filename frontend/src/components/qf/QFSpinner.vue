<script setup lang="ts">
withDefaults(defineProps<{ size?: number }>(), { size: 18 });
</script>

<template>
  <div class="qf-spinner" :style="{ width: `${size}px`, height: `${size}px` }" />
</template>
