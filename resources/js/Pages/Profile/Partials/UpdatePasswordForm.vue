<script setup>
import CRUDField from '@/components/CRUDField.vue';
import FormSection from '@/components/FormSection.vue';
import { useAppToast } from '@/composables/useAppToast';
import { useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';

const toast = useAppToast();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            toast.success('Password updated');
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
            }
            if (form.errors.current_password) {
                form.reset('current_password');
            }
        },
    });
};
</script>

<template>
    <form @submit.prevent="updatePassword">
        <FormSection
            title="Update Password"
            description="Ensure your account is using a long, random password to stay secure."
        >
            <CRUDField
                v-model="form.current_password"
                label="Current Password"
                fieldId="current_password"
                component="Password"
                :feedback="false"
                :error="form.errors.current_password"
                autocomplete="current-password"
                columnClass="col-span-12 md:col-span-6"
                required
            />

            <div class="hidden md:block md:col-span-6"></div>

            <CRUDField
                v-model="form.password"
                label="New Password"
                fieldId="password"
                component="Password"
                :error="form.errors.password"
                autocomplete="new-password"
                required
            />

            <CRUDField
                v-model="form.password_confirmation"
                label="Confirm Password"
                fieldId="password_confirmation"
                component="Password"
                :feedback="false"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
                required
            />

            <template #footer>
                <Button type="submit" label="Save" icon="pi pi-check" :loading="form.processing" />
            </template>
        </FormSection>
    </form>
</template>
