<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import CRUDModal from '@/components/CRUDModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusTag from '@/components/StatusTag.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import DatePicker from 'primevue/datepicker';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import { ref, watch } from 'vue';
import { useConfirm } from 'primevue/useconfirm';

const props = defineProps({
    partners: { type: Object, required: true },
    role: { type: String, default: '' },
});

const toast = useAppToast();
const confirm = useConfirm();

const role = ref(props.role || null);
const dialog = ref(false);
const editing = ref(null);

const ROLES = [
    { label: 'All', value: null },
    { label: 'Customers', value: 'customer' },
    { label: 'Vendors', value: 'vendor' },
];

const TAXPAYER_TYPES = [
    { label: 'Juridical', value: 'juridical' },
    { label: 'Individual', value: 'individual' },
];

const form = useForm({
    code: '',
    registered_name: '',
    trade_name: '',
    is_customer: true,
    is_vendor: false,
    tin: '',
    branch_code: '000',
    address: '',
    email: '',
    is_vat_registered: true,
    taxpayer_type: 'juridical',
    default_atc_code: '',
    payment_terms_days: 0,
    sworn_declaration_valid_until: null,
});

watch(role, (value) => {
    router.get(route('partners.index'), value ? { role: value } : {}, { preserveState: true, replace: true });
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialog.value = true;
};

const openEdit = (partner) => {
    editing.value = partner;
    form.clearErrors();
    Object.keys(form.data()).forEach((key) => {
        form[key] = partner[key] ?? form[key];
    });
    form.sworn_declaration_valid_until = partner.sworn_declaration_valid_until
        ? new Date(partner.sworn_declaration_valid_until)
        : null;
    dialog.value = true;
};

const asDate = (value) =>
    value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : value;

const submit = () => {
    const options = {
        onSuccess: () => {
            dialog.value = false;
            form.reset();
        },
        onError: (errors) => toast.error('Not saved', Object.values(errors)[0]),
        preserveScroll: true,
    };

    form.transform((data) => ({
        ...data,
        sworn_declaration_valid_until: asDate(data.sworn_declaration_valid_until),
    }));

    editing.value
        ? form.put(route('partners.update', editing.value.id), options)
        : form.post(route('partners.store'), options);
};

const deactivate = (partner) => {
    confirm.require({
        message: `Deactivate ${partner.registered_name}? Posted documents keep referencing it — nothing is deleted.`,
        header: 'Deactivate partner',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: { label: 'Keep', severity: 'secondary', text: true },
        acceptProps: { label: 'Deactivate', severity: 'danger' },
        accept: () => router.delete(route('partners.destroy', partner.id), { preserveScroll: true }),
    });
};

const formatTin = (partner) =>
    partner.tin
        ? `${partner.tin.slice(0, 3)}-${partner.tin.slice(3, 6)}-${partner.tin.slice(6, 9)}-${String(partner.branch_code || '000').padStart(5, '0')}`
        : '—';
</script>

<template>
    <AuthenticatedLayout title="Partners">
        <template #header>
            <AppPageHeader
                title="Customers and vendors"
                subtitle="A missing or malformed TIN on an invoice costs the buyer its input-VAT claim"
            />
        </template>

        <div class="card">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <SelectButton v-model="role" :options="ROLES" optionLabel="label" optionValue="value" />
                <Button label="New partner" icon="pi pi-plus" severity="success" @click="openCreate" />
            </div>

            <DataTable :value="partners.data" dataKey="id" class="p-datatable-sm" responsiveLayout="scroll">
                <template #empty>
                    <EmptyState icon="pi pi-users" title="No partners yet" hint="Import a CSV or add one by hand." />
                </template>

                <Column field="code" header="Code" class="min-w-28">
                    <template #body="{ data }"><span class="font-mono text-sm">{{ data.code }}</span></template>
                </Column>

                <Column field="registered_name" header="Registered name" class="min-w-64">
                    <template #body="{ data }">
                        <div class="font-semibold text-surface-900 dark:text-surface-0">{{ data.registered_name }}</div>
                        <div v-if="data.trade_name" class="text-sm text-muted-color">{{ data.trade_name }}</div>
                    </template>
                </Column>

                <Column header="TIN" class="min-w-44">
                    <template #body="{ data }">
                        <span class="font-mono text-sm">{{ formatTin(data) }}</span>
                    </template>
                </Column>

                <Column header="Roles" class="min-w-36">
                    <template #body="{ data }">
                        <div class="flex gap-1">
                            <Tag v-if="data.is_customer" value="Customer" severity="info" class="text-xs" />
                            <Tag v-if="data.is_vendor" value="Vendor" severity="warn" class="text-xs" />
                        </div>
                    </template>
                </Column>

                <Column header="VAT" class="min-w-28">
                    <template #body="{ data }">
                        <Tag
                            :value="data.is_vat_registered ? 'VAT' : 'Non-VAT'"
                            :severity="data.is_vat_registered ? 'success' : 'secondary'"
                            class="text-xs"
                        />
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
            :title="editing ? 'Edit partner' : 'New partner'"
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
                    <label for="registered_name" class="mb-2 block font-medium">Registered name</label>
                    <InputText
                        id="registered_name"
                        v-model="form.registered_name"
                        :invalid="!!form.errors.registered_name"
                        fluid
                    />
                    <small v-if="form.errors.registered_name" class="text-red-500">{{ form.errors.registered_name }}</small>
                </div>

                <div class="col-span-12 md:col-span-8">
                    <label for="tin" class="mb-2 block font-medium">TIN</label>
                    <InputText id="tin" v-model="form.tin" placeholder="123-456-789" :invalid="!!form.errors.tin" fluid />
                    <small v-if="form.errors.tin" class="text-red-500">{{ form.errors.tin }}</small>
                </div>

                <div class="col-span-12 md:col-span-4">
                    <label for="branch_code" class="mb-2 block font-medium">Branch code</label>
                    <InputText id="branch_code" v-model="form.branch_code" placeholder="000" fluid />
                </div>

                <div class="col-span-12">
                    <label for="address" class="mb-2 block font-medium">Registered address</label>
                    <InputText id="address" v-model="form.address" fluid />
                </div>

                <div class="col-span-12 md:col-span-6">
                    <label for="email" class="mb-2 block font-medium">Email</label>
                    <InputText id="email" v-model="form.email" type="email" fluid />
                </div>

                <div class="col-span-12 md:col-span-6">
                    <label class="mb-2 block font-medium">Taxpayer type</label>
                    <Select
                        v-model="form.taxpayer_type"
                        :options="TAXPAYER_TYPES"
                        optionLabel="label"
                        optionValue="value"
                        fluid
                    />
                </div>

                <div class="col-span-12 md:col-span-6">
                    <label for="terms" class="mb-2 block font-medium">Payment terms (days)</label>
                    <InputNumber id="terms" v-model="form.payment_terms_days" :min="0" :max="365" fluid />
                </div>

                <div class="col-span-12 md:col-span-6">
                    <label for="atc" class="mb-2 block font-medium">Default ATC</label>
                    <InputText id="atc" v-model="form.default_atc_code" placeholder="WC160" fluid />
                </div>

                <div class="col-span-12">
                    <label for="sworn" class="mb-2 block font-medium">Sworn declaration valid until</label>
                    <DatePicker id="sworn" v-model="form.sworn_declaration_valid_until" dateFormat="dd M yy" showIcon fluid />
                    <small class="text-muted-color">
                        Absent or expired, the higher withholding rate applies.
                    </small>
                </div>

                <div class="col-span-12 flex flex-wrap gap-6">
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="form.is_customer" inputId="is_customer" binary />
                        <label for="is_customer">Customer</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="form.is_vendor" inputId="is_vendor" binary />
                        <label for="is_vendor">Vendor</label>
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="form.is_vat_registered" inputId="is_vat_registered" binary />
                        <label for="is_vat_registered">VAT-registered</label>
                    </div>
                </div>
            </template>
        </CRUDModal>
    </AuthenticatedLayout>
</template>
