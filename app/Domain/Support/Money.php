<?php

namespace App\Domain\Support;

use InvalidArgumentException;

/**
 * Money as integer centavos (BIGINT minor units) — CLAUDE.md rule 5.
 * Single currency (PHP) v1. All arithmetic is exact integer math; the only
 * rounding rules in the system live HERE (spec 02 §3):
 *  - multiplyByRateBp(): half-up to the centavo, symmetric for negatives
 *  - splitInclusiveByRateBp(): net derived, vat = gross − net (reconstitutes exactly)
 *  - allocate(): largest-remainder (Hamilton) — parts always sum to the whole
 */
final readonly class Money
{
    private function __construct(
        private int $centavos,
    ) {}

    public static function fromCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function centavos(): int
    {
        return $this->centavos;
    }

    public function isZero(): bool
    {
        return $this->centavos === 0;
    }

    public function isNegative(): bool
    {
        return $this->centavos < 0;
    }

    public function plus(self $other): self
    {
        return new self($this->centavos + $other->centavos);
    }

    public function minus(self $other): self
    {
        return new self($this->centavos - $other->centavos);
    }

    public function negate(): self
    {
        return new self(-$this->centavos);
    }

    public function equals(self $other): bool
    {
        return $this->centavos === $other->centavos;
    }

    /**
     * Apply a basis-point rate (12% = 1200 bp), rounding HALF-UP to the
     * centavo. Symmetric (away from zero) for negative bases — credit
     * memos must mirror their originals exactly.
     */
    public function multiplyByRateBp(int $rateBp): self
    {
        $sign = $this->centavos < 0 ? -1 : 1;

        return new self($sign * intdiv(abs($this->centavos) * $rateBp + 5000, 10000));
    }

    /**
     * Split a tax-INCLUSIVE gross into [net, vat] such that net + vat
     * reconstitutes the gross exactly: net is derived half-up, vat is the
     * remainder (spec 02 §3).
     *
     * @return array{0: self, 1: self}
     */
    public function splitInclusiveByRateBp(int $rateBp): array
    {
        $sign = $this->centavos < 0 ? -1 : 1;
        $gross = abs($this->centavos);

        $net = intdiv($gross * 10000 + intdiv(10000 + $rateBp, 2), 10000 + $rateBp);
        $net = new self($sign * $net);

        return [$net, $this->minus($net)];
    }

    /**
     * Largest-remainder (Hamilton) allocation across positive integer
     * weights. The parts ALWAYS sum to the whole — this is how pro-rata
     * splits (payments across invoices, discounts across lines) stay
     * balanced (spec 02 §3).
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('allocate() requires at least one weight.');
        }

        foreach ($weights as $w) {
            if (! is_int($w) || $w <= 0) {
                throw new InvalidArgumentException('allocate() weights must be positive integers.');
            }
        }

        $sign = $this->centavos < 0 ? -1 : 1;
        $total = abs($this->centavos);
        $sumWeights = array_sum($weights);

        $parts = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $i => $w) {
            $exact = $total * $w;
            $parts[$i] = intdiv($exact, $sumWeights);
            $remainders[$i] = $exact % $sumWeights;
            $allocated += $parts[$i];
        }

        // Hand leftover centavos to the largest remainders (stable by index).
        $leftover = $total - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($leftover <= 0) {
                break;
            }
            $parts[$i]++;
            $leftover--;
        }

        ksort($parts);

        return array_map(fn (int $p) => new self($sign * $p), $parts);
    }

    /** "₱1,234.56" / "-₱1,234.56" */
    public function format(): string
    {
        return ($this->centavos < 0 ? '-' : '').'₱'.$this->formatAbs();
    }

    /** "1,234.56" (sign-less magnitude with sign prefix when negative) */
    public function formatBare(): string
    {
        return ($this->centavos < 0 ? '-' : '').$this->formatAbs();
    }

    private function formatAbs(): string
    {
        return number_format(abs($this->centavos) / 100, 2);
    }
}
