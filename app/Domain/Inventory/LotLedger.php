<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Models\InventoryLot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lot tracking, expiry and FEFO (docs/specs/08 §1).
 *
 * `remaining_qty` is DERIVED — Σ `lot_movements.qty_delta`, recomputed under
 * `lockForUpdate()` — so it cannot drift the way a mutable counter does.
 * `lot_movements` is append-only for the same reason `stock_movements` is.
 *
 * The idempotent `reverse()` is the prior art's single best pattern, kept
 * verbatim in spirit: it keys on the NET OUTSTANDING per lot for a source,
 * so a double-fired reversal (a retried job, a double-clicked button)
 * releases nothing the second time instead of corrupting the lot.
 */
class LotLedger
{
    // Deliberately has no StockLedger dependency: lots and quantities are
    // two views of the same event, and the caller (InventoryEffects) records
    // both in one transaction. Coupling them here would let a lot movement
    // happen without its stock movement.

    /** Create or top up a lot and record its receipt. */
    public function receive(
        int $itemId,
        int $locationId,
        string $lotCode,
        string $qty,
        string $costPerBase,
        ?string $expiryDate = null,
        ?string $mfgDate = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $stockMovementId = null,
    ): InventoryLot {
        $this->assertPositive($qty);
        $item = $this->item($itemId);

        // `require_expiry` is rejected BEFORE anything persists, so a batch
        // of receipt lines never lands half-written (08 §5).
        if ($item->require_expiry && $expiryDate === null) {
            throw new InventoryException("{$item->code} requires an expiry date on every lot.");
        }

        return DB::transaction(function () use (
            $itemId, $locationId, $lotCode, $qty, $costPerBase,
            $expiryDate, $mfgDate, $sourceType, $sourceId, $stockMovementId
        ): InventoryLot {
            $lot = InventoryLot::query()
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->where('lot_code', $lotCode)
                ->lockForUpdate()
                ->first();

            if ($lot === null) {
                $lot = InventoryLot::create([
                    'item_id' => $itemId,
                    'location_id' => $locationId,
                    'lot_code' => $lotCode,
                    'mfg_date' => $mfgDate,
                    'expiry_date' => $expiryDate,
                    'received_qty' => $qty,
                    'cost_per_base' => $costPerBase,
                    'status' => 'active',
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ]);
            } else {
                // Same lot code arriving again: top it up rather than
                // creating a duplicate the unique key would reject anyway.
                $lot->update([
                    'received_qty' => bcadd((string) $lot->received_qty, $qty, 5),
                    'status' => 'active',
                ]);
            }

            $this->recordMovement($lot, 'receive', $qty, $sourceType, $sourceId, $stockMovementId);

            return $lot->fresh();
        });
    }

    /**
     * FEFO draw: consume `$qty` from the soonest-expiring active lots.
     *
     * Lots with no expiry sort last, so dated stock always leaves first.
     * The caller gets back what was actually drawn from where — which is
     * what a recall or a spoilage investigation needs.
     *
     * @return list<array{lot_id:int, lot_code:string, qty:string, expiry_date:?string}>
     */
    public function release(
        int $itemId,
        int $locationId,
        string $qty,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $stockMovementId = null,
    ): array {
        $this->assertPositive($qty);

        return DB::transaction(function () use ($itemId, $locationId, $qty, $sourceType, $sourceId, $stockMovementId): array {
            $remaining = $qty;
            $drawn = [];

            foreach ($this->fefoLots($itemId, $locationId) as $lot) {
                if (bccomp($remaining, '0', 5) <= 0) {
                    break;
                }

                $available = $this->remainingQty((int) $lot->id);

                if (bccomp($available, '0', 5) <= 0) {
                    continue;
                }

                $take = bccomp($available, $remaining, 5) >= 0 ? $remaining : $available;

                $this->recordMovement($lot, 'release', '-'.$take, $sourceType, $sourceId, $stockMovementId);

                $drawn[] = [
                    'lot_id' => (int) $lot->id,
                    'lot_code' => $lot->lot_code,
                    'qty' => $take,
                    'expiry_date' => $lot->expiry_date === null ? null : (string) $lot->expiry_date,
                ];

                if (bccomp(bcsub($available, $take, 5), '0', 5) === 0) {
                    InventoryLot::query()->where('id', $lot->id)->update(['status' => 'consumed']);
                }

                $remaining = bcsub($remaining, $take, 5);
            }

            if (bccomp($remaining, '0', 5) > 0) {
                throw new InsufficientStock(
                    "No lot has {$remaining} left to release for item [{$itemId}] at location [{$locationId}]. "
                    .'Receive the stock into a lot, or record a positive adjustment which creates one.'
                );
            }

            return $drawn;
        });
    }

