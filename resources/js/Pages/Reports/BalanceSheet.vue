<script setup>
import MoneyText from '@/components/MoneyText.vue';
import ReportShell from '@/components/ReportShell.vue';
import { router } from '@inertiajs/vue3';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
    tie: { type: Object, required: true },
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
    router.get(route('reports.balance-sheet'), { period_id: value }, { preserveState: true, replace: true });
});

const SECTIONS = [
    { key: 'assets', label: 'Assets' },
    { key: 'liabilities', label: 'Liabilities' },
    { key: 'equity', label: 'Equity' },
];
</script>

<template>
    <ReportShell
        title="Balance Sheet"
        subtitle="Assets against the claims on them, as of the period end"
        :header="header"
        :params="{ period_id: periodId }"
        export-route="reports.balance-sheet"
    >
        <template #filters>
            <div>
                <label for="period" class="mb-2 block text-sm font-medium">As of</label>
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

        <Message v-if="!tie.ties" severity="error" class="mb-4" :closable="false">
            This balance sheet does not tie to the trial balance. Do not file it — run
            <code>ledger:verify</code> and investigate before doing anything else.
        </Message>

        <div class="grid grid-cols-12 gap-4">
            <div v-for="section in SECTIONS" :key="section.key" class="col-span-12 lg:col-span-4">
                <div class="card h-full">
                    <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                        {{ section.label }}
                    </h2>

                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="row in report.sections[section.key].rows" :key="row.account_id">
                                <td class="py-1">
                                    <span class="font-mono text-xs text-muted-color">{{ row.code }}</span>
                                    <span class="ml-2">{{ row.name }}</span>
                                    <!-- A contra account subtracts from its own section. -->
                                    <span v-if="row.is_contra" class="ml-1 text-xs text-muted-color">(contra)</span>
                                </td>
                                <td class="py-1 text-right"><MoneyText :centavos="row.amount" :symbol="false" /></td>
                            </tr>

                            <tr v-if="section.key === 'equity'">
                                <td class="py-1 italic">Net income for the period</td>
                                <td class="py-1 text-right">
                                    <MoneyText :centavos="report.net_income" :symbol="false" />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-surface-300 dark:border-surface-600">
                                <td class="py-2 font-semibold">Total {{ section.label.toLowerCase() }}</td>
                                <td class="py-2 text-right font-semibold">
                                    <MoneyText
                                        :centavos="
                                            section.key === 'equity'
                                                ? report.sections.equity.total + report.net_income
                                                : report.sections[section.key].total
                                        "
                                        :symbol="false"
                                    />
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="flex flex-wrap items-center justify-end gap-10">
                <div class="text-right">
                    <div class="text-sm text-muted-color">Total assets</div>
                    <span class="text-lg font-bold"><MoneyText :centavos="report.total_assets" /></span>
                </div>
                <div class="text-right">
                    <div class="text-sm text-muted-color">Total liabilities and equity</div>
                    <span class="text-lg font-bold">
                        <MoneyText :centavos="report.total_liabilities_and_equity" />
                    </span>
                </div>
                <i
                    :class="report.balanced ? 'pi pi-check-circle text-green-500' : 'pi pi-times-circle text-red-500'"
                    class="text-2xl"
                    v-tooltip.left="report.balanced ? 'Balanced' : 'Out of balance'"
                />
            </div>
        </div>
    </ReportShell>
</template>
