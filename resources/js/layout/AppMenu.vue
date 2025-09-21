<script setup>
import { ref } from 'vue';

import AppMenuItem from './AppMenuItem.vue';

const model = ref([
    {
        label: 'Home',
        items: [{ label: 'Dashboard', icon: 'pi pi-fw pi-home', to: '/dashboard' }]
    },
    {
        label: 'SaaS Management',
        items: [
            { label: 'Users', icon: 'pi pi-fw pi-users', to: '/users' },
            { label: 'Tenants', icon: 'pi pi-fw pi-building', to: '/tenants' },
            { label: 'Roles & Permissions', icon: 'pi pi-fw pi-shield', to: '/roles' },
            { label: 'Billing', icon: 'pi pi-fw pi-credit-card', to: '/billing' },
            { label: 'Settings', icon: 'pi pi-fw pi-cog', to: '/settings' }
        ]
    },
    {
        label: 'Account',
        icon: 'pi pi-fw pi-user',
        items: [
            {
                label: 'Profile',
                icon: 'pi pi-fw pi-user',
                to: '/profile'
            },
            {
                label: 'Settings',
                icon: 'pi pi-fw pi-cog',
                to: '/profile'
            }
        ]
    }
]);
</script>

<template>
    <ul class="layout-menu">
        <template v-for="(item, i) in model" :key="item">
            <app-menu-item v-if="!item.separator" :item="item" :index="i"></app-menu-item>
            <li v-if="item.separator" class="menu-separator"></li>
        </template>
    </ul>
</template>

<style lang="scss" scoped></style>
