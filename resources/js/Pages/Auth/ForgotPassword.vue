<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';

defineProps({
    status: String,
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <Head title="Forgot Password" />

    <AuthCardLayout
        title="Forgot password"
        subtitle="We'll email you a password reset link"
    >
        <Message v-if="status" severity="success" :closable="false" class="mb-6">{{ status }}</Message>

        <form @submit.prevent="submit" class="w-full md:w-[26rem]">
            <label for="email" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Email</label>
            <InputText
                id="email"
                type="email"
                class="w-full"
                v-model="form.email"
                :invalid="!!form.errors.email"
                autocomplete="username"
                autofocus
                required
            />
            <small v-if="form.errors.email" class="block text-red-500 mt-1">{{ form.errors.email }}</small>

            <div class="flex items-center justify-between mt-6">
                <Link :href="route('login')" class="font-medium no-underline cursor-pointer text-primary">
                    Back to log in
                </Link>
                <Button label="Email Reset Link" type="submit" :loading="form.processing" />
            </div>
        </form>
    </AuthCardLayout>
</template>
