<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import { ref } from 'vue';

defineProps({
    counts: { type: Object, required: true },
    locations: { type: Array, required: true },
    expiring: { type: Array, default: () => [] },
});

const dialog = ref(false);

const form = useForm({
    location_id: null,
    is_blind: true,
    memo: '',
});

const submit = () => form.post(route('counts.store'), { onSuccess: () => (dialog.value = false) });
</script>

<template>
    <AuthenticatedLayout title="Stock counts">
        <template #header>
            <AppPageHeader
                title="Stock counts"
                subtitle="The snapshot freezes when counting starts, so sales during the count are not shrinkage"
            />
        </template>

        <Message v-if="expiring.length" severity="warn" class="mb-4" :closable="false">
            <p class="m-0 font-semibold">{{ expiring.length }} lot(s) expire within 30 days</p>
            <ul class="mt-2 mb-0 list-disc pl-5 text-sm">
                <li v-for="lot in expiring.slice(0, 5)" :key="lot.lot_id">
                    {{ lot.lot_code }} — {{ Number(lot.remaining_qty).toLocaleString() }} left, expires
                    {{ lot.expiry_date }}
                </li>
            </ul>
        </Message>

        <div class="card">
            <div class="mb-4 flex justify-end">
                <Button label="Start a count" icon="pi pi-plus" severity="success" @click="dialog = true" />
            </div>

            <DataTable :value="counts.data" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-list-check" title="No counts yet" hint="Start one when you walk the shelves." />
                </template>

                <Column header="Count" class="min-w-32">
                    <template #body="{ data }">
                        <Link :href="route('counts.show', data.id)" class="font-semibold text-primary hover:underline">
                            {{ data.reference ?? `#${data.id}` }}
                        </Link>
                    </template>
                </Column>
                <Column field="name" header="Location" class="min-w-48" />
                <Column header="Mode" class="min-w-28">
                    <template #body="{ data }">
                        <span class="text-sm text-muted-color">{{ data.is_blind ? 'Blind' : 'Open' }}</span>
                    </template>
                </Column>
                <Column header="Status" class="min-w-32">
                    <template #body="{ data }">
                        <StatusTag :status="data.status" :map="{ counting: 'info', review: 'warn', approved: 'success' }" />
                    </template>
                </Column>
                <Column field="memo" header="Memo" class="min-w-48">
                    <template #body="{ data }"><span class="text-sm text-muted-color">{{ data.memo }}</span></template>
                </Column>
            </DataTable>
        </div>

        <Dialog v-model:visible="dialog" modal header="Start a stock count" :style="{ width: '32rem' }">
            <div class="flex flex-col gap-4">
                <div>
                    <label for="location" class="mb-2 block font-medium">Location</label>
                    <Select
                        id="location"
                        v-model="form.location_id"
                        :options="locations"
                        optionValue="id"
                        :optionLabel="(l) => `${l.code} — ${l.name}`"
                        fluid
                    />
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox v-model="form.is_blind" inputId="blind" binary />
                    <label for="blind">Blind count — hide system quantities from the counter</label>
                </div>
                <p class="m-0 text-sm text-muted-color">
                    Showing the counter what the system expects turns a count into a confirmation exercise.
                </p>
                <div>
                    <label for="memo" class="mb-2 block font-medium">Memo</label>
                    <InputText id="memo" v-model="form.memo" fluid />
                </div>
            </div>
            <template #footer>
                <Button label="Cancel" severity="secondary" text @click="dialog = false" />
                <Button label="Start counting" :loading="form.processing" :disabled="!form.location_id" @click="submit" />
            </template>
        </Dialog>
    </AuthenticatedLayout>
</template>
