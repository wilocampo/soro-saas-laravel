<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Message from 'primevue/message';
import { computed } from 'vue';

const props = defineProps({
    status: String,
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <Head title="Email Verification" />

    <AuthCardLayout
        title="Verify your email"
        subtitle="Click the link in the email we sent you — or request another below"
    >
        <div class="w-full md:w-[26rem]">
            <Message v-if="verificationLinkSent" severity="success" :closable="false" class="mb-6">
                A new verification link has been sent to the email address you provided during registration.
            </Message>

            <form @submit.prevent="submit" class="flex items-center justify-between gap-4">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="font-medium no-underline cursor-pointer text-primary bg-transparent border-0 p-0"
                >
                    Log out
                </Link>
                <Button label="Resend Verification Email" type="submit" :loading="form.processing" />
            </form>
        </div>
    </AuthCardLayout>
</template>
