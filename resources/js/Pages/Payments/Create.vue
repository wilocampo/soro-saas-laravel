<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import FormSection from '@/components/FormSection.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import MoneyText from '@/components/MoneyText.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    direction: { type: String, required: true },
    partners: { type: Array, required: true },
    cashAccounts: { type: Array, required: true },
});

const toast = useAppToast();
const isReceipt = computed(() => props.direction === 'received');
const openDocuments = ref([]);
const selected = ref({});

const form = useForm({
    direction: props.direction,
    partner_id: null,
    payment_date: new Date(),
    amount_centavos: 0,
    ewt_centavos: 0,
    atc_code: '',
    cash_account_id: props.cashAccounts[0]?.id ?? null,
    memo: '',
    allocations: [],
});

// The cash plus the tax withheld is what the documents were settled for.
const settled = computed(() => (form.amount_centavos || 0) + (form.ewt_centavos || 0));
const applied = computed(() =>
    Object.values(selected.value).reduce((sum, row) => sum + (row.checked ? row.applied_centavos : 0), 0)
);
const unapplied = computed(() => settled.value - applied.value);

watch(
    () => form.partner_id,
    async (id) => {
        openDocuments.value = [];
        selected.value = {};

        if (!id) return;

        const response = await fetch(
            `${route('payments.open-documents')}?partner_id=${id}&direction=${props.direction}`,
            { headers: { Accept: 'application/json' } }
        );

        openDocuments.value = await response.json();

        openDocuments.value.forEach((doc) => {
            selected.value[`${doc.type}:${doc.id}`] = {
                checked: false,
                applied_centavos: doc.outstanding_centavos,
            };
        });
    }
);

const asDate = (value) =>
    value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : value;

const submit = () => {
    form.allocations = openDocuments.value
        .filter((doc) => selected.value[`${doc.type}:${doc.id}`]?.checked)
        .map((doc) => ({
            allocatable_type: doc.type,
            allocatable_id: doc.id,
            applied_centavos: selected.value[`${doc.type}:${doc.id}`].applied_centavos,
        }));

    form
        .transform((data) => ({ ...data, payment_date: asDate(data.payment_date) }))
        .post(route('payments.store'), {
            onError: () => toast.error('The payment was not posted', 'Check the highlighted fields.'),
        });
};
</script>

