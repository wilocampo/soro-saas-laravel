<?php

namespace App\Domain\Ledger\Posting\Rules;

use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\PostingContext;

/**
 * Turns one document into a BALANCED draft (docs/specs/02 §2). The basis
 * selects the recognition path INSIDE the rule; an intentionally empty
 * draft (cash-basis invoice) means "no GL entry", not "error".
 */
interface PostingRule
{
    public function documentType(): string;

    public function build(object $document, PostingContext $context): JournalDraft;
}
