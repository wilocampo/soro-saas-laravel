<script setup>
import AppConfigurator from '@/layout/AppConfigurator.vue';
import { useLayout } from '@/layout/composables/layout';
import { ref } from 'vue';
import Button from 'primevue/button';

const { toggleDarkMode, isDarkTheme } = useLayout();
const showConfigurator = ref(false);

const toggleConfigurator = () => {
    showConfigurator.value = !showConfigurator.value;
};

const closeConfigurator = () => {
    showConfigurator.value = false;
};
</script>

<template>
    <div class="fixed flex gap-4 top-8 right-8">
        <Button type="button" @click="toggleDarkMode" rounded :icon="isDarkTheme ? 'pi pi-moon' : 'pi pi-sun'" severity="secondary" />
        <div class="relative">
            <Button
                @click="toggleConfigurator"
                icon="pi pi-palette"
                type="button"
                rounded
            />
            <AppConfigurator v-if="showConfigurator" @close="closeConfigurator" />
        </div>
    </div>
</template>
