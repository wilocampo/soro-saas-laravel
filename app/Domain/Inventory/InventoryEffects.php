<?php

namespace App\Domain\Inventory;

use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Models\StockAdjustment;
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
        private readonly LotLedger $lots,
    ) {}

    public function apply(Model $document, ?JournalEntry $entry): void
    {
        match (true) {
            $document instanceof GoodsReceipt => $this->receive($document, $entry),
            $document instanceof SalesInvoice => $this->ship($document, $entry),
            $document instanceof StockAdjustment => $this->adjust($document, $entry),
            default => null,
        };
    }

    /**
     * An adjustment moves quantity without touching the average: found or
     * lost stock changes how much there is, not what it cost.
     */
    private function adjust(StockAdjustment $adjustment, ?JournalEntry $entry): void
    {
        if ($adjustment->getAttribute('status') === 'cancelled') {
            return;
        }

        foreach ($adjustment->lines as $line) {
            $this->stock->record(
                itemId: (int) $line->item_id,
                locationId: (int) $adjustment->location_id,
                // count_adjust carries a signed delta; a plain adjustment is
                // directional, so pick the type that matches the sign.
                movementType: $adjustment->reason === 'count'
                    ? 'count_adjust'
                    : (bccomp((string) $line->qty_delta, '0', 5) > 0 ? 'adjust_in' : 'adjust_out'),
                qtyDelta: (string) $line->qty_delta,
                unitCost: (string) $line->unit_cost,
                sourceType: 'stock_adjustment',
                sourceId: (int) $adjustment->id,
                journalEntryId: $entry?->id,
                lotId: $line->lot_id === null ? null : (int) $line->lot_id,
            );
        }
    }

    /** Receipts add stock at their ACTUAL cost, which moves the average. */
    private function receive(GoodsReceipt $receipt, ?JournalEntry $entry): void
    {
        if ($receipt->getAttribute('status') === 'cancelled') {
            return;
        }

        foreach ($receipt->lines as $line) {
            $movementId = $this->stock->record(
                itemId: (int) $line->item_id,
                locationId: (int) $receipt->location_id,
                movementType: 'receive',
                qtyDelta: (string) $line->qty_stock,
                unitCost: (string) $line->unit_cost,
                sourceType: 'goods_receipt',
                sourceId: (int) $receipt->id,
                journalEntryId: $entry?->id,
            );

            // Lot-tracked items also open (or top up) their lot, so FEFO has
            // something to draw from and a recall has something to trace.
            if ($line->item->isLotTracked() && $line->lot_code !== null) {
                $this->lots->receive(
                    itemId: (int) $line->item_id,
                    locationId: (int) $receipt->location_id,
                    lotCode: (string) $line->lot_code,
                    qty: (string) $line->qty_stock,
                    costPerBase: (string) $line->unit_cost,
                    expiryDate: $line->expiry_date?->toDateString(),
                    sourceType: 'goods_receipt',
                    sourceId: (int) $receipt->id,
                    stockMovementId: $movementId,
                );
            }
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
            $movementId = $this->stock->record(
                itemId: $cost['item_id'],
                locationId: $cost['location_id'],
                movementType: 'sale',
                qtyDelta: '-'.ltrim($cost['qty'], '-'),
                sourceType: 'sales_invoice',
                sourceId: (int) $invoice->id,
                journalEntryId: $entry?->id,
            );

            // FEFO draws the oldest-expiring lots first. Server-side, always:
            // a client suggestion is a hint, never the record (08 §4.1).
            if ($this->isLotTracked($cost['item_id'])) {
                $this->lots->release(
                    itemId: $cost['item_id'],
                    locationId: $cost['location_id'],
                    qty: ltrim($cost['qty'], '-'),
                    sourceType: 'sales_invoice',
                    sourceId: (int) $invoice->id,
                    stockMovementId: $movementId,
                );
            }

            DB::table('sales_invoice_lines')->where('id', $lineId)
                ->update(['cogs_centavos' => $cost['cogs_centavos']]);
        }
    }

    private function isLotTracked(int $itemId): bool
    {
        return DB::table('items')->where('id', $itemId)->value('tracking') === 'lot';
    }
}
