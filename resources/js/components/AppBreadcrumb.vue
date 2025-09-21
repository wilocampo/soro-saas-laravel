<template>
    <Breadcrumb :home="home" :model="items">
        <template #item="{ item, props }">
            <Link 
                v-if="item.url" 
                :href="item.url" 
                v-bind="props.action"
                :class="item.icon"
                class="cursor-pointer"
            >
                <span class="text-surface-700 dark:text-surface-0">{{ item.label }}</span>
            </Link>
            <span 
                v-else
                class="text-surface-700 dark:text-surface-0"
            >
                {{ item.label }}
            </span>
        </template>
    </Breadcrumb>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Breadcrumb from 'primevue/breadcrumb';

const props = defineProps({
    items: {
        type: Array,
        required: true,
        default: () => []
    },
    homeHref: {
        type: String,
        default: '/dashboard'
    }
});

const home = computed(() => ({
    icon: 'pi pi-home',
    // label: ' ',
    url: props.homeHref
}));
</script>

<style scoped>
/* Use PrimeVue's built-in styling */
</style>

