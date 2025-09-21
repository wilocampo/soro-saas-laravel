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
                            <h1 class="text-3xl font-bold text-900 m-0">User Management</h1>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <DataTable
                        :value="filteredUsers"
                        :paginator="true"
                        :rows="10"
                        :rowsPerPageOptions="[5, 10, 20, 50]"
                        paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
                        currentPageReportTemplate="Showing {first} to {last} of {totalRecords} users"
                        :globalFilterFields="['name', 'email']"
                        :loading="loading"
                        class="p-datatable-sm"
                        responsiveLayout="scroll"
                        :globalFilter="globalFilter"
                    >
                        <template #header>
                            <div class="flex flex-column sm:flex-row sm:align-items-center sm:justify-between gap-3">
                                <div class="flex align-items-center">
                                    <span class="text-900 font-semibold">Manage Users</span>
                                </div>
                                <div class="flex align-items-center gap-2">
                                    <span class="p-input-icon-left">
                                        <i class="pi pi-search" />
                                        <InputText
                                            v-model="globalFilter"
                                            placeholder="Search users..."
                                            class="w-full sm:w-20rem"
                                        />
                                    </span>
                                    <Button
                                        @click="createUser"
                                        label="Add User"
                                        icon="pi pi-plus"
                                        class="p-button-success"
                                    />
                                </div>
                            </div>
                        </template>

                        <Column field="name" header="User" sortable style="min-width: 200px">
                            <template #body="{ data }">
                                <div class="flex align-items-center gap-2">
                                    <Avatar
                                        :label="getInitials(data.name)"
                                        :style="{ backgroundColor: getAvatarColor(data.name), color: 'white' }"
                                        shape="circle"
                                        size="normal"
                                    />
                                    <div class="flex flex-column">
                                        <span class="font-semibold text-900">{{ data.name }}</span>
                                        <span class="text-600 text-sm">{{ data.email }}</span>
                                    </div>
                                </div>
                            </template>
                        </Column>
                        
                        <Column field="roles" header="Roles" sortable style="min-width: 150px">
                            <template #body="{ data }">
                                <div class="flex flex-wrap gap-1">
                                    <Tag
                                        v-for="role in data.roles"
                                        :key="role.id"
                                        :value="role.name"
                                        severity="info"
                                        class="text-xs"
                                    />
                                    <span v-if="!data.roles || data.roles.length === 0" class="text-500 text-sm">No roles</span>
                                </div>
                            </template>
                        </Column>
                        
                        <Column field="created_at" header="Created" sortable style="min-width: 120px">
                            <template #body="{ data }">
                                <span class="text-900">{{ formatDate(data.created_at) }}</span>
                            </template>
                        </Column>
                        
                        <Column header="Actions" :exportable="false" style="min-width: 120px">
                            <template #body="{ data }">
                                <div class="flex align-items-center gap-2">
                                    <Button
                                        @click="viewUser(data)"
                                        icon="pi pi-eye"
                                        severity="info"
                                        text
                                        rounded
                                        size="small"
                                        v-tooltip.top="'View'"
                                    />
                                    <Button
                                        @click="editUser(data)"
                                        icon="pi pi-pencil"
                                        severity="warning"
                                        text
                                        rounded
                                        size="small"
                                        v-tooltip.top="'Edit'"
                                    />
                                    <Button
                                        @click="confirmDeleteUser(data)"
                                        icon="pi pi-trash"
                                        severity="danger"
                                        text
                                        rounded
                                        size="small"
                                        v-tooltip.top="'Delete'"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Dialog -->
        <Dialog
            v-model:visible="deleteUserDialog"
            :style="{ width: '450px' }"
            header="Confirm"
            :modal="true"
        >
            <div class="flex align-items-center justify-content-center">
                <i class="pi pi-exclamation-triangle mr-3" style="font-size: 2rem" />
                <span v-if="selectedUser">Are you sure you want to delete <b>{{ selectedUser.name }}</b>?</span>
            </div>
            <template #footer>
                <Button
                    label="Cancel"
                    icon="pi pi-times"
                    @click="deleteUserDialog = false"
                    class="p-button-text"
                />
                <Button
                    label="Delete"
                    icon="pi pi-check"
                    @click="deleteUser"
                    class="p-button-danger"
                />
            </template>
        </Dialog>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppBreadcrumb from '@/components/AppBreadcrumb.vue';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputText from 'primevue/inputtext';
import Avatar from 'primevue/avatar';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    users: Object,
    roles: Array
});

// Reactive data
const loading = ref(false);
const globalFilter = ref('');
const deleteUserDialog = ref(false);
const selectedUser = ref(null);

// Breadcrumb items
const breadcrumbItems = computed(() => [
    { label: 'Users', url: '/users' }
]);

// Computed
const filteredUsers = computed(() => {
    if (!globalFilter.value) return props.users.data;
    
    return props.users.data.filter(user => 
        user.name.toLowerCase().includes(globalFilter.value.toLowerCase()) ||
        user.email.toLowerCase().includes(globalFilter.value.toLowerCase())
    );
});

// Methods
const createUser = () => {
    router.visit('/users/create');
};

const viewUser = (user) => {
    router.visit(`/users/${user.id}`);
};

const editUser = (user) => {
    router.visit(`/users/${user.id}/edit`);
};

const confirmDeleteUser = (user) => {
    selectedUser.value = user;
    deleteUserDialog.value = true;
};

const deleteUser = () => {
    if (selectedUser.value) {
        router.delete(`/users/${selectedUser.value.id}`, {
            onSuccess: () => {
                deleteUserDialog.value = false;
                selectedUser.value = null;
            }
        });
    }
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString();
};

const getInitials = (name) => {
    return name
        .split(' ')
        .map(word => word.charAt(0))
        .join('')
        .toUpperCase()
        .substring(0, 2);
};

const getAvatarColor = (name) => {
    const colors = [
        '#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f97316',
        '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#84cc16'
    ];
    const hash = name.split('').reduce((a, b) => {
        a = ((a << 5) - a) + b.charCodeAt(0);
        return a & a;
    }, 0);
    return colors[Math.abs(hash) % colors.length];
};
</script>
