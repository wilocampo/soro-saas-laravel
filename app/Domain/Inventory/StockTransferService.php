<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Models\StockTransfer;
use Illuminate\Support\Facades\DB;

/**
 * Location → location (docs/specs/08 §3).
 *
 * **No journal entry in v1, deliberately.** The goods have not changed
 * value, owner or accounting classification — only shelf. Posting a JE that
 * debits and credits the same inventory account would add noise to the
 * general ledger and tell a reader nothing. If a tenant ever maps locations
 * to different GL accounts, this is where that changes (D14 territory).
 *
 * Both movements are written in one transaction, so stock can never be
 * out of one place without being in the other.
 */
class StockTransferService
{
    public function __construct(private readonly StockLedger $stock) {}

    public function post(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status === 'posted') {
            return $transfer;   // idempotent
        }
        if ($transfer->status === 'cancelled') {
            throw new InventoryException('A cancelled transfer cannot be posted.');
        }
        if ((int) $transfer->from_location_id === (int) $transfer->to_location_id) {
            throw new InventoryException('A transfer must move stock between two different locations.');
        }

        $lines = $transfer->lines;

        if ($lines->isEmpty()) {
            throw new InventoryException('A transfer needs at least one line.');
        }

        return DB::transaction(function () use ($transfer, $lines): StockTransfer {
            foreach ($lines as $line) {
                // Out first: the negative-stock gate fires here, so an
                // impossible transfer fails before anything has moved.
                $this->stock->record(
                    itemId: (int) $line->item_id,
                    locationId: (int) $transfer->from_location_id,
                    movementType: 'transfer_out',
                    qtyDelta: '-'.ltrim((string) $line->qty, '-'),
                    sourceType: 'stock_transfer',
                    sourceId: (int) $transfer->id,
                );

                $this->stock->record(
                    itemId: (int) $line->item_id,
                    locationId: (int) $transfer->to_location_id,
                    movementType: 'transfer_in',
                    qtyDelta: ltrim((string) $line->qty, '-'),
                    // The average is global in v1 (D14), so a transfer moves
                    // stock at the item's one cost and cannot shift it.
                    sourceType: 'stock_transfer',
                    sourceId: (int) $transfer->id,
                );
            }

            $transfer->update(['status' => 'posted']);

            return $transfer->fresh();
        });
    }
}
