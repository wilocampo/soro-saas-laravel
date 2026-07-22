<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import { computed, ref } from 'vue';

const props = defineProps({
    summary: { type: Object, required: true },
});

const toast = useAppToast();
const notes = ref(props.summary.reconciliation.notes ?? '');
const isDraft = computed(() => props.summary.reconciliation.status === 'draft');

const toggle = (line) => {
    router.post(
        route('reconciliations.toggle', props.summary.reconciliation.id),
        { journal_line_id: line.journal_line_id, cleared: !line.cleared },
        {
            preserveScroll: true,
            onError: (errors) => toast.error('Not saved', Object.values(errors)[0]),
        }
    );
};

const complete = () => {
    router.post(
        route('reconciliations.complete', props.summary.reconciliation.id),
        { notes: notes.value || null },
        { preserveScroll: true, onError: (errors) => toast.error('Not completed', Object.values(errors)[0]) }
    );
};
</script>

<template>
    <AuthenticatedLayout :title="`Reconciliation — ${summary.account.code}`">
        <template #header>
            <AppPageHeader
                :title="`${summary.account.code} — ${summary.account.name}`"
                :subtitle="`Statement dated ${summary.reconciliation.statement_date}`"
            >
                <template #actions>
                    <StatusTag :status="summary.reconciliation.status" :map="{ completed: 'success' }" />
                    <Button
                        v-if="isDraft"
                        label="Complete"
                        icon="pi pi-check"
                        :disabled="!summary.reconciled"
                        @click="complete"
                    />
                </template>
            </AppPageHeader>
        </template>

        <div class="card">
            <div class="flex flex-wrap justify-between gap-8 text-sm">
                <div>
                    <div class="text-muted-color">Closing balance per the statement</div>
                    <MoneyText :centavos="summary.statement_closing" />
                </div>
                <div>
                    <div class="text-muted-color">Add: deposits in transit</div>
                    <MoneyText :centavos="summary.deposits_in_transit" />
                </div>
                <div>
                    <div class="text-muted-color">Less: outstanding cheques</div>
                    <MoneyText :centavos="-summary.outstanding_cheques" :colorNegative="false" />
                </div>
                <div>
                    <div class="text-muted-color">Adjusted bank balance</div>
                    <span class="font-semibold"><MoneyText :centavos="summary.adjusted_bank" /></span>
                </div>
                <div>
                    <div class="text-muted-color">Balance per the books</div>
                    <span class="font-semibold"><MoneyText :centavos="summary.book_balance" /></span>
                </div>
                <div>
                    <div class="text-muted-color">Difference</div>
                    <span :class="summary.reconciled ? 'font-semibold text-green-500' : 'font-semibold text-red-500'">
                        <MoneyText :centavos="summary.difference" :colorNegative="false" />
                    </span>
                </div>
            </div>
        </div>

        <!--
            There is deliberately no adjustment field: a difference is a
            bank-only item that has not been booked, and the fix is to post
            it. A reconciliation that can be plugged reconciles nothing.
        -->
        <Message v-if="!summary.reconciled" severity="warn" class="mb-4" :closable="false">
            Still out by <MoneyText :centavos="summary.difference" :colorNegative="false" />. That is a bank-only
            item — a service charge or interest credit not yet in the books. Post it as a journal entry; there is
            no adjustment field here, because a reconciliation that can be plugged reconciles nothing.
        </Message>

        <Message v-else-if="isDraft" severity="success" class="mb-4" :closable="false">
            Reconciled. Completing it freezes the reconciliation and writes an audit entry.
        </Message>

        <div class="card">
            <DataTable :value="summary.lines" dataKey="journal_line_id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState
                        icon="pi pi-building-columns"
                        title="No movement"
                        hint="Nothing was posted to this account on or before the statement date."
                    />
                </template>

                <Column header="On statement" class="w-32">
                    <template #body="{ data }">
                        <Checkbox
                            :modelValue="data.cleared"
                            binary
                            :disabled="!isDraft"
                            @update:modelValue="toggle(data)"
                        />
                    </template>
                </Column>
                <Column field="entry_date" header="Date" class="min-w-28" />
                <Column field="entry_number" header="Entry no." class="min-w-36">
                    <template #body="{ data }"><span class="font-mono text-sm">{{ data.entry_number }}</span></template>
                </Column>
                <Column field="particulars" header="Particulars" class="min-w-64" />
                <Column header="Money in" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.debit" :centavos="data.debit" :symbol="false" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
                <Column header="Money out" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.credit" :centavos="data.credit" :symbol="false" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
            </DataTable>
        </div>

        <div v-if="isDraft" class="card flex flex-wrap items-end gap-4">
            <div class="flex-1">
                <label for="notes" class="mb-2 block font-medium">Notes</label>
                <InputText id="notes" v-model="notes" fluid />
            </div>
            <Button label="Complete reconciliation" icon="pi pi-check" :disabled="!summary.reconciled" @click="complete" />
        </div>

        <div class="card">
            <Link :href="route('reconciliations.index')" class="text-sm text-primary hover:underline">
                ← All reconciliations
            </Link>
        </div>
    </AuthenticatedLayout>
</template>
