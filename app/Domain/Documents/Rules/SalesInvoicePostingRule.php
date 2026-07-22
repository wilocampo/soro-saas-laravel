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
 * Cash:    no revenue until collection. For a services invoice that means
 *          an EMPTY draft — the invoice row and its tax snapshot still feed
 *          A/R aging from the subledger. For a STOCKED invoice the goods
 *          have physically gone, so inventory falls now (or the stock
 *          subledger stops tying to the GL) and the cost parks in Deferred
 *          COGS until the payment rule recognises it with the revenue.
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

        // Perpetual COGS (08 §2). Read inside the posting transaction with
        // the item locked, so the journal and the stock movement cannot
        // disagree about what the goods cost.
        $costOfSales = app(CostOfSales::class);
        $costs = $costOfSales->forInvoiceLines((int) $invoice->id);

        if ($context->basis->isCash()) {
            // Revenue waits for collection — but the GOODS have gone, so
            // inventory must fall now or the stock subledger stops tying to
            // the GL. The cost parks in Deferred COGS until the payment rule
            // recognises it alongside the revenue.
            return new JournalDraft(
                journalBook: 'sales',
                entryDate: $entryDate,
                memo: $costs === []
                    ? "Invoice {$invoice->invoice_number} (cash basis — recognized at collection)"
                    : "Invoice {$invoice->invoice_number} (cash basis — cost deferred, revenue at collection)",
                source: $source,
                idempotencyKey: $key,
                lines: $costOfSales->lines($costs, $context, deferred: true),
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

        // Accrual: the cost is recognised in the SAME entry as the revenue,
        // which is what "perpetual" means (08 §2).
        $lines = array_merge($lines, $costOfSales->lines($costs, $context, deferred: false));

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
