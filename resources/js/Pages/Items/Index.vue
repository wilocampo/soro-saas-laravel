<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import CRUDModal from '@/components/CRUDModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import MoneyText from '@/components/MoneyText.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { ref, watch } from 'vue';

const props = defineProps({
    items: { type: Object, required: true },
    type: { type: String, default: '' },
    uoms: { type: Array, required: true },
    accounts: { type: Array, required: true },
});

const toast = useAppToast();
const confirm = useConfirm();
const type = ref(props.type || null);
const dialog = ref(false);
const editing = ref(null);

const TYPES = [
    { label: 'All', value: null },
    { label: 'Inventory', value: 'inventory' },
    { label: 'Service', value: 'service' },
    { label: 'Non-inventory', value: 'non_inventory' },
];

const TRACKING = [
    { label: 'No tracking', value: 'none' },
    { label: 'Lot / batch', value: 'lot' },
];

const form = useForm({
    code: '',
    name: '',
    description: '',
    item_type: 'inventory',
    stock_uom_id: props.uoms[0]?.id ?? null,
    purchase_uom_id: null,
    purchase_to_stock_factor: 1,
    tracking: 'none',
    track_expiry: false,
    require_expiry: false,
    reorder_point: 0,
    inventory_account_id: null,
    income_account_id: null,
    cogs_account_id: null,
    adjustment_account_id: null,
});

watch(type, (value) => {
    router.get(route('items.index'), value ? { type: value } : {}, { preserveState: true, replace: true });
});

// An expiry date needs a lot to hang on, so the two settings move together.
watch(
    () => form.tracking,
    (tracking) => {
        if (tracking === 'none') {
            form.track_expiry = false;
            form.require_expiry = false;
        }
    }
);

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialog.value = true;
};

const openEdit = (item) => {
    editing.value = item;
    form.clearErrors();
    Object.keys(form.data()).forEach((key) => {
        if (item[key] !== undefined) form[key] = item[key];
    });
    dialog.value = true;
};

const submit = () => {
    const options = {
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
        onError: (errors) => toast.error('Not saved', Object.values(errors)[0]),
        preserveScroll: true,
    };

    editing.value ? form.put(route('items.update', editing.value.id), options) : form.post(route('items.store'), options);
};

const deactivate = (item) => {
    confirm.require({
        message: `Deactivate ${item.code}? Posted stock movements keep referencing it — nothing is deleted.`,
        header: 'Deactivate item',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: { label: 'Keep', severity: 'secondary', text: true },
        acceptProps: { label: 'Deactivate', severity: 'danger' },
        accept: () => router.delete(route('items.destroy', item.id), { preserveScroll: true }),
    });
};

const belowReorder = (item) =>
    item.item_type === 'inventory' && Number(item.on_hand) <= Number(item.reorder_point) && Number(item.reorder_point) > 0;
</script>

