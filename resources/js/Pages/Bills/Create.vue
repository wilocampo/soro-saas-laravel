<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import FormSection from '@/components/FormSection.vue';
import MoneyInput from '@/components/MoneyInput.vue';
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
import { computed, watch } from 'vue';

const props = defineProps({
    vendors: { type: Array, required: true },
    expenseAccounts: { type: Array, required: true },
    taxCodes: { type: Array, required: true },
    atcRates: { type: Array, default: () => [] },
});

const toast = useAppToast();
const vatRateBp = computed(() => props.taxCodes[0]?.rate_bp ?? 0);

const blankLine = () => ({
    description: '',
    quantity: 1,
    unit_price: 0,
    net_centavos: 0,
    input_vat_centavos: 0,
    account_id: props.expenseAccounts[0]?.id ?? null,
    tax_code_id: props.taxCodes[0]?.id ?? null,
});

const form = useForm({
    partner_id: null,
    bill_number: '',
    bill_date: new Date(),
    due_date: null,
    ewt_centavos: 0,
    atc_code: '',
    memo: '',
    lines: [blankLine()],
});

const recomputeLine = (line) => {
    const gross = Math.round((Number(line.quantity) || 0) * (Number(line.unit_price) || 0) * 100);
    line.net_centavos = gross;
    line.input_vat_centavos = line.tax_code_id ? Math.round((gross * vatRateBp.value) / 10000) : 0;
};

watch(() => form.lines, (lines) => lines.forEach(recomputeLine), { deep: true });

watch(
    () => form.partner_id,
    (id) => {
        const vendor = props.vendors.find((v) => v.id === id);
        if (vendor?.default_atc_code) form.atc_code = vendor.default_atc_code;
    }
);

const net = computed(() => form.lines.reduce((sum, l) => sum + (l.net_centavos || 0), 0));
const inputVat = computed(() => form.lines.reduce((sum, l) => sum + (l.input_vat_centavos || 0), 0));
// Payable = net + input VAT − the tax we withhold and remit for the vendor.
const payable = computed(() => net.value + inputVat.value - (form.ewt_centavos || 0));

const selectedVendor = computed(() => props.vendors.find((v) => v.id === form.partner_id));
const swornExpired = computed(() => {
    const until = selectedVendor.value?.sworn_declaration_valid_until;

    return until ? new Date(until) < new Date() : false;
});

const addLine = () => form.lines.push(blankLine());
const removeLine = (index) => form.lines.length > 1 && form.lines.splice(index, 1);

const asDate = (value) =>
    value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : value;

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            bill_date: asDate(data.bill_date),
            due_date: asDate(data.due_date),
        }))
        .post(route('bills.store'), {
            onError: () => toast.error('The bill was not recorded', 'Check the highlighted fields.'),
        });
};
</script>

