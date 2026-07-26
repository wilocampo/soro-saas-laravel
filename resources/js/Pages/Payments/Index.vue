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
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import { ref, watch } from 'vue';

const props = defineProps({
    payments: { type: Object, required: true },
    direction: { type: String, default: '' },
});

const direction = ref(props.direction || null);

const DIRECTIONS = [
    { label: 'All', value: null },
    { label: 'Collections', value: 'received' },
    { label: 'Disbursements', value: 'paid' },
];

watch(direction, (value) => {
    router.get(route('payments.index'), value ? { direction: value } : {}, { preserveState: true, replace: true });
});

const formatDate = (value) =>
    value ? new Date(value).toLocaleDateString('en-PH', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <AuthenticatedLayout title="Payments">
        <template #header>
            <AppPageHeader title="Payments" subtitle="Collections and disbursements, with the tax withheld on either side" />
        </template>

        <div class="card">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <SelectButton v-model="direction" :options="DIRECTIONS" optionLabel="label" optionValue="value" />
                <div class="flex items-center gap-2">
                    <Link :href="route('payments.create', { direction: 'received' })">
                        <Button label="Collection" icon="pi pi-arrow-down" severity="success" />
                    </Link>
                    <Link :href="route('payments.create', { direction: 'paid' })">
                        <Button label="Disbursement" icon="pi pi-arrow-up" severity="warn" outlined />
                    </Link>
                </div>
            </div>

            <DataTable :value="payments.data" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-wallet" title="No payments yet" hint="Collections and disbursements appear here." />
                </template>

                <Column field="payment_number" header="Number" class="min-w-36">
                    <template #body="{ data }">
                        <span class="font-mono text-sm font-semibold">{{ data.payment_number ?? '—' }}</span>
                    </template>
                </Column>

                <Column header="Direction" class="min-w-36">
                    <template #body="{ data }">
                        <Tag
                            :value="data.direction === 'received' ? 'Collection' : 'Disbursement'"
                            :severity="data.direction === 'received' ? 'success' : 'warn'"
                            class="text-xs"
                        />
                    </template>
                </Column>

                <Column header="Date" class="min-w-32">
                    <template #body="{ data }">{{ formatDate(data.payment_date) }}</template>
                </Column>

                <Column header="Partner" class="min-w-64">
                    <template #body="{ data }">{{ data.partner?.registered_name }}</template>
                </Column>

                <Column header="Cash" class="min-w-32 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.amount_centavos" /></template>
                </Column>

                <Column header="Withheld" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.ewt_centavos" :centavos="data.ewt_centavos" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>

                <Column field="atc_code" header="ATC" class="min-w-24">
                    <template #body="{ data }">
                        <span class="font-mono text-sm">{{ data.atc_code ?? '—' }}</span>
                    </template>
                </Column>

                <Column header="Status" class="min-w-28">
                    <template #body="{ data }"><StatusTag :status="data.status" /></template>
                </Column>
            </DataTable>
        </div>
    </AuthenticatedLayout>
</template>
