<script setup>
import AuthCardLayout from '@/Layouts/AuthCardLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import Password from 'primevue/password';

const props = defineProps({
    email: String,
    token: String,
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Reset Password" />

    <AuthCardLayout title="Reset password" subtitle="Choose a new password for your account">
        <form @submit.prevent="submit" class="w-full md:w-[26rem] flex flex-col gap-4">
            <div>
                <label for="email" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Email</label>
                <InputText id="email" type="email" class="w-full" v-model="form.email" :invalid="!!form.errors.email" autocomplete="username" required />
                <small v-if="form.errors.email" class="block text-red-500 mt-1">{{ form.errors.email }}</small>
            </div>

            <div>
                <label for="password" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">New Password</label>
                <Password id="password" v-model="form.password" :toggleMask="true" fluid :invalid="!!form.errors.password" autocomplete="new-password" autofocus required />
                <small v-if="form.errors.password" class="block text-red-500 mt-1">{{ form.errors.password }}</small>
            </div>

            <div>
                <label for="password_confirmation" class="block text-surface-900 dark:text-surface-0 font-medium mb-2">Confirm Password</label>
                <Password id="password_confirmation" v-model="form.password_confirmation" :toggleMask="true" fluid :feedback="false" :invalid="!!form.errors.password_confirmation" autocomplete="new-password" required />
                <small v-if="form.errors.password_confirmation" class="block text-red-500 mt-1">{{ form.errors.password_confirmation }}</small>
            </div>

            <Button label="Reset Password" class="w-full mt-2" type="submit" :loading="form.processing" />
        </form>
    </AuthCardLayout>
</template>
