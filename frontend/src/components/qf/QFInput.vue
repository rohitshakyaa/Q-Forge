<script setup lang="ts">
withDefaults(
  defineProps<{
    modelValue?: string | number;
    label?: string;
    placeholder?: string;
    type?: string;
    hint?: string;
    rows?: number;
    id?: string;
    autocomplete?: string;
    required?: boolean;
    disabled?: boolean;
  }>(),
  {
    modelValue: '',
    type: 'text',
    rows: 4,
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: string];
}>();

const onInput = (e: Event) => {
  const target = e.target as HTMLInputElement | HTMLTextAreaElement;
  emit('update:modelValue', target.value);
};
</script>

<template>
  <div class="qf-field">
    <label v-if="label" class="qf-label" :for="id">{{ label }}</label>
    <textarea
      v-if="type === 'textarea'"
      class="qf-input qf-textarea"
      :id="id"
      :placeholder="placeholder"
      :value="String(modelValue ?? '')"
      :rows="rows"
      :required="required"
      :disabled="disabled"
      @input="onInput"
    />
    <input
      v-else
      class="qf-input"
      :id="id"
      :type="type"
      :placeholder="placeholder"
      :value="modelValue"
      :autocomplete="autocomplete"
      :required="required"
      :disabled="disabled"
      @input="onInput"
    />
    <span v-if="hint" class="text-xs text-text3">{{ hint }}</span>
  </div>
</template>
