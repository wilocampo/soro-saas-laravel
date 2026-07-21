<script setup>
/**
 * Page title + subtitle + #actions (spec 11 §3). Drops into
 * AuthenticatedLayout's #header slot — replaces hand-rolled h1+p blocks.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null }
});
</script>

<template>
    <div class="flex items-center justify-between gap-4 min-w-0">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0 m-0 leading-tight md:text-xl truncate">{{ title }}</h1>
            <p v-if="subtitle" class="m-0 mt-1 text-sm text-muted-color">{{ subtitle }}</p>
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2 flex-shrink-0">
            <slot name="actions" />
        </div>
    </div>
</template>