<template>
    <AuthenticatedLayout :title="isReceipt ? 'Record a collection' : 'Record a disbursement'">
        <template #header>
            <AppPageHeader
                :title="isReceipt ? 'Record a collection' : 'Record a disbursement'"
                :subtitle="
                    isReceipt
                        ? 'Cash the customer paid, plus any tax they withheld from us (Form 2307)'
                        : 'Cash paid to the vendor, net of any tax we withheld'
                "
            >
                <template #actions>
                    <Link :href="route('payments.index')">
                        <Button label="Cancel" severity="secondary" outlined />
                    </Link>
                </template>
            </AppPageHeader>
        </template>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <FormSection
                :title="isReceipt ? 'Collection' : 'Disbursement'"
                description="Money that settles no document is an advance, and posts to a deposit account rather than distorting the receivable."
            >
                <div class="col-span-12 md:col-span-6">
                    <label for="partner" class="mb-2 block font-medium">{{ isReceipt ? 'Customer' : 'Vendor' }}</label>
                    <Select
                        id="partner"
                        v-model="form.partner_id"
                        :options="partners"
                        optionLabel="registered_name"
                        optionValue="id"
                        filter
                        :invalid="!!form.errors.partner_id"
                        fluid
                    />
                </div>

                <div class="col-span-12 md:col-span-3">
                    <label for="payment_date" class="mb-2 block font-medium">Date</label>
                    <DatePicker id="payment_date" v-model="form.payment_date" dateFormat="dd M yy" showIcon fluid />
                </div>

                <div class="col-span-12 md:col-span-3">
                    <label for="cash_account" class="mb-2 block font-medium">Cash account</label>
                    <Select
                        id="cash_account"
                        v-model="form.cash_account_id"
                        :options="cashAccounts"
                        optionValue="id"
                        :optionLabel="(a) => `${a.code} — ${a.name}`"
                        fluid
                    />
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label for="amount" class="mb-2 block font-medium">Cash {{ isReceipt ? 'received' : 'paid' }}</label>
                    <MoneyInput inputId="amount" v-model="form.amount_centavos" :min="0" :invalid="!!form.errors.amount_centavos" />
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label for="ewt" class="mb-2 block font-medium">
                        Tax withheld {{ isReceipt ? '(by the customer)' : '(from the vendor)' }}
                    </label>
                    <MoneyInput inputId="ewt" v-model="form.ewt_centavos" :min="0" />
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label for="atc" class="mb-2 block font-medium">ATC</label>
                    <InputText id="atc" v-model="form.atc_code" placeholder="WC160" fluid />
                </div>

                <div class="col-span-12">
                    <label for="memo" class="mb-2 block font-medium">Memo</label>
                    <InputText id="memo" v-model="form.memo" fluid />
                </div>
            </FormSection>

            <div class="card">
                <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Apply to documents</h2>

                <p v-if="!form.partner_id" class="text-sm text-muted-color">
                    Choose a {{ isReceipt ? 'customer' : 'vendor' }} to see their open documents.
                </p>
                <p v-else-if="!openDocuments.length" class="text-sm text-muted-color">
                    Nothing outstanding — the whole amount will be held as an advance.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-surface-200 dark:border-surface-700 text-left">
                                <th class="w-12 p-2"></th>
                                <th class="p-2 font-medium">Document</th>
                                <th class="p-2 font-medium">Date</th>
                                <th class="p-2 font-medium text-right">Outstanding</th>
                                <th class="p-2 font-medium w-48 text-right">Apply</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="doc in openDocuments"
                                :key="`${doc.type}:${doc.id}`"
                                class="border-b border-surface-100 dark:border-surface-800"
                            >
                                <td class="p-2">
                                    <Checkbox v-model="selected[`${doc.type}:${doc.id}`].checked" binary />
                                </td>
                                <td class="p-2 font-mono">{{ doc.number }}</td>
                                <td class="p-2 text-muted-color">{{ doc.date }}</td>
                                <td class="p-2 text-right"><MoneyText :centavos="doc.outstanding_centavos" /></td>
                                <td class="p-2">
                                    <MoneyInput
                                        v-model="selected[`${doc.type}:${doc.id}`].applied_centavos"
                                        :min="0"
                                        :max="doc.outstanding_centavos"
                                        :disabled="!selected[`${doc.type}:${doc.id}`].checked"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 flex flex-wrap justify-end gap-8 text-sm">
                    <div class="text-right">
                        <div class="text-muted-color">Settled ({{ isReceipt ? 'cash + withheld' : 'cash + withheld' }})</div>
                        <MoneyText :centavos="settled" />
                    </div>
                    <div class="text-right">
                        <div class="text-muted-color">Applied</div>
                        <MoneyText :centavos="applied" />
                    </div>
                    <div class="text-right">
                        <div class="font-semibold">{{ isReceipt ? 'Held as deposit' : 'Advance to supplier' }}</div>
                        <span class="font-semibold"><MoneyText :centavos="unapplied" /></span>
                    </div>
                </div>

                <Message v-if="unapplied < 0" severity="error" class="mt-4" :closable="false">
                    You are applying more than the payment covers. A document cannot be over-applied.
                </Message>
            </div>

            <div class="card flex flex-wrap items-center gap-3">
                <span class="text-sm text-muted-color">
                    {{ isReceipt ? 'A collection: cash in, plus any creditable tax the customer withheld.' : 'A disbursement: cash out, less any tax withheld from the payee.' }}
                </span>
                <div class="ml-auto flex items-center gap-2">
                    <Link :href="route('payments.index')">
                        <Button label="Cancel" severity="secondary" text type="button" />
                    </Link>
                    <Button
                        type="submit"
                        label="Post payment"
                        icon="pi pi-check"
                        :loading="form.processing"
                        :disabled="settled <= 0 || unapplied < 0"
                    />
                </div>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
