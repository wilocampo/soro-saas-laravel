<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useLayout } from '@/layout/composables/layout';
import { router } from '@inertiajs/vue3';

const { isDarkTheme } = useLayout();

// Props
const props = defineProps({
    user: {
        type: Object,
        required: true,
        default: () => ({
            name: 'User',
            email: 'user@example.com',
            avatar: null
        })
    },
    menuItems: {
        type: Array,
        default: () => [
            { label: 'Profile', icon: 'pi pi-user', action: 'profile', href: '/profile' },
            { label: 'Settings', icon: 'pi pi-cog', action: 'settings', href: '/settings' },
            { label: 'Logout', icon: 'pi pi-sign-out', action: 'logout', href: '/logout', danger: true }
        ]
    }
});

// Emits
const emit = defineEmits(['menu-item-clicked']);

// Computed properties
const userInitials = computed(() => {
    if (!props.user.name) return 'U';
    return props.user.name
        .split(' ')
        .map(word => word.charAt(0))
        .join('')
        .toUpperCase()
        .slice(0, 2);
});

const avatarBackgroundColor = computed(() => {
    // Generate a consistent color based on user name
    if (!props.user.name) return '#6366f1';
    
    const colors = [
        '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16',
        '#22c55e', '#10b981', '#14b8a6', '#06b6d4', '#0ea5e9',
        '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef',
        '#ec4899', '#f43f5e', '#64748b', '#6b7280', '#374151'
    ];
    
    let hash = 0;
    for (let i = 0; i < props.user.name.length; i++) {
        hash = props.user.name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return colors[Math.abs(hash) % colors.length];
});

// State
const showDropdown = ref(false);
const dropdownRef = ref(null);

// Methods
const toggleDropdown = () => {
    showDropdown.value = !showDropdown.value;
};

const closeDropdown = () => {
    showDropdown.value = false;
};

const handleMenuItemClick = (item) => {
    closeDropdown();
    emit('menu-item-clicked', item);
    
    // Default navigation if no custom handler
    if (item.href) {
        if (item.method === 'POST') {
            router.post(item.href);
        } else {
            router.visit(item.href);
        }
    }
};

const handleClickOutside = (event) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target)) {
        const isUserAction = event.target.closest('.layout-topbar-action');
        if (!isUserAction || !event.target.closest('[data-user-dropdown]')) {
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
            class="layout-topbar-action"
            data-user-dropdown
        >
            <i class="pi pi-user"></i>
        </button>
        
        <div 
            v-if="showDropdown" 
            ref="dropdownRef"
            class="user-dropdown absolute top-[3.25rem] right-0 w-48 p-2 border rounded-lg origin-top shadow-[0px_3px_5px_rgba(0,0,0,0.02),0px_0px_2px_rgba(0,0,0,0.05),0px_1px_4px_rgba(0,0,0,0.08)] z-50 backdrop-blur-sm" 
            :class="isDarkTheme ? 'bg-gray-900 border-gray-700' : 'bg-white border-gray-200'"
        >
            <div class="flex flex-col">
                <!-- User Info Section -->
                <div class="px-3 py-2 border-b" :class="isDarkTheme ? 'border-gray-700' : 'border-gray-200'">
                    <div class="flex items-center space-x-3">
                        <!-- Avatar -->
                        <div 
                            class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-semibold"
                            :style="{ backgroundColor: avatarBackgroundColor }"
                        >
                            <img 
                                v-if="user.avatar" 
                                :src="user.avatar" 
                                :alt="user.name"
                                class="w-8 h-8 rounded-full object-cover"
                            >
                            <span v-else>{{ userInitials }}</span>
                        </div>
                        <!-- User Details -->
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate" :class="isDarkTheme ? 'text-white' : 'text-gray-900'">
                                {{ user.name }}
                            </p>
                            <p class="text-xs truncate" :class="isDarkTheme ? 'text-gray-400' : 'text-gray-500'">
                                {{ user.email }}
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Menu Items -->
                <div class="py-1">
                    <button 
                        v-for="item in menuItems" 
                        :key="item.action"
                        @click="handleMenuItemClick(item)"
                        type="button" 
                        class="user-menu-item w-full flex items-center px-3 py-2 text-sm rounded transition-colors duration-150"
                        :class="[
                            item.danger 
                                ? 'text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20' 
                                : isDarkTheme 
                                    ? 'text-gray-300 hover:bg-gray-700 hover:text-white' 
                                    : 'text-gray-700 hover:bg-gray-100 hover:text-gray-900'
                        ]"
                    >
                        <i :class="[item.icon, 'mr-2']"></i>
                        {{ item.label }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.user-dropdown {
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

.user-menu-item {
    transition: all 0.15s ease-in-out;
}

.user-menu-item:hover {
    transform: translateX(2px);
}

/* Ensure proper contrast on hover */
.user-menu-item:not(.text-red-600):not(.dark\\:text-red-400):hover {
    color: inherit !important;
}

/* Dark mode hover improvements */
.dark .user-menu-item:not(.text-red-600):not(.dark\\:text-red-400):hover {
    color: white !important;
}
</style>
