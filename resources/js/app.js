import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// PrimeVue imports
import PrimeVue from 'primevue/config';
import ConfirmationService from 'primevue/confirmationservice';
import ToastService from 'primevue/toastservice';
import StyleClass from 'primevue/styleclass';
import Breadcrumb from 'primevue/breadcrumb';
import Aura from '@primeuix/themes/aura';

// PrimeIcons
import 'primeicons/primeicons.css';

// Sakai styles
import '@/assets/styles.scss';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(PrimeVue, {
                theme: {
                    preset: Aura,
                    options: {
                        darkModeSelector: '.app-dark'
                    }
                }
            })
            .use(ConfirmationService)
            .use(ToastService);
        
        // Register PrimeVue directives
        app.directive('styleclass', StyleClass);
        
        // Register PrimeVue components globally
        app.component('Breadcrumb', Breadcrumb);
        
        return app.mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
