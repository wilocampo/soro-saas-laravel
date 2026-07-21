<script setup>
import { computed } from 'vue';

/**
 * The single source of peso formatting (spec 11 §5). Money crosses the wire
 * as INTEGER CENTAVOS — never a float — and is converted only here, at the
 * display boundary.
 */
const props = defineProps({
    centavos: { type: [Number, String], default: 0 },
    /** Show an explicit + on positive amounts (movement columns). */
    signed: { type: Boolean, default: false },
    /** Render the symbol; off inside a column that already has one. */
    symbol: { type: Boolean, default: true },
    /** Red for negatives — off where a negative is expected (a credit). */
    colorNegative: { type: Boolean, default: true },
});

const value = computed(() => Number(props.centavos ?? 0));

const formatted = computed(() => {
    const pesos = Math.abs(value.value) / 100;
    const body = pesos.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const sign = value.value < 0 ? '−' : props.signed && value.value > 0 ? '+' : '';

    return `${sign}${props.symbol ? '₱' : ''}${body}`;
});
</script>

<template>
    <span
        class="tabular-nums whitespace-nowrap"
        :class="colorNegative && value < 0 ? 'text-red-500' : 'text-surface-900 dark:text-surface-0'"
    >
        {{ formatted }}
    </span>
</template>
