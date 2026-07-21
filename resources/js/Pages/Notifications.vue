<template>
    <AuthenticatedLayout title="Notifications">
        <div class="max-w-4xl">
            <div class="card">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-semibold text-surface-900 dark:text-surface-0 m-0">All Notifications</h2>
                    <Button
                        label="Mark all as read"
                        severity="secondary"
                        size="small"
                        @click="markAllAsRead"
                        :disabled="!hasUnread"
                    />
                </div>

                <EmptyState
                    v-if="notifications.length === 0"
                    icon="pi pi-bell-slash"
                    title="No notifications"
                    hint="You're all caught up! Check back later for new updates."
                />

                <div v-else class="flex flex-col gap-3">
                    <button
                        v-for="notification in notifications"
                        :key="notification.id"
                        type="button"
                        @click="markAsRead(notification)"
                        class="w-full text-left p-4 rounded-lg border transition-colors border-surface-200 dark:border-surface-700"
                        :class="notification.read
                            ? 'bg-surface-50 dark:bg-surface-800 hover:bg-surface-100 dark:hover:bg-surface-700'
                            : 'bg-surface-0 dark:bg-surface-900 border-l-4 !border-l-primary hover:bg-surface-50 dark:hover:bg-surface-800'"
                    >
                        <div class="flex items-start gap-4">
                            <div class="flex-shrink-0 mt-1 w-10 h-10 rounded-full flex items-center justify-center bg-surface-100 dark:bg-surface-800">
                                <i :class="typeIcon(notification.type)" class="text-lg text-muted-color"></i>
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold text-surface-900 dark:text-surface-0 m-0">
                                        {{ notification.title }}
                                    </h3>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <Tag v-if="typeSeverity(notification.type)" :value="notification.type" :severity="typeSeverity(notification.type)" class="text-xs" />
                                        <span class="text-xs text-muted-color">{{ notification.time }}</span>
                                        <Badge v-if="!notification.read" severity="info" />
                                    </div>
                                </div>
                                <p class="text-sm text-muted-color mt-1 mb-0">
                                    {{ notification.message }}
                                </p>
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EmptyState from '@/components/EmptyState.vue';
import { router } from '@inertiajs/vue3';
import Badge from 'primevue/badge';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { computed } from 'vue';

const props = defineProps({
    notifications: { type: Array, default: () => [] }
});

const hasUnread = computed(() => props.notifications.some((n) => !n.read));

const markAsRead = (notification) => {
    if (!notification.read) {
        router.post(`/notifications/${notification.id}/read`, {}, { preserveScroll: true });
    }
};

const markAllAsRead = () => {
    router.post('/notifications/read', {}, { preserveScroll: true });
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

// Severity tokens only — no hardcoded color maps (spec 11 §2 rule 2).
const typeSeverity = (type) => {
    const severities = {
        warning: 'warn',
        error: 'danger',
        success: 'success',
        payment: 'success',
        system: 'secondary',
        info: 'info'
    };
    return severities[type] ?? null;
};
</script>
