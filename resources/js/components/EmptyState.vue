<script setup>
/**
 * Icon + title + hint + optional action (spec 11 §3) — used by tables,
 * Notifications, and first-run screens. Theme tokens only.
 */
defineProps({
    icon: { type: String, default: 'pi pi-inbox' },
    title: { type: String, required: true },
    hint: { type: String, default: null }
});
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-3 py-10 text-center">
        <i :class="icon" class="text-4xl text-surface-400 dark:text-surface-500" />
        <div>
            <p class="m-0 font-medium text-surface-700 dark:text-surface-200">{{ title }}</p>
            <p v-if="hint" class="m-0 mt-1 text-sm text-muted-color">{{ hint }}</p>
        </div>
        <slot name="action" />
    </div>
</template>
