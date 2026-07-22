<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\InventoryException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * THE choke point for quantity (docs/specs/08). Nothing writes
 * `stock_movements` except this class, exactly as nothing writes
 * `journal_lines` except `PostingService`.
 *
 * Three invariants it exists to hold:
 *  1. Movements are APPEND-ONLY. A correction is an opposite movement.
 *  2. On-hand is DERIVED. `item_location_balances` is a rebuildable cache,
 *     upserted under `lockForUpdate()` so concurrent movements serialise.
 *  3. Moving weighted average is recomputed under a per-item
 *     `SELECT … FOR UPDATE`, so two receipts of the same item cannot
 *     interleave and produce an average that reflects neither.
 */
class StockLedger
{
    /** Movement types that consume stock; used for the negative-stock gate. */
    private const OUTBOUND = ['sale', 'adjust_out', 'transfer_out'];

    /**
     * Record one movement. Returns the movement id.
     *
     * `$unitCost` is the cost CONTEXT of the movement: the actual cost for a
     * receipt, the current average for an issue. Callers pass null for an
     * issue and let the ledger read the average it must not race with.
     */
    public function record(
        int $itemId,
        int $locationId,
        string $movementType,
        string $qtyDelta,
        ?string $unitCost = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?int $journalEntryId = null,
        ?int $lotId = null,
        ?CarbonImmutable $movedAt = null,
    ): int {
        if ($this->isZero($qtyDelta)) {
            throw new InventoryException('A stock movement of zero quantity records nothing.');
        }

        $outbound = in_array($movementType, self::OUTBOUND, true);

        if ($outbound && bccomp($qtyDelta, '0', 5) > 0) {
            throw new InventoryException("A [{$movementType}] movement must be negative.");
        }
        if (! $outbound && bccomp($qtyDelta, '0', 5) < 0 && $movementType !== 'count_adjust' && $movementType !== 'reversal') {
            throw new InventoryException("A [{$movementType}] movement must be positive.");
        }

        return DB::transaction(function () use (
            $itemId, $locationId, $movementType, $qtyDelta, $unitCost,
            $sourceType, $sourceId, $journalEntryId, $lotId, $movedAt, $outbound
        ) {
            // Lock the item first, then the balance row: the same fixed lock
            // order the posting engine uses, so the two subsystems can never
            // deadlock against each other.
            $item = $this->lockItem($itemId);
            $balance = $this->lockBalance($itemId, $locationId);

            $cost = $unitCost ?? (string) $item->avg_cost;

            if ($outbound) {
                $this->assertSufficient($item, $balance, $qtyDelta, $locationId);
            }

            // A receipt moves the average BEFORE the movement is written, so
            // the movement's stored cost context is the pre-receipt average
            // and the item's new average reflects it.
            if ($movementType === 'receive' && $unitCost !== null) {
                $this->applyMovingAverage($item, $balance->qty_on_hand, $qtyDelta, $unitCost);
            }

            $movementId = (int) DB::table('stock_movements')->insertGetId([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'movement_type' => $movementType,
                'qty_delta' => $qtyDelta,
                'unit_cost' => $cost,
                'lot_id' => $lotId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'journal_entry_id' => $journalEntryId,
                'moved_at' => ($movedAt ?? CarbonImmutable::now())->toDateTimeString(),
                'created_by' => $this->actorId(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->applyToCache($itemId, $locationId, $qtyDelta);

            return $movementId;
        });
    }

    /** On-hand at one location, from the cache. */
    public function onHand(int $itemId, int $locationId): string
    {
        $qty = DB::table('item_location_balances')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->value('qty_on_hand');

        return $qty === null ? '0.00000' : (string) $qty;
    }

    /** On-hand across every location — what valuation uses. */
    public function onHandEverywhere(int $itemId): string
    {
        $qty = DB::table('item_location_balances')
            ->where('item_id', $itemId)
            ->sum('qty_on_hand');

        return number_format((float) $qty, 5, '.', '');
    }

    /**
     * Rebuild the cache from the movements — proof that it is a cache and
     * not the truth (08 §1).
     *
     * @return int rows written
     */
    public function rebuildBalances(): int
    {
        return DB::transaction(function (): int {
            DB::table('item_location_balances')->delete();

            $sums = DB::table('stock_movements')
                ->groupBy('item_id', 'location_id')
                ->selectRaw('item_id, location_id, SUM(qty_delta) AS qty')
                ->get();

            $rows = $sums->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'location_id' => (int) $row->location_id,
                'qty_on_hand' => (string) $row->qty,
                'rebuilt_at' => now(),
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('item_location_balances')->insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * Value of stock on hand, in CENTAVOS, at the current average cost.
     * This is the figure `inventory:verify` ties to the GL.
     */
    public function valuationCentavos(): int
    {
        $rows = DB::table('item_location_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->groupBy('i.id', 'i.avg_cost')
            ->selectRaw('i.id, i.avg_cost, SUM(b.qty_on_hand) AS qty')
            ->get();

        $total = 0;

        foreach ($rows as $row) {
            // Round per ITEM, not per location: rounding each location would
            // accumulate error the GL has no matching entry for.
            $total += (int) round((float) $row->qty * (float) $row->avg_cost * 100);
        }

        return $total;
    }

    /**
     * new_avg = (on_hand × avg_cost + received × unit_cost) / (on_hand + received)
     *
     * Negative or zero on-hand at receipt time resets the average to the
     * incoming cost — standard practice, and the alternative (dividing by a
     * non-positive denominator) is meaningless (08 §2).
     */
    private function applyMovingAverage(object $item, string $onHand, string $receivedQty, string $unitCost): void
    {
        $newQty = bcadd($onHand, $receivedQty, 5);

        $newAverage = bccomp($onHand, '0', 5) <= 0 || bccomp($newQty, '0', 5) <= 0
            ? $unitCost
            : bcdiv(
                bcadd(bcmul($onHand, (string) $item->avg_cost, 10), bcmul($receivedQty, $unitCost, 10), 10),
                $newQty,
                6,
            );

        DB::table('items')->where('id', $item->id)->update([
            'avg_cost' => $newAverage,
            // Informational only — never used for valuation (08 §2).
            'last_cost' => $unitCost,
            'updated_at' => now(),
        ]);
    }

    private function assertSufficient(object $item, object $balance, string $qtyDelta, int $locationId): void
    {
        $resulting = bcadd($balance->qty_on_hand, $qtyDelta, 5);

        if (bccomp($resulting, '0', 5) >= 0) {
            return;
        }

        $policy = DB::table('ledger_settings')->where('id', 1)->value('negative_stock_policy') ?? 'block';

        if ($policy === 'block') {
            $requested = ltrim($qtyDelta, '-');

            throw new InsufficientStock(
                "{$item->code} has {$balance->qty_on_hand} on hand at location {$locationId}; "
                ."{$requested} was requested. Receive stock or record an adjustment first."
            );
        }
    }

    private function lockItem(int $itemId): object
    {
        $item = DB::table('items')->where('id', $itemId)->lockForUpdate()
            ->first(['id', 'code', 'item_type', 'avg_cost']);

        if ($item === null) {
            throw new InventoryException("No item [{$itemId}].");
        }
        if ($item->item_type !== 'inventory') {
            throw new InventoryException("{$item->code} is a {$item->item_type} item and carries no stock.");
        }

        return $item;
    }

    private function lockBalance(int $itemId, int $locationId): object
    {
        $balance = DB::table('item_location_balances')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        // No row yet: on-hand is zero. Created by applyToCache().
        return (object) ['item_id' => $itemId, 'location_id' => $locationId, 'qty_on_hand' => '0.00000'];
    }

    private function applyToCache(int $itemId, int $locationId, string $qtyDelta): void
    {
        $updated = DB::table('item_location_balances')
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->update(['qty_on_hand' => DB::raw('qty_on_hand + '.$this->sqlNumber($qtyDelta))]);

        if ($updated === 0) {
            DB::table('item_location_balances')->insert([
                'item_id' => $itemId,
                'location_id' => $locationId,
                'qty_on_hand' => $qtyDelta,
                'rebuilt_at' => null,
            ]);
        }
    }

    /** Guard the raw fragment: quantities are numeric, never user text. */
    private function sqlNumber(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InventoryException("Quantity [{$value}] is not numeric.");
        }

        return $value;
    }

    private function isZero(string $qty): bool
    {
        return ! is_numeric($qty) || bccomp($qty, '0', 5) === 0;
    }

    private function actorId(): int
    {
        $id = auth()->id();

        if ($id !== null) {
            return (int) $id;
        }

        $system = DB::table('users')->where('is_system', true)->value('id');

        if ($system === null) {
            throw new InventoryException('No authenticated user and no system actor is seeded.');
        }

        return (int) $system;
    }
}
