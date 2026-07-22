<script setup>
import AppPageHeader from '@/components/AppPageHeader.vue';
import MoneyText from '@/components/MoneyText.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { router } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { computed } from 'vue';

const props = defineProps({
    billingEnabled: { type: Boolean, required: true },
    status: { type: String, required: true },
    canPost: { type: Boolean, required: true },
    graceDays: { type: Number, required: true },
    trialEndsAt: { type: String, default: null },
    plans: { type: Array, required: true },
    subscription: { type: Object, default: null },
    paymentMethod: { type: Object, default: null },
});

const SEVERITY = {
    active: 'success',
    trialing: 'info',
    grace: 'warn',
    lapsed: 'danger',
    not_billed: 'secondary',
};

const LABEL = {
    active: 'Active',
    trialing: 'Trial',
    grace: 'Grace period',
    lapsed: 'Lapsed',
    not_billed: 'Billed by contract',
};

const currentPrice = computed(() => props.subscription?.stripe_price ?? null);

const subscribe = (plan) => router.post(route('billing.subscribe'), { plan: plan.key });
</script>

<template>
    <AuthenticatedLayout title="Billing">
        <template #header>
            <AppPageHeader title="Billing" subtitle="Your subscription and payment method">
                <template #actions>
                    <Tag :value="LABEL[status]" :severity="SEVERITY[status]" />
                    <a v-if="paymentMethod" :href="route('billing.portal')">
                        <Button label="Manage in Stripe" icon="pi pi-external-link" outlined />
                    </a>
                </template>
            </AppPageHeader>
        </template>

        <!--
            The promise that matters most, stated plainly: a lapsed
            subscription never holds a taxpayer's books hostage. BIR makes
            the REGISTRANT responsible for producing them.
        -->
        <Message v-if="!canPost" severity="error" class="mb-4" :closable="false">
            <p class="m-0 font-semibold">Posting is paused</p>
            <p class="m-0 mt-1">
                New entries cannot be posted while the subscription is lapsed. Your books remain fully readable
                and exportable — they are your records, and they stay yours. Renew below to resume posting.
            </p>
        </Message>

        <Message v-else-if="status === 'grace'" severity="warn" class="mb-4" :closable="false">
            The subscription has ended but you are still within the {{ graceDays }}-day grace period, so posting
            continues for now.
        </Message>

        <Message v-else-if="status === 'trialing'" severity="info" class="mb-4" :closable="false">
            Trial in progress<template v-if="trialEndsAt"> until {{ trialEndsAt }}</template>.
        </Message>

        <Message v-else-if="!billingEnabled" severity="info" class="mb-4" :closable="false">
            This is a single-tenant installation, billed by contract rather than by card. No subscription is
            required and posting is never gated.
        </Message>

        <div v-if="billingEnabled" class="grid grid-cols-12 gap-4">
            <div v-for="plan in plans" :key="plan.key" class="col-span-12 md:col-span-4">
                <div class="card h-full flex flex-col">
                    <h2 class="mb-1 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">
                        {{ plan.name }}
                    </h2>
                    <div class="mb-3">
                        <span class="text-3xl font-bold"><MoneyText :centavos="plan.monthly_centavos" /></span>
                        <span class="text-sm text-muted-color"> / month</span>
                    </div>
                    <p class="mt-0 flex-1 text-sm text-muted-color">{{ plan.description }}</p>

                    <ul class="mb-4 list-none p-0 text-sm">
                        <li class="py-1">
                            <i class="pi pi-users mr-2 text-muted-color" />
                            {{ plan.limits.users ?? 'Unlimited' }} users
                        </li>
                        <li class="py-1">
                            <i class="pi pi-building mr-2 text-muted-color" />
                            {{ plan.limits.branches ?? 'Unlimited' }} branches
                        </li>
                    </ul>

                    <Button
                        v-if="currentPrice === plan.price_id"
                        label="Current plan"
                        severity="secondary"
                        outlined
                        disabled
                    />
                    <Button
                        v-else
                        :label="subscription ? 'Switch to this plan' : 'Start subscription'"
                        @click="subscribe(plan)"
                    />
                </div>
            </div>
        </div>

        <div v-if="subscription" class="card">
            <h2 class="mb-4 mt-0 text-xl font-semibold text-surface-900 dark:text-surface-0">Subscription</h2>
            <dl class="m-0 grid grid-cols-2 gap-y-3 text-sm md:w-1/2">
                <dt class="text-muted-color">Stripe status</dt>
                <dd class="m-0">{{ subscription.stripe_status }}</dd>
                <dt class="text-muted-color">Payment method</dt>
                <dd class="m-0">
                    <template v-if="paymentMethod">
                        {{ paymentMethod.brand }} •••• {{ paymentMethod.last_four }}
                    </template>
                    <span v-else class="text-muted-color">None on file</span>
                </dd>
                <dt v-if="subscription.ends_at" class="text-muted-color">Ends</dt>
                <dd v-if="subscription.ends_at" class="m-0">{{ subscription.ends_at }}</dd>
            </dl>
        </div>
    </AuthenticatedLayout>
</template>
