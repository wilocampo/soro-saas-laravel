<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Models\GoodsReceiptLine;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockTransferService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Physical counts, variance and transfers (docs/specs/08 §4.1, §3) — the
 * "identify variances" half of the module.
 */
class StockCountAndTransferTest extends LedgerTestCase
{
    private int $itemId;

    private int $otherItemId;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locationId = (int) DB::table('locations')->where('is_default', true)->value('id');
        $this->itemId = $this->makeItem('ITM-001', 'Canned Sardines 155g');
        $this->otherItemId = $this->makeItem('ITM-002', 'Instant Noodles');

        // Receive through a real document, so the GL carries the inventory
        // the subledger claims — otherwise the tie-out assertions below
        // would be meaningless.
        $this->receive($this->itemId, '100', '12.500000');
        $this->receive($this->otherItemId, '200', '5.000000');
    }

    private function receive(int $itemId, string $qty, string $unitCost): void
    {
        $vendor = Partner::firstOrCreate(['code' => 'V-800'], [
            'is_vendor' => true, 'registered_name' => 'Supplier Inc.', 'is_vat_registered' => true,
        ]);

        $receipt = GoodsReceipt::create([
            'partner_id' => $vendor->id,
            'location_id' => $this->locationId,
            'received_date' => now()->toDateString(),
            'status' => 'posted',
        ]);

        GoodsReceiptLine::create([
            'goods_receipt_id' => $receipt->id,
            'line_no' => 1,
            'item_id' => $itemId,
            'qty_entered' => $qty,
            'entered_uom_id' => DB::table('uoms')->where('symbol', 'pc')->value('id'),
            'conversion_factor' => 1,
            'qty_stock' => $qty,
            'unit_cost' => $unitCost,
            'line_cost_centavos' => (int) round((float) $qty * (float) $unitCost * 100),
        ]);

        $receipt->load('lines');
        $receipt->recalculateTotals();
        $receipt->save();

        app(DocumentPoster::class)->post($receipt);
    }

    private function makeItem(string $code, string $name): int
    {
        return (int) DB::table('items')->insertGetId([
            'code' => $code,
            'name' => $name,
            'item_type' => 'inventory',
            'stock_uom_id' => DB::table('uoms')->where('symbol', 'pc')->value('id'),
            'inventory_account_id' => $this->accountId('1400'),
            'cogs_account_id' => $this->accountId('5200'),
            'adjustment_account_id' => $this->accountId('5300'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function accountBalance(string $code): int
    {
        return (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $this->accountId($code))
            ->whereIn('je.status', ['posted', 'void'])
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) - COALESCE(SUM(jl.credit_centavos),0) AS net')
            ->value('net');
    }

    /**
     * The count round trip: 3 short on sardines (−37.50), 5 found on
     * noodles (+25.00) → net −12.50 to shrinkage.
     */
    public function test_a_count_produces_an_immutable_adjustment_and_journal_entry(): void
    {
        $service = app(StockCountService::class);
        $count = $service->open($this->locationId);
        $count = $service->startCounting($count);

        // The snapshot froze what the books said.
        $snapshot = DB::table('stock_count_lines')->where('item_id', $this->itemId)->value('snapshot_qty');
        $this->assertSame(0, bccomp('100', (string) $snapshot, 5));

        $service->recordCount($count, $this->itemId, '97', 'three cans missing');
        $service->recordCount($count, $this->otherItemId, '205');

        $count = $service->submitForReview($count);
        $adjustment = $service->approve($count);

        $this->assertNotNull($adjustment);
        $this->assertSame('approved', $count->fresh()->status);
        $this->assertSame('ADJ-000001', $adjustment->reference);

        // 3 × 12.50 short = −3,750; 5 × 5.00 found = +2,500 → net −1,250.
        $this->assertSame(-1_250, (int) $adjustment->total_value_centavos);

        // The GL agrees: 2,250.00 received, less 12.50 net shrinkage.
        $this->assertSame(225_000 - 1_250, $this->accountBalance('1400'));
        $this->assertSame(1_250, $this->accountBalance('5300'));

        // Stock now matches what was physically counted.
        $stock = app(StockLedger::class);
        $this->assertSame(0, bccomp('97', $stock->onHand($this->itemId, $this->locationId), 5));
        $this->assertSame(0, bccomp('205', $stock->onHand($this->otherItemId, $this->locationId), 5));

        $this->assertTrialBalanceZero();
        $this->artisan('inventory:verify')->assertSuccessful();
    }

    /** The frozen snapshot is the point: sales during the count are not shrinkage. */
    public function test_a_sale_during_counting_does_not_masquerade_as_shrinkage(): void
    {
        $service = app(StockCountService::class);
        $count = $service->startCounting($service->open($this->locationId));

        // 10 sold while staff were walking the aisles.
        app(StockLedger::class)->record($this->itemId, $this->locationId, 'sale', '-10');

        // The counter finds 90 on the shelf — which is correct, not short.
        $service->recordCount($count, $this->itemId, '90');
        $service->recordCount($count, $this->otherItemId, '200');
        $service->submitForReview($count);

        $line = DB::table('stock_count_lines')->where('item_id', $this->itemId)->first();

        // Variance is against the FROZEN snapshot of 100, so it reads −10 —
        // the sale, which the review screen can then explain away.
        $this->assertSame(0, bccomp('-10', (string) $line->variance_qty, 5));
        $this->assertSame(0, bccomp('100', (string) $line->snapshot_qty, 5));
    }

    /** A clean count is a result, not a failure — and posts nothing. */
    public function test_a_count_with_no_variance_approves_without_an_adjustment(): void
    {
        $service = app(StockCountService::class);
        $count = $service->startCounting($service->open($this->locationId));

        $service->recordCount($count, $this->itemId, '100');
        $service->recordCount($count, $this->otherItemId, '200');
        $service->submitForReview($count);

        $this->assertNull($service->approve($count));
        $this->assertSame('approved', $count->fresh()->status);
        $this->assertSame(0, DB::table('stock_adjustments')->count());

        // Approval is audited either way.
        $this->assertSame(1, DB::table('audit_log')->where('event', 'stock_count.approved')->count());
    }

    /** An uncounted line is not a zero. */
    public function test_an_uncounted_line_blocks_review(): void
    {
        $service = app(StockCountService::class);
        $count = $service->startCounting($service->open($this->locationId));
        $service->recordCount($count, $this->itemId, '100');

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/not a zero/');

        $service->submitForReview($count);
    }

    public function test_an_approved_count_cannot_be_recounted(): void
    {
        $service = app(StockCountService::class);
        $count = $service->startCounting($service->open($this->locationId));
        $service->recordCount($count, $this->itemId, '100');
        $service->recordCount($count, $this->otherItemId, '200');
        $service->submitForReview($count);
        $service->approve($count);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/not open for entry/');

        $service->recordCount($count->fresh(), $this->itemId, '50');
    }

    public function test_a_negative_counted_quantity_is_refused(): void
    {
        $service = app(StockCountService::class);
        $count = $service->startCounting($service->open($this->locationId));

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        $service->recordCount($count, $this->itemId, '-5');
    }

    /** A transfer moves quantity between locations and posts NO journal entry. */
    public function test_a_transfer_moves_stock_without_touching_the_ledger(): void
    {
        $branchId = (int) DB::table('locations')->insertGetId([
            'code' => 'BR-01', 'name' => 'Branch', 'type' => 'store',
            'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $entriesBefore = DB::table('journal_entries')->count();

        $transfer = StockTransfer::create([
            'from_location_id' => $this->locationId,
            'to_location_id' => $branchId,
            'transfer_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        StockTransferLine::create([
            'stock_transfer_id' => $transfer->id,
            'line_no' => 1,
            'item_id' => $this->itemId,
            'qty' => '30',
        ]);

        app(StockTransferService::class)->post($transfer->load('lines'));

        $stock = app(StockLedger::class);
        $this->assertSame(0, bccomp('70', $stock->onHand($this->itemId, $this->locationId), 5));
        $this->assertSame(0, bccomp('30', $stock->onHand($this->itemId, $branchId), 5));
        // Total is unchanged: nothing was created or destroyed.
        $this->assertSame(0, bccomp('100', $stock->onHandEverywhere($this->itemId), 5));

        // The goods changed shelf, not value or owner — no JE (08 §3).
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());
        $this->artisan('inventory:verify')->assertSuccessful();
    }

    /** An impossible transfer fails before anything has moved. */
    public function test_a_transfer_beyond_on_hand_moves_nothing(): void
    {
        $branchId = (int) DB::table('locations')->insertGetId([
            'code' => 'BR-02', 'name' => 'Branch 2', 'type' => 'store',
            'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $transfer = StockTransfer::create([
            'from_location_id' => $this->locationId,
            'to_location_id' => $branchId,
            'transfer_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
        StockTransferLine::create([
            'stock_transfer_id' => $transfer->id,
            'line_no' => 1,
            'item_id' => $this->itemId,
            'qty' => '500',
        ]);

        try {
            app(StockTransferService::class)->post($transfer->load('lines'));
            $this->fail('A transfer beyond on-hand must be refused.');
        } catch (InsufficientStock $e) {
            $this->addToAssertionCount(1);
        }

        $stock = app(StockLedger::class);
        $this->assertSame(0, bccomp('100', $stock->onHand($this->itemId, $this->locationId), 5));
        $this->assertSame(0, bccomp('0', $stock->onHand($this->itemId, $branchId), 5));
        $this->assertSame('draft', $transfer->fresh()->status);
    }

    /**
     * Moving stock to where it already is records nothing. Both layers
     * refuse it: MariaDB's CHECK stops the row being written at all, and
     * the service guards the case for sqlite, where CHECK support is not
     * relied upon. The invariant is what matters, not which layer wins.
     */
    public function test_a_transfer_to_the_same_location_is_refused(): void
    {
        try {
            $transfer = StockTransfer::create([
                'from_location_id' => $this->locationId,
                'to_location_id' => $this->locationId,
                'transfer_date' => now()->toDateString(),
                'status' => 'draft',
            ]);

            app(StockTransferService::class)->post($transfer);
            $this->fail('A same-location transfer must be refused.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('chk_transfer_distinct', $e->getMessage());
        } catch (InventoryException $e) {
            $this->assertStringContainsString('two different locations', $e->getMessage());
        }
    }
}
