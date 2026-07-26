<script setup>
/**
 * A dashboard KPI card (spec 11 §3). Label + icon on top, a large value in
 * the default slot, an optional footer line. The `tone` colours only the
 * icon — semantic colour is reserved for the icon so the number itself stays
 * neutral and legible (.impeccable: numbers are the hero, quiet by default).
 */
defineProps({
    label: { type: String, required: true },
    icon: { type: String, required: true },
    // primary is the calm default; danger/warn/success are for state that
    // genuinely needs the eye.
    tone: {
        type: String,
        default: 'primary',
        validator: (v) => ['primary', 'danger', 'warn', 'success', 'muted'].includes(v),
    },
});

const toneClass = {
    primary: 'text-primary',
    danger: 'text-red-500',
    warn: 'text-orange-500',
    success: 'text-green-500',
    muted: 'text-muted-color',
};
</script>

<template>
    <div class="card !mb-0 flex h-full flex-col gap-3">
        <div class="flex items-start justify-between gap-2">
            <span class="text-sm font-medium text-muted-color">{{ label }}</span>
            <span
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-100 dark:bg-surface-800"
            >
                <i :class="[icon, toneClass[tone]]" class="text-lg" />
            </span>
        </div>

        <div class="text-2xl font-bold text-surface-900 dark:text-surface-0 md:text-3xl">
            <slot />
        </div>

        <div v-if="$slots.footer" class="mt-auto pt-1 text-sm">
            <slot name="footer" />
        </div>
    </div>
</template>
