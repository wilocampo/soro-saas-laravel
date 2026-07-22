<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { ref } from 'vue';

defineProps({
    reconciliations: { type: Array, required: true },
    cashAccounts: { type: Array, required: true },
});

const toast = useAppToast();
const dialog = ref(false);

const form = useForm({
    cash_account_id: null,
    statement_date: new Date(),
    statement_closing_centavos: 0,
});

const asDate = (value) =>
    value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : value;

const submit = () => {
    form
        .transform((data) => ({ ...data, statement_date: asDate(data.statement_date) }))
        .post(route('reconciliations.store'), {
            onError: (errors) => toast.error('Not started', Object.values(errors)[0]),
        });
};
</script>

<template>
    <AuthenticatedLayout title="Bank reconciliation">
        <template #header>
            <AppPageHeader
                title="Bank reconciliation"
                subtitle="Mark what the bank has seen; what is left is in transit or outstanding"
            >
                <template #actions>
                    <Button label="New reconciliation" icon="pi pi-plus" severity="success" @click="dialog = true" />
                </template>
            </AppPageHeader>
        </template>

        <div class="card">
            <DataTable :value="reconciliations" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState
                        icon="pi pi-building-columns"
                        title="Nothing reconciled yet"
                        hint="Start one when the bank statement arrives."
                    />
                </template>

                <Column header="Account" class="min-w-64">
                    <template #body="{ data }">
                        <Link :href="route('reconciliations.show', data.id)" class="text-primary hover:underline">
                            <span class="font-mono text-sm">{{ data.code }}</span>
                            <span class="ml-2">{{ data.name }}</span>
                        </Link>
                    </template>
                </Column>
                <Column field="statement_date" header="Statement date" class="min-w-36" />
                <Column header="Statement closing" class="min-w-40 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.statement_closing_centavos" /></template>
                </Column>
                <Column header="Status" class="min-w-28">
                    <template #body="{ data }"><StatusTag :status="data.status" :map="{ completed: 'success' }" /></template>
                </Column>
                <Column field="notes" header="Notes" class="min-w-48">
                    <template #body="{ data }"><span class="text-sm text-muted-color">{{ data.notes }}</span></template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="dialog" modal header="New reconciliation" :style="{ width: '32rem' }">
            <div class="flex flex-col gap-4">
                <div>
                    <label for="account" class="mb-2 block font-medium">Cash account</label>
                    <Select
                        id="account"
                        v-model="form.cash_account_id"
                        :options="cashAccounts"
                        optionValue="id"
                        :optionLabel="(a) => `${a.code} — ${a.name}`"
                        fluid
                    />
                </div>
                <div>
                    <label for="date" class="mb-2 block font-medium">Statement date</label>
                    <DatePicker id="date" v-model="form.statement_date" dateFormat="dd M yy" showIcon fluid />
                </div>
                <div>
                    <label for="closing" class="mb-2 block font-medium">Closing balance per the statement</label>
                    <MoneyInput inputId="closing" v-model="form.statement_closing_centavos" :min="-100000000000" />
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" text @click="dialog = false" />
                <Button label="Start" :loading="form.processing" :disabled="!form.cash_account_id" @click="submit" />
            </template>
        </Dialog>
    </AuthenticatedLayout>
</template>
