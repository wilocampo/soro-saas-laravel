<template>
    <AuthenticatedLayout>
        <template #breadcrumb>
            <AppBreadcrumb :items="breadcrumbItems" />
        </template>
        
        <div class="grid">
            <div class="col-12">
                <div class="card">
                    <!-- Header -->
                    <div class="flex flex-column sm:flex-row sm:align-items-center sm:justify-between mb-6 gap-3">
                        <div class="flex align-items-center">
                            <h1 class="text-3xl font-bold text-900 m-0">Add New User</h1>
                        </div>
                        <div class="flex align-items-center gap-2">
                            <Button
                                type="button"
                                label="Back to Users"
                                icon="pi pi-arrow-left"
                                severity="secondary"
                                @click="cancel"
                            />
                        </div>
                    </div>

                    <!-- Form -->
                    <form @submit.prevent="submit" class="p-fluid">
                        <div class="grid">
                            <div class="col-12">
                                <div class="grid">
                                    <div class="col-12 md:col-6">
                                        <div class="field">
                                            <label for="name" class="font-medium text-900">Full Name *</label>
                                            <InputText
                                                id="name"
                                                v-model="form.name"
                                                placeholder="Enter full name"
                                                :class="{ 'p-invalid': form.errors.name }"
                                                class="w-full"
                                            />
                                            <small v-if="form.errors.name" class="p-error">{{ form.errors.name }}</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 md:col-6">
                                        <div class="field">
                                            <label for="email" class="font-medium text-900">Email Address *</label>
                                            <InputText
                                                id="email"
                                                v-model="form.email"
                                                type="email"
                                                placeholder="user@example.com"
                                                :class="{ 'p-invalid': form.errors.email }"
                                                class="w-full"
                                            />
                                            <small v-if="form.errors.email" class="p-error">{{ form.errors.email }}</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 md:col-6">
                                        <div class="field">
                                            <label for="password" class="font-medium text-900">Password *</label>
                                            <Password
                                                id="password"
                                                v-model="form.password"
                                                placeholder="Enter password"
                                                :class="{ 'p-invalid': form.errors.password }"
                                                :feedback="false"
                                                toggleMask
                                                class="w-full"
                                            />
                                            <small v-if="form.errors.password" class="p-error">{{ form.errors.password }}</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 md:col-6">
                                        <div class="field">
                                            <label for="password_confirmation" class="font-medium text-900">Confirm Password *</label>
                                            <Password
                                                id="password_confirmation"
                                                v-model="form.password_confirmation"
                                                placeholder="Confirm password"
                                                :class="{ 'p-invalid': form.errors.password_confirmation }"
                                                :feedback="false"
                                                toggleMask
                                                class="w-full"
                                            />
                                            <small v-if="form.errors.password_confirmation" class="p-error">{{ form.errors.password_confirmation }}</small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="field">
                                            <label for="roles" class="font-medium text-900">Roles</label>
                                            <MultiSelect
                                                id="roles"
                                                v-model="form.roles"
                                                :options="roles"
                                                optionLabel="name"
                                                optionValue="id"
                                                placeholder="Select roles"
                                                :class="{ 'p-invalid': form.errors.roles }"
                                                class="w-full"
                                                display="chip"
                                            />
                                            <small v-if="form.errors.roles" class="p-error">{{ form.errors.roles }}</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-content-end gap-2 mt-4">
                            <Button
                                type="button"
                                label="Cancel"
                                severity="secondary"
                                @click="cancel"
                                class="p-button-outlined"
                            />
                            <Button
                                type="submit"
                                label="Create User"
                                icon="pi pi-plus"
                                :loading="form.processing"
                                class="p-button-success"
                            />
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppBreadcrumb from '@/components/AppBreadcrumb.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';
import MultiSelect from 'primevue/multiselect';
import { useForm } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';

defineProps({
    roles: Array
});

// Breadcrumb items
const breadcrumbItems = computed(() => [
    { label: 'Users', url: '/users' },
    { label: 'Create' }
]);

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    roles: [],
});

const submit = () => {
    form.post('/users');
};

const cancel = () => {
    router.visit('/users');
};
</script>
