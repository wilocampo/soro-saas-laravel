<?php

namespace App\Domain\Ledger\Posting;

use Carbon\CarbonImmutable;

/**
 * Immutable posting intent (docs/specs/02 §1). $fiscalPeriodId routes the
 * year_end_close/adjustment books to period 13 explicitly — a date lookup is
 * ambiguous when periods 12 and 13 both cover the FY end date (01 §7).
 */
final class JournalDraft
{
    /** @param  JournalLineDraft[]  $lines */
    public function __construct(
        public readonly string $journalBook,
        public readonly CarbonImmutable $entryDate,
        public readonly string $memo,
        public readonly SourceRef $source,
        public readonly string $idempotencyKey,
        public readonly array $lines,
        public readonly ?ReversalOf $reverses = null,
        public readonly ?int $fiscalPeriodId = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function totalDebit(): int
    {
        return array_sum(array_map(fn (JournalLineDraft $l) => $l->debitCentavos, $this->lines));
    }

    public function totalCredit(): int
    {
        return array_sum(array_map(fn (JournalLineDraft $l) => $l->creditCentavos, $this->lines));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit() === $this->totalCredit();
    }
}
