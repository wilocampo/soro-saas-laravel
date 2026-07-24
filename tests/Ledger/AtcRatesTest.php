<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Ledger\Posting\TaxResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * D25 (research draft — licensed CPA sign-off pending) — the
 * expanded-withholding rate table.
 *
 * This table is the withholding engine: every 2307, 0619-E, 1601-EQ, QAP and
 * 1604-E is generated from it, so a wrong code fails alphalist validation and
 * a wrong rate under-remits somebody's tax.
 *
 * It is tested harder than its size suggests because it is the one place a
 * plausible-looking guess would have shipped silently — and it took TWO tries
 * to get the commissions row right. The first draft used WI139/WI140,
 * WC139/WC140 (wrong codes); the fix that replaced them seeded WI515 at 5%
 * with the professional-fee sworn-declaration logic (also wrong — WI515/WC515
 * is a flat 10% broker/agent pair). Both errors are pinned here so neither
 * can creep back. They never reached a live calculation only because the
 * table was incomplete and `TaxResolver` throws rather than invent a rate —
 * the property the refuse-to-guess test at the end guards.
 */
class AtcRatesTest extends LedgerTestCase
{
    private function rate(string $atc, string $payeeType): int
    {
        return app(TaxResolver::class)->atcRateBp($atc, $payeeType, CarbonImmutable::parse('2026-07-24'));
    }

    /** The correction: WI515/WC515 is a FLAT 10% pair, not 139/140, not 5%. */
    public function test_commissions_use_the_corrected_codes_and_rate(): void
    {
        // Both sides of the pair are 10%. The 5% we first seeded on WI515 was
        // the professional-fee logic bleeding into a flat-rate broker code.
        $this->assertSame(1000, $this->rate('WI515', 'individual'));
        $this->assertSame(1000, $this->rate('WC515', 'juridical'));

        // The wrong codes must not resolve to anything at all. If someone
        // re-adds them from the old spec table, this fails loudly.
        foreach (['WI139', 'WI140', 'WC139', 'WC140'] as $wrong) {
            $this->assertSame(0, DB::table('atc_rates')->where('atc_code', $wrong)->count(), "{$wrong} was never a real commissions ATC.");
        }
    }

    /** The seven confirmed payment types, at the rates the CPA signed off. */
    public function test_the_confirmed_rates(): void
    {
        $this->assertSame(500, $this->rate('WI010', 'individual'), 'Professional, within threshold.');
        $this->assertSame(1000, $this->rate('WI011', 'individual'), 'Professional, above threshold.');
        $this->assertSame(1000, $this->rate('WC010', 'juridical'), 'Juridical professional ≤ ₱720k.');
        $this->assertSame(1500, $this->rate('WC011', 'juridical'), 'Juridical professional above.');
        $this->assertSame(500, $this->rate('WC100', 'juridical'), 'Rentals.');
        $this->assertSame(200, $this->rate('WC120', 'juridical'), 'Contractors.');
        $this->assertSame(100, $this->rate('WC158', 'juridical'), 'TWA → goods.');
        $this->assertSame(200, $this->rate('WC160', 'juridical'), 'TWA → services.');
    }

    /**
     * D26 — the sworn-declaration gate belongs ONLY to the professional-fee
     * codes. WI515 (broker/agent commissions) is flat 10% and must NOT be in
     * this set — its presence here was the bug this test now guards against.
     */
    public function test_only_the_professional_fee_rates_depend_on_a_sworn_declaration(): void
    {
        $gated = DB::table('atc_rates')->where('requires_sworn_declaration', true)->pluck('atc_code')->all();

        sort($gated);
        $this->assertSame(['WC010', 'WI010'], $gated);
    }

    /**
     * The government-payment codes stay OUT until a government-payee flow
     * exists to claim them — auto-assigning them to a private purchase is a
     * documented cause of alphalist validation failure (03 §6).
     */
    public function test_government_payment_codes_are_not_seeded(): void
    {
        foreach (['WC157', 'WI157', 'WC640', 'WI640'] as $government) {
            $this->assertSame(0, DB::table('atc_rates')->where('atc_code', $government)->count());
        }
    }

    /**
     * The property that made the wrong codes harmless: an ATC we have not
     * been given REFUSES to resolve. The seeded rows are a confirmed subset,
     * not the whole eBIRForms library, so this is the live safety net until
     * the rest arrives.
     */
    public function test_an_unseeded_atc_refuses_to_resolve_rather_than_guess(): void
    {
        $this->expectException(InvalidDraft::class);
        $this->expectExceptionMessage('No effective ATC rate');

        $this->rate('WC888', 'juridical');
    }

    /** Rates are date-effective: nothing resolves before the table's era. */
    public function test_rates_are_date_effective(): void
    {
        $this->expectException(InvalidDraft::class);

        app(TaxResolver::class)->atcRateBp('WC160', 'juridical', CarbonImmutable::parse('2017-01-01'));
    }
}
