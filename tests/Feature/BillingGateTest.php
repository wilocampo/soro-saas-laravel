<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSubscriptionAllowsPosting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The billing gate (Phase 5, docs/specs/10).
 *
 * The load-bearing rule, and the reason the gate is asymmetric: a lapsed
 * subscription stops POSTING and nothing else. BIR holds the REGISTRANT
 * responsible for keeping and producing its books, so a system that hid a
 * taxpayer's own records behind an unpaid invoice would put that taxpayer
 * in breach through no act of ours.
 */
class BillingGateTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'Acme Trading',
            'domain' => 'acme.soro.test',
            'subdomain' => 'acme',
            'database' => 'tenant_acme',
            'is_active' => true,
        ], $overrides));
    }

    /** A subscription is created, so the tenant may post. */
    private function subscribe(Tenant $tenant, ?CarbonImmutable $endsAt = null, string $status = 'active'): void
    {
        $tenant->forceFill(['stripe_id' => 'cus_test'])->save();

        DB::table('subscriptions')->insert([
            'tenant_id' => $tenant->id,
            'type' => 'default',
            'stripe_id' => 'sub_test',
            'stripe_status' => $status,
            'stripe_price' => 'price_business',
            'quantity' => 1,
            'ends_at' => $endsAt?->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_active_subscription_may_post(): void
    {
        config(['billing.enabled' => true]);
        $tenant = $this->tenant();
        $this->subscribe($tenant);

        $this->assertTrue($tenant->fresh()->canPost());
        $this->assertSame('active', $tenant->fresh()->billingStatus());
    }

    public function test_a_tenant_on_trial_may_post_without_a_card(): void
    {
        config(['billing.enabled' => true]);
        $tenant = $this->tenant();
        // Billing columns are deliberately NOT mass-assignable: a trial is
        // granted by provisioning, never by a request payload.
        $tenant->forceFill(['trial_ends_at' => CarbonImmutable::now()->addDays(14)])->save();

        $this->assertTrue($tenant->canPost());
        $this->assertSame('trialing', $tenant->billingStatus());
    }

    /** A card failing at 2am must not stop Monday's invoicing. */
    public function test_a_recently_ended_subscription_is_still_in_grace(): void
    {
        config(['billing.enabled' => true, 'billing.grace_days' => 7]);
        $tenant = $this->tenant();
        $this->subscribe($tenant, CarbonImmutable::now()->subDays(2), 'canceled');

        $this->assertTrue($tenant->fresh()->canPost());
        $this->assertSame('grace', $tenant->fresh()->billingStatus());
    }

    public function test_posting_stops_once_the_grace_period_expires(): void
    {
        config(['billing.enabled' => true, 'billing.grace_days' => 7]);
        $tenant = $this->tenant();
        $this->subscribe($tenant, CarbonImmutable::now()->subDays(30), 'canceled');

        $this->assertFalse($tenant->fresh()->canPost());
        $this->assertSame('lapsed', $tenant->fresh()->billingStatus());
    }

    public function test_a_tenant_that_never_subscribed_cannot_post(): void
    {
        config(['billing.enabled' => true]);
        $tenant = $this->tenant();

        $this->assertFalse($tenant->canPost());
        $this->assertSame('lapsed', $tenant->billingStatus());
    }

    /** SINGLE_TENANT installs bill by contract; the gate is off entirely. */
    public function test_billing_disabled_never_gates_posting(): void
    {
        config(['billing.enabled' => false]);
        $tenant = $this->tenant();

        $this->assertTrue($tenant->canPost());
        $this->assertSame('not_billed', $tenant->billingStatus());
    }

    /**
     * The asymmetry, asserted at the routing layer: WRITE routes carry the
     * gate and READ routes do not. If this ever inverts, a non-paying
     * taxpayer loses access to records they are legally required to keep.
     */
    public function test_only_write_routes_carry_the_billing_gate(): void
    {
        $gated = [];
        $ungated = [];

        foreach (app('router')->getRoutes() as $route) {
            $hasGate = in_array('can-post', $route->gatherMiddleware(), true)
                || in_array(EnsureSubscriptionAllowsPosting::class, $route->gatherMiddleware(), true);

            $isWrite = count(array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) > 0;

            if ($hasGate) {
                $gated[] = $route->uri();
                $this->assertTrue($isWrite, "Read route [{$route->uri()}] must not be behind the billing gate.");
            } elseif ($isWrite && str_starts_with($route->getName() ?? '', 'invoices.')) {
                $ungated[] = $route->uri();
            }
        }

        $this->assertNotEmpty($gated, 'Write routes must be gated.');
        $this->assertSame([], $ungated, 'Every invoice write route must be gated.');
    }

    /** Reading and exporting must never be gated — that is the whole point. */
    public function test_reading_and_exporting_are_never_gated(): void
    {
        $mustStayOpen = [
            'invoices.index', 'invoices.show', 'invoices.pdf',
            'reports.trial-balance', 'reports.balance-sheet', 'reports.journal',
            'reports.general-ledger', 'reports.soa', 'billing.show',
        ];

        foreach ($mustStayOpen as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route [{$name}] should exist.");
            $this->assertNotContains(
                'can-post',
                $route->gatherMiddleware(),
                "Route [{$name}] must stay reachable when a subscription lapses — the books are the tenant's."
            );
        }
    }
}
