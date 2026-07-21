<script setup>
import InputNumber from 'primevue/inputnumber';
import { computed } from 'vue';

/**
 * Peso entry whose v-model is INTEGER CENTAVOS (spec 11 §5, CLAUDE.md #5).
 *
 * The operator types pesos; the model holds centavos. Rounding happens once,
 * here, on the way in — so a float never reaches the ledger.
 */
const props = defineProps({
    modelValue: { type: [Number, String], default: 0 },
    min: { type: Number, default: 0 },
    max: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
    inputId: { type: String, default: undefined },
    invalid: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const pesos = computed({
    get: () => Number(props.modelValue ?? 0) / 100,
    // Math.round, not truncation: 12.005 must become 1201, not 1200.
    set: (value) => emit('update:modelValue', Math.round((Number(value) || 0) * 100)),
});
</script>

<template>
    <InputNumber
        v-model="pesos"
        mode="currency"
        currency="PHP"
        locale="en-PH"
        :min="min / 100"
        :max="max === null ? undefined : max / 100"
        :minFractionDigits="2"
        :maxFractionDigits="2"
        :disabled="disabled"
        :inputId="inputId"
        :invalid="invalid"
        fluid
        inputClass="text-right tabular-nums"
    />
</template>
