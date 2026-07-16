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
  'update:modelValue': [value: string | number];
}>();

const normalized = computed(() =>
  props.options.map((o) => (typeof o === 'string' ? { value: o, label: o } : o)),
);

const onChange = (e: Event) => {
  const raw = (e.target as HTMLSelectElement).value;
  // The DOM always gives us a string — emit the original option value so
  // number values (e.g. unit ids) keep their type for strict comparisons.
  const match = normalized.value.find((o) => String(o.value) === raw);
  emit('update:modelValue', match ? match.value : raw);
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