<template>
    <AuthenticatedLayout title="Items">
        <template #header>
            <AppPageHeader title="Items" subtitle="Valued at moving weighted average — the same figure the balance sheet uses" />
        </template>

        <div class="card">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <SelectButton v-model="type" :options="TYPES" optionLabel="label" optionValue="value" />
                <Button label="New item" icon="pi pi-plus" severity="success" @click="openCreate" />
            </div>

            <DataTable :value="items.data" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-tags" title="No items yet" hint="Add what you buy and sell." />
                </template>

                <Column field="code" header="Code" class="min-w-28">
                    <template #body="{ data }"><span class="font-mono text-sm">{{ data.code }}</span></template>
                </Column>
                <Column field="name" header="Name" class="min-w-64">
                    <template #body="{ data }">
                        <div>{{ data.name }}</div>
                        <div class="flex gap-1 mt-1">
                            <Tag v-if="data.tracking === 'lot'" value="Lot" severity="info" class="text-xs" />
                            <Tag v-if="data.track_expiry" value="Expiry" severity="warn" class="text-xs" />
                        </div>
                    </template>
                </Column>
                <Column field="item_type" header="Type" class="min-w-32">
                    <template #body="{ data }">
                        <span class="text-muted-color text-sm">{{ data.item_type.replace('_', '-') }}</span>
                    </template>
                </Column>
                <Column header="On hand" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <span v-if="data.on_hand === null" class="text-muted-color">—</span>
                        <span v-else :class="belowReorder(data) ? 'font-semibold text-orange-500' : ''">
                            {{ Number(data.on_hand).toLocaleString() }}
                        </span>
                    </template>
                </Column>
                <Column header="Avg cost" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.item_type === 'inventory'" :centavos="Math.round(Number(data.avg_cost) * 100)" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
                <Column header="Value" class="min-w-32 text-right">
                    <template #body="{ data }">
                        <MoneyText v-if="data.value_centavos !== null" :centavos="data.value_centavos" />
                        <span v-else class="text-muted-color">—</span>
                    </template>
                </Column>
                <Column header="Status" class="min-w-28">
                    <template #body="{ data }"><StatusTag :status="!!data.is_active" /></template>
                </Column>
                <Column header="Actions" class="min-w-28">
                    <template #body="{ data }">
                        <div class="flex gap-2">
                            <Button icon="pi pi-pencil" rounded outlined @click="openEdit(data)" v-tooltip.top="'Edit'" />
                            <Button
                                v-if="data.is_active"
                                icon="pi pi-ban"
                                severity="danger"
                                rounded
                                outlined
                                @click="deactivate(data)"
                                v-tooltip.top="'Deactivate'"
                            />
                        </div>
                    </template>
                </Column>
            </DataTable>
        </div>

        <CRUDModal
            v-model:visible="dialog"
            :title="editing ? 'Edit item' : 'New item'"
            :submit-label="editing ? 'Save' : 'Create'"
            :loading="form.processing"
            @submit="submit"
        >
            <template #fields>
                <div class="col-span-12 md:col-span-4">
                    <label for="code" class="mb-2 block font-medium">Code</label>
                    <InputText id="code" v-model="form.code" :invalid="!!form.errors.code" fluid />
                    <small v-if="form.errors.code" class="text-red-500">{{ form.errors.code }}</small>
                </div>
                <div class="col-span-12 md:col-span-8">
                    <label for="name" class="mb-2 block font-medium">Name</label>
                    <InputText id="name" v-model="form.name" :invalid="!!form.errors.name" fluid />
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label class="mb-2 block font-medium">Type</label>
                    <Select
                        v-model="form.item_type"
                        :options="TYPES.filter((t) => t.value)"
                        optionLabel="label"
                        optionValue="value"
                        fluid
                    />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-2 block font-medium">Stock unit</label>
                    <Select v-model="form.stock_uom_id" :options="uoms" optionValue="id" optionLabel="name" fluid />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-2 block font-medium">Purchase unit</label>
                    <Select
                        v-model="form.purchase_uom_id"
                        :options="uoms"
                        optionValue="id"
                        optionLabel="name"
                        showClear
                        fluid
                    />
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label for="factor" class="mb-2 block font-medium">Purchase → stock factor</label>
                    <InputNumber id="factor" v-model="form.purchase_to_stock_factor" :min="0" :maxFractionDigits="5" fluid />
                    <small class="text-muted-color">e.g. 48 if a case holds 48 pieces.</small>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-2 block font-medium">Tracking</label>
                    <Select v-model="form.tracking" :options="TRACKING" optionLabel="label" optionValue="value" fluid />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label for="reorder" class="mb-2 block font-medium">Reorder point</label>
                    <InputNumber id="reorder" v-model="form.reorder_point" :min="0" :maxFractionDigits="5" fluid />
                </div>

                <div class="col-span-12 flex flex-wrap gap-6">
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="form.track_expiry" inputId="track_expiry" binary :disabled="form.tracking !== 'lot'" />
                        <label for="track_expiry">Track expiry dates</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="form.require_expiry" inputId="require_expiry" binary :disabled="form.tracking !== 'lot'" />
                        <label for="require_expiry">Require an expiry on every lot</label>
                    </div>
                </div>

                <div v-if="form.item_type === 'inventory'" class="col-span-12 grid grid-cols-12 gap-4">
                    <div class="col-span-12 md:col-span-3">
                        <label class="mb-2 block font-medium">Inventory account</label>
                        <Select
                            v-model="form.inventory_account_id"
                            :options="accounts.filter((a) => a.type_code === 'asset')"
                            optionValue="id"
                            :optionLabel="(a) => `${a.code} — ${a.name}`"
                            showClear
                            fluid
                        />
                    </div>
                    <div class="col-span-12 md:col-span-3">
                        <label class="mb-2 block font-medium">Income account</label>
                        <Select
                            v-model="form.income_account_id"
                            :options="accounts.filter((a) => a.type_code === 'income')"
                            optionValue="id"
                            :optionLabel="(a) => `${a.code} — ${a.name}`"
                            showClear
                            fluid
                        />
                    </div>
                    <div class="col-span-12 md:col-span-3">
                        <label class="mb-2 block font-medium">COGS account</label>
                        <Select
                            v-model="form.cogs_account_id"
                            :options="accounts.filter((a) => a.type_code === 'expense')"
                            optionValue="id"
                            :optionLabel="(a) => `${a.code} — ${a.name}`"
                            showClear
                            fluid
                        />
                    </div>
                    <div class="col-span-12 md:col-span-3">
                        <label class="mb-2 block font-medium">Adjustment account</label>
                        <Select
                            v-model="form.adjustment_account_id"
                            :options="accounts.filter((a) => a.type_code === 'expense')"
                            optionValue="id"
                            :optionLabel="(a) => `${a.code} — ${a.name}`"
                            showClear
                            fluid
                        />
                    </div>
                </div>
            </template>
        </CRUDModal>
    </AuthenticatedLayout>
</template>
