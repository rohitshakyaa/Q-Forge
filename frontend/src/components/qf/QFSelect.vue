<script setup lang="ts">
import { computed } from 'vue';

export type QFOption = string | { value: string | number; label: string };

const props = withDefaults(
  defineProps<{
    modelValue?: string | number;
    label?: string;
    options: QFOption[];
    id?: string;
    disabled?: boolean;
  }>(),
  {
    modelValue: '',
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();

const normalized = computed(() =>
  props.options.map((o) => (typeof o === 'string' ? { value: o, label: o } : o)),
);

const onChange = (e: Event) => {
  emit('update:modelValue', (e.target as HTMLSelectElement).value);
};
</script>

<template>
  <div class="qf-field">
    <label v-if="label" class="qf-label" :for="id">{{ label }}</label>
    <select
      class="qf-input qf-select"
      :id="id"
      :value="modelValue"
      :disabled="disabled"
      @change="onChange"
    >
      <option v-for="opt in normalized" :key="opt.value" :value="opt.value">
        {{ opt.label }}
      </option>
    </select>
  </div>
</template>
