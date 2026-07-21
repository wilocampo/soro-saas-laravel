<script setup>
import CRUDField from '@/components/CRUDField.vue';
import CRUDModal from '@/components/CRUDModal.vue';
import FormSection from '@/components/FormSection.vue';
import { useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import { ref } from 'vue';

// Password-gated destructive action: the argless ConfirmDialog pattern
// can't collect the current password the backend requires, so this uses
// the kit modal with a danger submit instead.
const confirmingUserDeletion = ref(false);

const form = useForm({
    password: '',
});

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <FormSection
        title="Delete Account"
        description="Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain."
    >
        <div class="col-span-12">
            <Button
                label="Delete Account"
                icon="pi pi-trash"
                severity="danger"
                @click="confirmingUserDeletion = true"
            />
        </div>
    </FormSection>

    <CRUDModal
        v-model:visible="confirmingUserDeletion"
        title="Are you sure you want to delete your account?"
        submit-label="Delete Account"
        submit-icon="pi pi-trash"
        mode="edit"
        severity="danger"
        :loading="form.processing"
        @submit="deleteUser"
        @cancel="closeModal"
        @hide="closeModal"
    >
        <template #fields>
            <div class="col-span-12">
                <p class="m-0 mb-4 text-sm text-muted-color">
                    Once your account is deleted, all of its resources and data will be permanently
                    deleted. Please enter your password to confirm you would like to permanently
                    delete your account.
                </p>
            </div>
            <CRUDField
                v-model="form.password"
                label="Password"
                fieldId="delete_password"
                component="Password"
                :feedback="false"
                :error="form.errors.password"
                autocomplete="current-password"
                columnClass="col-span-12"
                required
                @keyup.enter="deleteUser"
            />
        </template>
    </CRUDModal>
</template>
