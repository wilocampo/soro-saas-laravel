<script setup>
import { computed } from 'vue';
import ColorPicker from 'primevue/colorpicker';
import InputText from 'primevue/inputtext';

/**
 * ColorPicker + hex text combo (spec 11 §3) — replaces native
 * <input type="color">. v-model is a '#RRGGBB' string.
 */
const props = defineProps({
    modelValue: { type: String, default: '#3B82F6' },
    label: { type: String, required: true },
    fieldId: { type: String, required: true },
    error: { type: String, default: '' },
    helpText: { type: String, default: '' },
    columnClass: { type: String, default: 'col-span-12 md:col-span-6' }
});

const emit = defineEmits(['update:modelValue']);

// PrimeVue ColorPicker works in bare hex (no '#'); the field stores '#RRGGBB'.
const pickerValue = computed({
    get: () => (props.modelValue ?? '').replace(/^#/, ''),
    set: (value) => emit('update:modelValue', `#${String(value).replace(/^#/, '')}`)
});

const textValue = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
});
</script>

<template>
    <div :class="columnClass">
        <div class="flex flex-col gap-2">
            <label :for="fieldId" class="font-medium text-surface-900 dark:text-surface-0">{{ label }}</label>
            <div class="flex items-center gap-3">
                <ColorPicker v-model="pickerValue" :inputId="fieldId" format="hex" />
                <InputText v-model="textValue" class="flex-1" :invalid="!!error" placeholder="#3B82F6" />
            </div>
            <small v-if="error" class="text-red-500">{{ error }}</small>
            <small v-if="helpText" class="text-muted-color text-sm">{{ helpText }}</small>
        </div>
    </div>
</template>
