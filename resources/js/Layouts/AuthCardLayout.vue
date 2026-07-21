<script setup>
import FloatingConfigurator from '@/components/FloatingConfigurator.vue';

/**
 * Sakai auth-card shell shared by every guest page (spec 11 §4.7).
 * Pages provide their own <Head> title and form content.
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null }
});

const appName = import.meta.env.VITE_APP_NAME || 'Soro SaaS';
</script>

<template>
    <FloatingConfigurator />
    <div class="bg-surface-50 dark:bg-surface-950 flex items-center justify-center min-h-screen min-w-[100vw] overflow-hidden">
        <div class="flex flex-col items-center justify-center px-4">
            <div class="auth-card-ring rounded-[56px] p-[0.3rem] shadow-md">
                <div class="w-full bg-surface-0 dark:bg-surface-900 py-16 px-8 sm:px-16 rounded-[53px]">
                    <div class="text-center mb-8">
                        <div class="text-primary text-4xl font-semibold mb-3">{{ appName }}</div>
                        <div class="text-surface-900 dark:text-surface-0 text-2xl font-medium mb-2">{{ title }}</div>
                        <span v-if="subtitle" class="text-muted-color font-medium">{{ subtitle }}</span>
                    </div>

                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.auth-card-ring {
    background: linear-gradient(180deg, var(--p-primary-color) 10%, transparent 30%);
}
</style>
