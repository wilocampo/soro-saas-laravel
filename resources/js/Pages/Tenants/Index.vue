<template>
    <AuthenticatedLayout>
        <div class="p-6">
            <div class="max-w-7xl mx-auto">
                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Tenant Management</h1>
                    <p class="text-gray-600 dark:text-gray-400">Manage all tenants in your system</p>
                </div>

                <!-- Actions -->
                <div class="mb-6 flex justify-between items-center">
                    <div class="flex space-x-4">
                        <Button
                            @click="createTenant"
                            label="Create Tenant"
                            icon="pi pi-plus"
                            severity="success"
                        />
                    </div>
                </div>

                <!-- Tenants Table -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <DataTable
                        :value="tenants.data"
                        :paginator="true"
                        :rows="10"
                        :rowsPerPageOptions="[5, 10, 20, 50]"
                        paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                        currentPageReportTemplate="Showing {first} to {last} of {totalRecords} tenants"
                        class="p-datatable-sm"
                    >
                        <Column field="name" header="Name" sortable>
                            <template #body="{ data }">
                                <div class="flex items-center space-x-3">
                                    <div class="w-10 h-10 bg-primary-100 dark:bg-primary-900 rounded-full flex items-center justify-center">
                                        <i class="pi pi-building text-primary-600 dark:text-primary-400"></i>
                                    </div>
                                    <div>
                                        <div class="font-medium text-gray-900 dark:text-white">{{ data.name }}</div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ data.subdomain }}.{{ data.domain }}</div>
                                    </div>
                                </div>
                            </template>
                        </Column>
                        
                        <Column field="users_count" header="Users" sortable>
                            <template #body="{ data }">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                    {{ data.users_count || 0 }} users
                                </span>
                            </template>
                        </Column>
                        
                        <Column field="is_active" header="Status" sortable>
                            <template #body="{ data }">
                                <span 
                                    :class="data.is_active 
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' 
                                        : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'"
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                >
                                    {{ data.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </template>
                        </Column>
                        
                        <Column field="created_at" header="Created" sortable>
                            <template #body="{ data }">
                                {{ formatDate(data.created_at) }}
                            </template>
                        </Column>
                        
                        <Column header="Actions" :exportable="false" style="min-width:8rem">
                            <template #body="{ data }">
                                <div class="flex space-x-2">
                                    <Button
                                        @click="viewTenant(data)"
                                        icon="pi pi-eye"
                                        severity="info"
                                        text
                                        rounded
                                        size="small"
                                    />
                                    <Button
                                        @click="editTenant(data)"
                                        icon="pi pi-pencil"
                                        severity="warning"
                                        text
                                        rounded
                                        size="small"
                                    />
                                    <Button
                                        @click="deleteTenant(data)"
                                        icon="pi pi-trash"
                                        severity="danger"
                                        text
                                        rounded
                                        size="small"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import { router } from '@inertiajs/vue3';

defineProps({
    tenants: Object
});

const createTenant = () => {
    router.visit('/tenants/create');
};

const viewTenant = (tenant) => {
    router.visit(`/tenants/${tenant.id}`);
};

const editTenant = (tenant) => {
    router.visit(`/tenants/${tenant.id}/edit`);
};

const deleteTenant = (tenant) => {
    if (confirm('Are you sure you want to delete this tenant?')) {
        router.delete(`/tenants/${tenant.id}`);
    }
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString();
};
</script>




