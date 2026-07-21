<template>
    <AuthenticatedLayout title="Edit User">
        <template #breadcrumb>
            <AppBreadcrumb :items="breadcrumbItems" />
        </template>
        
        <CRUDForm
            title="Edit User"
            backLabel="Back to Users"
            submitLabel="Update User"
            submitIcon="pi pi-check"
            :loading="form.processing"
            @submit="submit"
            @cancel="cancel"
        >
            <template #fields>
                <CRUDField
                    v-model="form.name"
                    label="Full Name"
                    fieldId="name"
                    placeholder="Enter full name"
                    :error="form.errors.name"
                    required
                />
                
                <CRUDField
                    v-model="form.email"
                    label="Email Address"
                    fieldId="email"
                    type="email"
                    placeholder="user@example.com"
                    :error="form.errors.email"
                    required
                />
                
                <CRUDField
                    v-model="form.password"
                    label="Password"
                    fieldId="password"
                    component="Password"
                    placeholder="Leave blank to keep current password"
                    :error="form.errors.password"
                    :feedback="false"
                    :toggleMask="true"
                    helpText="Leave blank to keep current password"
                />
                
                <CRUDField
                    v-model="form.password_confirmation"
                    label="Confirm Password"
                    fieldId="password_confirmation"
                    component="Password"
                    placeholder="Confirm new password"
                    :error="form.errors.password_confirmation"
                    :feedback="false"
                    :toggleMask="true"
                />
                
                <CRUDField
                    v-model="form.roles"
                    label="Roles"
                    fieldId="roles"
                    component="MultiSelect"
                    placeholder="Select roles"
                    :error="form.errors.roles"
                    :options="roles"
                    optionLabel="name"
                    optionValue="id"
                    display="chip"
                    columnClass="col-span-12"
                />
            </template>
        </CRUDForm>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppBreadcrumb from '@/components/AppBreadcrumb.vue';
import CRUDForm from '@/components/CRUDForm.vue';
import CRUDField from '@/components/CRUDField.vue';

const props = defineProps({
    user: Object,
    roles: Array
});

// Breadcrumb items
const breadcrumbItems = computed(() => [
    { label: 'Users', url: '/users' },
    { label: 'Edit' }
]);

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    roles: props.user.roles?.map(role => role.id) || [],
});

const submit = () => {
    form.put(`/users/${props.user.id}`);
};

const cancel = () => {
    router.visit('/users');
};
</script>
