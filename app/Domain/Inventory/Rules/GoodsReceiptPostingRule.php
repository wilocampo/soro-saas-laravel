<?php

namespace App\Domain\Inventory\Rules;

use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\PartyRef;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\Rules\PostingRule;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;

/**
 * Goods receipt → journal (docs/specs/08 §3, D15).
 *
 *   Dr Inventory (per item's own inventory account, at actual cost)
 *   Cr Goods Received Not Invoiced
 *
 * GRNI rather than A/P is the whole point: the liability exists the moment
 * the goods arrive, but it is not yet a payable to a specific invoice. When
 * the vendor's bill arrives it clears GRNI to A/P — so the balance sheet is
 * never quietly wrong in the gap between delivery and paperwork, which is
 * exactly where SMEs lose track.
 *
 * Cash basis: still posted. Inventory is an ASSET movement, not a
 * recognition event — a cash-basis registrant that received goods really
 * does hold them, and its balance sheet must say so. What waits for cash is
 * the EXPENSE, and that is COGS at sale, handled by the invoice rule.
 */
class GoodsReceiptPostingRule implements PostingRule
{
    public function documentType(): string
    {
        return 'goods_receipt';
    }

    public function build(object $document, PostingContext $context): JournalDraft
    {
        /** @var GoodsReceipt $receipt */
        $receipt = $document;

        $entryDate = CarbonImmutable::parse($receipt->received_date->toDateString());
        $party = $receipt->partner_id === null ? null : new PartyRef('vendor', (int) $receipt->partner_id);
        $lines = [];

        foreach ($receipt->lines as $line) {
            if ((int) $line->line_cost_centavos === 0) {
                continue;
            }

            $lines[] = new JournalLineDraft(
                // The ITEM's account, not a global one: two items may
                // capitalise to different inventory accounts (08 §1).
                accountId: (int) ($line->item->inventory_account_id ?? $context->accounts->id('inventory')),
                debitCentavos: (int) $line->line_cost_centavos,
                memo: $line->item->name,
                party: $party,
                sourceLineRef: "line:{$line->line_no}",
            );
        }

        if ($lines !== []) {
            // The GRNI credit is DEFINED as the sum of the debits, so the
            // entry balances by construction (02 §3).
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id('grni'),
                creditCentavos: (int) $receipt->total_cost_centavos,
                memo: 'Goods received not invoiced'
                    .($receipt->vendor_reference === null ? '' : " — {$receipt->vendor_reference}"),
                party: $party,
            );
        }

        return new JournalDraft(
            journalBook: 'purchase',
            entryDate: $entryDate,
            memo: "Goods receipt {$receipt->reference}",
            source: new SourceRef('goods_receipt', (int) $receipt->id),
            idempotencyKey: "goods_receipt:{$receipt->id}:post:1",
            lines: $lines,
        );
    }
}
