<script setup lang="ts">
defineProps<{
  steps: string[];
  current: number;
}>();
</script>

<template>
  <div class="qf-steps">
    <template v-for="(label, i) in steps" :key="label">
      <div class="qf-step">
        <div
          :class="[
            'qf-step-circle',
            i < current && 'done',
            i === current && 'active',
          ]"
        >
          {{ i < current ? '✓' : i + 1 }}
        </div>
        <span :class="['qf-step-label', i === current && 'active']">{{ label }}</span>
      </div>
      <div
        v-if="i < steps.length - 1"
        :class="['qf-step-connector', i < current && 'done']"
      />
    </template>
  </div>
</template>
