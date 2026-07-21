<template>
    <AuthenticatedLayout title="Settings">
        <form @submit.prevent="submit" class="flex flex-col gap-6 max-w-4xl">
            <FormSection title="General" description="Business identity and contact details.">
                <CRUDField
                    v-model="form.app_name"
                    label="Application Name"
                    fieldId="app_name"
                    placeholder="Enter application name"
                    :error="form.errors.app_name"
                />

                <CRUDField
                    v-model="form.contact_email"
                    label="Contact Email"
                    fieldId="contact_email"
                    type="email"
                    placeholder="contact@example.com"
                    :error="form.errors.contact_email"
                />

                <CRUDField
                    v-model="form.contact_phone"
                    label="Contact Phone"
                    fieldId="contact_phone"
                    placeholder="+63 917 123 4567"
                    :error="form.errors.contact_phone"
                />

                <CRUDField
                    v-model="form.timezone"
                    label="Timezone"
                    fieldId="timezone"
                    component="Select"
                    :options="timezones"
                    placeholder="Select timezone"
                    :error="form.errors.timezone"
                    helpText="Books and period boundaries follow Asia/Manila (D18)."
                />

                <CRUDField
                    v-model="form.address"
                    label="Address"
                    fieldId="address"
                    component="Textarea"
                    rows="3"
                    placeholder="Enter your business address"
                    :error="form.errors.address"
                    columnClass="col-span-12"
                />
            </FormSection>

            <FormSection title="Appearance" description="Brand colors used across the app.">
                <ColorField
                    v-model="form.primary_color"
                    label="Primary Color"
                    fieldId="primary_color"
                    :error="form.errors.primary_color"
                />

                <ColorField
                    v-model="form.secondary_color"
                    label="Secondary Color"
                    fieldId="secondary_color"
                    :error="form.errors.secondary_color"
                />
            </FormSection>

            <FormSection title="Branding" description="Logo and favicon shown in the shell and on documents.">
                <div class="col-span-12 md:col-span-6">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium text-surface-900 dark:text-surface-0">Application Logo</label>
                        <FileUpload
                            mode="basic"
                            customUpload
                            accept="image/*"
                            :maxFileSize="2000000"
                            chooseLabel="Choose Logo"
                            @select="form.app_logo = $event.files[0] ?? null"
                        />
                        <small class="text-muted-color text-sm">Recommended size: 200x50px, max 2MB.</small>
                        <small v-if="form.errors.app_logo" class="text-red-500">{{ form.errors.app_logo }}</small>
                    </div>
                </div>

                <div class="col-span-12 md:col-span-6">
                    <div class="flex flex-col gap-2">
                        <label class="font-medium text-surface-900 dark:text-surface-0">Favicon</label>
                        <FileUpload
                            mode="basic"
                            customUpload
                            accept="image/*"
                            :maxFileSize="1000000"
                            chooseLabel="Choose Favicon"
                            @select="form.app_favicon = $event.files[0] ?? null"
                        />
                        <small class="text-muted-color text-sm">Recommended size: 32x32px, max 1MB.</small>
                        <small v-if="form.errors.app_favicon" class="text-red-500">{{ form.errors.app_favicon }}</small>
                    </div>
                </div>
            </FormSection>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    label="Save Settings"
                    icon="pi pi-save"
                    :loading="form.processing"
                />
            </div>
        </form>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ColorField from '@/components/ColorField.vue';
import CRUDField from '@/components/CRUDField.vue';
import FormSection from '@/components/FormSection.vue';
import { useAppToast } from '@/composables/useAppToast';
import { useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import FileUpload from 'primevue/fileupload';

const props = defineProps({
    settings: Object
});

const toast = useAppToast();

const form = useForm({
    app_name: props.settings?.app_name || '',
    contact_email: props.settings?.contact_email || '',
    contact_phone: props.settings?.contact_phone || '',
    address: props.settings?.address || '',
    timezone: props.settings?.timezone || 'Asia/Manila',
    primary_color: props.settings?.primary_color || '#3B82F6',
    secondary_color: props.settings?.secondary_color || '#10B981',
    app_logo: null,
    app_favicon: null,
});

// PH-first (D18); UTC kept for ops-only tenants.
const timezones = [
    { label: 'Asia/Manila', value: 'Asia/Manila' },
    { label: 'UTC', value: 'UTC' },
    { label: 'Asia/Singapore', value: 'Asia/Singapore' },
    { label: 'Asia/Hong_Kong', value: 'Asia/Hong_Kong' },
    { label: 'Asia/Tokyo', value: 'Asia/Tokyo' },
    { label: 'America/Los_Angeles', value: 'America/Los_Angeles' },
    { label: 'America/New_York', value: 'America/New_York' },
    { label: 'Europe/London', value: 'Europe/London' },
];

const submit = () => {
    // File uploads require multipart POST — PUT bodies don't carry files
    // through PHP, so spoof the method.
    form.transform((data) => ({ ...data, _method: 'put' })).post('/settings', {
        preserveScroll: true,
        onSuccess: () => toast.success('Settings saved'),
    });
};
</script>
