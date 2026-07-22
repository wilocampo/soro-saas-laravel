<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\LotLedger;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Models\GoodsReceiptLine;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Lot tracking, expiry and FEFO (docs/specs/08 §1). */
class LotFefoTest extends LedgerTestCase
{
    private int $itemId;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locationId = (int) DB::table('locations')->where('is_default', true)->value('id');
        $this->itemId = (int) DB::table('items')->insertGetId([
            'code' => 'MILK-1L',
            'name' => 'Fresh Milk 1L',
            'item_type' => 'inventory',
            'stock_uom_id' => DB::table('uoms')->where('symbol', 'pc')->value('id'),
            'tracking' => 'lot',
            'track_expiry' => true,
            'require_expiry' => true,
            'inventory_account_id' => $this->accountId('1400'),
            'cogs_account_id' => $this->accountId('5200'),
            'adjustment_account_id' => $this->accountId('5300'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function receiveLot(string $lotCode, string $qty, string $expiry, string $cost = '40.000000'): void
    {
        $vendor = Partner::firstOrCreate(['code' => 'V-700'], [
            'is_vendor' => true, 'registered_name' => 'Dairy Co.', 'is_vat_registered' => true,
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
            'item_id' => $this->itemId,
            'qty_entered' => $qty,
            'entered_uom_id' => DB::table('uoms')->where('symbol', 'pc')->value('id'),
            'conversion_factor' => 1,
            'qty_stock' => $qty,
            'unit_cost' => $cost,
            'line_cost_centavos' => (int) round((float) $qty * (float) $cost * 100),
            'lot_code' => $lotCode,
            'expiry_date' => $expiry,
        ]);

        $receipt->load('lines');
        $receipt->recalculateTotals();
        $receipt->save();

        app(DocumentPoster::class)->post($receipt);
    }

    private function sell(int $qty): SalesInvoice
    {
        $customer = Partner::firstOrCreate(['code' => 'C-700'], [
            'is_customer' => true, 'registered_name' => 'Corner Store', 'is_vat_registered' => true,
        ]);

        $invoice = SalesInvoice::create([
            'partner_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'status' => 'issued',
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Fresh Milk 1L',
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'quantity' => $qty,
            'unit_price' => 60,
            'net_centavos' => $qty * 6_000,
            'vat_centavos' => 0,
            'account_id' => $this->accountId('4000'),
        ]);

        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        app(DocumentPoster::class)->post($invoice);

        return $invoice->fresh();
    }

    /** A receipt of a lot-tracked item opens its lot. */
    public function test_receiving_a_lot_tracked_item_opens_the_lot(): void
    {
        $today = CarbonImmutable::now();
        $this->receiveLot('L-A', '50', $today->addDays(10)->toDateString());

        $lot = InventoryLot::query()->firstOrFail();
        $this->assertSame('L-A', $lot->lot_code);
        $this->assertSame(0, bccomp('50', app(LotLedger::class)->remainingQty((int) $lot->id), 5));
        $this->assertSame('active', $lot->status);
    }

    /** FEFO: the soonest-expiring lot leaves first, regardless of receipt order. */
    public function test_a_sale_draws_the_soonest_expiring_lot_first(): void
    {
        $today = CarbonImmutable::now();

        // Received newest-first on purpose: FEFO must not follow receipt order.
        $this->receiveLot('L-LATE', '30', $today->addDays(30)->toDateString());
        $this->receiveLot('L-SOON', '20', $today->addDays(3)->toDateString());

        $this->sell(25);

        $lots = app(LotLedger::class);
        $soon = InventoryLot::query()->where('lot_code', 'L-SOON')->firstOrFail();
        $late = InventoryLot::query()->where('lot_code', 'L-LATE')->firstOrFail();

        // The 3-day lot emptied, then 5 came out of the 30-day lot.
        $this->assertSame(0, bccomp('0', $lots->remainingQty((int) $soon->id), 5));
        $this->assertSame(0, bccomp('25', $lots->remainingQty((int) $late->id), 5));
        $this->assertSame('consumed', $soon->fresh()->status);
        $this->assertSame('active', $late->fresh()->status);

        // The stock ledger agrees with the lot ledger.
        $this->assertSame(0, bccomp('25', app(StockLedger::class)->onHand($this->itemId, $this->locationId), 5));
    }

    /** A lot with no expiry is drawn last: dated stock must move first. */
    public function test_undated_lots_are_drawn_after_dated_ones(): void
    {
        $today = CarbonImmutable::now();
        $lots = app(LotLedger::class);

        // This item tracks lots but does not MANDATE a date, which is the
        // only way an undated lot can exist in the first place.
        DB::table('items')->where('id', $this->itemId)->update(['require_expiry' => false]);

        $lots->receive($this->itemId, $this->locationId, 'L-NONE', '10', '40.000000');
        $lots->receive($this->itemId, $this->locationId, 'L-DATED', '10', '40.000000', $today->addDays(60)->toDateString());

        $drawn = $lots->release($this->itemId, $this->locationId, '10', 'test', 1);

        $this->assertCount(1, $drawn);
        $this->assertSame('L-DATED', $drawn[0]['lot_code']);
    }

    /** The prior art's best pattern: reversal is idempotent. */
    public function test_reversing_a_source_twice_releases_nothing_the_second_time(): void
    {
        $today = CarbonImmutable::now();
        $lots = app(LotLedger::class);
        $lots->receive($this->itemId, $this->locationId, 'L-A', '40', '40.000000', $today->addDays(10)->toDateString());

        $lots->release($this->itemId, $this->locationId, '15', 'sales_invoice', 99);
        $lot = InventoryLot::query()->where('lot_code', 'L-A')->firstOrFail();
        $this->assertSame(0, bccomp('25', $lots->remainingQty((int) $lot->id), 5));

        $this->assertSame(1, $lots->reverse('sales_invoice', 99), 'One lot was touched.');
        $this->assertSame(0, bccomp('40', $lots->remainingQty((int) $lot->id), 5));

        // Fire it again — a retried job, a double-clicked void.
        $this->assertSame(0, $lots->reverse('sales_invoice', 99), 'Nothing is outstanding any more.');
        $this->assertSame(0, bccomp('40', $lots->remainingQty((int) $lot->id), 5), 'The lot is not corrupted.');
    }

    /** Reversing a full draw makes a consumed lot active again. */
    public function test_reversing_a_full_draw_reactivates_the_lot(): void
    {
        $today = CarbonImmutable::now();
        $lots = app(LotLedger::class);
        $lots->receive($this->itemId, $this->locationId, 'L-A', '10', '40.000000', $today->addDays(5)->toDateString());

        $lots->release($this->itemId, $this->locationId, '10', 'sales_invoice', 7);
        $lot = InventoryLot::query()->where('lot_code', 'L-A')->firstOrFail();
        $this->assertSame('consumed', $lot->fresh()->status);

        $lots->reverse('sales_invoice', 7);

        $this->assertSame('active', $lot->fresh()->status);
        $this->assertSame(0, bccomp('10', $lots->remainingQty((int) $lot->id), 5));
    }

    /** `require_expiry` is enforced before anything persists (08 §5). */
    public function test_a_lot_without_an_expiry_date_is_refused_when_required(): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/requires an expiry date/');

        app(LotLedger::class)->receive($this->itemId, $this->locationId, 'L-X', '5', '40.000000');
    }

    public function test_releasing_more_than_the_lots_hold_is_refused(): void
    {
        $today = CarbonImmutable::now();
        $lots = app(LotLedger::class);
        $lots->receive($this->itemId, $this->locationId, 'L-A', '5', '40.000000', $today->addDays(5)->toDateString());

        $this->expectException(InsufficientStock::class);
        $this->expectExceptionMessageMatches('/No lot has/');

        $lots->release($this->itemId, $this->locationId, '9', 'test', 1);
    }

    /**
     * Closing the prior art's known gap: found stock on a lot-tracked item
     * gets its own lot, so FEFO can consume it.
     */
    public function test_found_stock_creates_an_adjustment_lot_that_fefo_can_consume(): void
    {
        $lots = app(LotLedger::class);
        DB::table('items')->where('id', $this->itemId)->update(['require_expiry' => false, 'avg_cost' => '40.000000']);

        $lot = $lots->adjustmentLot($this->itemId, $this->locationId, '12');

        $this->assertStringStartsWith('ADJ-', $lot->lot_code);
        $this->assertSame(0, bccomp('12', $lots->remainingQty((int) $lot->id), 5));
        $this->assertSame(0, bccomp('40.000000', (string) $lot->cost_per_base, 6), 'Valued at the current average.');

        // And it is genuinely consumable — the gap this closes.
        $drawn = $lots->release($this->itemId, $this->locationId, '12', 'test', 1);
        $this->assertSame($lot->lot_code, $drawn[0]['lot_code']);
    }

    /** The expiry dashboard reads lots with something left in them. */
    public function test_expiring_lots_are_listed_with_what_remains(): void
    {
        $today = CarbonImmutable::now();
        $lots = app(LotLedger::class);

        $lots->receive($this->itemId, $this->locationId, 'L-SOON', '10', '40.000000', $today->addDays(5)->toDateString());
        $lots->receive($this->itemId, $this->locationId, 'L-FAR', '10', '40.000000', $today->addDays(90)->toDateString());
        // Emptied: it has expiry but nothing left, so it must not be listed.
        $lots->receive($this->itemId, $this->locationId, 'L-GONE', '4', '40.000000', $today->addDays(2)->toDateString());
        $lots->release($this->itemId, $this->locationId, '4', 'test', 1);

        $expiring = $lots->expiringBy($today->addDays(30)->toDateString());

        $this->assertCount(1, $expiring);
        $this->assertSame('L-SOON', $expiring[0]['lot_code']);
        $this->assertSame(0, bccomp('10', $expiring[0]['remaining_qty'], 5));
    }

    /** Append-only, enforced by the database. */
    public function test_lot_movements_cannot_be_updated_or_deleted(): void
    {
        $this->requiresMariaDb();

        $today = CarbonImmutable::now();
        app(LotLedger::class)->receive(
            $this->itemId, $this->locationId, 'L-A', '5', '40.000000', $today->addDays(5)->toDateString()
        );

        $movementId = (int) DB::table('lot_movements')->value('id');

        try {
            DB::table('lot_movements')->where('id', $movementId)->update(['qty_delta' => '999']);
            $this->fail('A lot movement must not be updatable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('lot_movements')->where('id', $movementId)->delete();
            $this->fail('A lot movement must not be deletable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
