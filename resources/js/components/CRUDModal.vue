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
        <form @submit.prevent="onSubmit" class="p-fluid">
            <div class="grid">
                <div class="col-12">
                    <div class="grid">
                        <slot name="fields"></slot>
                    </div>
                </div>
            </div>
        </form>

        <template #footer>
            <div class="flex justify-content-end gap-2">
                <Button
                    type="button"
                    label="Cancel"
                    icon="pi pi-times"
                    severity="secondary"
                    @click="onCancel"
                    class="p-button-outlined"
                />
                <Button
                    type="button"
                    :label="submitLabel"
                    :icon="submitIcon"
                    :loading="loading"
                    @click="onSubmit"
                    :class="submitButtonClass"
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
    }
});

const emit = defineEmits(['update:visible', 'submit', 'cancel', 'hide']);

const submitButtonClass = computed(() => {
    return props.mode === 'create' ? 'p-button-success' : 'p-button-primary';
});

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

<style scoped>
/* Use PrimeVue's built-in styling */
</style>
