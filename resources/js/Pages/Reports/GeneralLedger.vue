<script setup>
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import ReportShell from '@/components/ReportShell.vue';
import StatusTag from '@/components/StatusTag.vue';
import { router } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    report: { type: Object, required: true },
    accounts: { type: Array, required: true },
    accountId: { type: Number, required: true },
    from: { type: String, required: true },
    to: { type: String, required: true },
    header: { type: Object, required: true },
});

const accountId = ref(props.accountId);
const from = ref(new Date(props.from));
const to = ref(new Date(props.to));

const asDate = (value) =>
    `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;

const params = computed(() => ({
    account_id: accountId.value,
    from: asDate(from.value),
    to: asDate(to.value),
}));

watch([accountId, from, to], () => {
    router.get(route('reports.general-ledger'), params.value, { preserveState: true, replace: true });
});
</script>

<template>
    <ReportShell
        title="General Ledger"
        :subtitle="`${report.account.code} — ${report.account.name}`"
        :header="header"
        :params="params"
        export-route="reports.general-ledger"
    >
        <template #filters>
            <div>
                <label for="account" class="mb-2 block text-sm font-medium">Account</label>
                <Select
                    id="account"
                    v-model="accountId"
                    :options="accounts"
                    optionValue="id"
                    :optionLabel="(a) => `${a.code} — ${a.name}`"
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

        <Message v-if="!report.ties" severity="error" class="mb-4" :closable="false">
            The running balance does not reconcile to the movement on this account. Run
            <code>ledger:verify</code>.
        </Message>

        <div class="card">
            <div class="mb-4 flex flex-wrap justify-end gap-10 text-sm">
                <div class="text-right">
                    <div class="text-muted-color">Balance brought forward</div>
                    <MoneyText :centavos="report.opening_signed" />
                </div>
                <div class="text-right">
                    <div class="text-muted-color">Closing balance</div>
                    <span class="font-semibold"><MoneyText :centavos="report.closing_signed" /></span>
                </div>
            </div>

            <DataTable :value="report.rows" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-book" title="No movement" hint="Nothing was posted to this account in the range." />
                </template>

                <Column field="entry_date" header="Date" class="min-w-28" />
                <Column field="entry_number" header="Entry no." class="min-w-36">
                    <template #body="{ data }"><span class="font-mono text-sm">{{ data.entry_number }}</span></template>
                </Column>
                <Column field="particulars" header="Particulars" class="min-w-64" />
                <Column header="Contra" class="min-w-32">
                    <template #body="{ data }">
                        <span class="font-mono text-xs text-muted-color">
                            {{ data.contra_accounts.join(', ') }}
                        </span>
                    </template>
                </Column>
                <Column header="Status" class="min-w-24">
                    <template #body="{ data }"><StatusTag :status="data.status" /></template>
                </Column>
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
                <Column header="Balance" class="min-w-32 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.running_signed" :symbol="false" /></template>
                </Column>
            </DataTable>
        </div>
    </ReportShell>
</template>
