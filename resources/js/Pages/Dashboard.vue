<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatCard from '@/components/StatCard.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Chart from 'primevue/chart';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { computed, onMounted, ref } from 'vue';

/**
 * Dashboard-lite (Phase 3). Every figure comes from the same services the
 * financial statements use, so this can never tell a different story from
 * the reports. No fabricated data lives here (.impeccable: correctness is
 * the aesthetic).
 */
const props = defineProps({
    summary: { type: Object, default: null },
});

const pl = computed(() => props.summary?.profit_and_loss ?? null);
const setup = computed(() => props.summary?.setup ?? null);

const urgency = (days) => (days < 0 ? 'danger' : days <= 7 ? 'warn' : 'secondary');

/*
 * The trend chart. Colours are read from the live theme tokens so the chart
 * belongs to whatever preset/dark-mode is active, rather than being pinned to
 * a hardcoded palette. Amounts arrive in centavos and become pesos here.
 */
const chartData = ref(null);
const chartOptions = ref(null);

const buildChart = () => {
    const trend = props.summary?.trend ?? [];
    if (trend.length === 0) return;

    const style = getComputedStyle(document.documentElement);
    const primary = style.getPropertyValue('--p-primary-500').trim() || '#10b981';
    const expense = style.getPropertyValue('--p-red-400').trim() || '#f87171';
    const text = style.getPropertyValue('--p-text-muted-color').trim() || '#6b7280';
    const grid = style.getPropertyValue('--p-content-border-color').trim() || '#e5e7eb';

    const pesos = (list) => list.map((v) => v / 100);

    chartData.value = {
        labels: trend.map((p) => p.label),
        datasets: [
            {
                label: 'Income',
                data: pesos(trend.map((p) => p.income)),
                backgroundColor: primary,
                borderRadius: 4,
                maxBarThickness: 28,
            },
            {
                label: 'Expenses',
                data: pesos(trend.map((p) => p.expenses)),
                backgroundColor: expense,
                borderRadius: 4,
                maxBarThickness: 28,
            },
        ],
    };

    chartOptions.value = {
        maintainAspectRatio: false,
        aspectRatio: 0.7,
        plugins: {
            legend: { labels: { color: text, usePointStyle: true, boxWidth: 8 } },
            tooltip: {
                callbacks: {
                    label: (ctx) =>
                        `${ctx.dataset.label}: ₱${ctx.parsed.y.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`,
                },
            },
        },
        scales: {
            x: { ticks: { color: text }, grid: { display: false } },
            y: {
                ticks: {
                    color: text,
                    callback: (v) => `₱${Number(v).toLocaleString('en-PH')}`,
                },
                grid: { color: grid, drawBorder: false },
            },
        },
    };
};

const hasTrend = computed(() => (props.summary?.trend?.length ?? 0) > 0);

onMounted(buildChart);
</script>

