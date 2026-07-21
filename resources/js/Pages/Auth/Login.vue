<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Password from 'primevue/password';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log in" />

    <AuthCardLayout title="Welcome back" subtitle="Sign in to continue">
        <Message v-if="status" severity="success" :closable="false" class="mb-6">{{ status }}</Message>

        <form @submit.prevent="submit" class="w-full md:w-[26rem]">
            <label for="email" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Email</label>
            <InputText
                id="email"
                type="email"
                placeholder="Email address"
                class="w-full mb-1"
                v-model="form.email"
                :invalid="!!form.errors.email"
                autocomplete="username"
                autofocus
            />
            <small v-if="form.errors.email" class="block text-red-500 mb-3">{{ form.errors.email }}</small>
            <div v-else class="mb-4"></div>

            <label for="password" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Password</label>
            <Password
                id="password"
                v-model="form.password"
                placeholder="Password"
                :toggleMask="true"
                fluid
                :feedback="false"
                :invalid="!!form.errors.password"
                autocomplete="current-password"
            />
            <small v-if="form.errors.password" class="block text-red-500 mt-1">{{ form.errors.password }}</small>

            <div class="flex items-center justify-between mt-4 mb-8 gap-8">
                <div class="flex items-center">
                    <Checkbox v-model="form.remember" inputId="remember" binary class="mr-2" />
                    <label for="remember">Remember me</label>
                </div>
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="font-medium no-underline text-right cursor-pointer text-primary"
                >
                    Forgot password?
                </Link>
            </div>

            <Button label="Sign In" class="w-full" type="submit" :loading="form.processing" />
        </form>
    </AuthCardLayout>
</template>
