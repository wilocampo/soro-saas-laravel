<?php

namespace Tests\Support;

use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;

/**
 * Lightweight generative harness (docs/specs/05) — random VALID balanced
 * postings and random INVALID ones, from a fixed seed for reproducibility.
 */
class LedgerGenerator
{
    /** @param  int[]  $accountIds  postable account ids to draw from */
    public function __construct(
        private readonly array $accountIds,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        int $seed = 4242,
    ) {
        mt_srand($seed);
    }

    public function validDraft(int $index): JournalDraft
    {
        $total = mt_rand(1, 5_000_000); // up to ₱50,000.00
        $debits = $this->split($total, mt_rand(1, 3));
        $credits = $this->split($total, mt_rand(1, 3));

        $lines = [];
        foreach ($debits as $amount) {
            $lines[] = new JournalLineDraft(accountId: $this->pickAccount(), debitCentavos: $amount);
        }
        foreach ($credits as $amount) {
            $lines[] = new JournalLineDraft(accountId: $this->pickAccount(), creditCentavos: $amount);
        }

        return new JournalDraft(
            journalBook: 'general',
            entryDate: $this->pickDate(),
            memo: "generated #{$index}",
            source: SourceRef::none(),
            idempotencyKey: "generated:{$index}",
            lines: $lines,
        );
    }

    /** Same shape but guaranteed unbalanced (off by 1..99 centavos). */
    public function unbalancedDraft(int $index): JournalDraft
    {
        $valid = $this->validDraft($index);
        $lines = $valid->lines;
        $first = $lines[0];
        $lines[0] = new JournalLineDraft(
            accountId: $first->accountId,
            debitCentavos: $first->debitCentavos > 0 ? $first->debitCentavos + mt_rand(1, 99) : 0,
            creditCentavos: $first->creditCentavos > 0 ? $first->creditCentavos + mt_rand(1, 99) : 0,
        );

        return new JournalDraft(
            journalBook: $valid->journalBook,
            entryDate: $valid->entryDate,
            memo: "unbalanced #{$index}",
            source: SourceRef::none(),
            idempotencyKey: "unbalanced:{$index}",
            lines: $lines,
        );
    }

    /** Exact integer split that always sums to the whole (largest remainder). */
    private function split(int $total, int $parts): array
    {
        if ($parts === 1) {
            return [$total];
        }

        $cuts = [];
        for ($i = 0; $i < $parts - 1; $i++) {
            $cuts[] = mt_rand(1, max(1, $total - 1));
        }
        sort($cuts);

        $amounts = [];
        $previous = 0;
        foreach ($cuts as $cut) {
            $amounts[] = max(1, $cut - $previous);
            $previous = $cut;
        }
        $amounts[] = max(1, $total - $previous);

        // Fix drift from the max(1, …) floors so the parts sum exactly.
        $drift = array_sum($amounts) - $total;
        $amounts[array_key_last($amounts)] -= $drift;
        if ($amounts[array_key_last($amounts)] < 1) {
            return [$total]; // degenerate split — fall back to one line
        }

        return $amounts;
    }

    private function pickAccount(): int
    {
        return $this->accountIds[mt_rand(0, count($this->accountIds) - 1)];
    }

    private function pickDate(): CarbonImmutable
    {
        $days = (int) $this->from->diffInDays($this->to);

        return $this->from->addDays(mt_rand(0, max(0, $days)));
    }
}
