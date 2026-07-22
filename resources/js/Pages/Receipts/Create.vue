<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import FormSection from '@/components/FormSection.vue';
import MoneyText from '@/components/MoneyText.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import DatePicker from 'primevue/datepicker';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, ref } from 'vue';

/**
 * Receiving (spec 08 §5, "make encoding receives easy").
 *
 * Keyboard-first: scan a barcode or pick an item, and the line appears with
 * its purchase UoM and conversion already defaulted. The factor shown here
 * is a PREVIEW — the server re-derives the one it stores, because a number
 * the browser computed is not evidence.
 */
const props = defineProps({
    vendors: { type: Array, required: true },
    locations: { type: Array, required: true },
    items: { type: Array, required: true },
    uoms: { type: Array, required: true },
});

const toast = useAppToast();
const barcode = ref('');
const barcodeInput = ref(null);

const uomSymbol = (id) => props.uoms.find((u) => u.id === id)?.symbol ?? '';

const form = useForm({
    partner_id: null,
    location_id: props.locations.find((l) => l.is_default)?.id ?? props.locations[0]?.id ?? null,
    received_date: new Date(),
    vendor_reference: '',
    memo: '',
    lines: [],
});

const lineFor = (item, uomId = null, qty = 1) => {
    const enteredUom = uomId ?? item.purchase_uom_id ?? item.stock_uom_id;

    return {
        item_id: item.id,
        code: item.code,
        name: item.name,
        tracking: item.tracking,
        require_expiry: item.require_expiry,
        stock_uom_id: item.stock_uom_id,
        qty_entered: qty,
        entered_uom_id: enteredUom,
        // Preview only; the server re-derives what it stores.
        conversion_factor: enteredUom === item.stock_uom_id ? 1 : Number(item.purchase_to_stock_factor),
        unit_cost: Number(item.last_cost) || 0,
        last_cost: Number(item.last_cost) || 0,
        lot_code: '',
        expiry_date: null,
    };
};

const addItem = (item) => {
    if (!item) return;
    form.lines.push(lineFor(item));
};

const scan = async () => {
    const code = barcode.value.trim();
    if (!code) return;

    const response = await fetch(route('items.barcode', code), { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        toast.warn('Unknown barcode', `Nothing is registered against ${code}.`);
        barcode.value = '';
        return;
    }

    const scanned = await response.json();
    const item = props.items.find((i) => i.id === scanned.id);

    if (!item) {
        toast.warn('Not receivable', `${scanned.code} is not an active inventory item.`);
    } else {
        // Scanning the same thing twice bumps the quantity rather than
        // stacking duplicate lines — what actually happens at a delivery.
        const existing = form.lines.find((l) => l.item_id === item.id && l.entered_uom_id === scanned.uom_id);

        if (existing) {
            existing.qty_entered += 1;
        } else {
            form.lines.push(lineFor(item, scanned.uom_id));
        }
    }

    barcode.value = '';
    barcodeInput.value?.$el?.focus();
};

const stockQty = (line) => (Number(line.qty_entered) || 0) * (Number(line.conversion_factor) || 0);
const lineCentavos = (line) => Math.round(stockQty(line) * (Number(line.unit_cost) || 0) * 100);

const total = computed(() => form.lines.reduce((sum, l) => sum + lineCentavos(l), 0));
const totalUnits = computed(() => form.lines.reduce((sum, l) => sum + stockQty(l), 0));

/** The same check the server enforces, surfaced while the operator can fix it. */
const priceWarning = (line) => {
    if (!line.last_cost) return null;
    const deviation = Math.abs(line.unit_cost - line.last_cost) / line.last_cost;

    return deviation > 0.15
        ? `${(deviation * 100).toFixed(0)}% ${line.unit_cost > line.last_cost ? 'above' : 'below'} last cost`
        : null;
};

const lotIncomplete = (line) =>
    line.tracking === 'lot' && (!line.lot_code || (line.require_expiry && !line.expiry_date));

const blockers = computed(() => form.lines.filter(lotIncomplete).length);

const asDate = (value) =>
    value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : value;

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            received_date: asDate(data.received_date),
            lines: data.lines.map((l) => ({
                item_id: l.item_id,
                qty_entered: l.qty_entered,
                entered_uom_id: l.entered_uom_id,
                conversion_factor: l.conversion_factor,
                unit_cost: l.unit_cost,
                lot_code: l.lot_code || null,
                expiry_date: asDate(l.expiry_date),
            })),
        }))
        .post(route('receipts.store'), {
            onError: () => toast.error('The receipt was not posted', 'Check the highlighted fields.'),
        });
};
</script>

