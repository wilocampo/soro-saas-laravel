<?php

namespace App\Domain\Ledger\Posting;

/** Marks a draft as the mirror of an existing posted entry (docs/specs/02 §5). */
final class ReversalOf
{
    public function __construct(
        public readonly int $journalEntryId,
        public readonly string $reason,
    ) {}
}
