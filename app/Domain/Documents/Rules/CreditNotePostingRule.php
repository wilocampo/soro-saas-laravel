<?php

namespace App\Domain\Documents\Rules;

use App\Domain\Documents\Models\CreditNote;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\PartyRef;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\Rules\PostingRule;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;

/**
 * Credit / debit note → journal (docs/specs/03 §4).
 *
 * Customer credit note (a return, allowance or post-issue discount):
 *   Dr Sales Returns & Allowances (per line) / Dr Output VAT / Cr A/R
 * Vendor credit note (we return goods, or the vendor grants a discount):
 *   Dr A/P / Cr expense per line / Cr Input VAT
 * A DEBIT note is the same entry with the sides swapped — it increases what
 * is owed rather than reducing it.
 *
 * Cash basis: an empty draft. Nothing was ever recognized for an unpaid
 * document, and a refund of a paid one moves cash, so it is the refund
 * PAYMENT that posts — not the note.
 */
class CreditNotePostingRule implements PostingRule
{
    public function documentType(): string
    {
        return 'credit_note';
    }

    public function build(object $document, PostingContext $context): JournalDraft
    {
        /** @var CreditNote $note */
        $note = $document;

        $isCustomer = $note->side === 'customer';
        $entryDate = CarbonImmutable::parse($note->note_date->toDateString());
        $source = new SourceRef('credit_note', (int) $note->id);
        $key = "credit_note:{$note->id}:post:1";
        $book = $isCustomer ? 'sales' : 'purchase';
        $label = ($note->isCredit() ? 'Credit note ' : 'Debit note ').$note->note_number;

        if ($context->basis->isCash()) {
            return new JournalDraft(
                journalBook: $book,
                entryDate: $entryDate,
                memo: "{$label} (cash basis — the refund payment posts, not the note)",
                source: $source,
                idempotencyKey: $key,
                lines: [],
            );
        }

        $context->assertMayShiftVat((int) $note->vat_centavos, $label);

        $party = new PartyRef($isCustomer ? 'customer' : 'vendor', (int) $note->partner_id);

        // A customer CREDIT note debits the components and credits A/R; every
        // other combination flips one of those two sides. XOR gives the four
        // cases without four near-identical branches.
        $componentsAreDebits = $isCustomer === $note->isCredit();

        $lines = [];

        // Control line first: defined as the sum of the components (02 §3).
        $lines[] = new JournalLineDraft(
            accountId: $context->accounts->id($isCustomer ? 'ar' : 'ap'),
            debitCentavos: $componentsAreDebits ? 0 : (int) $note->total_centavos,
            creditCentavos: $componentsAreDebits ? (int) $note->total_centavos : 0,
            memo: $label,
            party: $party,
        );

        foreach ($note->lines as $line) {
            if ((int) $line->net_centavos === 0) {
                continue;
            }

            $lines[] = new JournalLineDraft(
                accountId: (int) $line->account_id,
                debitCentavos: $componentsAreDebits ? (int) $line->net_centavos : 0,
                creditCentavos: $componentsAreDebits ? 0 : (int) $line->net_centavos,
                memo: $line->description,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                taxBaseCentavos: (int) $line->net_centavos,
                party: $party,
                sourceLineRef: "line:{$line->line_no}",
            );
        }

        if ($note->vat_centavos > 0) {
            // The VAT follows the note: a customer credit note REVERSES
            // output VAT already declared, so it lands on the debit side.
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id($isCustomer ? 'output_vat' : 'input_vat'),
                debitCentavos: $componentsAreDebits ? (int) $note->vat_centavos : 0,
                creditCentavos: $componentsAreDebits ? 0 : (int) $note->vat_centavos,
                memo: $isCustomer ? 'Output VAT adjustment' : 'Input VAT adjustment',
                taxBaseCentavos: (int) $note->net_centavos,
            );
        }

        return new JournalDraft(
            journalBook: $book,
            entryDate: $entryDate,
            memo: "{$label}: {$note->reason}",
            source: $source,
            idempotencyKey: $key,
            lines: $lines,
        );
    }
}
