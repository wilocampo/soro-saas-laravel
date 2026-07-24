<?php

namespace App\Domain\Inventory;

use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\PostingContext;
use Illuminate\Support\Facades\DB;

/**
 * Perpetual cost of sales (docs/specs/08 §2).
 *
 * A sale of an inventory item relieves stock at the moving average, in the
 * SAME journal entry as the revenue. Two things make that safe:
 *
 *  1. The item is locked (`FOR UPDATE`) while its average is read, so a
 *     concurrent receipt cannot move the average between the figure that
 *     lands in the journal and the figure the stock movement records.
 *  2. `cogs_centavos` is captured on the invoice LINE, so a later reversal
 *     books the cost the goods actually left at, not today's average.
 *
 * **Basis.** Inventory is accrual-only: a tenant carrying stock cannot keep
 * cash-basis books (D38), so the cost always books straight to COGS in the
 * same entry as the revenue. (Phase 2b briefly parked cash-basis cost in a
 * Deferred COGS holding account; that path was removed once D38 barred the
 * combination that needed it.)
 */
class CostOfSales
{
    /**
     * COGS per invoice line, in centavos, keyed by line id. Locks each item.
     *
     * @return array<int, array{item_id:int, location_id:int, qty:string, cogs_centavos:int}>
     */
    public function forInvoiceLines(int $invoiceId): array
    {
        $lines = DB::table('sales_invoice_lines as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->where('l.sales_invoice_id', $invoiceId)
            ->where('i.item_type', 'inventory')
            ->orderBy('l.line_no')
            ->get(['l.id', 'l.item_id', 'l.location_id', 'l.quantity']);

        $costs = [];

        foreach ($lines as $line) {
            // Lock before reading the average: a receipt committing between
            // the read and the movement would make the journal and the stock
            // ledger disagree about what the goods cost.
            $average = (string) DB::table('items')
                ->where('id', $line->item_id)
                ->lockForUpdate()
                ->value('avg_cost');

            $qty = (string) $line->quantity;

            $costs[(int) $line->id] = [
                'item_id' => (int) $line->item_id,
                'location_id' => (int) ($line->location_id ?? Models\Location::default()->id),
                'qty' => $qty,
                'cogs_centavos' => (int) round((float) $qty * (float) $average * 100),
            ];
        }

        return $costs;
    }

    /**
     * The journal lines for a sale's cost side: Dr COGS per item, Cr Inventory.
     *
     * @param  array<int, array{item_id:int, location_id:int, qty:string, cogs_centavos:int}>  $costs
     * @return list<JournalLineDraft>
     */
    public function lines(array $costs, PostingContext $context): array
    {
        $total = array_sum(array_column($costs, 'cogs_centavos'));

        if ($total === 0) {
            return [];
        }

        $lines = [];

        foreach ($costs as $cost) {
            if ($cost['cogs_centavos'] === 0) {
                continue;
            }

            $lines[] = new JournalLineDraft(
                accountId: $this->cogsAccountFor($cost['item_id'], $context),
                debitCentavos: $cost['cogs_centavos'],
                memo: 'Cost of goods sold',
            );
        }

        // The inventory credit is the sum of the debits, so the cost side
        // balances by construction (02 §3).
        $lines[] = new JournalLineDraft(
            accountId: $context->accounts->id('inventory'),
            creditCentavos: $total,
            memo: 'Inventory relieved at moving average',
        );

        return $lines;
    }

    /** The item's own COGS account when it has one; the COGS role otherwise. */
    private function cogsAccountFor(int $itemId, PostingContext $context): int
    {
        $itemAccount = DB::table('items')->where('id', $itemId)->value('cogs_account_id');

        return $itemAccount === null ? $context->accounts->id('cogs') : (int) $itemAccount;
    }
}
