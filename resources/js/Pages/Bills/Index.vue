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
    bills: { type: Object, required: true },
    status: { type: String, default: '' },
});

const status = ref(props.status || null);

const STATUSES = [
    { label: 'All', value: null },
    { label: 'Open', value: 'open' },
    { label: 'Paid', value: 'paid' },
    { label: 'Cancelled', value: 'cancelled' },
];

watch(status, (value) => {
    router.get(route('bills.index'), value ? { status: value } : {}, { preserveState: true, replace: true });
});

const formatDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <AuthenticatedLayout title="Bills">
        <template #header>
            <AppPageHeader
                title="Vendor bills"
                subtitle="Withholding accrues at the booking date, not at payment (RR 4-2024)"
            >
                <template #actions>
                    <Link :href="route('bills.create')">
                        <Button label="New bill" icon="pi pi-plus" severity="success" />
                    </Link>
                </template>
            </AppPageHeader>
        </template>

        <div class="card">
            <div class="mb-4 flex items-center justify-between gap-3">
                <Select
                    v-model="status"
                    :options="STATUSES"
                    optionLabel="label"
                    optionValue="value"
                    placeholder="All statuses"
                    class="w-56"
                />
                <Link :href="route('reports.aging', { kind: 'payables' })" class="text-sm text-primary hover:underline">
                    A/P aging →
                </Link>
            </div>

            <DataTable :value="bills.data" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-receipt" title="No bills yet" hint="Supplier bills appear here once recorded." />
                </template>

                <Column header="Reference" class="min-w-36">
                    <template #body="{ data }">
                        <Link :href="route('bills.show', data.id)" class="font-semibold text-primary hover:underline">
                            {{ data.reference ?? 'Unnumbered' }}
                        </Link>
                    </template>
                </Column>

                <Column field="bill_number" header="Vendor's no." class="min-w-32">
                    <template #body="{ data }"><span class="font-mono text-sm">{{ data.bill_number }}</span></template>
                </Column>

                <Column header="Date" class="min-w-32">
                    <template #body="{ data }">{{ formatDate(data.bill_date) }}</template>
                </Column>

                <Column header="Vendor" class="min-w-64">
                    <template #body="{ data }">{{ data.partner?.registered_name }}</template>
                </Column>

                <Column header="Net" class="min-w-32 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.net_centavos" /></template>
                </Column>

                <Column header="Input VAT" class="min-w-32 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.input_vat_centavos" /></template>
                </Column>

                <Column header="EWT" class="min-w-28 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.ewt_centavos" :centavos="data.ewt_centavos" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>

                <Column header="Payable" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <span class="font-semibold"><MoneyText :centavos="data.total_centavos" /></span>
                    </template>
                </Column>

                <Column header="Status" class="min-w-28">
                    <template #body="{ data }"><StatusTag :status="data.status" /></template>
                </Column>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
