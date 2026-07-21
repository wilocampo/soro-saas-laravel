<script setup>
import CRUDField from '@/components/CRUDField.vue';
import FormSection from '@/components/FormSection.vue';
import { useAppToast } from '@/composables/useAppToast';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Message from 'primevue/message';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const toast = useAppToast();
const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => toast.success('Profile updated'),
    });
};
</script>

<template>
    <form @submit.prevent="submit">
        <FormSection
            title="Profile Information"
            description="Update your account's profile information and email address."
        >
            <CRUDField
                v-model="form.name"
                label="Name"
                fieldId="name"
                :error="form.errors.name"
                required
                autocomplete="name"
            />

            <CRUDField
                v-model="form.email"
                label="Email"
                fieldId="email"
                type="email"
                :error="form.errors.email"
                required
                autocomplete="username"
            />

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="col-span-12">
                <Message severity="warn" :closable="false">
                    Your email address is unverified.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="underline"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </Message>
                <Message
                    v-if="status === 'verification-link-sent'"
                    severity="success"
                    :closable="false"
                    class="mt-2"
                >
                    A new verification link has been sent to your email address.
                </Message>
            </div>

            <template #footer>
                <Button type="submit" label="Save" icon="pi pi-check" :loading="form.processing" />
            </template>
        </FormSection>
    </form>
</template>