<template>
    <AuthenticatedLayout title="Receive stock">
        <template #header>
            <AppPageHeader title="Receive stock" subtitle="Scan or pick; the conversion defaults from the item">
                <template #actions>
                    <Link :href="route('receipts.index')">
                        <Button label="Cancel" severity="secondary" outlined />
                    </Link>
                </template>
            </AppPageHeader>
        </template>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <FormSection title="Delivery" description="Received goods credit GRNI until the supplier's bill arrives.">
                <div class="col-span-12 md:col-span-4">
                    <label for="vendor" class="mb-2 block font-medium">Supplier</label>
                    <Select
                        id="vendor"
                        v-model="form.partner_id"
                        :options="vendors"
                        optionLabel="registered_name"
                        optionValue="id"
                        filter
                        showClear
                        placeholder="Optional — blank for found or opening stock"
                        fluid
                    />
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label for="location" class="mb-2 block font-medium">Location</label>
                    <Select
                        id="location"
                        v-model="form.location_id"
                        :options="locations"
                        optionValue="id"
                        :optionLabel="(l) => `${l.code} — ${l.name}`"
                        fluid
                    />
                </div>
                <div class="col-span-12 md:col-span-2">
                    <label for="date" class="mb-2 block font-medium">Received</label>
                    <DatePicker id="date" v-model="form.received_date" dateFormat="dd M yy" showIcon fluid />
                </div>
                <div class="col-span-12 md:col-span-3">
                    <label for="ref" class="mb-2 block font-medium">Supplier's DR / invoice no.</label>
                    <InputText id="ref" v-model="form.vendor_reference" fluid />
                </div>
            </FormSection>

            <div class="card">
                <div class="mb-4 flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-64">
                        <label for="barcode" class="mb-2 block font-medium">Scan barcode</label>
                        <InputText
                            id="barcode"
                            ref="barcodeInput"
                            v-model="barcode"
                            placeholder="Scan or type, then Enter"
                            autofocus
                            fluid
                            @keyup.enter="scan"
                        />
                    </div>
                    <div class="flex-1 min-w-64">
                        <label for="picker" class="mb-2 block font-medium">or pick an item</label>
                        <Select
                            id="picker"
                            :options="items"
                            optionLabel="name"
                            filter
                            placeholder="Search by code or name"
                            :filterFields="['code', 'name']"
                            fluid
                            @update:modelValue="addItem"
                        >
                            <template #option="{ option }">
                                <span class="font-mono text-xs text-muted-color">{{ option.code }}</span>
                                <span class="ml-2">{{ option.name }}</span>
                            </template>
                        </Select>
                    </div>
                </div>

                <p v-if="!form.lines.length" class="text-sm text-muted-color">
                    No lines yet. Scanning the same barcode twice bumps the quantity instead of adding a duplicate.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-surface-200 text-left dark:border-surface-700">
                                <th class="p-2 font-medium">Item</th>
                                <th class="p-2 font-medium w-24">Qty</th>
                                <th class="p-2 font-medium w-32">Unit</th>
                                <th class="p-2 font-medium w-28">× factor</th>
                                <th class="p-2 font-medium w-36">Unit cost</th>
                                <th class="p-2 font-medium w-32 text-right">Stock qty</th>
                                <th class="p-2 font-medium w-32 text-right">Line cost</th>
                                <th class="w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(line, index) in form.lines" :key="index">
                                <tr class="border-b border-surface-100 dark:border-surface-800">
                                    <td class="p-2">
                                        <div class="font-mono text-xs text-muted-color">{{ line.code }}</div>
                                        <div>{{ line.name }}</div>
                                    </td>
                                    <td class="p-2">
                                        <InputNumber v-model="line.qty_entered" :min="0" :maxFractionDigits="5" fluid />
                                    </td>
                                    <td class="p-2">
                                        <Select
                                            v-model="line.entered_uom_id"
                                            :options="uoms"
                                            optionValue="id"
                                            optionLabel="symbol"
                                            fluid
                                        />
                                    </td>
                                    <td class="p-2">
                                        <InputNumber v-model="line.conversion_factor" :min="0" :maxFractionDigits="5" fluid />
                                    </td>
                                    <td class="p-2">
                                        <InputNumber
                                            v-model="line.unit_cost"
                                            mode="currency"
                                            currency="PHP"
                                            locale="en-PH"
                                            :minFractionDigits="2"
                                            :maxFractionDigits="6"
                                            :min="0"
                                            inputClass="text-right tabular-nums"
                                            fluid
                                        />
                                    </td>
                                    <td class="p-2 text-right tabular-nums">
                                        {{ stockQty(line).toLocaleString() }} {{ uomSymbol(line.stock_uom_id) }}
                                    </td>
                                    <td class="p-2 text-right"><MoneyText :centavos="lineCentavos(line)" /></td>
                                    <td class="p-2">
                                        <Button
                                            icon="pi pi-trash"
                                            severity="danger"
                                            text
                                            rounded
                                            type="button"
                                            @click="form.lines.splice(index, 1)"
                                        />
                                    </td>
                                </tr>

                                <!-- Live conversion preview + the price flag, at the
                                     cheapest moment to catch a mis-key. -->
                                <tr v-if="priceWarning(line) || line.tracking === 'lot'">
                                    <td colspan="8" class="px-2 pb-2">
                                        <div class="flex flex-wrap items-center gap-3">
                                            <span class="text-xs text-muted-color">
                                                {{ line.qty_entered }} {{ uomSymbol(line.entered_uom_id) }}
                                                = {{ stockQty(line).toLocaleString() }}
                                                {{ uomSymbol(line.stock_uom_id) }}
                                            </span>
                                            <span v-if="priceWarning(line)" class="text-xs text-orange-500">
                                                ⚠ {{ priceWarning(line) }}
                                            </span>
                                            <template v-if="line.tracking === 'lot'">
                                                <InputText
                                                    v-model="line.lot_code"
                                                    placeholder="Lot code (required)"
                                                    class="w-48"
                                                    :invalid="!line.lot_code"
                                                />
                                                <DatePicker
                                                    v-model="line.expiry_date"
                                                    dateFormat="dd M yy"
                                                    showIcon
                                                    :placeholder="line.require_expiry ? 'Expiry (required)' : 'Expiry'"
                                                    :invalid="line.require_expiry && !line.expiry_date"
                                                />
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-surface-300 dark:border-surface-600">
                                <td colspan="5" class="p-2 text-right font-semibold">Total</td>
                                <td class="p-2 text-right tabular-nums font-semibold">
                                    {{ totalUnits.toLocaleString() }}
                                </td>
                                <td class="p-2 text-right font-semibold"><MoneyText :centavos="total" /></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <Message v-if="blockers" severity="warn" :closable="false">
                {{ blockers }} lot-tracked line(s) still need a lot code or expiry date. They are rejected before
                anything is written, so nothing lands half-saved.
            </Message>

            <div class="card flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    label="Post receipt"
                    icon="pi pi-check"
                    :loading="form.processing"
                    :disabled="!form.lines.length || blockers > 0"
                />
                <Link :href="route('receipts.index')">
                    <Button label="Cancel" severity="secondary" text type="button" />
                </Link>
                <span class="text-sm text-muted-color">
                    Posting debits Inventory and credits Goods Received Not Invoiced.
                </span>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
