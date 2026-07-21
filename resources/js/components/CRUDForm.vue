<template>
    <div class="card">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-3">
            <div class="flex items-center">
                <h1 class="text-3xl font-bold text-surface-900 dark:text-surface-0 m-0">{{ title }}</h1>
            </div>
            <div class="flex items-center gap-2">
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
        <form @submit.prevent="$emit('submit')">
            <div class="grid grid-cols-12 gap-4">
                <slot name="fields"></slot>
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between gap-2 mt-6">
                <div class="flex items-center gap-2">
                    <slot name="footer-start"></slot>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        label="Cancel"
                        severity="secondary"
                        outlined
                        @click="$emit('cancel')"
                    />
                    <Button
                        type="submit"
                        :label="submitLabel"
                        :icon="submitIcon"
                        :loading="loading"
                        :severity="submitSeverity"
                    />
                </div>
            </div>
        </form>
    </div>
</template>

<script setup>
import Button from 'primevue/button';

defineProps({
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
    submitSeverity: {
        type: String,
        default: 'primary'
    },
    loading: {
        type: Boolean,
        default: false
    }
});

defineEmits(['submit', 'cancel']);
</script>
