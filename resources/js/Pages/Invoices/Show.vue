<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import { ref } from 'vue';

const props = defineProps({
    invoice: { type: Object, required: true },
    outstanding: { type: Number, required: true },
    compliance: { type: Object, required: true },
    journalEntry: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
});

const toast = useAppToast();
const cancelDialog = ref(false);
const cancelReason = ref('');
const emailDialog = ref(false);
const emailTo = ref('');

const formatDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';

const submitCancel = () => {
    router.post(
        route('invoices.cancel', props.invoice.id),
        { reason: cancelReason.value },
        {
            onSuccess: () => {
                cancelDialog.value = false;
                cancelReason.value = '';
            },
            onError: (errors) => toast.error('Not cancelled', Object.values(errors)[0]),
        }
    );
};

const submitEmail = () => {
    router.post(
        route('invoices.email', props.invoice.id),
        { to: emailTo.value || null },
        {
            onSuccess: () => {
                emailDialog.value = false;
                emailTo.value = '';
            },
            onError: (errors) => toast.error('Not sent', Object.values(errors)[0]),
        }
    );
};
</script>

<template>
    <AuthenticatedLayout :title="`Invoice ${invoice.invoice_number}`">
        <template #header>
            <AppPageHeader
                :title="`Invoice ${invoice.invoice_number}`"
                :subtitle="invoice.partner?.registered_name"
            >
                <template #actions>
                    <a :href="route('invoices.pdf', invoice.id)" target="_blank" rel="noopener">
                        <Button label="PDF" icon="pi pi-file-pdf" outlined />
                    </a>
                    <Button
                        label="Email"
                        icon="pi pi-send"
                        outlined
                        :disabled="invoice.status === 'cancelled'"
                        @click="emailDialog = true"
                    />
                    <Button
                        label="Cancel invoice"
                        icon="pi pi-ban"
                        severity="danger"
                        outlined
                        :disabled="invoice.status === 'cancelled'"
                        @click="cancelDialog = true"
                    />
                </template>
            </AppPageHeader>
        </template>

        <Message v-if="compliance.fatal.length" severity="error" class="mb-4" :closable="false">
            <p class="m-0 font-semibold">This invoice would cost the buyer its input-VAT claim:</p>
            <ul class="mt-2 mb-0 list-disc pl-5">
                <li v-for="(issue, i) in compliance.fatal" :key="i">{{ issue }}</li>
            </ul>
        </Message>

        <Message v-if="compliance.warnings.length" severity="warn" class="mb-4" :closable="false">
            <ul class="m-0 list-disc pl-5">
                <li v-for="(issue, i) in compliance.warnings" :key="i">{{ issue }}</li>
            </ul>
        </Message>

        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12 lg:col-span-8">
                <div class="card">
                    <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Lines</h2>
                    <DataTable :value="invoice.lines" dataKey="id" class="p-datatable-sm">
                        <Column field="line_no" header="#" class="w-12" />
                        <Column field="description" header="Description / nature of service" />
                        <Column header="Net" class="text-right w-32">
                            <template #body="{ data }"><MoneyText :centavos="data.net_centavos" /></template>
                        </Column>
                        <Column header="VAT" class="text-right w-32">
                            <template #body="{ data }"><MoneyText :centavos="data.vat_centavos" /></template>
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
                    This invoice has no journal entry. On cash basis nothing is recognised until collection, and a
                    document carried in at cutover is already inside the opening A/R balance.
                </Message>
            </div>

            <div class="col-span-12 lg:col-span-4">
                <div class="card">
                    <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Summary</h2>
                    <dl class="m-0 grid grid-cols-2 gap-y-3 text-sm">
                        <dt class="text-muted-color">Status</dt>
                        <dd class="m-0 text-right"><StatusTag :status="invoice.status" /></dd>

                        <dt class="text-muted-color">Invoice date</dt>
                        <dd class="m-0 text-right">{{ formatDate(invoice.invoice_date) }}</dd>

                        <dt class="text-muted-color">Due date</dt>
                        <dd class="m-0 text-right">{{ formatDate(invoice.due_date) }}</dd>

                        <dt class="text-muted-color">Net</dt>
                        <dd class="m-0 text-right"><MoneyText :centavos="invoice.net_centavos" /></dd>

                        <dt class="text-muted-color">VAT</dt>
                        <dd class="m-0 text-right"><MoneyText :centavos="invoice.vat_centavos" /></dd>

                        <dt class="font-semibold">Total</dt>
                        <dd class="m-0 text-right font-semibold"><MoneyText :centavos="invoice.total_centavos" /></dd>

                        <dt class="text-muted-color">Paid</dt>
                        <dd class="m-0 text-right"><MoneyText :centavos="invoice.amount_paid_centavos" /></dd>

                        <dt class="font-semibold">Balance</dt>
                        <dd class="m-0 text-right font-semibold"><MoneyText :centavos="outstanding" /></dd>
                    </dl>

                    <p v-if="invoice.status === 'cancelled'" class="mt-4 mb-0 text-sm text-muted-color">
                        Cancelled: {{ invoice.cancellation_reason }}. The serial number is retained and never reused.
                    </p>
                </div>

                <div class="card">
                    <Link :href="route('invoices.index')" class="text-sm text-primary hover:underline">
                        ← All invoices
                    </Link>
                </div>
            </div>
        </div>

        <Dialog v-model:visible="cancelDialog" modal header="Cancel this invoice" :style="{ width: '32rem' }">
            <p class="mt-0 text-sm text-muted-color">
                Cancellation voids the journal entry in place and keeps the serial number. It is only available while
                the period is open — once the figures are in a filed return the adjustment must be a credit note.
            </p>
            <label for="reason" class="mb-2 block font-medium">Reason</label>
            <InputText id="reason" v-model="cancelReason" fluid autofocus />
            <template #footer>
                <Button label="Keep it" severity="secondary" text @click="cancelDialog = false" />
                <Button label="Cancel invoice" severity="danger" :disabled="!cancelReason.trim()" @click="submitCancel" />
            </template>
        </Dialog>

        <Dialog v-model:visible="emailDialog" modal header="Email this invoice" :style="{ width: '32rem' }">
            <p class="mt-0 text-sm text-muted-color">
                Sends the BIR-faithful PDF. Leave blank to use
                {{ invoice.partner?.email || "the customer's recorded address" }}.
            </p>
            <label for="to" class="mb-2 block font-medium">Recipient</label>
            <InputText id="to" v-model="emailTo" type="email" :placeholder="invoice.partner?.email" fluid />
            <template #footer>
                <Button label="Close" severity="secondary" text @click="emailDialog = false" />
                <Button label="Send" icon="pi pi-send" @click="submitEmail" />
            </template>
        </Dialog>
    </AuthenticatedLayout>
</template>
