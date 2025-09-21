<template>
    <AuthenticatedLayout>
        <template #breadcrumb>
            <AppBreadcrumb :items="breadcrumbItems" />
        </template>
        
        <div class="grid">
            <div class="col-12">
                <CRUDDataTable
                    :data="filteredUsers"
                    title="Manage Users"
                    :loading="loading"
                    :globalFilterFields="['name', 'email']"
                    currentPageReportTemplate="Showing {first} to {last} of {totalRecords} users"
                    @create="createUser"
                    @export="exportUsers"
                    @delete-selected="deleteSelectedUsers"
                >
                    <template #columns>
                        <Column field="name" header="Name" sortable style="min-width: 16rem">
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
                        
                        <Column field="roles" header="Roles" sortable style="min-width: 10rem">
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
                        
                        <Column field="created_at" header="Created" sortable style="min-width: 12rem">
                            <template #body="{ data }">
                                <span class="text-900">{{ formatDate(data.created_at) }}</span>
                            </template>
                        </Column>
                        
                        <Column header="Actions" :exportable="false" style="min-width: 12rem">
                            <template #body="{ data }">
                                <div class="flex align-items-center gap-2">
                                    <Button
                                        @click="editUser(data)"
                                        icon="pi pi-pencil"
                                        class="p-button-icon-only p-button-rounded p-button-outlined mr-2"
                                        v-tooltip.top="'Edit'"
                                    />
                                    <Button
                                        @click="confirmDeleteUser(data)"
                                        icon="pi pi-trash"
                                        class="p-button-icon-only p-button-danger p-button-rounded p-button-outlined"
                                        v-tooltip.top="'Delete'"
                                    />
                                </div>
                            </template>
                        </Column>
                    </template>
                </CRUDDataTable>
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
import CRUDDataTable from '@/components/CRUDDataTable.vue';
import Button from 'primevue/button';
import Column from 'primevue/column';
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
const selectedUsers = ref([]);
const dt = ref();

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

const deleteSelectedUsers = () => {
    if (selectedUsers.value && selectedUsers.value.length > 0) {
        // Implement bulk delete functionality
        const userIds = selectedUsers.value.map(user => user.id);
        router.delete('/users/bulk-delete', {
            data: { ids: userIds },
            onSuccess: () => {
                selectedUsers.value = [];
            }
        });
    }
};

const exportUsers = () => {
    // Implement export functionality
    console.log('Export users functionality to be implemented');
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
