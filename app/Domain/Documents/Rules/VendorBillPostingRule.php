<?php

namespace App\Domain\Documents\Rules;

use App\Domain\Documents\Models\VendorBill;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\PartyRef;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\Rules\PostingRule;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;

/**
 * Vendor bill → journal (docs/specs/02 §4.3, fixture S5).
 *
 * Accrual: Dr expense/asset per line / Dr Input VAT / Cr A/P (payable) /
 *          Cr Withholding Tax Payable. **EWT accrues at the BOOKING date,
 *          not at payment (RR 4-2024)** — which is why the liability is
 *          recognized here rather than in the disbursement rule.
 * Cash:    empty draft; recognition happens at payment.
 */
class VendorBillPostingRule implements PostingRule
{
    public function documentType(): string
    {
        return 'vendor_bill';
    }

    public function build(object $document, PostingContext $context): JournalDraft
    {
        /** @var VendorBill $bill */
        $bill = $document;

        $entryDate = CarbonImmutable::parse($bill->bill_date->toDateString());
        $source = new SourceRef('vendor_bill', (int) $bill->id);
        $key = "vendor_bill:{$bill->id}:post:1";

        if ($context->basis->isCash()) {
            return new JournalDraft(
                journalBook: 'purchase',
                entryDate: $entryDate,
                memo: "Bill {$bill->bill_number} (cash basis — recognized at payment)",
                source: $source,
                idempotencyKey: $key,
                lines: [],
            );
        }

        $party = new PartyRef('vendor', (int) $bill->partner_id);
        $lines = [];

        $costed = $bill->lines->filter(fn ($line) => (int) $line->net_centavos > 0)->values();

        // A NON-VAT registrant cannot CLAIM input tax — the VAT a VAT-
        // registered supplier charges it is part of the cost of the purchase
        // (spec 03 §5). Spread it back over the lines by largest remainder so
        // the pieces sum to the VAT exactly.
        $foldVatIntoCost = ! $context->isVatRegistered && (int) $bill->input_vat_centavos > 0;
        $vatShare = $foldVatIntoCost && $costed->isNotEmpty()
            ? $context->rounding->allocate(
                (int) $bill->input_vat_centavos,
                $costed->map(fn ($line) => (int) $line->net_centavos)->all(),
            )
            : [];

        foreach ($costed as $index => $line) {
            $lines[] = new JournalLineDraft(
                accountId: (int) $line->account_id,
                debitCentavos: (int) $line->net_centavos + ($vatShare[$index] ?? 0),
                memo: $line->description,
                taxCodeId: $line->tax_code_id === null ? null : (int) $line->tax_code_id,
                taxBaseCentavos: (int) $line->net_centavos,
                party: $party,
                sourceLineRef: "line:{$line->line_no}",
            );
        }

        if ($bill->input_vat_centavos > 0 && ! $foldVatIntoCost) {
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id('input_vat'),
                debitCentavos: $bill->input_vat_centavos,
                memo: 'Input VAT',
                taxBaseCentavos: $bill->net_centavos,
            );
        }

        // A/P is the plug BY DEFINITION: net + input VAT − EWT withheld.
        $lines[] = new JournalLineDraft(
            accountId: $context->accounts->id('ap'),
            creditCentavos: $bill->total_centavos,
            memo: "Bill {$bill->bill_number}",
            party: $party,
        );

        if ($bill->ewt_centavos > 0) {
            // Tax WE withhold from the vendor and remit (0619E/1601EQ) — a
            // liability, distinct from the creditable-withholding ASSET that
            // customers withhold from us (02 §4.3 note).
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id('wht_payable'),
                creditCentavos: $bill->ewt_centavos,
                memo: 'Withholding tax payable',
                taxBaseCentavos: $bill->net_centavos,
                atcCode: $bill->atc_code,
                party: $party,
            );
        }

        return new JournalDraft(
            journalBook: 'purchase',
            entryDate: $entryDate,
            memo: "Bill {$bill->bill_number}",
            source: $source,
            idempotencyKey: $key,
            lines: $lines,
        );
    }
}
