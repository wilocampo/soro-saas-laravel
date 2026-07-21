<?php

namespace App\Domain\Documents\Rules;

use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\PartyRef;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\Rules\PostingRule;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;

/**
 * Sales invoice → journal (docs/specs/02 §4.1, fixtures S2/S3).
 *
 * Accrual: Dr A/R (total) / Cr revenue per line (net) / Cr Output VAT.
 * Cash:    an EMPTY draft — no GL entry exists until collection. The
 *          invoice row and its tax snapshot still feed A/R aging from the
 *          subledger, which is legitimate for a cash-basis registrant.
 */
class SalesInvoicePostingRule implements PostingRule
{
    public function documentType(): string
    {
        return 'sales_invoice';
    }

    public function build(object $document, PostingContext $context): JournalDraft
    {
        /** @var SalesInvoice $invoice */
        $invoice = $document;

        $entryDate = CarbonImmutable::parse($invoice->invoice_date->toDateString());
        $source = new SourceRef('sales_invoice', (int) $invoice->id);
        $key = "sales_invoice:{$invoice->id}:post:1";

        if ($context->basis->isCash()) {
            return new JournalDraft(
                journalBook: 'sales',
                entryDate: $entryDate,
                memo: "Invoice {$invoice->invoice_number} (cash basis — recognized at collection)",
                source: $source,
                idempotencyKey: $key,
                lines: [],
            );
        }

        $context->assertMayShiftVat((int) $invoice->vat_centavos, "Invoice {$invoice->invoice_number}");

        $party = new PartyRef('customer', (int) $invoice->partner_id);
        $lines = [];

        // Control line first: A/R is DEFINED as the sum of the components,
        // so the entry balances by construction (02 §3).
        $lines[] = new JournalLineDraft(
            accountId: $context->accounts->id('ar'),
            debitCentavos: $invoice->total_centavos,
            memo: "Invoice {$invoice->invoice_number}",
            party: $party,
        );

        foreach ($invoice->lines as $line) {
            if ((int) $line->net_centavos === 0) {
                continue;
            }

            $lines[] = new JournalLineDraft(
                accountId: (int) $line->account_id,
                creditCentavos: (int) $line->net_centavos,
                memo: $line->description,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                taxBaseCentavos: (int) $line->net_centavos,
                party: $party,
                sourceLineRef: "line:{$line->line_no}",
            );
        }

        if ($invoice->vat_centavos > 0) {
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id('output_vat'),
                creditCentavos: $invoice->vat_centavos,
                memo: 'Output VAT',
                taxBaseCentavos: $invoice->net_centavos,
            );
        }

        return new JournalDraft(
            journalBook: 'sales',
            entryDate: $entryDate,
            memo: "Invoice {$invoice->invoice_number}",
            source: $source,
            idempotencyKey: $key,
            lines: $lines,
        );
    }
}
