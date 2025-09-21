import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePageTitle() {
    const page = usePage();
    
    const pageTitle = computed(() => {
        const currentPath = page.url;
        const pathSegments = currentPath.split('/').filter(segment => segment);
        
        if (pathSegments.length === 0) {
            return 'Dashboard - Soro SaaS';
        }
        
        const lastSegment = pathSegments[pathSegments.length - 1];
        
        // Handle special cases for page titles
        if (lastSegment === 'dashboard') {
            return 'Dashboard - Soro SaaS';
        } else if (lastSegment === 'users') {
            return 'User Management - Soro SaaS';
        } else if (lastSegment === 'tenants') {
            return 'Tenant Management - Soro SaaS';
        } else if (lastSegment === 'settings') {
            return 'System Settings - Soro SaaS';
        } else if (lastSegment === 'notifications') {
            return 'Notifications - Soro SaaS';
        } else if (lastSegment === 'create') {
            const parentSegment = pathSegments[pathSegments.length - 2];
            if (parentSegment === 'users') {
                return 'Create User - Soro SaaS';
            } else if (parentSegment === 'tenants') {
                return 'Create Tenant - Soro SaaS';
            }
            return 'Create - Soro SaaS';
        } else if (lastSegment === 'edit') {
            const parentSegment = pathSegments[pathSegments.length - 2];
            if (parentSegment === 'users') {
                return 'Edit User - Soro SaaS';
            } else if (parentSegment === 'tenants') {
                return 'Edit Tenant - Soro SaaS';
            }
            return 'Edit - Soro SaaS';
        } else if (lastSegment.match(/^\d+$/)) {
            const parentSegment = pathSegments[pathSegments.length - 2];
            if (parentSegment === 'users') {
                return 'User Details - Soro SaaS';
            } else if (parentSegment === 'tenants') {
                return 'Tenant Details - Soro SaaS';
            }
            return 'Details - Soro SaaS';
        }
        
        return `${lastSegment.charAt(0).toUpperCase() + lastSegment.slice(1)} - Soro SaaS`;
    });
    
    // Watch for title changes and update document title
    watch(pageTitle, (newTitle) => {
        console.log('Setting document title to:', newTitle);
        document.title = newTitle;
    }, { immediate: true });
    
    return {
        pageTitle
    };
}
