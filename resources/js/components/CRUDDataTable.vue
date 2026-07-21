<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-4">
            <template #start>
                <Button
                    @click="$emit('create')"
                    label="New"
                    icon="pi pi-plus"
                    severity="success"
                    class="mr-2"
                />
                <Button
                    v-if="showSelection"
                    @click="deleteSelected"
                    label="Delete"
                    icon="pi pi-trash"
                    severity="danger"
                    outlined
                    :disabled="!selection || selection.length === 0"
                />
                <slot name="toolbar-actions"></slot>
            </template>
            <template #end>
                <slot name="toolbar-end">
                    <Button
                        @click="$emit('export')"
                        label="Export"
                        icon="pi pi-upload"
                        severity="secondary"
                        outlined
                    />
                </slot>
            </template>
        </Toolbar>

        <!-- Data Table -->
        <DataTable
            ref="dt"
            :value="data"
            v-model:selection="selection"
            v-model:filters="filters"
            :paginator="true"
            :rows="rows"
            :rowsPerPageOptions="rowsPerPageOptions"
            paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
            :currentPageReportTemplate="currentPageReportTemplate"
            :globalFilterFields="globalFilterFields"
            :loading="loading"
            class="p-datatable-sm"
            :dataKey="dataKey"
        >
            <template #header>
                <div class="flex flex-wrap gap-2 items-center justify-between">
                    <h4 class="m-0">{{ title }}</h4>
                    <IconField>
                        <InputIcon class="pi pi-search" />
                        <InputText
                            v-model="filters.global.value"
                            placeholder="Search..."
                        />
                    </IconField>
                </div>
            </template>

            <template #empty>
                <slot name="empty">
                    <EmptyState title="No records found" hint="Try adjusting the search, or create a new record." />
                </slot>
            </template>

            <Column
                v-if="showSelection"
                selectionMode="multiple"
                headerStyle="width: 3rem"
                :exportable="false"
            ></Column>

            <slot name="columns"></slot>
        </DataTable>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { FilterMatchMode } from '@primevue/core/api';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Toolbar from 'primevue/toolbar';
import EmptyState from '@/components/EmptyState.vue';

defineProps({
    data: {
        type: Array,
        required: true
    },
    title: {
        type: String,
        default: 'Manage Items'
    },
    loading: {
        type: Boolean,
        default: false
    },
    rows: {
        type: Number,
        default: 10
    },
    rowsPerPageOptions: {
        type: Array,
        default: () => [5, 10, 20, 50]
    },
    currentPageReportTemplate: {
        type: String,
        default: 'Showing {first} to {last} of {totalRecords} items'
    },
    globalFilterFields: {
        type: Array,
        default: () => []
    },
    dataKey: {
        type: String,
        default: 'id'
    },
    showSelection: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['create', 'export', 'delete-selected']);

// Parent may bind v-model:selection; unbound usage keeps local state.
const selection = defineModel('selection', { default: () => [] });

const filters = ref({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS }
});

const dt = ref();

const deleteSelected = () => {
    if (selection.value && selection.value.length > 0) {
        emit('delete-selected', selection.value);
    }
};

// PrimeVue DataTable CSV export (respects :exportable on columns).
defineExpose({
    exportCSV: () => dt.value.exportCSV()
});
</script>