    /**
     * Undo everything a source did to lots — idempotently.
     *
     * The prior art's best pattern: net the movements already recorded for
     * this source per lot and post the opposite. Running it twice releases
     * nothing the second time, because the net is then zero. That is what
     * makes a retried job or a double-clicked void safe.
     */
    public function reverse(string $sourceType, int $sourceId): int
    {
        return DB::transaction(function () use ($sourceType, $sourceId): int {
            $nets = DB::table('lot_movements')
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->groupBy('inventory_lot_id')
                ->selectRaw('inventory_lot_id, SUM(qty_delta) AS net')
                ->get();

            $reversed = 0;

            foreach ($nets as $row) {
                if (bccomp((string) $row->net, '0', 5) === 0) {
                    continue;   // already reversed — nothing outstanding
                }

                $lot = InventoryLot::query()->where('id', $row->inventory_lot_id)->lockForUpdate()->firstOrFail();

                $this->recordMovement($lot, 'reversal', bcmul((string) $row->net, '-1', 5), $sourceType, $sourceId);

                // Reversing a release makes the lot active again.
                if (bccomp($this->remainingQty((int) $lot->id), '0', 5) > 0) {
                    $lot->update(['status' => 'active']);
                }

                $reversed++;
            }

            return $reversed;
        });
    }

    /** Σ qty_delta — the lot's remaining quantity, always derived. */
    public function remainingQty(int $lotId): string
    {
        $sum = DB::table('lot_movements')->where('inventory_lot_id', $lotId)->sum('qty_delta');

        return number_format((float) $sum, 5, '.', '');
    }

    /**
     * Lots expiring on or before a date, with something left in them —
     * what the spoilage workflow and the expiry dashboard read.
     *
     * @return list<array<string, mixed>>
     */
    public function expiringBy(string $date, ?int $locationId = null): array
    {
        $lots = InventoryLot::query()
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', $date)
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->orderBy('expiry_date')
            ->get();

        $rows = [];

        foreach ($lots as $lot) {
            $remaining = $this->remainingQty((int) $lot->id);

            if (bccomp($remaining, '0', 5) <= 0) {
                continue;
            }

            $rows[] = [
                'lot_id' => (int) $lot->id,
                'item_id' => (int) $lot->item_id,
                'location_id' => (int) $lot->location_id,
                'lot_code' => $lot->lot_code,
                'expiry_date' => (string) $lot->expiry_date->toDateString(),
                'remaining_qty' => $remaining,
            ];
        }

        return $rows;
    }

    /**
     * Found stock on a lot-tracked item needs a lot, or FEFO can never
     * consume it. Spec 08 names this as the prior art's known gap.
     */
    public function adjustmentLot(int $itemId, int $locationId, string $qty, ?int $stockMovementId = null): InventoryLot
    {
        $item = $this->item($itemId);
        $code = 'ADJ-'.CarbonImmutable::now()->format('Ymd-His');

        return $this->receive(
            itemId: $itemId,
            locationId: $locationId,
            lotCode: $code,
            qty: $qty,
            costPerBase: (string) $item->avg_cost,
            sourceType: 'stock_adjustment',
            stockMovementId: $stockMovementId,
        );
    }

    /** @return Collection<int, InventoryLot> */
    private function fefoLots(int $itemId, int $locationId)
    {
        return InventoryLot::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('status', 'active')
            ->lockForUpdate()
            // Soonest expiry first; undated lots last, so dated stock always
            // leaves before stock that never expires.
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date, id')
            ->get();
    }

    private function recordMovement(
        InventoryLot $lot,
        string $reason,
        string $qtyDelta,
        ?string $sourceType,
        ?int $sourceId,
        ?int $stockMovementId = null,
    ): void {
        DB::table('lot_movements')->insert([
            'inventory_lot_id' => $lot->id,
            'reason' => $reason,
            'qty_delta' => $qtyDelta,
            'stock_movement_id' => $stockMovementId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'moved_at' => now(),
            'created_by' => $this->actorId(),
        ]);
    }

    private function item(int $itemId): object
    {
        $item = DB::table('items')->where('id', $itemId)
            ->first(['id', 'code', 'tracking', 'require_expiry', 'avg_cost']);

        if ($item === null) {
            throw new InventoryException("No item [{$itemId}].");
        }

        return $item;
    }

    private function assertPositive(string $qty): void
    {
        if (! is_numeric($qty) || bccomp($qty, '0', 5) <= 0) {
            throw new InventoryException("Lot quantity [{$qty}] must be positive.");
        }
    }

    private function actorId(): int
    {
        $id = auth()->id() ?? DB::table('users')->where('is_system', true)->value('id');

        if ($id === null) {
            throw new InventoryException('No authenticated user and no system actor is seeded.');
        }

        return (int) $id;
    }
}
