<template>
    <AuthenticatedLayout title="Create Tenant">
        <div class="max-w-2xl">
            <CRUDForm
                title="Create New Tenant"
                backLabel="Back to Tenants"
                submitLabel="Create Tenant"
                submitIcon="pi pi-plus"
                :loading="form.processing"
                @submit="submit"
                @cancel="cancel"
            >
                <template #fields>
                    <CRUDField
                        v-model="form.name"
                        label="Tenant Name"
                        fieldId="name"
                        placeholder="Enter tenant name"
                        :error="form.errors.name"
                        columnClass="col-span-12"
                        required
                    />

                    <CRUDField
                        v-model="form.subdomain"
                        label="Subdomain"
                        fieldId="subdomain"
                        placeholder="company"
                        :error="form.errors.subdomain"
                        helpText="Only letters, numbers, and hyphens allowed"
                        required
                    />

                    <CRUDField
                        v-model="form.domain"
                        label="Domain"
                        fieldId="domain"
                        placeholder="example.com"
                        :error="form.errors.domain"
                        required
                    />

                    <div class="col-span-12">
                        <div class="flex items-center gap-2">
                            <Checkbox v-model="form.is_active" inputId="is_active" :binary="true" />
                            <label for="is_active" class="font-medium text-surface-900 dark:text-surface-0">
                                Active (tenant can be accessed)
                            </label>
                        </div>
                    </div>

                    <!-- Initial owner account (seeded into the tenant database) -->
                    <div class="col-span-12 pt-4 border-t border-surface-200 dark:border-surface-700">
                        <h3 class="text-sm font-semibold text-surface-700 dark:text-surface-200 m-0">Initial owner account</h3>
                    </div>

                    <CRUDField
                        v-model="form.admin_name"
                        label="Owner name"
                        fieldId="admin_name"
                        :error="form.errors.admin_name"
                        columnClass="col-span-12"
                        required
                    />

                    <CRUDField
                        v-model="form.admin_email"
                        label="Owner email"
                        fieldId="admin_email"
                        type="email"
                        :error="form.errors.admin_email"
                        required
                    />

                    <CRUDField
                        v-model="form.admin_password"
                        label="Owner password"
                        fieldId="admin_password"
                        component="Password"
                        :feedback="false"
                        :error="form.errors.admin_password"
                        required
                    />
                </template>
            </CRUDForm>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CRUDField from '@/components/CRUDField.vue';
import CRUDForm from '@/components/CRUDForm.vue';
import { router, useForm } from '@inertiajs/vue3';
import Checkbox from 'primevue/checkbox';

const form = useForm({
    name: '',
    subdomain: '',
    domain: 'soro.local',
    is_active: true,
    admin_name: '',
    admin_email: '',
    admin_password: '',
});

const submit = () => {
    form.post('/tenants');
};

const cancel = () => {
    router.visit('/tenants');
};
</script>
