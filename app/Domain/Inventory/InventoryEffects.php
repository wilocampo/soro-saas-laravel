<?php

namespace App\Domain\Inventory;

use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Ledger\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The stock side of a document, recorded in the SAME transaction as its
 * journal entry (docs/specs/08 §3).
 *
 * Money and quantity move together or not at all. `DocumentPoster` calls
 * this immediately after the post, still inside the transaction, so a
 * failure anywhere unwinds both — there is no window in which the journal
 * says goods were received and the stock ledger disagrees.
 */
class InventoryEffects
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly CostOfSales $costOfSales,
    ) {}

    public function apply(Model $document, ?JournalEntry $entry): void
    {
        match (true) {
            $document instanceof GoodsReceipt => $this->receive($document, $entry),
            $document instanceof SalesInvoice => $this->ship($document, $entry),
            default => null,
        };
    }

    /** Receipts add stock at their ACTUAL cost, which moves the average. */
    private function receive(GoodsReceipt $receipt, ?JournalEntry $entry): void
    {
        if ($receipt->getAttribute('status') === 'cancelled') {
            return;
        }

        foreach ($receipt->lines as $line) {
            $this->stock->record(
                itemId: (int) $line->item_id,
                locationId: (int) $receipt->location_id,
                movementType: 'receive',
                qtyDelta: (string) $line->qty_stock,
                unitCost: (string) $line->unit_cost,
                sourceType: 'goods_receipt',
                sourceId: (int) $receipt->id,
                journalEntryId: $entry?->id,
            );
        }
    }

    /**
     * Sales relieve stock at the moving average. The cost is written back to
     * the invoice line so a cash-basis collection — which may recognise
     * months later, at a different average — books the cost that applied to
     * the goods actually shipped.
     */
    private function ship(SalesInvoice $invoice, ?JournalEntry $entry): void
    {
        if ($invoice->getAttribute('status') === 'cancelled') {
            return;
        }

        // Same transaction, item already locked by the posting rule, so this
        // returns exactly the figures that went into the journal.
        $costs = $this->costOfSales->forInvoiceLines((int) $invoice->id);

        foreach ($costs as $lineId => $cost) {
            $this->stock->record(
                itemId: $cost['item_id'],
                locationId: $cost['location_id'],
                movementType: 'sale',
                qtyDelta: '-'.ltrim($cost['qty'], '-'),
                sourceType: 'sales_invoice',
                sourceId: (int) $invoice->id,
                journalEntryId: $entry?->id,
            );

            DB::table('sales_invoice_lines')->where('id', $lineId)
                ->update(['cogs_centavos' => $cost['cogs_centavos']]);
        }
    }
}
