<template>
    <AuthenticatedLayout>
        <div class="p-4 px-6 mb-6 bg-surface-card border-bottom-1 border-surface-border md:p-3 md:px-4">
            <div class="flex justify-between items-center md:flex-column md:items-start md:gap-3">
                <div class="page-title">
                    <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0 m-0">Dashboard</h1>
                </div>
                <div class="breadcrumb-nav md:w-full">
                    <AppBreadcrumb :items="breadcrumbItems" />
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-12 gap-8">
            <StatsWidget />

            <div class="col-span-12 xl:col-span-6">
                <RecentSalesWidget />
                <BestSellingWidget />
            </div>
            <div class="col-span-12 xl:col-span-6">
                <RevenueStreamWidget />
                <NotificationsWidget />
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppBreadcrumb from '@/components/AppBreadcrumb.vue';
import { usePageTitle } from '@/composables/usePageTitle';
import BestSellingWidget from '@/components/dashboard/BestSellingWidget.vue';
import NotificationsWidget from '@/components/dashboard/NotificationsWidget.vue';
import RecentSalesWidget from '@/components/dashboard/RecentSalesWidget.vue';
import RevenueStreamWidget from '@/components/dashboard/RevenueStreamWidget.vue';
import StatsWidget from '@/components/dashboard/StatsWidget.vue';

const page = usePage();
const { pageTitle } = usePageTitle();

// Breadcrumb items
const breadcrumbItems = computed(() => {
    const currentPath = page.url;
    const pathSegments = currentPath.split('/').filter(segment => segment);
    
    const breadcrumbs = [];
    let currentPathAccumulator = '';
    
    pathSegments.forEach((segment, index) => {
        currentPathAccumulator += `/${segment}`;
        
        // Convert segment to readable label
        let label = segment.charAt(0).toUpperCase() + segment.slice(1);
        
        // Handle special cases
        if (segment === 'dashboard') {
            label = 'Dashboard';
        } else if (segment === 'users') {
            label = 'Users';
        } else if (segment === 'tenants') {
            label = 'Tenants';
        } else if (segment === 'settings') {
            label = 'Settings';
        } else if (segment === 'notifications') {
            label = 'Notifications';
        } else if (segment === 'create') {
            label = 'Create';
        } else if (segment === 'edit') {
            label = 'Edit';
        } else if (segment.match(/^\d+$/)) {
            // If it's a number (ID), show it as "Details"
            label = 'Details';
        }
        
        breadcrumbs.push({
            label: label,
            href: index < pathSegments.length - 1 ? currentPathAccumulator : null
        });
    });
    
    return breadcrumbs;
});
</script>