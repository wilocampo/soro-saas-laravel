<script setup>
import { useLayout } from '@/layout/composables/layout';
import AppConfigurator from './AppConfigurator.vue';
import UserDropdown from '@/components/UserDropdown.vue';
import NotificationDropdown from '@/components/NotificationDropdown.vue';
import { Link } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';

const { toggleMenu, toggleDarkMode, isDarkTheme } = useLayout();

const appName = import.meta.env.VITE_APP_NAME || 'Soro SaaS';

const showConfigurator = ref(false);
const configuratorRef = ref(null);

const toggleConfigurator = () => {
    showConfigurator.value = !showConfigurator.value;
};

const closeConfigurator = () => {
    showConfigurator.value = false;
};

const handleClickOutside = (event) => {
    // Close configurator if clicking outside
    if (configuratorRef.value && !configuratorRef.value.contains(event.target)) {
        const isTopbarAction = event.target.closest('.layout-topbar-action');
        if (!isTopbarAction || !event.target.closest('.layout-topbar-action-highlight')) {
            closeConfigurator();
        }
    }
};

onMounted(() => {
    document.addEventListener('mousedown', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('mousedown', handleClickOutside);
});
</script>

<template>
    <div class="layout-topbar">
        <div class="layout-topbar-logo-container">
            <button class="layout-menu-button layout-topbar-action" @click="toggleMenu">
                <i class="pi pi-bars"></i>
            </button>
            <Link :href="route('dashboard')" class="layout-topbar-logo">
                <i class="pi pi-book text-2xl" style="color: var(--p-primary-color)"></i>
                <span>{{ appName }}</span>
            </Link>
        </div>

        <div class="layout-topbar-actions">
            <div class="layout-config-menu">
                <button type="button" class="layout-topbar-action" @click="toggleDarkMode">
                    <i :class="['pi', { 'pi-moon': isDarkTheme, 'pi-sun': !isDarkTheme }]"></i>
                </button>
                <div class="relative">
                    <button
                        @click="toggleConfigurator"
                        type="button"
                        class="layout-topbar-action layout-topbar-action-highlight"
                    >
                        <i class="pi pi-palette"></i>
                    </button>
                    <AppConfigurator v-if="showConfigurator" @close="closeConfigurator" />
                </div>
            </div>

            <button
                class="layout-topbar-menu-button layout-topbar-action"
                v-styleclass="{ selector: '@next', enterFromClass: 'hidden', enterActiveClass: 'animate-scalein', leaveToClass: 'hidden', leaveActiveClass: 'animate-fadeout', hideOnOutsideClick: true }"
            >
                <i class="pi pi-ellipsis-v"></i>
            </button>

            <div class="layout-topbar-menu hidden lg:block">
                <div class="layout-topbar-menu-content">
                    <NotificationDropdown />
                </div>
            </div>

            <UserDropdown />
        </div>
    </div>
</template>
