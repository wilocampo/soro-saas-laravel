<template>
    <div :class="columnClass">
        <div class="field">
            <label :for="fieldId" class="font-medium text-900">
                {{ label }}
                <span v-if="required" class="text-red-500">*</span>
            </label>
            <component
                :is="component"
                :id="fieldId"
                :modelValue="modelValue"
                @update:modelValue="$emit('update:modelValue', $event)"
                :placeholder="placeholder"
                :class="fieldClass"
                :type="type"
                :options="options"
                :optionLabel="optionLabel"
                :optionValue="optionValue"
                :display="display"
                :feedback="feedback"
                :toggleMask="toggleMask"
                v-bind="$attrs"
            />
            <small v-if="error" class="p-error">{{ error }}</small>
            <small v-if="helpText" class="text-600 text-sm">{{ helpText }}</small>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import MultiSelect from 'primevue/multiselect';
import Dropdown from 'primevue/dropdown';
import Textarea from 'primevue/textarea';
import Calendar from 'primevue/calendar';

const props = defineProps({
    modelValue: {
        required: true
    },
    label: {
        type: String,
        required: true
    },
    fieldId: {
        type: String,
        required: true
    },
    type: {
        type: String,
        default: 'text'
    },
    component: {
        type: String,
        default: 'InputText'
    },
    placeholder: {
        type: String,
        default: ''
    },
    required: {
        type: Boolean,
        default: false
    },
    error: {
        type: String,
        default: ''
    },
    helpText: {
        type: String,
        default: ''
    },
    columnClass: {
        type: String,
        default: 'col-12 md:col-6'
    },
    options: {
        type: Array,
        default: () => []
    },
    optionLabel: {
        type: String,
        default: 'label'
    },
    optionValue: {
        type: String,
        default: 'value'
    },
    display: {
        type: String,
        default: 'chip'
    },
    feedback: {
        type: Boolean,
        default: true
    },
    toggleMask: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['update:modelValue']);

const fieldClass = computed(() => {
    const baseClass = 'w-full';
    const errorClass = props.error ? 'p-invalid' : '';
    return `${baseClass} ${errorClass}`.trim();
});

const components = {
    InputText,
    Password,
    MultiSelect,
    Dropdown,
    Textarea,
    Calendar
};

const component = computed(() => {
    return components[props.component] || InputText;
});
</script>
