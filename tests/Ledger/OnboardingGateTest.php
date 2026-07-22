<?php

namespace Tests\Ledger;

use App\Domain\Compliance\GoLiveGate;
use App\Domain\Ledger\Models\CompanyProfile;
use App\Http\Middleware\TenantMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Onboarding and the go-live gate (docs/specs/03 §1, 10 §2 — Phase 5).
 *
 * The gate is advisory by design: we are not the tenant's compliance
 * officer. What it must do is refuse to PRETEND — an unregistered CAS is
 * visibly unregistered, and declaring go-live is an audited act.
 */
class OnboardingGateTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(TenantMiddleware::class);
        $this->actingAs(User::query()->firstOrFail());
    }

    private function completeRegistration(array $overrides = []): void
    {
        CompanyProfile::query()->where('id', 1)->update(array_merge([
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue, Makati City, 1226',
            'tin' => '246813579',
            'accn' => 'AC-2026-000123',
            'accn_issued_at' => '2026-01-15',
            'npc_registered' => true,
            'npc_registration_number' => 'NPC-2026-0001',
            'dpo_name' => 'J. Ocampo',
        ], $overrides));
    }

    /** A freshly provisioned tenant is not ready, and says exactly why. */
    public function test_a_new_tenant_is_blocked_with_named_reasons(): void
    {
        $check = app(GoLiveGate::class)->check();

        $this->assertFalse($check['ready']);

        $blocking = array_column($check['blocking'], 'key');
        $this->assertContains('company_profile', $blocking, 'The placeholder profile must block.');
        $this->assertContains('accn', $blocking, 'An unregistered CAS must block.');
        $this->assertContains('npc_registration', $blocking, 'DPA registration must block.');
    }

    public function test_completing_registration_clears_the_blocking_items(): void
    {
        $this->completeRegistration();

        $check = app(GoLiveGate::class)->check();

        $this->assertTrue($check['ready']);
        $this->assertSame([], $check['blocking']);
        // Advisory items remain, and that is fine — they are advice.
        $this->assertNotEmpty($check['advisory']);
    }

    /** The ACCN is the BIR gate; without it the tenant is not registered. */
    public function test_a_missing_accn_alone_blocks_go_live(): void
    {
        $this->completeRegistration(['accn' => null]);

        $check = app(GoLiveGate::class)->check();

        $this->assertFalse($check['ready']);
        $this->assertSame(['accn'], array_column($check['blocking'], 'key'));
    }

    public function test_go_live_is_refused_while_anything_is_outstanding(): void
    {
        $this->completeRegistration(['npc_registered' => false]);

        $this->post(route('onboarding.go-live'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(CompanyProfile::current()->go_live_at);
    }

    /** Going live is an audited act with a name against it. */
    public function test_going_live_is_recorded_and_audited(): void
    {
        $this->completeRegistration();

        $this->post(route('onboarding.go-live'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = CompanyProfile::current();
        $this->assertNotNull($profile->go_live_at);
        $this->assertTrue($profile->isLive());

        $audit = DB::table('audit_log')->where('event', 'tenant.went_live')->first();
        $this->assertNotNull($audit);
        $this->assertSame('AC-2026-000123', $audit->document_number);
        $this->assertStringContainsString('J. Ocampo', (string) $audit->after_json);
    }

    /** Registration details are stamped on legal documents, so changes are audited. */
    public function test_updating_registration_details_is_audited(): void
    {
        $this->put(route('onboarding.update'), [
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue, Makati City',
            'tin' => '246-813-579',
            'branch_code' => '000',
            'registration_mode' => 'cas',
            'accn' => 'AC-2026-000123',
            'accn_issued_at' => '2026-01-15',
        ])->assertRedirect()->assertSessionHas('success');

        // The dashes are stripped; the branch code lives in its own field.
        $this->assertSame('246813579', CompanyProfile::current()->tin);

        $this->assertSame(1, DB::table('audit_log')->where('event', 'company_profile.updated')->count());
    }

    public function test_an_accn_without_its_issue_date_is_refused(): void
    {
        $this->put(route('onboarding.update'), [
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue',
            'tin' => '246813579',
            'accn' => 'AC-2026-000123',
        ])->assertSessionHasErrors('accn_issued_at');
    }

    /** A privacy attestation without a named officer is not an attestation. */
    public function test_confirming_npc_registration_requires_a_named_dpo(): void
    {
        $this->put(route('onboarding.update'), [
            'registered_name' => 'Soro Test Trading Inc.',
            'registered_address' => '123 Ayala Avenue',
            'tin' => '246813579',
            'npc_registered' => true,
            'dpo_name' => '',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertFalse((bool) CompanyProfile::current()->npc_registered);
    }

    public function test_the_onboarding_page_renders_the_checklist(): void
    {
        $this->get(route('onboarding.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Onboarding/Show')
                ->where('checklist.ready', false)
                ->has('checklist.items')
            );
    }
}
