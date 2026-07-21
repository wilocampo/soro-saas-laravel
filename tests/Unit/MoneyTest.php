<?php

namespace Tests\Unit;

use App\Domain\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_construction_and_accessors(): void
    {
        $m = Money::fromCentavos(112000);
        $this->assertSame(112000, $m->centavos());
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::fromCentavos(-5)->isNegative());
    }

    public function test_arithmetic_is_exact_integer_math(): void
    {
        $a = Money::fromCentavos(1000000);
        $b = Money::fromCentavos(120000);

        $this->assertSame(1120000, $a->plus($b)->centavos());
        $this->assertSame(880000, $a->minus($b)->centavos());
        $this->assertSame(-1000000, $a->negate()->centavos());
        $this->assertTrue($a->equals(Money::fromCentavos(1000000)));
    }

    public function test_rate_bp_half_up_matches_spec_examples(): void
    {
        // Spec 02 §3: vat = intdiv(base*rate_bp + 5000, 10000); 12% = 1200 bp
        $this->assertSame(120000, Money::fromCentavos(1000000)->multiplyByRateBp(1200)->centavos()); // ₱10,000 → ₱1,200
        $this->assertSame(12, Money::fromCentavos(100)->multiplyByRateBp(1200)->centavos());

        // Half-up at the boundary: 0.375 → 0.38 ; 0.374 → 0.37 (values in centavos)
        $this->assertSame(38, Money::fromCentavos(375)->multiplyByRateBp(1000)->centavos());  // 10% of 3.75 = 0.375 → 0.38
        $this->assertSame(37, Money::fromCentavos(374)->multiplyByRateBp(1000)->centavos());
    }

    public function test_rate_bp_negative_base_is_symmetric(): void
    {
        // Credit memos: rounding must be symmetric (away from zero), not truncated
        $this->assertSame(-38, Money::fromCentavos(-375)->multiplyByRateBp(1000)->centavos());
        $this->assertSame(-120000, Money::fromCentavos(-1000000)->multiplyByRateBp(1200)->centavos());
    }

    public function test_vat_exclusive_from_inclusive_gross_reconstitutes_exactly(): void
    {
        // Spec 02 §3: net = round(gross*10000/(10000+rate_bp)); vat = gross − net
        $gross = Money::fromCentavos(1120000); // ₱11,200 VAT-inclusive
        [$net, $vat] = $gross->splitInclusiveByRateBp(1200);

        $this->assertSame(1000000, $net->centavos());
        $this->assertSame(120000, $vat->centavos());
        $this->assertSame($gross->centavos(), $net->plus($vat)->centavos()); // always exact
    }

    public function test_allocate_largest_remainder_sums_exactly(): void
    {
        // Spec 02 §3 worked example: ₱1,000.00 across 3 equal parts
        $parts = Money::fromCentavos(100000)->allocate([1, 1, 1]);

        $this->assertSame([33334, 33333, 33333], array_map(fn ($p) => $p->centavos(), $parts));
        $this->assertSame(100000, array_sum(array_map(fn ($p) => $p->centavos(), $parts)));

        // Weighted allocation preserves the total exactly
        $weighted = Money::fromCentavos(99999)->allocate([500, 250, 250]);
        $this->assertSame(99999, array_sum(array_map(fn ($p) => $p->centavos(), $weighted)));
    }

    public function test_allocate_negative_total(): void
    {
        $parts = Money::fromCentavos(-100000)->allocate([1, 1, 1]);
        $this->assertSame(-100000, array_sum(array_map(fn ($p) => $p->centavos(), $parts)));
        $this->assertSame([-33334, -33333, -33333], array_map(fn ($p) => $p->centavos(), $parts));
    }

    public function test_allocate_rejects_empty_or_nonpositive_weights(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromCentavos(100)->allocate([]);
    }

    public function test_formatting(): void
    {
        $this->assertSame('₱11,200.00', Money::fromCentavos(1120000)->format());
        $this->assertSame('₱0.05', Money::fromCentavos(5)->format());
        $this->assertSame('-₱1,234.56', Money::fromCentavos(-123456)->format());
        $this->assertSame('11,200.00', Money::fromCentavos(1120000)->formatBare());
    }
}
