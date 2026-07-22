<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import { computed, reactive } from 'vue';

const props = defineProps({
    count: { type: Object, required: true },
    lines: { type: Array, required: true },
    hideSnapshot: { type: Boolean, default: false },
});

const toast = useAppToast();
const entry = reactive({});

const isCounting = computed(() => props.count.status === 'counting');
const isReview = computed(() => props.count.status === 'review');
const uncounted = computed(() => props.lines.filter((l) => l.counted_qty === null).length);

// Review ranks by absolute PESO variance: two units of something expensive
// matter more than two hundred of something cheap.
const ranked = computed(() =>
    [...props.lines]
        .filter((l) => Number(l.variance_qty ?? 0) !== 0)
        .sort((a, b) => Math.abs(b.variance_centavos ?? 0) - Math.abs(a.variance_centavos ?? 0))
);

const netVariance = computed(() => props.lines.reduce((sum, l) => sum + (l.variance_centavos ?? 0), 0));

const record = (line) => {
    const value = entry[line.item_id];
    if (value === undefined || value === null) return;

    router.post(
        route('counts.record', props.count.id),
        { item_id: line.item_id, counted_qty: value },
        {
            preserveScroll: true,
            onError: (errors) => toast.error('Not recorded', Object.values(errors)[0]),
        }
    );
};

const submitForReview = () =>
    router.post(route('counts.review', props.count.id), {}, {
        preserveScroll: true,
        onError: (errors) => toast.error('Not submitted', Object.values(errors)[0]),
    });

const approve = () =>
    router.post(route('counts.approve', props.count.id), {}, {
        preserveScroll: true,
        onError: (errors) => toast.error('Not approved', Object.values(errors)[0]),
    });
</script>

<template>
    <AuthenticatedLayout :title="`Count ${count.reference ?? count.id}`">
        <template #header>
            <AppPageHeader
                :title="`Stock count ${count.reference ?? '#' + count.id}`"
                :subtitle="count.is_blind ? 'Blind count' : 'Open count'"
            >
                <template #actions>
                    <StatusTag :status="count.status" :map="{ counting: 'info', review: 'warn', approved: 'success' }" />
                    <Button
                        v-if="isCounting"
                        label="Submit for review"
                        icon="pi pi-check"
                        :disabled="uncounted > 0"
                        @click="submitForReview"
                    />
                    <Button v-if="isReview" label="Approve" icon="pi pi-verified" severity="success" @click="approve" />
                </template>
            </AppPageHeader>
        </template>

        <Message v-if="isCounting && uncounted" severity="info" class="mb-4" :closable="false">
            {{ uncounted }} line(s) not yet counted. An uncounted line is not a zero — enter every one.
        </Message>

        <Message v-if="isReview" severity="warn" class="mb-4" :closable="false">
            Approving posts an adjustment and freezes this count. It cannot be undone; a later correction is another
            count or an adjustment.
        </Message>

        <div v-if="isCounting" class="card">
            <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Entry</h2>
            <DataTable :value="lines" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <Column header="Item" class="min-w-64">
                    <template #body="{ data }">
                        <span class="font-mono text-xs text-muted-color">{{ data.code }}</span>
                        <span class="ml-2">{{ data.name }}</span>
                    </template>
                </Column>
                <Column v-if="!hideSnapshot" header="System" class="min-w-28 text-right">
                    <template #body="{ data }">{{ Number(data.snapshot_qty).toLocaleString() }}</template>
                </Column>
                <Column header="Counted" class="min-w-48">
                    <template #body="{ data }">
                        <div class="flex gap-2">
                            <InputNumber
                                v-model="entry[data.item_id]"
                                :placeholder="data.counted_qty ?? 'Enter'"
                                :min="0"
                                :maxFractionDigits="5"
                                @keyup.enter="record(data)"
                                fluid
                            />
                            <Button icon="pi pi-check" outlined @click="record(data)" v-tooltip.top="'Record'" />
                        </div>
                    </template>
                </Column>
                <Column header="Recorded" class="min-w-28 text-right">
                    <template #body="{ data }">
                        <span v-if="data.counted_qty !== null">{{ Number(data.counted_qty).toLocaleString() }}</span>
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
            </DataTable>
        </div>

        <div v-else class="card">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="m-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                    Variance, ranked by value
                </h2>
                <div class="text-right text-sm">
                    <div class="text-muted-color">Net variance</div>
                    <span class="font-semibold"><MoneyText :centavos="netVariance" /></span>
                </div>
            </div>

            <DataTable :value="ranked" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <p class="py-6 text-center text-muted-color">No variance — the books matched the shelves.</p>
                </template>

                <Column header="Item" class="min-w-64">
                    <template #body="{ data }">
                        <span class="font-mono text-xs text-muted-color">{{ data.code }}</span>
                        <span class="ml-2">{{ data.name }}</span>
                    </template>
                </Column>
                <Column header="System" class="min-w-28 text-right">
                    <template #body="{ data }">{{ Number(data.snapshot_qty).toLocaleString() }}</template>
                </Column>
                <Column header="Counted" class="min-w-28 text-right">
                    <template #body="{ data }">{{ Number(data.counted_qty).toLocaleString() }}</template>
                </Column>
                <Column header="Variance" class="min-w-28 text-right">
                    <template #body="{ data }">
                        <span :class="Number(data.variance_qty) < 0 ? 'text-red-500' : 'text-green-500'">
                            {{ Number(data.variance_qty).toLocaleString() }}
                        </span>
                    </template>
                </Column>
                <Column header="Value" class="min-w-32 text-right">
                    <template #body="{ data }"><MoneyText :centavos="data.variance_centavos" /></template>
                </Column>
                <Column field="note" header="Note" class="min-w-48">
                    <template #body="{ data }"><span class="text-sm text-muted-color">{{ data.note }}</span></template>
                </Column>
            </DataTable>
        </div>

        <div class="card">
            <Link :href="route('counts.index')" class="text-sm text-primary hover:underline">← All counts</Link>
        </div>
    </AuthenticatedLayout>
</template>
