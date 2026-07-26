<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Select from 'primevue/select';
import { ref, watch } from 'vue';

const props = defineProps({
    invoices: { type: Object, required: true },
    status: { type: String, default: '' },
});

const status = ref(props.status || null);

const STATUSES = [
    { label: 'All', value: null },
    { label: 'Issued', value: 'issued' },
    { label: 'Paid', value: 'paid' },
    { label: 'Cancelled', value: 'cancelled' },
];

watch(status, (value) => {
    router.get(route('invoices.index'), value ? { status: value } : {}, {
        preserveState: true,
        replace: true,
    });
});

const formatDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <AuthenticatedLayout title="Invoices">
        <template #header>
            <AppPageHeader title="Invoices" subtitle="The principal VAT document for goods and services (RR 7-2024)" />
        </template>

        <div class="card">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <Select
                    v-model="status"
                    :options="STATUSES"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="All statuses"
                    class="w-56"
                />
                <div class="flex items-center gap-3">
                    <Link :href="route('reports.aging')" class="text-sm text-primary hover:underline">
                        A/R aging →
                    </Link>
                    <Link :href="route('invoices.create')">
                        <Button label="New invoice" icon="pi pi-plus" severity="success" />
                    </Link>
                </div>
            </div>

            <DataTable :value="invoices.data" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState
                        icon="pi pi-file"
                        title="No invoices yet"
                        hint="Issued invoices appear here with their serial numbers."
                    />
                </template>

                <Column field="invoice_number" header="Number" class="min-w-40">
                    <template #body="{ data }">
                        <Link
                            :href="route('invoices.show', data.id)"
                            class="font-semibold text-primary hover:underline"
                        >
                            {{ data.invoice_number ?? 'Unnumbered' }}
                        </Link>
                    </template>
                </Column>

                <Column field="invoice_date" header="Date" class="min-w-32">
                    <template #body="{ data }">
                        <span class="text-surface-900 dark:text-surface-0">{{ formatDate(data.invoice_date) }}</span>
                    </template>
                </Column>

                <Column header="Customer" class="min-w-64">
                    <template #body="{ data }">
                        <span class="text-surface-900 dark:text-surface-0">{{ data.partner?.registered_name }}</span>
                    </template>
                </Column>

                <Column field="due_date" header="Due" class="min-w-32">
                    <template #body="{ data }">
                        <span class="text-muted-color text-sm">{{ formatDate(data.due_date) }}</span>
                    </template>
                </Column>

                <Column header="Net" class="min-w-32 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.net_centavos" /></template>
                </Column>

                <Column header="VAT" class="min-w-28 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.vat_centavos" /></template>
                </Column>

                <Column header="Total" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <span class="font-semibold"><MoneyText :centavos="data.total_centavos" /></span>
                    </template>
                </Column>

                <Column header="Balance" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText :centavos="data.total_centavos - data.amount_paid_centavos" />
                    </template>
                </Column>

                <Column field="status" header="Status" class="min-w-28">
                    <template #body="{ data }"><StatusTag :status="data.status" /></template>
                </Column>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
