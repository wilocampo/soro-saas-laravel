<script setup>
import { computed } from 'vue';
import Tag from 'primevue/tag';

/**
 * Central severity map for domain statuses (spec 11 §3) — the ONLY place a
 * status maps to a color. No page hardcodes badge colors.
 */
const props = defineProps({
    status: { type: [String, Boolean], required: true },
    // Per-use overrides, e.g. { archived: 'contrast' }
    map: { type: Object, default: () => ({}) }
});

const SEVERITIES = {
    // generic
    active: 'success',
    inactive: 'secondary',
    enabled: 'success',
    disabled: 'secondary',
    // ledger (spec 01/02)
    draft: 'secondary',
    posted: 'success',
    void: 'danger',
    reversed: 'warn',
    // periods
    open: 'success',
    closed: 'warn',
    locked: 'danger',
    // provisioning / jobs / backups
    pending: 'warn',
    provisioning: 'info',
    failed: 'danger',
    completed: 'success',
    passed: 'success'
};

const normalized = computed(() => {
    if (typeof props.status === 'boolean') {
        return props.status ? 'active' : 'inactive';
    }

    return String(props.status).toLowerCase();
});

const severity = computed(() => props.map[normalized.value] ?? SEVERITIES[normalized.value] ?? 'secondary');

const label = computed(() => normalized.value.charAt(0).toUpperCase() + normalized.value.slice(1));
</script>

<template>
    <Tag :value="label" :severity="severity" />
</template>
