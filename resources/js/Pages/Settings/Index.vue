<template>
    <AuthenticatedLayout>
        <div class="p-6">
            <div class="max-w-4xl mx-auto">
                <!-- Header -->
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">System Settings</h1>
                    <p class="text-gray-600 dark:text-gray-400">Configure your application settings</p>
                </div>

                <form @submit.prevent="submit" class="space-y-8">
                    <!-- General Settings -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">General Settings</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Application Name
                                </label>
                                <InputText
                                    v-model="form.app_name"
                                    placeholder="Enter application name"
                                    class="w-full"
                                />
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Contact Email
                                </label>
                                <InputText
                                    v-model="form.contact_email"
                                    type="email"
                                    placeholder="contact@example.com"
                                    class="w-full"
                                />
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Contact Phone
                                </label>
                                <InputText
                                    v-model="form.contact_phone"
                                    placeholder="+1 (555) 123-4567"
                                    class="w-full"
                                />
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Timezone
                                </label>
                                <Dropdown
                                    v-model="form.timezone"
                                    :options="timezones"
                                    optionLabel="label"
                                    optionValue="value"
                                    placeholder="Select timezone"
                                    class="w-full"
                                />
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Address
                            </label>
                            <Textarea
                                v-model="form.address"
                                placeholder="Enter your business address"
                                rows="3"
                                class="w-full"
                            />
                        </div>
                    </div>

                    <!-- Appearance Settings -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Appearance</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Primary Color
                                </label>
                                <div class="flex items-center space-x-3">
                                    <input
                                        v-model="form.primary_color"
                                        type="color"
                                        class="w-12 h-10 rounded border border-gray-300 dark:border-gray-600"
                                    />
                                    <InputText
                                        v-model="form.primary_color"
                                        placeholder="#3B82F6"
                                        class="flex-1"
                                    />
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Secondary Color
                                </label>
                                <div class="flex items-center space-x-3">
                                    <input
                                        v-model="form.secondary_color"
                                        type="color"
                                        class="w-12 h-10 rounded border border-gray-300 dark:border-gray-600"
                                    />
                                    <InputText
                                        v-model="form.secondary_color"
                                        placeholder="#10B981"
                                        class="flex-1"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- File Uploads -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Branding</h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Application Logo
                                </label>
                                <FileUpload
                                    mode="basic"
                                    :auto="true"
                                    accept="image/*"
                                    :maxFileSize="2000000"
                                    @upload="handleLogoUpload"
                                    chooseLabel="Choose Logo"
                                    class="w-full"
                                />
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Recommended size: 200x50px, Max size: 2MB
                                </p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Favicon
                                </label>
                                <FileUpload
                                    mode="basic"
                                    :auto="true"
                                    accept="image/*"
                                    :maxFileSize="1000000"
                                    @upload="handleFaviconUpload"
                                    chooseLabel="Choose Favicon"
                                    class="w-full"
                                />
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Recommended size: 32x32px, Max size: 1MB
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            label="Save Settings"
                            icon="pi pi-save"
                            :loading="processing"
                        />
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Textarea from 'primevue/textarea';
import Dropdown from 'primevue/dropdown';
import FileUpload from 'primevue/fileupload';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    settings: Object
});

const processing = ref(false);

const form = useForm({
    app_name: props.settings?.app_name || '',
    contact_email: props.settings?.contact_email || '',
    contact_phone: props.settings?.contact_phone || '',
    address: props.settings?.address || '',
    timezone: props.settings?.timezone || 'UTC',
    primary_color: props.settings?.primary_color || '#3B82F6',
    secondary_color: props.settings?.secondary_color || '#10B981',
    app_logo: null,
    app_favicon: null,
});

const timezones = [
    { label: 'UTC', value: 'UTC' },
    { label: 'America/New_York', value: 'America/New_York' },
    { label: 'America/Chicago', value: 'America/Chicago' },
    { label: 'America/Denver', value: 'America/Denver' },
    { label: 'America/Los_Angeles', value: 'America/Los_Angeles' },
    { label: 'Europe/London', value: 'Europe/London' },
    { label: 'Europe/Paris', value: 'Europe/Paris' },
    { label: 'Asia/Tokyo', value: 'Asia/Tokyo' },
    { label: 'Asia/Shanghai', value: 'Asia/Shanghai' },
];

const handleLogoUpload = (event) => {
    form.app_logo = event.files[0];
};

const handleFaviconUpload = (event) => {
    form.app_favicon = event.files[0];
};

const submit = () => {
    processing.value = true;
    form.put('/settings', {
        onFinish: () => {
            processing.value = false;
        }
    });
};
</script>




