<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import FormSection from '@/components/FormSection.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useAppToast } from '@/composables/useAppToast';
import { router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import DatePicker from 'primevue/datepicker';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tag from 'primevue/tag';

const props = defineProps({
    checklist: { type: Object, required: true },
    profile: { type: Object, required: true },
    settings: { type: Object, default: null },
    placeholderTin: { type: String, required: true },
});

const toast = useAppToast();

const CLASSIFICATIONS = [
    { label: 'Micro', value: 'micro' },
    { label: 'Small', value: 'small' },
    { label: 'Medium', value: 'medium' },
    { label: 'Large', value: 'large' },
];

const MODES = [
    { label: 'Computerized Accounting System (CAS)', value: 'cas' },
    { label: 'Computerized Books of Accounts (CBA)', value: 'cba' },
    { label: 'Loose-leaf', value: 'loose_leaf' },
    { label: 'Manual', value: 'manual' },
];

const form = useForm({
    registered_name: props.profile.registered_name ?? '',
    registered_address: props.profile.registered_address ?? '',
    tin: props.profile.tin === props.placeholderTin ? '' : (props.profile.tin ?? ''),
    branch_code: props.profile.branch_code ?? '000',
    taxpayer_classification: props.profile.taxpayer_classification ?? null,
    is_twa: !!props.profile.is_twa,
    twa_effective_date: props.profile.twa_effective_date ? new Date(props.profile.twa_effective_date) : null,
    accn: props.profile.accn ?? '',
    accn_issued_at: props.profile.accn_issued_at ? new Date(props.profile.accn_issued_at) : null,
    registration_mode: props.profile.registration_mode ?? 'cas',
    npc_registered: !!props.profile.npc_registered,
    npc_registration_number: props.profile.npc_registration_number ?? '',
    dpo_name: props.profile.dpo_name ?? '',
    dpo_email: props.profile.dpo_email ?? '',
});

const asDate = (value) =>
    value instanceof Date
        ? `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
        : value;

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            twa_effective_date: asDate(data.twa_effective_date),
            accn_issued_at: asDate(data.accn_issued_at),
        }))
        .put(route('onboarding.update'), {
            onError: (errors) => toast.error('Not saved', Object.values(errors)[0]),
        });
};

const goLive = () =>
    router.post(route('onboarding.go-live'), {}, {
        onError: (errors) => toast.error('Not yet', Object.values(errors)[0]),
    });
</script>

<template>
    <AuthenticatedLayout title="Setup">
        <template #header>
            <AppPageHeader title="Setup and go-live" subtitle="What BIR and the NPC need before you issue real documents">
                <template #actions>
                    <Tag
                        v-if="profile.go_live_at"
                        value="Live"
                        severity="success"
                    />
                    <Button
                        v-else
                        label="Declare go-live"
                        icon="pi pi-verified"
                        :disabled="!checklist.ready"
                        @click="goLive"
                    />
                </template>
            </AppPageHeader>
        </template>

        <!--
            Advisory, not a hard block: we are not the tenant's compliance
            officer, and a system that silently refuses to work is worse
            than one that says clearly what is missing.
        -->
        <Message v-if="checklist.blocking.length" severity="warn" class="mb-4" :closable="false">
            <p class="m-0 font-semibold">{{ checklist.blocking.length }} item(s) outstanding before go-live</p>
            <ul class="mt-2 mb-0 list-disc pl-5">
                <li v-for="item in checklist.blocking" :key="item.key">
                    <strong>{{ item.title }}</strong> — {{ item.detail }}
                </li>
            </ul>
        </Message>

        <Message v-else-if="!profile.go_live_at" severity="success" class="mb-4" :closable="false">
            Everything required is in place. Declaring go-live records who took responsibility, with a timestamp,
            in the audit log.
        </Message>

        <div class="card">
            <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Checklist</h2>
            <ul class="list-none p-0">
                <li
                    v-for="item in checklist.items"
                    :key="item.key"
                    class="flex items-start gap-3 border-b border-surface-100 py-3 last:border-0 dark:border-surface-800"
                >
                    <i
                        :class="item.done ? 'pi pi-check-circle text-green-500' : 'pi pi-circle text-surface-400'"
                        class="mt-1"
                    />
                    <div>
                        <div class="font-medium text-surface-900 dark:text-surface-0">
                            {{ item.title }}
                            <Tag v-if="item.blocking" value="Required" severity="danger" class="ml-2 text-xs" />
                        </div>
                        <div class="text-sm text-muted-color">{{ item.detail }}</div>
                    </div>
                </li>
            </ul>
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <FormSection
                title="BIR registration"
                description="Printed on every invoice and on the header of every book and report."
            >
                <div class="col-span-12 md:col-span-8">
                    <label for="registered_name" class="mb-2 block font-medium">Registered name</label>
                    <InputText
                        id="registered_name"
                        v-model="form.registered_name"
                        :invalid="!!form.errors.registered_name"
                        fluid
                    />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label class="mb-2 block font-medium">Taxpayer classification</label>
                    <Select
                        v-model="form.taxpayer_classification"
                        :options="CLASSIFICATIONS"
                        optionLabel="label"
                        optionValue="value"
                        showClear
                        fluid
                    />
                </div>
                <div class="col-span-12">
                    <label for="address" class="mb-2 block font-medium">Registered business address</label>
                    <InputText id="address" v-model="form.registered_address" :invalid="!!form.errors.registered_address" fluid />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label for="tin" class="mb-2 block font-medium">TIN</label>
                    <InputText id="tin" v-model="form.tin" placeholder="123-456-789" :invalid="!!form.errors.tin" fluid />
                    <small v-if="form.errors.tin" class="text-red-500">{{ form.errors.tin }}</small>
                </div>
                <div class="col-span-12 md:col-span-2">
                    <label for="branch" class="mb-2 block font-medium">Branch code</label>
                    <InputText id="branch" v-model="form.branch_code" placeholder="000" fluid />
                </div>
                <div class="col-span-12 md:col-span-6">
                    <label class="mb-2 block font-medium">Registration mode</label>
                    <Select v-model="form.registration_mode" :options="MODES" optionLabel="label" optionValue="value" fluid />
                </div>

                <div class="col-span-12 flex flex-wrap items-end gap-6">
                    <div class="flex items-center gap-2">
                        <Checkbox v-model="form.is_twa" inputId="is_twa" binary />
                        <label for="is_twa">Top Withholding Agent</label>
                    </div>
                    <div v-if="form.is_twa">
                        <label for="twa_date" class="mb-2 block text-sm font-medium">TWA effective date</label>
                        <DatePicker id="twa_date" v-model="form.twa_effective_date" dateFormat="dd M yy" showIcon />
                    </div>
                </div>
            </FormSection>

            <FormSection
                title="Acknowledgement Certificate"
                description="A CAS must be registered with BIR before it issues documents (RMC 5-2021, RMO 9-2021). We stamp the ACCN on generated output — we cannot verify it with BIR for you."
            >
                <div class="col-span-12 md:col-span-6">
                    <label for="accn" class="mb-2 block font-medium">ACCN</label>
                    <InputText id="accn" v-model="form.accn" placeholder="AC-2026-000123" fluid />
                </div>
                <div class="col-span-12 md:col-span-6">
                    <label for="accn_date" class="mb-2 block font-medium">Issued on</label>
                    <DatePicker id="accn_date" v-model="form.accn_issued_at" dateFormat="dd M yy" showIcon fluid />
                    <small v-if="form.errors.accn_issued_at" class="text-red-500">{{ form.errors.accn_issued_at }}</small>
                </div>
            </FormSection>

            <FormSection
                title="Data Privacy Act"
                description="This system holds counterparty TINs, addresses, 2307 certificates and alphalist data. Your NPC registration and Data Protection Officer are yours to maintain; we record them (RA 10173)."
            >
                <div class="col-span-12 flex items-center gap-2">
                    <Checkbox v-model="form.npc_registered" inputId="npc" binary />
                    <label for="npc">We are registered with the National Privacy Commission</label>
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label for="npc_no" class="mb-2 block font-medium">NPC registration number</label>
                    <InputText id="npc_no" v-model="form.npc_registration_number" fluid />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label for="dpo" class="mb-2 block font-medium">Data Protection Officer</label>
                    <InputText id="dpo" v-model="form.dpo_name" fluid />
                </div>
                <div class="col-span-12 md:col-span-4">
                    <label for="dpo_email" class="mb-2 block font-medium">DPO email</label>
                    <InputText id="dpo_email" v-model="form.dpo_email" type="email" fluid />
                </div>

                <template #footer>
                    <Button type="submit" label="Save registration details" icon="pi pi-check" :loading="form.processing" />
                </template>
            </FormSection>
        </form>
    </AuthenticatedLayout>
</template>
