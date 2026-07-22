<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import NotificationsWidget from '@/components/dashboard/NotificationsWidget.vue';
import MoneyText from '@/components/MoneyText.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { computed } from 'vue';

/**
 * Dashboard-lite (Phase 3). Every figure comes from the same services the
 * financial statements use, so this can never tell a different story from
 * the reports. It replaced the Sakai demo widgets, which showed fabricated
 * sales — in an accounting product that is worse than an empty page.
 */
const props = defineProps({
    summary: { type: Object, default: null },
});

const pl = computed(() => props.summary?.profit_and_loss ?? null);

const urgency = (days) => (days < 0 ? 'danger' : days <= 7 ? 'warn' : 'secondary');
</script>

<template>
    <AuthenticatedLayout title="Dashboard">
        <template #header>
            <AppPageHeader title="Dashboard" :subtitle="summary ? `As of ${summary.as_of}` : null" />
        </template>

        <Message v-if="!summary" severity="info" :closable="false">
            No fiscal period covers today, so there is nothing to summarise yet.
        </Message>

        <template v-else>
            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 md:col-span-4">
                    <div class="card h-full">
                        <div class="mb-2 text-sm text-muted-color">Cash on hand</div>
                        <div class="text-3xl font-bold"><MoneyText :centavos="summary.cash.total" /></div>
                        <ul class="mt-4 list-none p-0 text-sm">
                            <li
                                v-for="account in summary.cash.accounts"
                                :key="account.account_id"
                                class="flex justify-between py-1"
                            >
                                <span class="text-muted-color">{{ account.name }}</span>
                                <MoneyText :centavos="account.amount" :symbol="false" />
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="col-span-12 md:col-span-4">
                    <div class="card h-full">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm text-muted-color">Owed to us</span>
                            <Link :href="route('reports.aging')" class="text-xs text-primary hover:underline">
                                aging →
                            </Link>
                        </div>
                        <div class="text-3xl font-bold"><MoneyText :centavos="summary.receivables.total" /></div>
                        <div class="mt-2 text-sm">
                            <span class="text-muted-color">Overdue </span>
                            <span :class="summary.receivables.overdue > 0 ? 'font-semibold text-red-500' : ''">
                                <MoneyText :centavos="summary.receivables.overdue" :colorNegative="false" />
                            </span>
                        </div>
                        <ul class="mt-3 list-none p-0 text-sm">
                            <li
                                v-for="partner in summary.receivables.worst"
                                :key="partner.partner_id"
                                class="flex justify-between py-1"
                            >
                                <span class="truncate text-muted-color">{{ partner.registered_name }}</span>
                                <MoneyText :centavos="partner.total" :symbol="false" />
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="col-span-12 md:col-span-4">
                    <div class="card h-full">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm text-muted-color">We owe</span>
                            <Link
                                :href="route('reports.aging', { kind: 'payables' })"
                                class="text-xs text-primary hover:underline"
                            >
                                aging →
                            </Link>
                        </div>
                        <div class="text-3xl font-bold"><MoneyText :centavos="summary.payables.total" /></div>
                        <div class="mt-2 text-sm">
                            <span class="text-muted-color">Overdue </span>
                            <span :class="summary.payables.overdue > 0 ? 'font-semibold text-red-500' : ''">
                                <MoneyText :centavos="summary.payables.overdue" :colorNegative="false" />
                            </span>
                        </div>
                        <ul class="mt-3 list-none p-0 text-sm">
                            <li
                                v-for="partner in summary.payables.worst"
                                :key="partner.partner_id"
                                class="flex justify-between py-1"
                            >
                                <span class="truncate text-muted-color">{{ partner.registered_name }}</span>
                                <MoneyText :centavos="partner.total" :symbol="false" />
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-12 gap-4">
                <div class="col-span-12 xl:col-span-7">
                    <div class="card h-full">
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
                                <tr class="border-b border-surface-200 text-left dark:border-surface-700">
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
                                <tr class="border-t-2 border-surface-300 dark:border-surface-600">
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
                    <div class="card h-full">
                        <h2 class="mb-1 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                            Filing deadlines
                        </h2>
                        <p class="mt-0 text-sm text-muted-color">
                            Statutory dates. The returns themselves arrive in Phase 4.
                        </p>

                        <ul class="list-none p-0">
                            <li
                                v-for="deadline in summary.deadlines"
                                :key="deadline.form"
                                class="flex items-center justify-between border-b border-surface-100 py-2 last:border-0 dark:border-surface-800"
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
        </template>

        <NotificationsWidget />
    </AuthenticatedLayout>
</template>
