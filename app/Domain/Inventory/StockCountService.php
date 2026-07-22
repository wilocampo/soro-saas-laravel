<?php

namespace App\Domain\Inventory;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentLine;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountLine;
use App\Domain\Ledger\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The physical count workflow (docs/specs/08 §4.1) — the "identify
 * variances" half of this module.
 *
 *   draft → counting → review → approved
 *
 * Two things make the count evidence rather than theatre:
 *
 *  1. **The snapshot is frozen when counting starts.** Variance measures the
 *     books against reality at one instant; without the freeze, sales made
 *     while staff walk the aisles would masquerade as shrinkage.
 *  2. **Blind by default.** Showing the counter what the system expects
 *     turns a count into a confirmation exercise.
 *
 * Approval is one-way and produces an immutable adjustment plus its journal
 * entry — the count itself is never edited afterwards.
 */
class StockCountService
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function open(int $locationId, bool $blind = true, ?string $memo = null): StockCount
    {
        return StockCount::create([
            'location_id' => $locationId,
            'status' => 'draft',
            'is_blind' => $blind,
            'memo' => $memo,
        ]);
    }

    /**
     * Freeze the snapshot and start counting.
     *
     * @param  list<int>|null  $itemIds  null = every stocked item with a
     *                                   balance row at this location
     */
    public function startCounting(StockCount $count, ?array $itemIds = null): StockCount
    {
        if ($count->status !== 'draft') {
            throw new InventoryException('Counting has already started for this count.');
        }

        return DB::transaction(function () use ($count, $itemIds): StockCount {
            $items = Item::query()
                ->where('item_type', 'inventory')
                ->where('is_active', true)
                ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))
                ->orderBy('code')
                ->get(['id', 'avg_cost']);

            foreach ($items as $item) {
                StockCountLine::create([
                    'stock_count_id' => $count->id,
                    'item_id' => $item->id,
                    // The books' opinion at this instant, frozen.
                    'snapshot_qty' => $this->stock->onHand((int) $item->id, (int) $count->location_id),
                    'counted_qty' => null,
                    'unit_cost' => $item->avg_cost,
                ]);
            }

            $count->update(['status' => 'counting', 'counting_started_at' => now()]);

            return $count->fresh();
        });
    }

    /** Record what was physically found. */
    public function recordCount(StockCount $count, int $itemId, string $countedQty, ?string $note = null): void
    {
        if ($count->status !== 'counting') {
            throw new InventoryException('This count is not open for entry.');
        }
        if (! is_numeric($countedQty) || bccomp($countedQty, '0', 5) < 0) {
            throw new InventoryException('A counted quantity cannot be negative.');
        }

        $line = StockCountLine::query()
            ->where('stock_count_id', $count->id)
            ->where('item_id', $itemId)
            ->first();

        if ($line === null) {
            throw new InventoryException("Item [{$itemId}] is not in the scope of this count.");
        }

        $variance = bcsub($countedQty, (string) $line->snapshot_qty, 5);

        $line->update([
            'counted_qty' => $countedQty,
            'variance_qty' => $variance,
            // Valued at the average, so the review screen can rank by pesos
            // rather than by units — a 2-unit variance on an expensive item
            // matters more than 200 on a cheap one (08 §4.1).
            'variance_centavos' => (int) round((float) $variance * (float) $line->unit_cost * 100),
            'note' => $note,
        ]);
    }

    /** Everything counted → ready for review. */
    public function submitForReview(StockCount $count): StockCount
    {
        if ($count->status !== 'counting') {
            throw new InventoryException('Only a count in progress can be submitted for review.');
        }

        $uncounted = StockCountLine::query()
            ->where('stock_count_id', $count->id)
            ->whereNull('counted_qty')
            ->count();

        if ($uncounted > 0) {
            throw new InventoryException(
                "{$uncounted} line(s) have no counted quantity. An uncounted line is not a zero — "
                .'enter it or narrow the count scope.'
            );
        }

        $count->update(['status' => 'review']);

        return $count->fresh();
    }

    /**
     * Approve: turn the variances into a posted adjustment. One-way.
     *
     * Quantities are re-validated against the CURRENT on-hand rather than
     * the snapshot, so the adjustment brings stock to what was actually
     * counted even if the books moved during the review.
     */
    public function approve(StockCount $count): ?StockAdjustment
    {
        if ($count->status !== 'review') {
            throw new InventoryException('Only a count under review can be approved.');
        }

        return DB::transaction(function () use ($count): ?StockAdjustment {
            $lines = StockCountLine::query()
                ->where('stock_count_id', $count->id)
                ->where('variance_qty', '!=', 0)
                ->orderBy('id')
                ->get();

            if ($lines->isEmpty()) {
                $count->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => auth()->id()]);
                $this->recordApproval($count, null);

                return null;   // a clean count is a result, not a failure
            }

            $adjustment = StockAdjustment::create([
                'location_id' => $count->location_id,
                'adjustment_date' => CarbonImmutable::now()->toDateString(),
                'reason' => 'count',
                'status' => 'posted',
                'stock_count_id' => $count->id,
                'memo' => "Physical count {$count->reference}",
            ]);

            foreach ($lines->values() as $index => $line) {
                StockAdjustmentLine::create([
                    'stock_adjustment_id' => $adjustment->id,
                    'line_no' => $index + 1,
                    'item_id' => $line->item_id,
                    'qty_delta' => $line->variance_qty,
                    'unit_cost' => $line->unit_cost,
                    'value_centavos' => $line->variance_centavos,
                ]);
            }

            $adjustment->load('lines');
            $adjustment->recalculateTotals();
            $adjustment->save();

            $this->poster->post($adjustment);

            $count->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
                'stock_adjustment_id' => $adjustment->id,
            ]);

            $this->recordApproval($count, $adjustment);

            return $adjustment->fresh();
        });
    }

    private function recordApproval(StockCount $count, ?StockAdjustment $adjustment): void
    {
        $this->audit->record(
            event: 'stock_count.approved',
            auditableType: 'stock_counts',
            auditableId: (int) $count->id,
            documentNumber: $count->reference,
            after: [
                'location_id' => (int) $count->location_id,
                'adjustment' => $adjustment?->reference,
                'variance_centavos' => $adjustment === null ? 0 : (int) $adjustment->total_value_centavos,
            ],
        );
    }
}
