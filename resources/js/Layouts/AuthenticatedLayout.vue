<script setup>
import { useLayout } from '@/layout/composables/layout';
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppFooter from '@/layout/AppFooter.vue';
import AppSidebar from '@/layout/AppSidebar.vue';
import AppTopbar from '@/layout/AppTopbar.vue';
import AppBreadcrumb from '@/components/AppBreadcrumb.vue';
import Toast from 'primevue/toast';
import { usePageTitle } from '@/composables/usePageTitle';

const { layoutConfig, layoutState, isSidebarActive } = useLayout();
const page = usePage();
const { pageTitle } = usePageTitle();

const outsideClickListener = ref(null);

watch(isSidebarActive, (newVal) => {
    if (newVal) {
        bindOutsideClickListener();
    } else {
        unbindOutsideClickListener();
    }
});

const containerClass = computed(() => {
    return {
        'layout-overlay': layoutConfig.menuMode === 'overlay',
        'layout-static': layoutConfig.menuMode === 'static',
        'layout-static-inactive': layoutState.staticMenuDesktopInactive && layoutConfig.menuMode === 'static',
        'layout-overlay-active': layoutState.overlayMenuActive,
        'layout-mobile-active': layoutState.staticMenuMobileActive
    };
});

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


function bindOutsideClickListener() {
    if (!outsideClickListener.value) {
        outsideClickListener.value = (event) => {
            if (isOutsideClicked(event)) {
                layoutState.overlayMenuActive = false;
                layoutState.staticMenuMobileActive = false;
                layoutState.menuHoverActive = false;
            }
        };
        document.addEventListener('click', outsideClickListener.value);
    }
}

function unbindOutsideClickListener() {
    if (outsideClickListener.value) {
        document.removeEventListener('click', outsideClickListener);
        outsideClickListener.value = null;
    }
}

function isOutsideClicked(event) {
    const sidebarEl = document.querySelector('.layout-sidebar');
    const topbarEl = document.querySelector('.layout-menu-button');

    return !(sidebarEl.isSameNode(event.target) || sidebarEl.contains(event.target) || topbarEl.isSameNode(event.target) || topbarEl.contains(event.target));
}
</script>

<template>
    <div class="layout-wrapper" :class="containerClass">
        <app-topbar></app-topbar>
        <app-sidebar></app-sidebar>
        <div class="layout-main-container">
            <div class="layout-main">
                <div class="py-4 px-8 mb-6 md:py-3 md:px-4 breadcrumb-container">
                    <div class="flex justify-between items-center w-full md:flex-col md:items-start md:gap-3">
                        <div class="flex-shrink-0">
                            <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0 m-0 leading-tight whitespace-nowrap md:text-xl">{{ pageTitle.replace(' - Soro SaaS', '') }}</h1>
                        </div>
                        <div class="flex-shrink-0 ml-4 md:ml-0 md:w-full">
                            <slot name="breadcrumb">
                                <AppBreadcrumb :items="breadcrumbItems" />
                            </slot>
                        </div>
                    </div>
                </div>
                <slot />
            </div>
            <app-footer></app-footer>
        </div>
        <div class="layout-mask animate-fadein"></div>
    </div>
    <Toast />
</template>

<style scoped>
.breadcrumb-container {
    background: var(--surface-card);
    border-bottom: 1px solid var(--surface-border);
}
</style>
