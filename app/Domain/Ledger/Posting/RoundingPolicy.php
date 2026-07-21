<?php

namespace App\Domain\Ledger\Posting;

use App\Domain\Support\Money;

/**
 * The only rounding in the system (docs/specs/02 §3), kept swappable
 * because the exact BIR convention (half-up vs bankers', per-line vs
 * per-invoice) is still a CPA open item (spec 03).
 *
 * Balance by construction: rules compute the COMPONENT lines (net, VAT)
 * with rounding, then define the control line as their arithmetic sum —
 * so the draft balances before it ever reaches the engine.
 */
class RoundingPolicy
{
    /** VAT on a tax-EXCLUSIVE base, half-up to the centavo. */
    public function taxOnNet(int $netCentavos, int $rateBp): int
    {
        return Money::fromCentavos($netCentavos)->multiplyByRateBp($rateBp)->centavos();
    }

    /**
     * Split a tax-INCLUSIVE gross into [net, tax] that reconstitute the
     * gross exactly (tax is the remainder, never independently rounded).
     *
     * @return array{0:int, 1:int}
     */
    public function splitInclusive(int $grossCentavos, int $rateBp): array
    {
        [$net, $tax] = Money::fromCentavos($grossCentavos)->splitInclusiveByRateBp($rateBp);

        return [$net->centavos(), $tax->centavos()];
    }

    /**
     * Largest-remainder (Hamilton) pro-rata split — the parts ALWAYS sum to
     * the whole, which is what keeps partial-payment recognition balanced.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    public function allocate(int $totalCentavos, array $weights): array
    {
        return array_map(
            fn (Money $part) => $part->centavos(),
            Money::fromCentavos($totalCentavos)->allocate($weights),
        );
    }
}
