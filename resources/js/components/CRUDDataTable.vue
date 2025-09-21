<template>
    <div class="card">
        <!-- Toolbar -->
        <Toolbar class="mb-4">
            <template #start>
                <Button
                    @click="$emit('create')"
                    label="New"
                    icon="pi pi-plus"
                    class="p-button-success mr-2"
                />
                <Button
                    @click="deleteSelected"
                    label="Delete"
                    icon="pi pi-trash"
                    class="p-button-danger"
                    :disabled="!selectedItems || selectedItems.length === 0"
                />
            </template>
            <template #end>
                <Button
                    @click="$emit('export')"
                    label="Export"
                    icon="pi pi-upload"
                    class="p-button-help"
                />
            </template>
        </Toolbar>

        <!-- Data Table -->
        <DataTable
            ref="dt"
            :value="data"
            v-model:selection="selectedItems"
            :paginator="true"
            :rows="rows"
            :rowsPerPageOptions="rowsPerPageOptions"
            paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport RowsPerPageDropdown"
            :currentPageReportTemplate="currentPageReportTemplate"
            :globalFilterFields="globalFilterFields"
            :loading="loading"
            class="p-datatable-sm"
            responsiveLayout="scroll"
            :globalFilter="globalFilter"
            :dataKey="dataKey"
        >
            <template #header>
                <div class="flex flex-wrap gap-2 items-center justify-between">
                    <h4 class="m-0">{{ title }}</h4>
                    <span class="p-input-icon-left">
                        <i class="pi pi-search" />
                        <InputText
                            v-model="globalFilter"
                            placeholder="Search..."
                        />
                    </span>
                </div>
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
import { ref, computed } from 'vue';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import InputText from 'primevue/inputtext';
import Toolbar from 'primevue/toolbar';

const props = defineProps({
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

const globalFilter = ref('');
const selectedItems = ref([]);
const dt = ref();

const deleteSelected = () => {
    if (selectedItems.value && selectedItems.value.length > 0) {
        emit('delete-selected', selectedItems.value);
    }
};
</script>
