<?php

namespace App\Domain\Inventory\Rules;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\PostingContext;
use App\Domain\Ledger\Posting\Rules\PostingRule;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;

/**
 * Stock adjustment → journal (docs/specs/08 §3).
 *
 *   short (shrinkage, spoilage, damage): Dr Shrinkage expense / Cr Inventory
 *   over  (found stock):                 Dr Inventory / Cr Shrinkage expense
 *
 * ⚠ D16 is a CPA sign-off item: found stock credited back to the shrinkage
 * expense (netting within the period) versus Other Income. We credit the
 * expense — it is almost always a correction of an earlier over-statement of
 * shrinkage, not new income — but the account is per item
 * (`items.adjustment_account_id`), so a tenant can be re-pointed without a
 * code change once its accountant rules.
 *
 * Posted in BOTH bases. An adjustment is not a recognition event driven by
 * cash: the goods are genuinely gone (or genuinely there), and a cash-basis
 * registrant's balance sheet must say so.
 */
class StockAdjustmentPostingRule implements PostingRule
{
    public function documentType(): string
    {
        return 'stock_adjustment';
    }

    public function build(object $document, PostingContext $context): JournalDraft
    {
        /** @var StockAdjustment $adjustment */
        $adjustment = $document;

        $lines = [];
        $inventoryDelta = 0;

        foreach ($adjustment->lines as $line) {
            $value = (int) $line->value_centavos;

            if ($value === 0) {
                continue;
            }

            $inventoryDelta += $value;

            // A short (negative value) debits the expense; found stock
            // credits it back.
            $lines[] = new JournalLineDraft(
                accountId: (int) ($line->item->adjustment_account_id ?? $context->accounts->id('inventory_adjustment')),
                debitCentavos: $value < 0 ? -$value : 0,
                creditCentavos: $value > 0 ? $value : 0,
                memo: ucfirst($adjustment->reason).' — '.$line->item->name,
                sourceLineRef: "line:{$line->line_no}",
            );
        }

        if ($inventoryDelta !== 0) {
            // Inventory is the mirror of the expense side, so the entry
            // balances by construction (02 §3).
            $lines[] = new JournalLineDraft(
                accountId: $context->accounts->id('inventory'),
                debitCentavos: $inventoryDelta > 0 ? $inventoryDelta : 0,
                creditCentavos: $inventoryDelta < 0 ? -$inventoryDelta : 0,
                memo: 'Inventory adjustment',
            );
        }

        return new JournalDraft(
            journalBook: 'general',
            entryDate: CarbonImmutable::parse($adjustment->adjustment_date->toDateString()),
            memo: "Stock adjustment {$adjustment->reference} ({$adjustment->reason})",
            source: new SourceRef('stock_adjustment', (int) $adjustment->id),
            idempotencyKey: "stock_adjustment:{$adjustment->id}:post:1",
            lines: $lines,
        );
    }
}
