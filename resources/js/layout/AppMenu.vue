<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import AppMenuItem from './AppMenuItem.vue';

/*
 * The sidebar is context-aware because the app is two applications behind
 * one login. Landlord routes (/tenants, /users, /settings) query the
 * landlord database; tenant routes (/onboarding, the ledger, the reports)
 * query the tenant's own database. Showing a landlord link while a tenant
 * is current produces a 500 — the table simply is not in that database.
 */
const isTenant = computed(() => usePage().props.tenancy?.isTenant === true);

const tenantMenu = [
    {
        label: 'Home',
        items: [{ label: 'Dashboard', icon: 'pi pi-fw pi-home', to: '/dashboard' }]
    },
    {
        // First in the list on purpose: nothing may be issued for real until
        // the registration details and the ACCN are recorded.
        label: 'Get started',
        items: [{ label: 'Onboarding & BIR profile', icon: 'pi pi-fw pi-verified', to: '/onboarding' }]
    },
    {
        label: 'Sales',
        items: [
            { label: 'Invoices', icon: 'pi pi-fw pi-file-edit', to: '/invoices' },
            { label: 'Payments', icon: 'pi pi-fw pi-wallet', to: '/payments' },
            { label: 'Customers & vendors', icon: 'pi pi-fw pi-id-card', to: '/partners' }
        ]
    },
    {
        label: 'Purchases',
        items: [
            { label: 'Vendor bills', icon: 'pi pi-fw pi-receipt', to: '/bills' },
            { label: 'Goods receipts', icon: 'pi pi-fw pi-inbox', to: '/receipts' }
        ]
    },
    {
        label: 'Inventory',
        items: [
            { label: 'Items', icon: 'pi pi-fw pi-box', to: '/items' },
            { label: 'Stock counts', icon: 'pi pi-fw pi-list-check', to: '/counts' }
        ]
    },
    {
        label: 'Reports',
        items: [
            { label: 'Trial balance', icon: 'pi pi-fw pi-calculator', to: '/reports/trial-balance' },
            { label: 'Balance sheet', icon: 'pi pi-fw pi-chart-bar', to: '/reports/balance-sheet' },
            { label: 'Income statement', icon: 'pi pi-fw pi-chart-line', to: '/reports/income-statement' },
            { label: 'General ledger', icon: 'pi pi-fw pi-book', to: '/reports/general-ledger' },
            { label: 'General journal', icon: 'pi pi-fw pi-align-left', to: '/reports/journal' },
            { label: 'A/R & A/P aging', icon: 'pi pi-fw pi-clock', to: '/reports/aging' },
            { label: 'Statement of account', icon: 'pi pi-fw pi-file', to: '/reports/statement-of-account' },
            { label: 'Bank reconciliation', icon: 'pi pi-fw pi-sync', to: '/reconciliations' }
        ]
    },
    {
        label: 'Administration',
        items: [
            { label: 'Company settings', icon: 'pi pi-fw pi-cog', to: '/tenant-settings' },
            { label: 'Users & roles', icon: 'pi pi-fw pi-users', to: '/tenant-users' },
            { label: 'Subscription', icon: 'pi pi-fw pi-credit-card', to: '/billing' }
        ]
    },
    {
        label: 'Account',
        items: [{ label: 'My profile', icon: 'pi pi-fw pi-user', to: '/profile' }]
    }
];

const landlordMenu = [
    {
        label: 'Home',
        items: [{ label: 'Dashboard', icon: 'pi pi-fw pi-home', to: '/dashboard' }]
    },
    {
        label: 'SaaS management',
        items: [
            { label: 'Tenants', icon: 'pi pi-fw pi-building', to: '/tenants' },
            { label: 'Users', icon: 'pi pi-fw pi-users', to: '/users' },
            { label: 'Settings', icon: 'pi pi-fw pi-cog', to: '/settings' }
        ]
    },
    {
        label: 'Account',
        items: [{ label: 'My profile', icon: 'pi pi-fw pi-user', to: '/profile' }]
    }
];

const model = computed(() => (isTenant.value ? tenantMenu : landlordMenu));
</script>

<template>
    <ul class="layout-menu">
        <template v-for="(item, i) in model" :key="item.label">
            <app-menu-item v-if="!item.separator" :item="item" :index="i"></app-menu-item>
            <li v-if="item.separator" class="menu-separator"></li>
        </template>
    </ul>
</template>

<style lang="scss" scoped></style>
