<template>
    <AuthenticatedLayout>
        <div class="grid">
            <div class="col-12">
                    <CRUDDataTable
                    :data="filteredUsers"
                    title="Manage Users"
                    :loading="loading"
                    :globalFilterFields="['name', 'email']"
                    currentPageReportTemplate="Showing {first} to {last} of {totalRecords} users"
                    @create="openCreateModal"
                    @export="exportUsers"
                    @delete-selected="deleteSelectedUsers"
                >
                    <template #columns>
                        <Column field="name" header="Name" sortable class="min-w-48" bodyClass="flex align-items-center">
                            <template #body="{ data }">
                                <div class="flex align-items-center gap-2">
                                    <Avatar
                                        :label="getInitials(data.name)"
                                        :style="{ backgroundColor: getAvatarColor(data.name) }"
                                        class="text-white"
                                        shape="circle"
                                        size="normal"
                                    />
                                    <span class="font-semibold text-900">{{ data.name }}</span>
                                </div>
                            </template>
                        </Column>
                        
                        <Column field="email" header="Email" sortable class="min-w-64" bodyClass="flex align-items-center">
                            <template #body="{ data }">
                                <span class="text-600 text-sm">{{ data.email }}</span>
                            </template>
                        </Column>
                        
                        <Column field="roles" header="Roles" sortable class="min-w-40" bodyClass="flex align-items-center">
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
                        
                        <Column field="created_at" header="Created" sortable class="min-w-48" bodyClass="flex align-items-center">
                            <template #body="{ data }">
                                <span class="text-900">{{ formatDate(data.created_at) }}</span>
                            </template>
                        </Column>
                        
                        <Column header="Actions" :exportable="false" class="min-w-48" bodyClass="flex align-items-center">
                            <template #body="{ data }">
                                <div class="flex align-items-center gap-2">
                                    <Button
                                        @click="openEditModal(data)"
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

        <!-- Create User Modal -->
        <CRUDModal
            v-model:visible="createUserDialog"
            title="Add New User"
            submit-label="Create User"
            submit-icon="pi pi-plus"
            mode="create"
            :loading="form.processing"
            @submit="createUser"
            @cancel="closeCreateModal"
            @hide="closeCreateModal"
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
                    placeholder="Enter password"
                    :error="form.errors.password"
                    :feedback="false"
                    :toggleMask="true"
                    required
                />
                
                <CRUDField
                    v-model="form.password_confirmation"
                    label="Confirm Password"
                    fieldId="password_confirmation"
                    component="Password"
                    placeholder="Confirm password"
                    :error="form.errors.password_confirmation"
                    :feedback="false"
                    :toggleMask="true"
                    required
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
                    columnClass="col-12"
                />
            </template>
        </CRUDModal>

        <!-- Edit User Modal -->
        <CRUDModal
            v-model:visible="editUserDialog"
            title="Edit User"
            submit-label="Update User"
            submit-icon="pi pi-check"
            mode="edit"
            :loading="editForm.processing"
            @submit="updateUser"
            @cancel="closeEditModal"
            @hide="closeEditModal"
        >
            <template #fields>
                <CRUDField
                    v-model="editForm.name"
                    label="Full Name"
                    fieldId="edit_name"
                    placeholder="Enter full name"
                    :error="editForm.errors.name"
                    required
                />
                
                <CRUDField
                    v-model="editForm.email"
                    label="Email Address"
                    fieldId="edit_email"
                    type="email"
                    placeholder="user@example.com"
                    :error="editForm.errors.email"
                    required
                />
                
                <CRUDField
                    v-model="editForm.password"
                    label="Password"
                    fieldId="edit_password"
                    component="Password"
                    placeholder="Leave blank to keep current password"
                    :error="editForm.errors.password"
                    :feedback="false"
                    :toggleMask="true"
                    helpText="Leave blank to keep current password"
                />
                
                <CRUDField
                    v-model="editForm.password_confirmation"
                    label="Confirm Password"
                    fieldId="edit_password_confirmation"
                    component="Password"
                    placeholder="Confirm new password"
                    :error="editForm.errors.password_confirmation"
                    :feedback="false"
                    :toggleMask="true"
                />
                
                <CRUDField
                    v-model="editForm.roles"
                    label="Roles"
                    fieldId="edit_roles"
                    component="MultiSelect"
                    placeholder="Select roles"
                    :error="editForm.errors.roles"
                    :options="roles"
                    optionLabel="name"
                    optionValue="id"
                    display="chip"
                    columnClass="col-12"
                />
            </template>
        </CRUDModal>

        <!-- Delete Confirmation Dialog -->
        <Dialog
            v-model:visible="deleteUserDialog"
            class="w-112"
            header="Confirm"
            :modal="true"
        >
            <div class="flex align-items-center justify-content-center">
                <i class="pi pi-exclamation-triangle mr-3 text-2xl" />
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
import CRUDDataTable from '@/components/CRUDDataTable.vue';
import CRUDModal from '@/components/CRUDModal.vue';
import CRUDField from '@/components/CRUDField.vue';
import Button from 'primevue/button';
import Column from 'primevue/column';
import Avatar from 'primevue/avatar';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import { useForm } from '@inertiajs/vue3';
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
const selectedUsers = ref([]); // For bulk delete
const dt = ref(); // Reference to DataTable component

// Modal states
const createUserDialog = ref(false);
const editUserDialog = ref(false);
const editingUser = ref(null);

// Forms
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    roles: [],
});

const editForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    roles: [],
});


// Computed
const filteredUsers = computed(() => {
    if (!globalFilter.value) return props.users.data;
    
    return props.users.data.filter(user => 
        user.name.toLowerCase().includes(globalFilter.value.toLowerCase()) ||
        user.email.toLowerCase().includes(globalFilter.value.toLowerCase())
    );
});

// Modal methods
const openCreateModal = () => {
    form.reset();
    createUserDialog.value = true;
};

const closeCreateModal = () => {
    createUserDialog.value = false;
    form.reset();
};

const openEditModal = (user) => {
    editingUser.value = user;
    editForm.reset();
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.roles = user.roles?.map(role => role.id) || [];
    editUserDialog.value = true;
};

const closeEditModal = () => {
    editUserDialog.value = false;
    editingUser.value = null;
    editForm.reset();
};

// CRUD methods
const createUser = () => {
    form.post('/users', {
        onSuccess: () => {
            closeCreateModal();
        }
    });
};

const updateUser = () => {
    if (editingUser.value) {
        editForm.put(`/users/${editingUser.value.id}`, {
            onSuccess: () => {
                closeEditModal();
            }
        });
    }
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
