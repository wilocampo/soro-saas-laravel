<template>
    <AuthenticatedLayout title="Tenants">
        <CRUDDataTable
            :data="tenants.data"
            title="Manage Tenants"
            :globalFilterFields="['name', 'subdomain', 'domain']"
            currentPageReportTemplate="Showing {first} to {last} of {totalRecords} tenants"
            :showSelection="false"
            @create="createTenant"
        >
            <template #toolbar-end><span></span></template>

            <template #empty>
                <EmptyState
                    title="No tenants yet"
                    hint="Create the first tenant to provision its database and owner account."
                />
            </template>

            <template #columns>
                <Column field="name" header="Name" sortable class="min-w-64">
                    <template #body="{ data }">
                        <div class="flex items-center gap-3">
                            <Avatar icon="pi pi-building" shape="circle" />
                            <div>
                                <div class="font-medium text-surface-900 dark:text-surface-0">{{ data.name }}</div>
                                <div class="text-sm text-muted-color">{{ data.subdomain }}.{{ data.domain }}</div>
                            </div>
                        </div>
                    </template>
                </Column>

                <Column field="users_count" header="Users" sortable class="min-w-24">
                    <template #body="{ data }">
                        <Tag :value="`${data.users_count ?? 0} users`" severity="secondary" />
                    </template>
                </Column>

                <Column field="provisioning_status" header="Status" sortable class="min-w-32">
                    <template #body="{ data }">
                        <StatusTag :status="tenantStatus(data)" />
                    </template>
                </Column>

                <Column field="created_at" header="Created" sortable class="min-w-40">
                    <template #body="{ data }">
                        <span class="text-surface-900 dark:text-surface-0">{{ formatDate(data.created_at) }}</span>
                    </template>
                </Column>

                <Column header="Actions" :exportable="false" class="min-w-24">
                    <template #body="{ data }">
                        <Button
                            @click="confirmDeleteTenant(data)"
                            icon="pi pi-trash"
                            severity="danger"
                            rounded
                            outlined
                            v-tooltip.top="'Delete tenant'"
                        />
                    </template>
                </Column>
            </template>
        </CRUDDataTable>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CRUDDataTable from '@/components/CRUDDataTable.vue';
import EmptyState from '@/components/EmptyState.vue';
import StatusTag from '@/components/StatusTag.vue';
import { useDeleteConfirm } from '@/composables/useDeleteConfirm';
import { router } from '@inertiajs/vue3';
import Avatar from 'primevue/avatar';
import Button from 'primevue/button';
import Column from 'primevue/column';
import Tag from 'primevue/tag';

defineProps({
    tenants: Object
});

const confirmDelete = useDeleteConfirm();

// One status column: provisioning state wins until the tenant is active.
const tenantStatus = (tenant) => {
    if (tenant.provisioning_status && tenant.provisioning_status !== 'active') {
        return tenant.provisioning_status;
    }

    return tenant.is_active ? 'active' : 'inactive';
};

const createTenant = () => {
    router.visit('/tenants/create');
};

const confirmDeleteTenant = (tenant) => {
    confirmDelete(
        `tenant "${tenant.name}" AND its entire database`,
        () => router.delete(`/tenants/${tenant.id}`, { preserveScroll: true })
    );
};

const formatDate = (date) => {
    return new Date(date).toLocaleDateString();
};
</script>
