<template>
    <Dialog
        :visible="visible"
        @update:visible="$emit('update:visible', $event)"
        :header="title"
        :modal="true"
        :style="{ width: '50rem' }"
        :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
        :closable="true"
        @hide="onHide"
    >
        <form @submit.prevent="onSubmit">
            <div class="grid grid-cols-12 gap-4">
                <slot name="fields"></slot>
            </div>
        </form>

        <template #footer>
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    outlined
                    @click="onCancel"
                />
                <Button
                    type="button"
                    :label="submitLabel"
                    :icon="submitIcon"
                    :loading="loading"
                    :severity="submitSeverity"
                    @click="onSubmit"
                />
            </div>
        </template>
    </Dialog>
</template>

<script setup>
import { computed } from 'vue';
import Dialog from 'primevue/dialog';
import Button from 'primevue/button';

const props = defineProps({
    visible: {
        type: Boolean,
        default: false
    },
    title: {
        type: String,
        required: true
    },
    submitLabel: {
        type: String,
        default: 'Save'
    },
    submitIcon: {
        type: String,
        default: 'pi pi-check'
    },
    loading: {
        type: Boolean,
        default: false
    },
    mode: {
        type: String,
        default: 'create', // 'create' or 'edit'
        validator: (value) => ['create', 'edit'].includes(value)
    },
    // Overrides the mode-derived severity (e.g. 'danger' for destructive submits)
    severity: {
        type: String,
        default: null
    }
});

const emit = defineEmits(['update:visible', 'submit', 'cancel', 'hide']);

const submitSeverity = computed(() => props.severity ?? (props.mode === 'create' ? 'success' : 'primary'));

const onHide = () => {
    emit('update:visible', false);
    emit('hide');
};

const onCancel = () => {
    emit('update:visible', false);
    emit('cancel');
};

const onSubmit = () => {
    emit('submit');
};
</script>
