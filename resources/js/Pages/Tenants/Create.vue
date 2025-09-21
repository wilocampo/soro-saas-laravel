<template>
    <AuthenticatedLayout>
        <div class="p-6">
            <div class="max-w-2xl mx-auto">
                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Create New Tenant</h1>
                    <p class="text-gray-600 dark:text-gray-400">Set up a new tenant organization</p>
                </div>

                <form @submit.prevent="submit" class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Tenant Name *
                                </label>
                                <InputText
                                    v-model="form.name"
                                    placeholder="Enter tenant name"
                                    class="w-full"
                                    :class="{ 'p-invalid': form.errors.name }"
                                />
                                <small v-if="form.errors.name" class="text-red-500">{{ form.errors.name }}</small>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Subdomain *
                                </label>
                                <div class="flex">
                                    <InputText
                                        v-model="form.subdomain"
                                        placeholder="company"
                                        class="w-full rounded-r-none"
                                        :class="{ 'p-invalid': form.errors.subdomain }"
                                    />
                                    <span class="inline-flex items-center px-3 border border-l-0 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-sm rounded-r-md">
                                        .{{ form.domain }}
                                    </span>
                                </div>
                                <small v-if="form.errors.subdomain" class="text-red-500">{{ form.errors.subdomain }}</small>
                                <small class="text-gray-500 dark:text-gray-400">Only letters, numbers, and hyphens allowed</small>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Domain *
                                </label>
                                <InputText
                                    v-model="form.domain"
                                    placeholder="example.com"
                                    class="w-full"
                                    :class="{ 'p-invalid': form.errors.domain }"
                                />
                                <small v-if="form.errors.domain" class="text-red-500">{{ form.errors.domain }}</small>
                            </div>
                            
                            <div class="flex items-center">
                                <Checkbox
                                    v-model="form.is_active"
                                    inputId="is_active"
                                    :binary="true"
                                />
                                <label for="is_active" class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Active (tenant can be accessed)
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end space-x-4">
                        <Button
                            type="button"
                            label="Cancel"
                            severity="secondary"
                            @click="cancel"
                        />
                        <Button
                            type="submit"
                            label="Create Tenant"
                            icon="pi pi-plus"
                            :loading="form.processing"
                        />
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Checkbox from 'primevue/checkbox';
import { useForm } from '@inertiajs/vue3';
import { router } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    subdomain: '',
    domain: 'soro.local',
    is_active: true,
});

const submit = () => {
    form.post('/tenants');
};

const cancel = () => {
    router.visit('/tenants');
};
</script>




