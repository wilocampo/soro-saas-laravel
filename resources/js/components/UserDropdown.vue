<script setup>
import { router, usePage } from '@inertiajs/vue3';
import Menu from 'primevue/menu';
import { computed, ref } from 'vue';

/**
 * Topbar user menu (spec 11 §3 UserMenu): PrimeVue Menu popup + Avatar
 * with token styling — no hex palette, no isDark ternaries.
 */
const page = usePage();

const user = computed(() => page.props.auth?.user ?? {});

const initials = computed(() =>
    (user.value.name ?? '')
        .split(' ')
        .map((word) => word.charAt(0))
        .join('')
        .toUpperCase()
        .substring(0, 2) || '?'
);

const menu = ref();

const items = [
    { label: 'Profile', icon: 'pi pi-user', command: () => router.visit('/profile') },
    { label: 'Settings', icon: 'pi pi-cog', command: () => router.visit('/settings') },
    { separator: true },
    { label: 'Logout', icon: 'pi pi-sign-out', command: () => router.post(route('logout')) }
];

const toggle = (event) => menu.value.toggle(event);
</script>

<template>
    <button type="button" class="layout-topbar-action flex items-center gap-2" @click="toggle">
        <!--
          A fixed square with rounded-full is a guaranteed circle. PrimeVue's
          Avatar had no fixed width and no shrink-0, so the topbar flex row
          squashed it into an oval. Token colours (bg-primary / text-primary-
          contrast) keep it theme-aware with no hardcoded hex.
        -->
        <span
            class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-contrast"
            aria-hidden="true"
        >
            <span v-if="initials !== '?'">{{ initials }}</span>
            <i v-else class="pi pi-user"></i>
        </span>
        <span class="hidden lg:block font-medium">{{ user.name }}</span>
        <i class="pi pi-angle-down hidden lg:block text-muted-color"></i>
    </button>

    <Menu ref="menu" :model="items" popup>
        <template #start>
            <div class="px-3 py-2 border-b border-surface-200 dark:border-surface-700 mb-1">
                <p class="text-sm font-medium text-surface-900 dark:text-surface-0 m-0 truncate">{{ user.name }}</p>
                <p class="text-xs text-muted-color m-0 truncate">{{ user.email }}</p>
            </div>
        </template>
    </Menu>
</template>
