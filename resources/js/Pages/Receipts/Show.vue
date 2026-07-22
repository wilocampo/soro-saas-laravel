<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link } from '@inertiajs/vue3';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Message from 'primevue/message';

defineProps({
    receipt: { type: Object, required: true },
    journalEntry: { type: Object, default: null },
    lines: { type: Array, default: () => [] },
});
</script>

<template>
    <AuthenticatedLayout :title="`Receipt ${receipt.reference}`">
        <template #header>
            <AppPageHeader
                :title="`Receipt ${receipt.reference}`"
                :subtitle="receipt.partner?.registered_name ?? 'Found / opening stock'"
            />
        </template>

        <div class="grid grid-cols-12 gap-4">
            <div class="col-span-12 lg:col-span-8">
                <div class="card">
                    <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Received</h2>
                    <DataTable :value="receipt.lines" dataKey="id" class="p-datatable-sm">
                        <Column field="line_no" header="#" class="w-12" />
                        <Column header="Item">
                            <template #body="{ data }">
                                <span class="font-mono text-xs text-muted-color">{{ data.item?.code }}</span>
                                <span class="ml-2">{{ data.item?.name }}</span>
                            </template>
                        </Column>
                        <Column header="Lot" class="w-40">
                            <template #body="{ data }">
                                <span v-if="data.lot_code" class="font-mono text-sm">{{ data.lot_code }}</span>
                                <span v-else class="text-muted-color">—</span>
                            </template>
                        </Column>
                        <Column header="Entered" class="text-right w-32">
                            <template #body="{ data }">{{ data.qty_entered }} × {{ data.conversion_factor }}</template>
                        </Column>
                        <Column header="Stock qty" class="text-right w-28">
                            <template #body="{ data }">{{ data.qty_stock }}</template>
                        </Column>
                        <Column header="Cost" class="text-right w-32">
                            <template #body="{ data }"><MoneyText :centavos="data.line_cost_centavos" /></template>
                        </Column>
                    </DataTable>

                    <Message
                        v-for="line in receipt.lines.filter((l) => l.variance_note)"
                        :key="line.id"
                        severity="warn"
                        class="mt-3"
                        :closable="false"
                    >
                        {{ line.item?.code }}: {{ line.variance_note }}
                    </Message>
                </div>

                <div v-if="journalEntry" class="card">
                    <div class="mb-4 flex items-center gap-3">
                        <h2 class="m-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                            Journal entry {{ journalEntry.entry_number }}
                        </h2>
                        <StatusTag :status="journalEntry.status" />
                    </div>
                    <DataTable :value="lines" class="p-datatable-sm">
                        <Column header="Account">
                            <template #body="{ data }">
                                <span class="font-mono text-sm">{{ data.code }}</span>
                                <span class="ml-2 text-muted-color">{{ data.name }}</span>
                            </template>
                        </Column>
                        <Column header="Debit" class="text-right w-32">
                            <template #body="{ data }">
                                <MoneyText v-if="data.debit_centavos" :centavos="data.debit_centavos" />
                                <span v-else class="text-muted-color">—</span>
                            </template>
                        </Column>
                        <Column header="Credit" class="text-right w-32">
                            <template #body="{ data }">
                                <MoneyText v-if="data.credit_centavos" :centavos="data.credit_centavos" />
                                <span v-else class="text-muted-color">—</span>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </div>

            <div class="col-span-12 lg:col-span-4">
                <div class="card">
                    <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Summary</h2>
                    <dl class="m-0 grid grid-cols-2 gap-y-3 text-sm">
                        <dt class="text-muted-color">Status</dt>
                        <dd class="m-0 text-right"><StatusTag :status="receipt.status" /></dd>
                        <dt class="text-muted-color">Location</dt>
                        <dd class="m-0 text-right">{{ receipt.location?.name }}</dd>
                        <dt class="text-muted-color">Their DR no.</dt>
                        <dd class="m-0 text-right font-mono">{{ receipt.vendor_reference ?? '—' }}</dd>
                        <dt class="font-semibold">Goods value</dt>
                        <dd class="m-0 text-right font-semibold">
                            <MoneyText :centavos="receipt.total_cost_centavos" />
                        </dd>
                    </dl>
                    <p class="mt-4 mb-0 text-sm text-muted-color">
                        Credited to Goods Received Not Invoiced. The supplier's bill clears it to Accounts Payable.
                    </p>
                </div>

                <div class="card">
                    <Link :href="route('receipts.index')" class="text-sm text-primary hover:underline">
                        ← All receipts
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
