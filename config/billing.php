<?php

// SaaS billing (docs/specs/10, Phase 5).
return [

    // A tenant that stops paying must NOT lose access to its books. BIR
    // requires the TAXPAYER to keep and produce them (RMC 5-2021 Annex B,
    // and the retention rules in spec 03 §2), and a system that hides a
    // registrant's own records behind an unpaid invoice would put that
    // taxpayer in breach through no act of their own. So a lapsed
    // subscription degrades to READ-ONLY: everything is visible and
    // exportable, nothing new can be posted.
    'lapsed_access' => env('BILLING_LAPSED_ACCESS', 'read_only'),

    // Days after a subscription ends before the read-only gate applies, so
    // a failed card at 2am does not stop Monday's invoicing.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // SINGLE_TENANT deployments are per-client VPS installs, billed by
    // contract rather than by card — the gate is off entirely.
    'enabled' => (bool) env('BILLING_ENABLED', ! env('SINGLE_TENANT', false)),

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 30),

    // Prices are Stripe price IDs; the peso figures are display-only and
    // must be kept in step with the Stripe dashboard.
    'plans' => [
        'starter' => [
            'name' => 'Starter',
            'price_id' => env('STRIPE_PRICE_STARTER'),
            'monthly_centavos' => 149_900,
            'description' => 'One branch, two users, the full ledger and BIR books.',
            'limits' => ['users' => 2, 'branches' => 1],
        ],
        'business' => [
            'name' => 'Business',
            'price_id' => env('STRIPE_PRICE_BUSINESS'),
            'monthly_centavos' => 349_900,
            'description' => 'Up to five branches and ten users, plus inventory.',
            'limits' => ['users' => 10, 'branches' => 5],
        ],
        'unlimited' => [
            'name' => 'Unlimited',
            'price_id' => env('STRIPE_PRICE_UNLIMITED'),
            'monthly_centavos' => 799_900,
            'description' => 'Unlimited branches and users.',
            'limits' => ['users' => null, 'branches' => null],
        ],
    ],
];
