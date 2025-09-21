<template>
    <AuthenticatedLayout>
        <div class="p-6">
            <div class="max-w-4xl mx-auto">
                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Notifications</h1>
                    <p class="text-gray-600 dark:text-gray-400">Manage your notifications and stay updated</p>
                </div>

                <!-- Notifications List -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">All Notifications</h2>
                            <Button
                                label="Mark all as read"
                                severity="secondary"
                                size="small"
                                @click="markAllAsRead"
                                :disabled="!hasUnreadNotifications"
                            />
                        </div>

                        <div v-if="notifications.length === 0" class="text-center py-12">
                            <i class="pi pi-bell-slash text-4xl text-gray-400 mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No notifications</h3>
                            <p class="text-gray-500 dark:text-gray-400">You're all caught up! Check back later for new updates.</p>
                        </div>

                        <div v-else class="space-y-4">
                            <div 
                                v-for="notification in notifications" 
                                :key="notification.id"
                                @click="markAsRead(notification.id)"
                                class="notification-item p-4 rounded-lg border cursor-pointer transition-all duration-200 hover:shadow-md"
                                :class="[
                                    // Background colors for read/unread
                                    notification.read 
                                        ? 'bg-gray-50 dark:bg-gray-700 border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-600' 
                                        : 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 hover:bg-blue-100 dark:hover:bg-blue-800/30',
                                    'border-l-4',
                                    notification.read ? 'border-l-transparent' : 'border-l-blue-500'
                                ]"
                            >
                                <div class="flex items-start space-x-4">
                                    <!-- Icon -->
                                    <div class="flex-shrink-0 mt-1">
                                        <div 
                                            class="w-10 h-10 rounded-full flex items-center justify-center"
                                            :class="getNotificationIconBg(notification.type)"
                                        >
                                            <i 
                                                :class="[getNotificationIcon(notification.type), getNotificationColor(notification.type)]"
                                                class="text-lg"
                                            ></i>
                                        </div>
                                    </div>
                                    
                                    <!-- Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between">
                                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                                                {{ notification.title }}
                                            </h3>
                                            <div class="flex items-center space-x-2">
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ notification.time }}
                                                </span>
                                                <div v-if="!notification.read" class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                            </div>
                                        </div>
                                        <p class="text-sm text-gray-600 dark:text-gray-300 mt-1">
                                            {{ notification.message }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
import { ref, computed } from 'vue';

// Sample notification data - in a real app, this would come from an API
const notifications = ref([
    {
        id: 1,
        title: 'New message received',
        message: 'You have a new message from John Doe regarding your recent order. Please check your inbox for details.',
        time: '2 minutes ago',
        read: false,
        type: 'message'
    },
    {
        id: 2,
        title: 'System maintenance scheduled',
        message: 'The system will be updated tonight at 2 AM. Please save your work before this time.',
        time: '1 hour ago',
        read: false,
        type: 'system'
    },
    {
        id: 3,
        title: 'Payment received',
        message: 'Payment of $299.00 has been successfully received for your subscription.',
        time: '3 hours ago',
        read: true,
        type: 'payment'
    },
    {
        id: 4,
        title: 'New user registered',
        message: 'A new user "Jane Smith" has registered on your platform and is awaiting approval.',
        time: '1 day ago',
        read: true,
        type: 'user'
    },
    {
        id: 5,
        title: 'Security alert',
        message: 'Unusual login activity detected from a new device. If this wasn\'t you, please secure your account.',
        time: '2 days ago',
        read: true,
        type: 'warning'
    }
]);

// Computed properties
const hasUnreadNotifications = computed(() => {
    return notifications.value.some(notification => !notification.read);
});

// Methods
const markAsRead = (notificationId) => {
    const notification = notifications.value.find(n => n.id === notificationId);
    if (notification && !notification.read) {
        notification.read = true;
    }
};

const markAllAsRead = () => {
    notifications.value.forEach(notification => {
        notification.read = true;
    });
};

const getNotificationIcon = (type) => {
    const icons = {
        message: 'pi pi-envelope',
        system: 'pi pi-cog',
        payment: 'pi pi-credit-card',
        user: 'pi pi-user',
        warning: 'pi pi-exclamation-triangle',
        info: 'pi pi-info-circle'
    };
    return icons[type] || 'pi pi-bell';
};

const getNotificationColor = (type) => {
    const colors = {
        message: 'text-blue-600',
        system: 'text-gray-600',
        payment: 'text-green-600',
        user: 'text-purple-600',
        warning: 'text-yellow-600',
        info: 'text-blue-600'
    };
    return colors[type] || 'text-gray-600';
};

const getNotificationIconBg = (type) => {
    const backgrounds = {
        message: 'bg-blue-100 dark:bg-blue-900/30',
        system: 'bg-gray-100 dark:bg-gray-700',
        payment: 'bg-green-100 dark:bg-green-900/30',
        user: 'bg-purple-100 dark:bg-purple-900/30',
        warning: 'bg-yellow-100 dark:bg-yellow-900/30',
        info: 'bg-blue-100 dark:bg-blue-900/30'
    };
    return backgrounds[type] || 'bg-gray-100 dark:bg-gray-700';
};
</script>

<style scoped>
.notification-item {
    transition: all 0.2s ease-in-out;
}

.notification-item:hover {
    transform: translateY(-1px);
}
</style>
