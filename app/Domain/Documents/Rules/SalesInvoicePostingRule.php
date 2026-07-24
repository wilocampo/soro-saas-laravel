<?php

namespace App\Domain\Documents\Rules;

use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Inventory\CostOfSales;
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
 * Accrual: Dr A/R (total) / Cr revenue per line (net) / Cr Output VAT,
 *          **plus Dr COGS / Cr Inventory** for stocked lines (08 §2).
 * Cash:    no revenue until collection, so the invoice posts NO journal
 *          entry — the invoice row and its VAT snapshot still feed A/R aging
 *          and the invoice-date VAT return from the subledger (D28). There is
 *          no cost side to defer, because a cash-basis tenant cannot carry
 *          inventory (D38); a cash-basis invoice is always services-only.
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
            // Revenue (and its output VAT in the GL) waits for collection, so
            // there is no journal entry now. The invoice subledger still
            // carries the VAT snapshot the invoice-date 2550Q reads (D28), and
            // there is no cost to book because a cash-basis tenant cannot hold
            // inventory (D38) — the invoice is services-only.
            return new JournalDraft(
                journalBook: 'sales',
                entryDate: $entryDate,
                memo: "Invoice {$invoice->invoice_number} (cash basis — recognized at collection)",
                source: $source,
                idempotencyKey: $key,
                lines: [],
            );
        }

        // Perpetual COGS (08 §2). Read inside the posting transaction with
        // the item locked, so the journal and the stock movement cannot
        // disagree about what the goods cost.
        $costOfSales = app(CostOfSales::class);
        $costs = $costOfSales->forInvoiceLines((int) $invoice->id);

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

        // Accrual: the cost is recognised in the SAME entry as the revenue,
        // which is what "perpetual" means (08 §2).
        $lines = array_merge($lines, $costOfSales->lines($costs, $context));

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
