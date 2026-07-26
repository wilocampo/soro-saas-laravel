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
import Textarea from 'primevue/textarea';
import { computed, watch } from 'vue';

const props = defineProps({
    customers: { type: Array, required: true },
    revenueAccounts: { type: Array, required: true },
    taxCodes: { type: Array, required: true },
    isVatRegistered: { type: Boolean, default: true },
});

const toast = useAppToast();

const defaultTaxCode = computed(() => (props.isVatRegistered ? (props.taxCodes[0]?.id ?? null) : null));
const vatRateBp = computed(() => props.taxCodes[0]?.rate_bp ?? 0);

const blankLine = () => ({
    description: '',
    quantity: 1,
    unit_price: 0,
    net_centavos: 0,
    vat_centavos: 0,
    account_id: props.revenueAccounts[0]?.id ?? null,
    tax_code_id: defaultTaxCode.value,
});

const form = useForm({
    partner_id: null,
    invoice_date: new Date(),
    due_date: null,
    memo: '',
    lines: [blankLine()],
});

/**
 * VAT is computed from the tax code's rate in BASIS POINTS and rounded half
 * up to the centavo — the same arithmetic the posting engine uses, so the
 * figure on screen is the figure that posts (spec 02 §3).
 */
const recomputeLine = (line) => {
    const gross = Math.round((Number(line.quantity) || 0) * (Number(line.unit_price) || 0) * 100);
    line.net_centavos = gross;
    line.vat_centavos = line.tax_code_id && props.isVatRegistered ? Math.round((gross * vatRateBp.value) / 10000) : 0;
};

watch(() => form.lines, (lines) => lines.forEach(recomputeLine), { deep: true });

// Customer terms drive the due date until the operator overrides it.
watch(
    () => form.partner_id,
    (id) => {
        const customer = props.customers.find((c) => c.id === id);
        if (customer?.payment_terms_days > 0 && form.invoice_date) {
            const due = new Date(form.invoice_date);
            due.setDate(due.getDate() + customer.payment_terms_days);
            form.due_date = due;
        }
    }
);

const net = computed(() => form.lines.reduce((sum, l) => sum + (l.net_centavos || 0), 0));
const vat = computed(() => form.lines.reduce((sum, l) => sum + (l.vat_centavos || 0), 0));
const total = computed(() => net.value + vat.value);

const addLine = () => form.lines.push(blankLine());
const removeLine = (index) => form.lines.length > 1 && form.lines.splice(index, 1);

// Dates cross the wire as plain Manila dates, never as a UTC timestamp (D18).
const asDate = (value) =>
    value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : value;

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            invoice_date: asDate(data.invoice_date),
            due_date: asDate(data.due_date),
        }))
        .post(route('invoices.store'), {
            onError: () => toast.error('The invoice was not issued', 'Check the highlighted fields.'),
        });
};
</script>

