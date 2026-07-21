<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import FileUpload from 'primevue/fileupload';
import Message from 'primevue/message';

const props = defineProps({
    bill: { type: Object, required: true },
    outstanding: { type: Number, required: true },
    receipts: { type: Array, default: () => [] },
    journalEntry: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
});

const toast = useAppToast();

const formatDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const formatSize = (bytes) => `${(bytes / 1024).toFixed(0)} KB`;

/**
 * Uploads through Inertia so the flash/toast path is the same as every other
 * mutation — FileUpload's own XHR would bypass it.
 */
const uploadReceipt = (event) => {
    router.post(
        route('bills.receipt', props.bill.id),
        { receipt: event.files[0] },
        {
            forceFormData: true,
            preserveScroll: true,
            onError: (errors) => toast.error('Not attached', Object.values(errors)[0]),
        }
    );
};
</script>

<template>
    <AuthenticatedLayout :title="`Bill ${bill.reference}`">
        <template #header>
            <AppPageHeader :title="`Bill ${bill.reference}`" :subtitle="bill.partner?.registered_name" />
        </template>

        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12 lg:col-span-8">
                <div class="card">
                    <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Lines</h2>
                    <DataTable :value="bill.lines" dataKey="id" class="p-datatable-sm">
                        <Column field="line_no" header="#" class="w-12" />
                        <Column field="description" header="Description" />
                        <Column header="Net" class="text-right w-32">
                            <template #body="{ data }"><MoneyText :centavos="data.net_centavos" /></template>
                        </Column>
                        <Column header="Input VAT" class="text-right w-32">
                            <template #body="{ data }"><MoneyText :centavos="data.input_vat_centavos" /></template>
                        </Column>
                    </DataTable>
                </div>

                <div v-if="journalEntry" class="card">
                    <div class="mb-4 flex items-center gap-3">
                        <h2 class="m-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                            Journal entry {{ journalEntry.entry_number }}
                        </h2>
                        <StatusTag :status="journalEntry.status" />
                    </div>
                    <DataTable :value="lines" class="p-datatable-sm">
                        <Column header="Account">
                            <template #body="{ data }">
                                <span class="font-mono text-sm">{{ data.code }}</span>
                                <span class="ml-2 text-muted-color">{{ data.name }}</span>
                            </template>
                        </Column>
                        <Column header="Debit" class="text-right w-32">
                            <template #body="{ data }">
                                <MoneyText v-if="data.debit_centavos > 0" :centavos="data.debit_centavos" />
                                <span v-else class="text-muted-color">—</span>
                            </template>
                        </Column>
                        <Column header="Credit" class="text-right w-32">
                            <template #body="{ data }">
                                <MoneyText v-if="data.credit_centavos > 0" :centavos="data.credit_centavos" />
                                <span v-else class="text-muted-color">—</span>
                            </template>
                        </Column>
                    </DataTable>
                </div>

                <Message v-else severity="info" :closable="false">
                    This bill has no journal entry — on cash basis nothing is recognised until it is paid.
                </Message>
            </div>

            <div class="col-span-12 lg:col-span-4">
                <div class="card">
                    <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Summary</h2>
                    <dl class="m-0 grid grid-cols-2 gap-y-3 text-sm">
                        <dt class="text-muted-color">Status</dt>
                        <dd class="m-0 text-right"><StatusTag :status="bill.status" /></dd>

                        <dt class="text-muted-color">Vendor's no.</dt>
                        <dd class="m-0 text-right font-mono">{{ bill.bill_number }}</dd>

                        <dt class="text-muted-color">Bill date</dt>
                        <dd class="m-0 text-right">{{ formatDate(bill.bill_date) }}</dd>

                        <dt class="text-muted-color">Due date</dt>
                        <dd class="m-0 text-right">{{ formatDate(bill.due_date) }}</dd>

                        <dt class="text-muted-color">Net</dt>
                        <dd class="m-0 text-right"><MoneyText :centavos="bill.net_centavos" /></dd>

                        <dt class="text-muted-color">Input VAT</dt>
                        <dd class="m-0 text-right"><MoneyText :centavos="bill.input_vat_centavos" /></dd>

                        <dt class="text-muted-color">Withheld ({{ bill.atc_code ?? '—' }})</dt>
                        <dd class="m-0 text-right">
                            <MoneyText :centavos="-bill.ewt_centavos" :colorNegative="false" />
                        </dd>

                        <dt class="font-semibold">Payable</dt>
                        <dd class="m-0 text-right font-semibold"><MoneyText :centavos="bill.total_centavos" /></dd>

                        <dt class="text-muted-color">Paid</dt>
                        <dd class="m-0 text-right"><MoneyText :centavos="bill.amount_paid_centavos" /></dd>

                        <dt class="font-semibold">Balance</dt>
                        <dd class="m-0 text-right font-semibold"><MoneyText :centavos="outstanding" /></dd>
                    </dl>
                </div>

                <div class="card">
                    <h2 class="mb-2 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Receipts</h2>
                    <p class="mt-0 text-sm text-muted-color">
                        The supplier's own invoice, scanned. BIR treats it as part of the books — same retention rules
                        as the entry it evidences.
                    </p>

                    <ul v-if="receipts.length" class="mb-4 list-none p-0">
                        <li v-for="receipt in receipts" :key="receipt.id" class="flex items-center gap-2 py-1">
                            <i class="pi pi-paperclip text-muted-color" />
                            <a :href="receipt.url" target="_blank" rel="noopener" class="text-primary hover:underline">
                                {{ receipt.name }}
                            </a>
                            <span class="text-xs text-muted-color">{{ formatSize(receipt.size) }}</span>
                        </li>
                    </ul>
                    <EmptyState v-else icon="pi pi-paperclip" title="No receipt attached" />

                    <FileUpload
                        mode="basic"
                        name="receipt"
                        accept="application/pdf,image/*"
                        :maxFileSize="10485760"
                        chooseLabel="Attach receipt"
                        customUpload
                        auto
                        @uploader="uploadReceipt"
                    />
                </div>

                <div class="card">
                    <Link :href="route('bills.index')" class="text-sm text-primary hover:underline">← All bills</Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