<template>
    <AuthenticatedLayout title="New bill">
        <template #header>
            <AppPageHeader title="New vendor bill" subtitle="The withholding entered here is booked now, not at payment">
                <template #actions>
                    <Link :href="route('bills.index')">
                        <Button label="Cancel" severity="secondary" outlined />
                    </Link>
                </template>
            </AppPageHeader>
        </template>

        <Message v-if="swornExpired" severity="warn" class="mb-4" :closable="false">
            This vendor's sworn declaration has expired — the <strong>higher</strong> withholding rate applies.
        </Message>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <FormSection title="Vendor and dates">
                <div class="col-span-12 md:col-span-5">
                    <label for="partner" class="mb-2 block font-medium">Vendor</label>
                    <Select
                        id="partner"
                        v-model="form.partner_id"
                        :options="vendors"
                        optionLabel="registered_name"
                        optionValue="id"
                        filter
                        :invalid="!!form.errors.partner_id"
                        fluid
                    />
                </div>

                <div class="col-span-12 md:col-span-3">
                    <label for="bill_number" class="mb-2 block font-medium">Vendor's invoice no.</label>
                    <InputText
                        id="bill_number"
                        v-model="form.bill_number"
                        :invalid="!!form.errors.bill_number"
                        fluid
                    />
                </div>

                <div class="col-span-12 md:col-span-2">
                    <label for="bill_date" class="mb-2 block font-medium">Bill date</label>
                    <DatePicker id="bill_date" v-model="form.bill_date" dateFormat="dd M yy" showIcon fluid />
                </div>

                <div class="col-span-12 md:col-span-2">
                    <label for="due_date" class="mb-2 block font-medium">Due date</label>
                    <DatePicker id="due_date" v-model="form.due_date" dateFormat="dd M yy" showIcon fluid />
                </div>
            </FormSection>

            <div class="card">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="m-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Lines</h2>
                    <Button label="Add line" icon="pi pi-plus" size="small" outlined type="button" @click="addLine" />
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-surface-200 dark:border-surface-700 text-left">
                                <th class="p-2 font-medium">Description</th>
                                <th class="p-2 font-medium w-24">Qty</th>
                                <th class="p-2 font-medium w-40">Unit price</th>
                                <th class="p-2 font-medium w-48">Account</th>
                                <th class="p-2 font-medium w-32 text-right">Net</th>
                                <th class="p-2 font-medium w-32 text-right">Input VAT</th>
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
                                    <InputText v-model="line.description" fluid />
                                </td>
                                <td class="p-2">
                                    <InputNumber v-model="line.quantity" :minFractionDigits="0" :maxFractionDigits="4" :min="0" fluid />
                                </td>
                                <td class="p-2">
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
                                        :options="expenseAccounts"
                                        optionValue="id"
                                        :optionLabel="(a) => `${a.code} — ${a.name}`"
                                        fluid
                                    />
                                </td>
                                <td class="p-2 text-right"><MoneyText :centavos="line.net_centavos" /></td>
                                <td class="p-2 text-right"><MoneyText :centavos="line.input_vat_centavos" /></td>
                                <td class="p-2">
                                    <Button
                                        icon="pi pi-trash"
                                        severity="danger"
                                        text
                                        rounded
                                        type="button"
                                        :disabled="form.lines.length === 1"
                                        @click="removeLine(index)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <FormSection
                title="Withholding"
                description="Tax we withhold from the vendor and remit on their behalf — a liability booked now (RR 4-2024)."
            >
                <div class="col-span-12 md:col-span-4">
                    <label for="ewt" class="mb-2 block font-medium">Tax withheld</label>
                    <MoneyInput inputId="ewt" v-model="form.ewt_centavos" :min="0" />
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label for="atc" class="mb-2 block font-medium">ATC</label>
                    <Select
                        v-if="atcRates.length"
                        id="atc"
                        v-model="form.atc_code"
                        :options="atcRates"
                        optionValue="atc_code"
                        :optionLabel="(a) => `${a.atc_code} — ${a.description ?? ''}`"
                        showClear
                        filter
                        fluid
                    />
                    <InputText v-else id="atc" v-model="form.atc_code" placeholder="WC160" fluid />
                    <small v-if="form.errors.atc_code" class="text-red-500">{{ form.errors.atc_code }}</small>
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label for="memo" class="mb-2 block font-medium">Memo</label>
                    <InputText id="memo" v-model="form.memo" fluid />
                </div>

                <div class="col-span-12 flex flex-wrap justify-end gap-8 border-t border-surface-200 pt-4 text-sm dark:border-surface-700">
                    <div class="text-right">
                        <div class="text-muted-color">Net</div>
                        <MoneyText :centavos="net" />
                    </div>
                    <div class="text-right">
                        <div class="text-muted-color">Input VAT</div>
                        <MoneyText :centavos="inputVat" />
                    </div>
                    <div class="text-right">
                        <div class="text-muted-color">Less withheld</div>
                        <MoneyText :centavos="-form.ewt_centavos" :colorNegative="false" />
                    </div>
                    <div class="text-right">
                        <div class="font-semibold">Payable</div>
                        <span class="font-semibold"><MoneyText :centavos="payable" /></span>
                    </div>
                </div>

                <template #footer>
                    <span class="text-sm text-muted-color">
                        Withholding is booked now, at the bill date, not when you pay (RR 4-2024).
                    </span>
                    <div class="ml-auto flex items-center gap-2">
                        <Link :href="route('bills.index')">
                            <Button label="Cancel" severity="secondary" text type="button" />
                        </Link>
                        <Button
                            type="submit"
                            label="Record bill"
                            icon="pi pi-check"
                            :loading="form.processing"
                            :disabled="net <= 0"
                        />
                    </div>
                </template>
            </FormSection>
        </form>
    </AuthenticatedLayout>
</template>
