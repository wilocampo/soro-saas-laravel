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
 *  2. `cogs_centavos` is captured on the invoice LINE. Under cash basis
 *     recognition happens at collection — possibly months later, at a
 *     different average — and the cost that must be booked is the one that
 *     applied to the goods actually shipped.
 *
 * **Basis and the tie-out.** Inventory falls when the goods leave, in both
 * bases, or the stock subledger stops tying to the GL. What differs is
 * where the cost lands: accrual books it straight to COGS; cash books it to
 * **Deferred COGS** (an asset) and the payment rule moves it to COGS when
 * revenue is recognised. That keeps `inventory:verify` exact in both bases
 * without deferring the asset movement itself.
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
     * The journal lines for a sale's cost side.
     *
     * @param  array<int, array{item_id:int, location_id:int, qty:string, cogs_centavos:int}>  $costs
     * @return list<JournalLineDraft>
     */
    public function lines(array $costs, PostingContext $context, bool $deferred): array
    {
        $total = array_sum(array_column($costs, 'cogs_centavos'));

        if ($total === 0) {
            return [];
        }

        $debitRole = $deferred ? 'deferred_cogs' : 'cogs';
        $lines = [];

        foreach ($costs as $cost) {
            if ($cost['cogs_centavos'] === 0) {
                continue;
            }

            $lines[] = new JournalLineDraft(
                accountId: $this->accountFor($cost['item_id'], $debitRole, $context),
                debitCentavos: $cost['cogs_centavos'],
                memo: $deferred ? 'Deferred cost of goods sold' : 'Cost of goods sold',
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

    /**
     * The item's own COGS account when it has one; the role otherwise. The
     * deferred account is deliberately NOT per item — it is a holding
     * account, and splitting it would make the later reclass ambiguous.
     */
    private function accountFor(int $itemId, string $role, PostingContext $context): int
    {
        if ($role === 'deferred_cogs') {
            return $context->accounts->id('deferred_cogs');
        }

        $itemAccount = DB::table('items')->where('id', $itemId)->value('cogs_account_id');

        return $itemAccount === null ? $context->accounts->id('cogs') : (int) $itemAccount;
    }
}
