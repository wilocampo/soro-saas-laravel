<script setup>
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import ReportShell from '@/components/ReportShell.vue';
import { router } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    statement: { type: Object, required: true },
    customers: { type: Array, required: true },
    partnerId: { type: Number, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    header: { type: Object, required: true },
});

const partnerId = ref(props.partnerId);
const from = ref(new Date(props.from));
const to = ref(new Date(props.to));

const asDate = (value) =>
    `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;

const params = computed(() => ({
    partner_id: partnerId.value,
    from: asDate(from.value),
    to: asDate(to.value),
}));

watch([partnerId, from, to], () => {
    router.get(route('reports.soa'), params.value, { preserveState: true, replace: true });
});

const BUCKETS = [
    { key: 'current', label: 'Current' },
    { key: '1_30', label: '1–30' },
    { key: '31_60', label: '31–60' },
    { key: '61_90', label: '61–90' },
    { key: 'over_90', label: 'Over 90' },
];
</script>

<template>
    <ReportShell
        title="Statement of Account"
        :subtitle="statement.partner.registered_name"
        :header="header"
        :params="params"
        export-route="reports.soa"
    >
        <template #filters>
            <div>
                <label for="customer" class="mb-2 block text-sm font-medium">Customer</label>
                <Select
                    id="customer"
                    v-model="partnerId"
                    :options="customers"
                    optionValue="id"
                    optionLabel="registered_name"
                    filter
                    class="w-96"
                />
            </div>
            <div>
                <label for="from" class="mb-2 block text-sm font-medium">From</label>
                <DatePicker id="from" v-model="from" dateFormat="dd M yy" showIcon />
            </div>
            <div>
                <label for="to" class="mb-2 block text-sm font-medium">To</label>
                <DatePicker id="to" v-model="to" dateFormat="dd M yy" showIcon />
            </div>
        </template>

        <!--
            RR 18-2012 / RMO 12-2013: a statement of account is a
            SUPPLEMENTARY document. A customer who files it instead of the
            invoice loses the input-VAT claim, so the page says so plainly.
        -->
        <Message severity="warn" class="mb-4" :closable="false">
            This is a <strong>supplementary</strong> document — proof of a running balance, not of a sale.
            It is <strong>not valid to support a claim for input tax</strong>; the invoice is.
        </Message>

        <div class="card">
            <div class="mb-4 flex flex-wrap justify-end gap-10 text-sm">
                <div class="text-right">
                    <div class="text-muted-color">Balance brought forward</div>
                    <MoneyText :centavos="statement.opening_centavos" />
                </div>
                <div class="text-right">
                    <div class="text-muted-color">Balance due</div>
                    <span class="text-lg font-bold"><MoneyText :centavos="statement.closing_centavos" /></span>
                </div>
            </div>

            <DataTable :value="statement.rows" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-file" title="No activity" hint="Nothing was billed or collected in this range." />
                </template>

                <Column field="date" header="Date" class="min-w-28" />
                <Column field="reference" header="Reference" class="min-w-36">
                    <template #body="{ data }"><span class="font-mono text-sm">{{ data.reference }}</span></template>
                </Column>
                <Column field="particulars" header="Particulars" class="min-w-64" />
                <Column header="Charges" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.charge" :centavos="data.charge" :symbol="false" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
                <Column header="Payments" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.payment" :centavos="data.payment" :symbol="false" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
                <Column header="Balance" class="min-w-32 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.balance" :symbol="false" /></template>
                </Column>
            </DataTable>
        </div>

        <div class="card">
            <h2 class="mb-4 mt-0 text-lg font-semibold text-surface-900 dark:text-surface-0">Aging of the balance</h2>
            <div class="flex flex-wrap gap-8">
                <div v-for="bucket in BUCKETS" :key="bucket.key" class="text-right">
                    <div class="text-sm text-muted-color">{{ bucket.label }}</div>
                    <MoneyText :centavos="statement.aging[bucket.key]" />
                </div>
            </div>
        </div>
    </ReportShell>
</template>
