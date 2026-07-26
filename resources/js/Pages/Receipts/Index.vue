<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';

defineProps({
    receipts: { type: Object, required: true },
});

const formatDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <AuthenticatedLayout title="Goods receipts">
        <template #header>
            <AppPageHeader
                title="Goods receipts"
                subtitle="Received goods credit GRNI until the supplier's bill arrives"
            />
        </template>

        <div class="card">
            <div class="mb-4 flex justify-end">
                <Link :href="route('receipts.create')">
                    <Button label="Receive stock" icon="pi pi-plus" severity="success" />
                </Link>
            </div>

            <DataTable :value="receipts.data" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-box" title="Nothing received yet" hint="Deliveries appear here once posted." />
                </template>

                <Column header="Reference" class="min-w-36">
                    <template #body="{ data }">
                        <Link :href="route('receipts.show', data.id)" class="font-semibold text-primary hover:underline">
                            {{ data.reference ?? 'Unnumbered' }}
                        </Link>
                    </template>
                </Column>
                <Column header="Date" class="min-w-32">
                    <template #body="{ data }">{{ formatDate(data.received_date) }}</template>
                </Column>
                <Column header="Supplier" class="min-w-64">
                    <template #body="{ data }">
                        {{ data.partner?.registered_name ?? 'Found / opening stock' }}
                    </template>
                </Column>
                <Column field="vendor_reference" header="Their DR no." class="min-w-32">
                    <template #body="{ data }">
                        <span class="font-mono text-sm">{{ data.vendor_reference ?? '—' }}</span>
                    </template>
                </Column>
                <Column header="Goods value" class="min-w-36 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.total_cost_centavos" /></template>
                </Column>
                <Column header="Status" class="min-w-28">
                    <template #body="{ data }"><StatusTag :status="data.status" /></template>
                </Column>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