<template>
    <AuthenticatedLayout title="Dashboard">
        <template #header>
            <AppPageHeader title="Dashboard" :subtitle="summary ? `As of ${summary.as_of}` : null" />
        </template>

        <Message v-if="!summary" severity="info" :closable="false">
            No fiscal period covers today, so there is nothing to summarise yet.
        </Message>

        <div v-else class="flex flex-col gap-6">
            <!-- Finish-setup prompt: only while the tenant is not yet live. -->
            <Message v-if="setup && !setup.live" severity="warn" :closable="false">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span>
                        This company is not live yet.
                        {{ setup.has_accn ? 'Complete the go-live checklist' : 'Record your BIR Acknowledgement Certificate' }}
                        before issuing documents for real.
                    </span>
                    <Link :href="route('onboarding.show')">
                        <Button label="Finish setup" icon="pi pi-arrow-right" iconPos="right" size="small" outlined />
                    </Link>
                </div>
            </Message>

            <!-- Row 1 — the four numbers an owner checks first. -->
            <div class="grid grid-cols-12 gap-6">
                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <StatCard label="Cash on hand" icon="pi pi-wallet" tone="primary">
                        <MoneyText :centavos="summary.cash.total" />
                        <template #footer>
                            <span class="text-muted-color">{{ summary.cash.accounts.length }} account{{ summary.cash.accounts.length === 1 ? '' : 's' }}</span>
                        </template>
                    </StatCard>
                </div>

                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <StatCard label="Owed to us" icon="pi pi-arrow-down-left" :tone="summary.receivables.overdue > 0 ? 'warn' : 'primary'">
                        <MoneyText :centavos="summary.receivables.total" />
                        <template #footer>
                            <span :class="summary.receivables.overdue > 0 ? 'font-semibold text-red-500' : 'text-muted-color'">
                                <MoneyText :centavos="summary.receivables.overdue" :colorNegative="false" /> overdue
                            </span>
                            <Link :href="route('reports.aging')" class="ml-2 text-primary hover:underline">aging →</Link>
                        </template>
                    </StatCard>
                </div>

                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <StatCard label="We owe" icon="pi pi-arrow-up-right" :tone="summary.payables.overdue > 0 ? 'warn' : 'muted'">
                        <MoneyText :centavos="summary.payables.total" />
                        <template #footer>
                            <span :class="summary.payables.overdue > 0 ? 'font-semibold text-red-500' : 'text-muted-color'">
                                <MoneyText :centavos="summary.payables.overdue" :colorNegative="false" /> overdue
                            </span>
                            <Link :href="route('reports.aging', { kind: 'payables' })" class="ml-2 text-primary hover:underline">aging →</Link>
                        </template>
                    </StatCard>
                </div>

                <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                    <StatCard
                        label="Net income (YTD)"
                        icon="pi pi-chart-line"
                        :tone="pl && pl.year_to_date.net_income < 0 ? 'danger' : 'success'"
                    >
                        <MoneyText :centavos="pl ? pl.year_to_date.net_income : 0" />
                        <template #footer>
                            <span class="text-muted-color">This period </span>
                            <MoneyText :centavos="pl ? pl.this_period.net_income : 0" :symbol="true" />
                        </template>
                    </StatCard>
                </div>
            </div>

            <!-- Row 2 — the trend chart beside quick actions. -->
            <div class="grid grid-cols-12 gap-6">
                <div class="col-span-12 xl:col-span-8">
                    <div class="card !mb-0 h-full">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="m-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                                Income vs expenses
                            </h2>
                            <span class="text-sm text-muted-color">by period, this fiscal year</span>
                        </div>
                        <div v-if="hasTrend" class="h-72">
                            <Chart type="bar" :data="chartData" :options="chartOptions" class="h-full" />
                        </div>
                        <p v-else class="py-10 text-center text-muted-color">
                            Post a few entries and the trend appears here.
                        </p>
                    </div>
                </div>

                <div class="col-span-12 xl:col-span-4">
                    <div class="card !mb-0 flex h-full flex-col">
                        <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                            Quick actions
                        </h2>
                        <div class="flex flex-col gap-2">
                            <Link :href="route('invoices.create')">
                                <Button label="New invoice" icon="pi pi-file-edit" text class="w-full !justify-start" />
                            </Link>
                            <Link :href="route('bills.create')">
                                <Button label="New vendor bill" icon="pi pi-receipt" text class="w-full !justify-start" />
                            </Link>
                            <Link :href="route('payments.create')">
                                <Button label="Record a payment" icon="pi pi-wallet" text class="w-full !justify-start" />
                            </Link>
                            <Link :href="route('receipts.create')">
                                <Button label="Receive stock" icon="pi pi-inbox" text class="w-full !justify-start" />
                            </Link>
                        </div>

                        <div class="mt-6 border-t border-surface pt-4">
                            <div class="mb-2 text-sm font-medium text-muted-color">This period</div>
                            <div class="flex items-center justify-between py-1 text-sm">
                                <span class="text-muted-color">{{ summary.period?.year_label }} · period {{ summary.period?.period_no }}</span>
                                <Tag
                                    :value="summary.period?.status"
                                    :severity="summary.period?.status === 'open' ? 'success' : 'secondary'"
                                    class="text-xs"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 3 — the P&L snapshot beside the filing calendar. -->
            <div class="grid grid-cols-12 gap-6">
                <div class="col-span-12 xl:col-span-7">
                    <div class="card !mb-0 h-full">
                        <div class="mb-4 flex items-center justify-between">
                            <h2 class="m-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                                Profit and loss
                            </h2>
                            <Link :href="route('reports.income-statement')" class="text-sm text-primary hover:underline">
                                full statement →
                            </Link>
                        </div>

                        <table v-if="pl" class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-surface text-left">
                                    <th class="py-2"></th>
                                    <th class="py-2 text-right font-medium">This period</th>
                                    <th class="py-2 text-right font-medium">Year to date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="py-1 text-muted-color">Income</td>
                                    <td class="py-1 text-right"><MoneyText :centavos="pl.this_period.income" /></td>
                                    <td class="py-1 text-right"><MoneyText :centavos="pl.year_to_date.income" /></td>
                                </tr>
                                <tr>
                                    <td class="py-1 text-muted-color">Expenses</td>
                                    <td class="py-1 text-right"><MoneyText :centavos="pl.this_period.expenses" /></td>
                                    <td class="py-1 text-right"><MoneyText :centavos="pl.year_to_date.expenses" /></td>
                                </tr>
                                <tr class="border-t-2 border-surface">
                                    <td class="py-2 font-semibold">Net income</td>
                                    <td class="py-2 text-right font-semibold">
                                        <MoneyText :centavos="pl.this_period.net_income" />
                                    </td>
                                    <td class="py-2 text-right font-semibold">
                                        <MoneyText :centavos="pl.year_to_date.net_income" />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-span-12 xl:col-span-5">
                    <div class="card !mb-0 h-full">
                        <h2 class="mb-1 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                            Filing deadlines
                        </h2>
                        <p class="mt-0 text-sm text-muted-color">
                            Statutory dates. The returns themselves arrive in Phase 4.
                        </p>

                        <ul class="m-0 list-none p-0">
                            <li
                                v-for="deadline in summary.deadlines"
                                :key="deadline.form"
                                class="flex items-center justify-between border-b border-surface py-2 last:border-0"
                            >
                                <div>
                                    <div class="font-medium text-surface-900 dark:text-surface-0">
                                        {{ deadline.form }}
                                    </div>
                                    <div class="text-xs text-muted-color">{{ deadline.name }}</div>
                                    <div class="text-xs text-muted-color">covers {{ deadline.covers }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm">{{ deadline.due }}</div>
                                    <Tag
                                        :value="
                                            deadline.days_remaining < 0
                                                ? `${Math.abs(deadline.days_remaining)} days overdue`
                                                : `in ${deadline.days_remaining} days`
                                        "
                                        :severity="urgency(deadline.days_remaining)"
                                        class="text-xs"
                                    />
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
