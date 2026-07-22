<script setup>
import MoneyText from '@/components/MoneyText.vue';
import ReportShell from '@/components/ReportShell.vue';
import { router } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
    periods: { type: Array, required: true },
    periodId: { type: Number, required: true },
    header: { type: Object, required: true },
});

const periodId = ref(props.periodId);

const periodOptions = computed(() =>
    props.periods.map((p) => ({
        value: p.id,
        label: `FY ${p.year_label} · period ${p.period_no} · to ${p.end_date}${p.status === 'open' ? '' : ' (closed)'}`,
    }))
);

watch(periodId, (value) => {
    router.get(route('reports.trial-balance'), { period_id: value }, { preserveState: true, replace: true });
});
</script>

<template>
    <ReportShell
        title="Trial Balance"
        subtitle="Every account with a balance, as of the period end"
        :header="header"
        :params="{ period_id: periodId }"
        export-route="reports.trial-balance"
    >
        <template #filters>
            <div>
                <label for="period" class="mb-2 block text-sm font-medium">Period</label>
                <Select
                    id="period"
                    v-model="periodId"
                    :options="periodOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-96"
                />
            </div>
        </template>

        <Message v-if="!report.balanced" severity="error" class="mb-4" :closable="false">
            The trial balance does not net to zero. That is a defect in the ledger, not a rounding
            difference — run <code>ledger:verify</code> before relying on any report.
        </Message>

        <div class="card">
            <DataTable :value="report.rows" dataKey="account_id" class="p-datatable-sm" responsiveLayout="scroll">
                <Column field="code" header="Code" class="min-w-28">
                    <template #body="{ data }"><span class="font-mono text-sm">{{ data.code }}</span></template>
                </Column>
                <Column field="name" header="Account" class="min-w-64" />
                <Column header="Debit" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.debit" :centavos="data.debit" :symbol="false" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
                <Column header="Credit" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.credit" :centavos="data.credit" :symbol="false" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>

                <template #footer>
                    <div class="flex justify-end gap-10 pt-2 text-sm">
                        <div class="text-right">
                            <div class="text-muted-color">Total debits</div>
                            <span class="font-semibold"><MoneyText :centavos="report.total_debit" /></span>
                        </div>
                        <div class="text-right">
                            <div class="text-muted-color">Total credits</div>
                            <span class="font-semibold"><MoneyText :centavos="report.total_credit" /></span>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </ReportShell>
</template>
