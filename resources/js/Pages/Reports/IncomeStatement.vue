<script setup>
import MoneyText from '@/components/MoneyText.vue';
import ReportShell from '@/components/ReportShell.vue';
import { router } from '@inertiajs/vue3';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
    periods: { type: Array, required: true },
    periodId: { type: Number, required: true },
    yearToDate: { type: Boolean, default: true },
    header: { type: Object, required: true },
});

const periodId = ref(props.periodId);
const ytd = ref(props.yearToDate);

const SCOPES = [
    { label: 'Year to date', value: true },
    { label: 'This period only', value: false },
];

const periodOptions = computed(() =>
    props.periods.map((p) => ({
        value: p.id,
        label: `FY ${p.year_label} · period ${p.period_no} · to ${p.end_date}`,
    }))
);

watch([periodId, ytd], ([period, scope]) => {
    router.get(
        route('reports.income-statement'),
        { period_id: period, ytd: scope ? 1 : 0 },
        { preserveState: true, replace: true }
    );
});

const SECTIONS = [
    { key: 'income', label: 'Income' },
    { key: 'expenses', label: 'Expenses' },
];
</script>

<template>
    <ReportShell
        title="Income Statement"
        :subtitle="`${report.from} to ${report.to}`"
        :header="header"
        :params="{ period_id: periodId, ytd: ytd ? 1 : 0 }"
        export-route="reports.income-statement"
    >
        <template #filters>
            <div>
                <label for="period" class="mb-2 block text-sm font-medium">Up to</label>
                <Select
                    id="period"
                    v-model="periodId"
                    :options="periodOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-96"
                />
            </div>
            <SelectButton v-model="ytd" :options="SCOPES" optionLabel="label" optionValue="value" :allowEmpty="false" />
        </template>

        <div class="card">
            <table class="w-full text-sm">
                <tbody>
                    <template v-for="section in SECTIONS" :key="section.key">
                        <tr>
                            <td colspan="2" class="pt-4 pb-1 text-base font-semibold text-surface-900 dark:text-surface-0">
                                {{ section.label }}
                            </td>
                        </tr>
                        <tr v-if="!report.sections[section.key].rows.length">
                            <td colspan="2" class="py-1 text-muted-color">Nothing recorded in this range.</td>
                        </tr>
                        <tr v-for="row in report.sections[section.key].rows" :key="row.account_id">
                            <td class="py-1 pl-4">
                                <span class="font-mono text-xs text-muted-color">{{ row.code }}</span>
                                <span class="ml-2">{{ row.name }}</span>
                                <span v-if="row.is_contra" class="ml-1 text-xs text-muted-color">(contra)</span>
                            </td>
                            <td class="py-1 text-right"><MoneyText :centavos="row.amount" :symbol="false" /></td>
                        </tr>
                        <tr class="border-t border-surface-200 dark:border-surface-700">
                            <td class="py-1 font-medium">Total {{ section.label.toLowerCase() }}</td>
                            <td class="py-1 text-right font-medium">
                                <MoneyText :centavos="report.sections[section.key].total" :symbol="false" />
                            </td>
                        </tr>
                    </template>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-surface-900 dark:border-surface-0">
                        <td class="py-3 text-base font-bold">Net income</td>
                        <td class="py-3 text-right text-base font-bold">
                            <MoneyText :centavos="report.net_income" />
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </ReportShell>
</template>
