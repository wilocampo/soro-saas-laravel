<template>
    <div class="card">
        <!-- Header -->
        <div class="flex flex-column sm:flex-row sm:align-items-center sm:justify-between mb-6 gap-3">
            <div class="flex align-items-center">
                <h1 class="text-3xl font-bold text-900 m-0">{{ title }}</h1>
            </div>
            <div class="flex align-items-center gap-2">
                <Button
                    type="button"
                    :label="backLabel"
                    icon="pi pi-arrow-left"
                    severity="secondary"
                    @click="$emit('cancel')"
                />
            </div>
        </div>

        <!-- Form -->
        <form @submit.prevent="$emit('submit')" class="p-fluid">
            <div class="grid">
                <div class="col-12">
                    <div class="grid">
                        <slot name="fields"></slot>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-content-end gap-2 mt-4">
                <Button
                    type="button"
                    label="Cancel"
                    severity="secondary"
                    @click="$emit('cancel')"
                    class="p-button-outlined"
                />
                <Button
                    type="submit"
                    :label="submitLabel"
                    :icon="submitIcon"
                    :loading="loading"
                    :class="submitClass"
                />
            </div>
        </form>
    </div>
</template>

<script setup>
import Button from 'primevue/button';

const props = defineProps({
    title: {
        type: String,
        required: true
    },
    backLabel: {
        type: String,
        default: 'Back'
    },
    submitLabel: {
        type: String,
        default: 'Save'
    },
    submitIcon: {
        type: String,
        default: 'pi pi-check'
    },
    submitClass: {
        type: String,
        default: 'p-button-success'
    },
    loading: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['submit', 'cancel']);
</script>
