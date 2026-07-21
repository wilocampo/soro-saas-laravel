<template>
    <div :class="columnClass">
        <div class="flex flex-col gap-2">
            <label :for="fieldId" class="font-medium text-surface-900 dark:text-surface-0">
                {{ label }}
                <span v-if="required" class="text-red-500">*</span>
            </label>
            <component
                :is="component"
                :id="fieldId"
                :modelValue="modelValue"
                @update:modelValue="$emit('update:modelValue', $event)"
                :placeholder="placeholder"
                class="w-full"
                :invalid="!!error"
                :type="type"
                :options="options"
                :optionLabel="optionLabel"
                :optionValue="optionValue"
                :display="display"
                :feedback="feedback"
                :toggleMask="toggleMask"
                v-bind="$attrs"
            />
            <small v-if="error" class="text-red-500">{{ error }}</small>
            <small v-if="helpText" class="text-muted-color text-sm">{{ helpText }}</small>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import InputMask from 'primevue/inputmask';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import MultiSelect from 'primevue/multiselect';
import Password from 'primevue/password';
import RadioButton from 'primevue/radiobutton';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import ToggleSwitch from 'primevue/toggleswitch';

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
    // 12-col span (Tailwind grid; parent renders `grid grid-cols-12`)
    columnClass: {
        type: String,
        default: 'col-span-12 md:col-span-6'
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

// PrimeVue 4 names only (spec 11 §2 rule 1). The v3 keys stay as aliases so
// existing pages keep working until their conversion ticket lands.
const components = {
    InputText,
    Password,
    MultiSelect,
    Select,
    Dropdown: Select,
    Textarea,
    DatePicker,
    Calendar: DatePicker,
    InputNumber,
    InputMask,
    ToggleSwitch,
    RadioButton,
    Checkbox
};

const component = computed(() => {
    return components[props.component] || InputText;
});
</script>
