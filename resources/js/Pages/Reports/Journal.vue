<script setup>
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import ReportShell from '@/components/ReportShell.vue';
import StatusTag from '@/components/StatusTag.vue';
import { router } from '@inertiajs/vue3';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
    books: { type: Object, required: true },
    book: { type: String, default: null },
    from: { type: String, required: true },
    to: { type: String, required: true },
    header: { type: Object, required: true },
});

const book = ref(props.book);
const from = ref(new Date(props.from));
const to = ref(new Date(props.to));

const bookOptions = computed(() => [
    { value: null, label: 'All journals' },
    ...Object.entries(props.books).map(([value, meta]) => ({
        value,
        label: `${meta.name} (${meta.series})`,
    })),
]);

const asDate = (value) =>
    `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;

const params = computed(() => ({
    book: book.value ?? undefined,
    from: asDate(from.value),
    to: asDate(to.value),
}));

watch([book, from, to], () => {
    router.get(route('reports.journal'), params.value, { preserveState: true, replace: true });
});
</script>

<template>
    <ReportShell
        :title="report.book_name"
        :subtitle="`${report.from} to ${report.to}`"
        :header="header"
        :params="params"
        export-route="reports.journal"
    >
        <template #filters>
            <div>
                <label for="book" class="mb-2 block text-sm font-medium">Book</label>
                <Select
                    id="book"
                    v-model="book"
                    :options="bookOptions"
                    optionLabel="label"
                    optionValue="value"
                    class="w-80"
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

        <Message v-if="!report.balanced" severity="error" class="mb-4" :closable="false">
            Debits and credits do not agree across this range. Every entry balances individually, so this
            can only mean the ledger has been tampered with — run <code>ledger:verify</code>.
        </Message>

        <div class="card">
            <EmptyState
                v-if="!report.entries.length"
                icon="pi pi-book"
                title="No entries"
                hint="Nothing was posted to this book in the range."
            />

            <div v-for="entry in report.entries" :key="entry.id" class="mb-6 last:mb-0">
                <div class="mb-2 flex flex-wrap items-center gap-3">
                    <span class="font-mono font-semibold text-surface-900 dark:text-surface-0">
                        {{ entry.entry_number }}
                    </span>
                    <span class="text-sm text-muted-color">{{ entry.entry_date }}</span>
                    <span class="text-sm text-muted-color">{{ entry.book_name }}</span>
                    <StatusTag :status="entry.status" />
                    <!-- Void entries stay visible: hiding them would be the
                         suppression the NIRC prohibits. -->
                    <span v-if="entry.is_reversal" class="text-xs text-muted-color">reversal</span>
                    <span v-else-if="entry.is_reversed" class="text-xs text-muted-color">reversed</span>
                    <span class="text-sm">{{ entry.memo }}</span>
                </div>

                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="line in entry.lines"
                            :key="line.line_no"
                            class="border-b border-surface-100 dark:border-surface-800"
                        >
                            <td class="w-8 py-1 text-muted-color">{{ line.line_no }}</td>
                            <td class="py-1" :class="{ 'pl-6': line.credit > 0 }">
                                <span class="font-mono text-xs text-muted-color">{{ line.code }}</span>
                                <span class="ml-2">{{ line.name }}</span>
                                <span v-if="line.memo" class="ml-2 text-xs text-muted-color">{{ line.memo }}</span>
                            </td>
                            <td class="w-32 py-1 text-right">
                                <MoneyText v-if="line.debit" :centavos="line.debit" :symbol="false" />
                            </td>
                            <td class="w-32 py-1 text-right">
                                <MoneyText v-if="line.credit" :centavos="line.credit" :symbol="false" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="report.entries.length" class="mt-4 flex justify-end gap-10 border-t-2 border-surface-300 pt-3 text-sm dark:border-surface-600">
                <div class="text-right">
                    <div class="text-muted-color">Total debits</div>
                    <span class="font-semibold"><MoneyText :centavos="report.total_debits" /></span>
                </div>
                <div class="text-right">
                    <div class="text-muted-color">Total credits</div>
                    <span class="font-semibold"><MoneyText :centavos="report.total_credits" /></span>
                </div>
            </div>
        </div>
    </ReportShell>
</template>
