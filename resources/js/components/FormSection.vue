<script setup>
/**
 * Titled form group with description + 12-col field grid (spec 11 §3, the
 * Sakai FormLayout pattern). Replaces ad-hoc space-y-6 stacks.
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null }
});
</script>

<template>
    <div class="card">
        <div class="mb-4">
            <h2 class="text-xl font-semibold text-surface-900 dark:text-surface-0 m-0">{{ title }}</h2>
            <p v-if="description" class="m-0 mt-1 text-sm text-muted-color">{{ description }}</p>
        </div>
        <div class="grid grid-cols-12 gap-4">
            <slot />
        </div>
        <div v-if="$slots.footer" class="flex items-center gap-3 mt-6">
            <slot name="footer" />
        </div>
    </div>
</template>
