<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Exceptions\IncompletePayment;

/**
 * Subscription management (docs/specs/10, Phase 5).
 *
 * Deliberately blunt about what lapsing does and does not do: the page says
 * plainly that the books stay readable and exportable, because a customer
 * deciding whether to renew should not have to wonder whether their records
 * are hostage.
 */
class BillingController extends Controller
{
    public function show(Request $request): Response
    {
        $tenant = currentTenant();

        abort_if($tenant === null, 404);

        $subscription = $tenant->subscription('default');

        return Inertia::render('Billing/Show', [
            'billingEnabled' => (bool) config('billing.enabled'),
            'status' => $tenant->billingStatus(),
            'canPost' => $tenant->canPost(),
            'graceDays' => (int) config('billing.grace_days'),
            'trialEndsAt' => $tenant->trial_ends_at?->toDateString(),
            'plans' => $this->plans(),
            'subscription' => $subscription === null ? null : [
                'stripe_status' => $subscription->stripe_status,
                'stripe_price' => $subscription->stripe_price,
                'ends_at' => $subscription->ends_at?->toDateString(),
                'on_grace_period' => $subscription->onGracePeriod(),
            ],
            'paymentMethod' => $tenant->pm_last_four === null ? null : [
                'brand' => $tenant->pm_type,
                'last_four' => $tenant->pm_last_four,
            ],
        ]);
    }

    /** Stripe Checkout for a new subscription. */
    public function subscribe(Request $request): RedirectResponse
    {
        $tenant = currentTenant();
        abort_if($tenant === null || ! config('billing.enabled'), 404);

        $plan = $request->validate([
            'plan' => ['required', 'string', 'in:'.implode(',', array_keys(config('billing.plans')))],
        ])['plan'];

        $priceId = config("billing.plans.{$plan}.price_id");

        if ($priceId === null) {
            return back()->with('error', "The {$plan} plan has no Stripe price configured yet.");
        }

        try {
            $checkout = $tenant->newSubscription('default', $priceId)
                ->trialDays((int) config('billing.trial_days'))
                ->checkout([
                    'success_url' => route('billing.show').'?checkout=success',
                    'cancel_url' => route('billing.show').'?checkout=cancelled',
                ]);
        } catch (IncompletePayment $e) {
            // Cashier exposes these through __get; the explicit accessors
            // keep static analysis honest.
            return redirect()->route('cashier.payment', [
                $e->payment->asStripePaymentIntent()->id,
                'redirect' => route('billing.show'),
            ]);
        }

        return redirect()->away($checkout->asStripeCheckoutSession()->url);
    }

    /**
     * The plan catalogue, keyed for the UI.
     *
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        $plans = [];

        /** @var array<string, array<string, mixed>> $configured */
        $configured = config('billing.plans');

        foreach ($configured as $key => $plan) {
            $plans[] = $plan + ['key' => $key];
        }

        return $plans;
    }

    /** Stripe's hosted billing portal — card updates, invoices, cancellation. */
    public function portal(Request $request): RedirectResponse
    {
        $tenant = currentTenant();
        abort_if($tenant === null || $tenant->stripe_id === null, 404);

        return redirect()->away($tenant->billingPortalUrl(route('billing.show')));
    }
}