<template>
    <AuthenticatedLayout title="New invoice">
        <template #header>
            <AppPageHeader title="New invoice" subtitle="A serial number is drawn only when the invoice posts">
                <template #actions>
                    <Link :href="route('invoices.index')">
                        <Button label="Cancel" severity="secondary" outlined />
                    </Link>
                </template>
            </AppPageHeader>
        </template>

        <Message v-if="!isVatRegistered" severity="info" class="mb-4">
            This tenant is registered <strong>NON-VAT</strong>: invoices cannot carry VAT, and each one prints
            “not valid for claim of input tax”.
        </Message>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <FormSection title="Customer and dates" description="The buyer's registered name and TIN are printed on the invoice.">
                <div class="col-span-12 md:col-span-6">
                    <label for="partner" class="mb-2 block font-medium">Customer</label>
                    <Select
                        id="partner"
                        v-model="form.partner_id"
                        :options="customers"
                        optionLabel="registered_name"
                        optionValue="id"
                        filter
                        placeholder="Select a customer"
                        :invalid="!!form.errors.partner_id"
                        fluid
                    />
                    <small v-if="form.errors.partner_id" class="text-red-500">{{ form.errors.partner_id }}</small>
                </div>

                <div class="col-span-12 md:col-span-3">
                    <label for="invoice_date" class="mb-2 block font-medium">Invoice date</label>
                    <DatePicker id="invoice_date" v-model="form.invoice_date" dateFormat="dd M yy" showIcon fluid />
                </div>

                <div class="col-span-12 md:col-span-3">
                    <label for="due_date" class="mb-2 block font-medium">Due date</label>
                    <DatePicker id="due_date" v-model="form.due_date" dateFormat="dd M yy" showIcon fluid />
                </div>
            </FormSection>

            <div class="card">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="m-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Lines</h2>
                    <Button label="Add line" icon="pi pi-plus" size="small" outlined @click="addLine" type="button" />
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-surface-200 dark:border-surface-700 text-left">
                                <th class="p-2 font-medium">Description / nature of service</th>
                                <th class="p-2 font-medium w-24">Qty</th>
                                <th class="p-2 font-medium w-40">Unit price</th>
                                <th class="p-2 font-medium w-48">Revenue account</th>
                                <th class="p-2 font-medium w-32 text-right">Net</th>
                                <th class="p-2 font-medium w-32 text-right">VAT</th>
                                <th class="w-12"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(line, index) in form.lines"
                                :key="index"
                                class="border-b border-surface-100 dark:border-surface-800"
                            >
                                <td class="p-2">
                                    <InputText
                                        v-model="line.description"
                                        placeholder="Required — a blank description costs the buyer its input tax"
                                        :invalid="!!form.errors[`lines.${index}.description`]"
                                        fluid
                                    />
                                </td>
                                <td class="p-2">
                                    <InputNumber
                                        v-model="line.quantity"
                                        :minFractionDigits="0"
                                        :maxFractionDigits="4"
                                        :min="0"
                                        fluid
                                    />
                                </td>
                                <td class="p-2">
                                    <!-- Unit price keeps sub-centavo precision in the
                                         subledger; only the rounded total posts (01 §0). -->
                                    <InputNumber
                                        v-model="line.unit_price"
                                        mode="currency"
                                        currency="PHP"
                                        locale="en-PH"
                                        :minFractionDigits="2"
                                        :maxFractionDigits="4"
                                        :min="0"
                                        inputClass="text-right tabular-nums"
                                        fluid
                                    />
                                </td>
                                <td class="p-2">
                                    <Select
                                        v-model="line.account_id"
                                        :options="revenueAccounts"
                                        optionValue="id"
                                        :optionLabel="(a) => `${a.code} — ${a.name}`"
                                        :invalid="!!form.errors[`lines.${index}.account_id`]"
                                        fluid
                                    />
                                </td>
                                <td class="p-2 text-right"><MoneyText :centavos="line.net_centavos" /></td>
                                <td class="p-2 text-right"><MoneyText :centavos="line.vat_centavos" /></td>
                                <td class="p-2">
                                    <Button
                                        icon="pi pi-trash"
                                        severity="danger"
                                        text
                                        rounded
                                        type="button"
                                        :disabled="form.lines.length === 1"
                                        @click="removeLine(index)"
                                        v-tooltip.top="'Remove line'"
                                    />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-surface-300 dark:border-surface-600">
                                <td colspan="4" class="p-2 text-right font-medium">VATable sales</td>
                                <td class="p-2 text-right"><MoneyText :centavos="net" /></td>
                                <td class="p-2 text-right"><MoneyText :centavos="vat" /></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="p-2 text-right text-base font-bold">
                                    Total amount due{{ vat > 0 ? ' (VAT inclusive)' : '' }}
                                </td>
                                <td colspan="2" class="p-2 text-right text-base font-bold">
                                    <MoneyText :centavos="total" />
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <small v-if="form.errors.lines" class="text-red-500">{{ form.errors.lines }}</small>
            </div>

            <FormSection title="Notes" description="Printed on the invoice below the totals.">
                <div class="col-span-12">
                    <Textarea v-model="form.memo" rows="2" autoResize fluid />
                </div>

                <template #footer>
                    <span class="text-sm text-muted-color">
                        The serial number is drawn when the invoice posts, so a failed post burns none.
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <Link :href="route('invoices.index')">
                            <Button label="Cancel" severity="secondary" text type="button" />
                        </Link>
                        <Button
                            type="submit"
                            label="Issue invoice"
                            icon="pi pi-check"
                            :loading="form.processing"
                            :disabled="total <= 0"
                        />
                    </div>
                </template>
            </FormSection>
        </form>
    </AuthenticatedLayout>
</template>
