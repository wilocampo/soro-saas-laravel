<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Password from 'primevue/password';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Confirm Password" />

    <AuthCardLayout
        title="Confirm password"
        subtitle="This is a secure area — please confirm your password before continuing"
    >
        <form @submit.prevent="submit" class="w-full md:w-[26rem]">
            <label for="password" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Password</label>
            <Password
                id="password"
                v-model="form.password"
                :toggleMask="true"
                fluid
                :feedback="false"
                :invalid="!!form.errors.password"
                autocomplete="current-password"
                autofocus
                required
            />
            <small v-if="form.errors.password" class="block text-red-500 mt-1">{{ form.errors.password }}</small>

            <Button label="Confirm" class="w-full mt-6" type="submit" :loading="form.processing" />
        </form>
    </AuthCardLayout>
</template>
