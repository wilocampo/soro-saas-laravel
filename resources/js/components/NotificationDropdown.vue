<script setup>
import EmptyState from '@/components/EmptyState.vue';
import { router, usePage } from '@inertiajs/vue3';
import Badge from 'primevue/badge';
import Button from 'primevue/button';
import Popover from 'primevue/popover';
import { computed, ref } from 'vue';

/**
 * Topbar bell (spec 11 §3 NotificationMenu): PrimeVue Popover + Badge,
 * theme tokens only, fed by the shared `notificationsMenu` prop.
 */
const page = usePage();

const menu = computed(() => page.props.notificationsMenu ?? { unreadCount: 0, recent: [] });

const popover = ref();

const toggle = (event) => popover.value.toggle(event);

const openNotification = (notification) => {
    popover.value.hide();
    if (!notification.read) {
        router.post(`/notifications/${notification.id}/read`, {}, { preserveScroll: true });
    }
};

const markAllRead = () => {
    router.post('/notifications/read', {}, { preserveScroll: true });
};

const viewAll = () => {
    popover.value.hide();
    router.visit('/notifications');
};

const typeIcon = (type) => {
    const icons = {
        message: 'pi pi-envelope',
        system: 'pi pi-cog',
        payment: 'pi pi-credit-card',
        user: 'pi pi-user',
        warning: 'pi pi-exclamation-triangle',
        error: 'pi pi-times-circle',
        success: 'pi pi-check-circle',
        info: 'pi pi-info-circle'
    };
    return icons[type] || 'pi pi-bell';
};
</script>

<template>
    <button type="button" class="layout-topbar-action relative" @click="toggle">
        <Badge v-if="menu.unreadCount > 0" :value="menu.unreadCount" severity="danger" class="absolute -top-1 -right-1" />
        <i class="pi pi-bell"></i>
        <span>Notifications</span>
    </button>

    <Popover ref="popover" class="w-80">
        <div class="flex items-center justify-between mb-3">
            <span class="font-semibold text-surface-900 dark:text-surface-0">Notifications</span>
            <Button
                v-if="menu.unreadCount > 0"
                label="Mark all read"
                text
                size="small"
                @click="markAllRead"
            />
        </div>

        <EmptyState
            v-if="menu.recent.length === 0"
            icon="pi pi-bell-slash"
            title="No notifications"
        />

        <div v-else class="flex flex-col">
            <button
                v-for="notification in menu.recent"
                :key="notification.id"
                type="button"
                @click="openNotification(notification)"
                class="flex items-start gap-3 w-full text-left p-2 rounded-lg hover:bg-surface-100 dark:hover:bg-surface-800 transition-colors"
            >
                <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-surface-100 dark:bg-surface-800">
                    <i :class="typeIcon(notification.type)" class="text-muted-color"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-surface-900 dark:text-surface-0 truncate">{{ notification.title }}</span>
                        <Badge v-if="!notification.read" severity="info" />
                    </div>
                    <p class="text-xs text-muted-color m-0 truncate">{{ notification.message }}</p>
                    <span class="text-xs text-muted-color">{{ notification.time }}</span>
                </div>
            </button>
        </div>

        <div class="mt-3 pt-3 border-t border-surface-200 dark:border-surface-700">
            <Button label="View all notifications" text size="small" class="w-full" @click="viewAll" />
        </div>
    </Popover>
</template>
