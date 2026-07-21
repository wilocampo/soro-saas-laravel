<script setup>
import { useLayout } from '@/layout/composables/layout';
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppFooter from '@/layout/AppFooter.vue';
import AppSidebar from '@/layout/AppSidebar.vue';
import AppTopbar from '@/layout/AppTopbar.vue';
import AppBreadcrumb from '@/components/AppBreadcrumb.vue';
import ConfirmDialog from 'primevue/confirmdialog';
import Toast from 'primevue/toast';
import { useAppToast } from '@/composables/useAppToast';

// Pages pass an explicit title (spec 11 §2); the URL-derived fallback keeps
// unconverted pages readable. The " - Soro SaaS" suffix comes from the
// createInertiaApp title callback, never from here.
const props = defineProps({
    title: { type: String, default: null }
});

const { layoutConfig, layoutState, isSidebarActive } = useLayout();
const page = usePage();
const appToast = useAppToast();

// Flash bridge: ->with('success'|'error'|…) on Laravel redirects → toast.
const FLASH_SUMMARIES = { success: 'Success', error: 'Something went wrong', warn: 'Warning', info: 'Notice' };
watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        for (const [severity, detail] of Object.entries({ success: flash.success, error: flash.error, warn: flash.warning, info: flash.info })) {
            if (detail) appToast[severity](FLASH_SUMMARIES[severity], detail);
        }
    },
    { immediate: true, deep: true }
);

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

const humanize = (segment) => segment.charAt(0).toUpperCase() + segment.slice(1);

const pathSegments = computed(() => page.url.split('?')[0].split('/').filter(Boolean));

const segmentLabel = (segment) => (segment.match(/^\d+$/) ? 'Details' : humanize(segment));

const breadcrumbItems = computed(() => {
    let path = '';

    return pathSegments.value.map((segment, index) => {
        path += `/${segment}`;

        return {
            label: segmentLabel(segment),
            href: index < pathSegments.value.length - 1 ? path : null
        };
    });
});

const pageTitle = computed(() => {
    if (props.title) {
        return props.title;
    }

    const segments = pathSegments.value;

    if (segments.length === 0) {
        return 'Dashboard';
    }

    const last = segments[segments.length - 1];
    const parent = segments.length > 1 ? segments[segments.length - 2] : null;

    if (['create', 'edit'].includes(last) || last.match(/^\d+$/)) {
        return parent ? `${segmentLabel(last)} — ${humanize(parent)}` : segmentLabel(last);
    }

    return humanize(last);
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
    <Head :title="pageTitle" />
    <div class="layout-wrapper" :class="containerClass">
        <app-topbar></app-topbar>
        <app-sidebar></app-sidebar>
        <div class="layout-main-container">
            <div class="layout-main">
                <div class="py-4 px-8 mb-6 md:py-3 md:px-4 breadcrumb-container">
                    <div class="flex justify-between items-center w-full max-sm:flex-col max-sm:items-start max-sm:gap-3">
                        <slot name="header">
                            <div class="flex-shrink-0">
                                <h1 class="text-2xl font-bold text-surface-900 dark:text-surface-0 m-0 leading-tight whitespace-nowrap md:text-xl">{{ pageTitle }}</h1>
                            </div>
                        </slot>
                        <div class="flex-shrink-0 ml-4 max-sm:ml-0 max-sm:w-full">
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
    <ConfirmDialog />
</template>

<style scoped>
.breadcrumb-container {
    background: var(--surface-card);
    border-bottom: 1px solid var(--surface-border);
}
</style>
