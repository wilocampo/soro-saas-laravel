<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Posting\JournalDraft;
use Carbon\CarbonImmutable;

/**
 * THE choke point (docs/specs/02 §1). Nothing writes journal_entries /
 * journal_lines except implementations of this contract.
 */
interface PostingService
{
    /** Idempotent. Returns null for an intentionally empty draft (e.g. a cash-basis invoice). */
    public function post(JournalDraft $draft): ?JournalEntry;

    public function reverse(JournalEntry $original, string $reason, ?CarbonImmutable $date = null): JournalEntry;

    public function void(JournalEntry $original, string $reason): JournalEntry;

    /** Pure — validates and returns the draft, writes nothing. For UI preview. */
    public function preview(JournalDraft $draft): JournalDraft;
}
