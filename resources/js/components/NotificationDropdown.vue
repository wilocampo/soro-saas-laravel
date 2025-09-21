<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { useLayout } from '@/layout/composables/layout';
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';

const { isDarkTheme } = useLayout();

// Props
const props = defineProps({
    notifications: {
        type: Array,
        default: () => [
            {
                id: 1,
                title: 'New message received',
                message: 'You have a new message from John Doe',
                time: '2 minutes ago',
                read: false,
                type: 'message'
            },
            {
                id: 2,
                title: 'System update',
                message: 'The system will be updated tonight at 2 AM',
                time: '1 hour ago',
                read: false,
                type: 'system'
            },
            {
                id: 3,
                title: 'Payment received',
                message: 'Payment of $299.00 has been received',
                time: '3 hours ago',
                read: true,
                type: 'payment'
            },
            {
                id: 4,
                title: 'New user registered',
                message: 'A new user has registered on your platform',
                time: '1 day ago',
                read: true,
                type: 'user'
            }
        ]
    }
});

// Emits
const emit = defineEmits(['mark-all-read', 'notification-clicked']);

// State
const showDropdown = ref(false);
const dropdownRef = ref(null);

// Computed properties
const unreadCount = computed(() => {
    return props.notifications.filter(notification => !notification.read).length;
});

const hasUnreadNotifications = computed(() => {
    return unreadCount.value > 0;
});

// Methods
const toggleDropdown = () => {
    showDropdown.value = !showDropdown.value;
};

const closeDropdown = () => {
    showDropdown.value = false;
};

const handleNotificationClick = (notification) => {
    closeDropdown();
    emit('notification-clicked', notification);
    
    // Mark as read if not already read
    if (!notification.read) {
        // You can emit an event to parent to handle marking as read
        emit('mark-as-read', notification.id);
    }
};

const handleMarkAllRead = () => {
    emit('mark-all-read');
    closeDropdown();
};

const handleViewAll = () => {
    closeDropdown();
    router.visit('/notifications');
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

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        const isNotificationAction = event.target.closest('.layout-topbar-action');
        if (!isNotificationAction || !event.target.closest('[data-notification-dropdown]')) {
            closeDropdown();
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
    <div class="relative">
        <button
            @click="toggleDropdown"
            type="button"
            class="layout-topbar-action relative"
            data-notification-dropdown
        >
            <i class="pi pi-bell"></i>
            <!-- Unread notification badge -->
            <span 
                v-if="hasUnreadNotifications"
                class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-medium"
            >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
            </span>
        </button>
        
        <div 
            v-if="showDropdown" 
            ref="dropdownRef"
            class="notification-dropdown absolute top-[3.25rem] right-0 w-80 p-2 border rounded-lg origin-top shadow-[0px_3px_5px_rgba(0,0,0,0.02),0px_0px_2px_rgba(0,0,0,0.05),0px_1px_4px_rgba(0,0,0,0.08)] z-50 backdrop-blur-sm" 
            :class="isDarkTheme ? 'bg-gray-900 border-gray-700' : 'bg-white border-gray-200'"
        >
            <div class="flex flex-col">
                <!-- Header -->
                <div class="flex items-center justify-between px-3 py-2 border-b" :class="isDarkTheme ? 'border-gray-700' : 'border-gray-200'">
                    <h3 class="text-sm font-semibold" :class="isDarkTheme ? 'text-white' : 'text-gray-900'">
                        Notifications
                    </h3>
                    <Button
                        v-if="hasUnreadNotifications"
                        @click="handleMarkAllRead"
                        label="Mark all read"
                        size="small"
                        severity="secondary"
                        text
                        class="text-xs"
                    />
                </div>
                
                <!-- Notifications List -->
                <div class="max-h-80 overflow-y-auto">
                    <div v-if="notifications.length === 0" class="px-3 py-4 text-center">
                        <i class="pi pi-bell-slash text-2xl mb-2" :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'"></i>
                        <p class="text-sm" :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">No notifications</p>
                    </div>
                    
                    <div v-else class="py-1">
                        <div 
                            v-for="notification in notifications" 
                            :key="notification.id"
                            @click="handleNotificationClick(notification)"
                            class="notification-item px-3 py-2 cursor-pointer transition-colors duration-150"
                            :class="[
                                // Hover styles for both light and dark modes
                                isDarkTheme 
                                    ? 'hover:bg-gray-700 hover:text-white' 
                                    : 'hover:bg-gray-100 hover:text-gray-900',
                                // Background for unread notifications
                                !notification.read 
                                    ? isDarkTheme 
                                        ? 'bg-blue-900/20 hover:bg-blue-800/30' 
                                        : 'bg-blue-50 hover:bg-blue-100'
                                    : isDarkTheme
                                        ? 'hover:bg-gray-700'
                                        : 'hover:bg-gray-100',
                                'border-l-4',
                                !notification.read ? 'border-blue-500' : 'border-transparent'
                            ]"
                        >
                            <div class="flex items-start space-x-3">
                                <!-- Icon -->
                                <div class="flex-shrink-0 mt-1">
                                    <i 
                                        :class="[getNotificationIcon(notification.type), getNotificationColor(notification.type)]"
                                        class="text-sm"
                                    ></i>
                                </div>
                                
                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate notification-title" :class="isDarkTheme ? 'text-white' : 'text-gray-900'">
                                        {{ notification.title }}
                                    </p>
                                    <p class="text-xs mt-1 line-clamp-2 notification-message" :class="isDarkTheme ? 'text-gray-300' : 'text-gray-600'">
                                        {{ notification.message }}
                                    </p>
                                    <p class="text-xs mt-1 notification-time" :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">
                                        {{ notification.time }}
                                    </p>
                                </div>
                                
                                <!-- Unread indicator -->
                                <div v-if="!notification.read" class="flex-shrink-0">
                                    <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div v-if="notifications.length > 0" class="px-3 py-2 border-t" :class="isDarkTheme ? 'border-gray-700' : 'border-gray-200'">
                    <Button
                        @click="handleViewAll"
                        label="View all notifications"
                        size="small"
                        severity="secondary"
                        text
                        class="w-full justify-center text-xs"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.notification-dropdown {
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.notification-item {
    transition: all 0.15s ease-in-out;
}

.notification-item:hover {
    transform: translateX(2px);
}

/* Ensure proper text color inheritance on hover */
.notification-item:hover .notification-title {
    color: inherit !important;
}

.notification-item:hover .notification-message {
    color: inherit !important;
}

.notification-item:hover .notification-time {
    color: inherit !important;
}

/* Light mode hover text colors */
.notification-item:hover .notification-title {
    @apply text-gray-900;
}

.notification-item:hover .notification-message {
    @apply text-gray-700;
}

.notification-item:hover .notification-time {
    @apply text-gray-600;
}

/* Dark mode hover text colors */
.dark .notification-item:hover .notification-title {
    @apply text-white;
}

.dark .notification-item:hover .notification-message {
    @apply text-gray-200;
}

.dark .notification-item:hover .notification-time {
    @apply text-gray-300;
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
