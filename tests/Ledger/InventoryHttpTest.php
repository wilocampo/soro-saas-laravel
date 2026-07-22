<?php

namespace Tests\Ledger;

use App\Domain\Documents\Models\Partner;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\Item;
use App\Domain\Inventory\StockLedger;
use App\Http\Middleware\TenantMiddleware;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The inventory HTTP layer: item master, barcode scan, receiving, counts. */
class InventoryHttpTest extends LedgerTestCase
{
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(TenantMiddleware::class);
        $this->actingAs(User::query()->firstOrFail());

        $this->locationId = (int) DB::table('locations')->where('is_default', true)->value('id');
    }

    private function pieceUom(): int
    {
        return (int) DB::table('uoms')->where('symbol', 'pc')->value('id');
    }

    private function caseUom(): int
    {
        return (int) DB::table('uoms')->where('symbol', 'case')->value('id');
    }

    private function createItem(array $overrides = []): Item
    {
        $this->post(route('items.store'), array_merge([
            'code' => 'ITM-001',
            'name' => 'Canned Sardines 155g',
            'item_type' => 'inventory',
            'stock_uom_id' => $this->pieceUom(),
            'purchase_uom_id' => $this->caseUom(),
            'purchase_to_stock_factor' => 48,
            'tracking' => 'none',
            'reorder_point' => 0,
        ], $overrides));

        return Item::query()->where('code', $overrides['code'] ?? 'ITM-001')->firstOrFail();
    }

    public function test_an_item_is_created_and_listed_with_its_valuation(): void
    {
        $item = $this->createItem();

        app(StockLedger::class)->record($item->id, $this->locationId, 'receive', '10', '12.500000');

        $this->get(route('items.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Items/Index')
                ->where('items.data.0.code', 'ITM-001')
                // 10 × 12.50 = 125.00, the same figure the balance sheet uses.
                ->where('items.data.0.value_centavos', 12_500)
            );
    }

    /** An expiry date needs a lot to hang on. */
    public function test_requiring_an_expiry_without_lot_tracking_is_refused(): void
    {
        $this->post(route('items.store'), [
            'code' => 'BAD-001',
            'name' => 'Nonsense',
            'item_type' => 'inventory',
            'stock_uom_id' => $this->pieceUom(),
            'purchase_to_stock_factor' => 1,
            'tracking' => 'none',
            'require_expiry' => true,
        ])->assertStatus(422);

        $this->assertSame(0, Item::query()->where('code', 'BAD-001')->count());
    }

    /** Costing is the stock ledger's job; a typed average would restate COGS. */
    public function test_the_average_cost_cannot_be_edited_by_hand(): void
    {
        $item = $this->createItem();
        app(StockLedger::class)->record($item->id, $this->locationId, 'receive', '10', '12.500000');

        $this->put(route('items.update', $item), [
            'code' => $item->code,
            'name' => $item->name,
            'item_type' => 'inventory',
            'stock_uom_id' => $this->pieceUom(),
            'purchase_to_stock_factor' => 48,
            'tracking' => 'none',
            'avg_cost' => '999.000000',
        ]);

        $this->assertSame(0, bccomp('12.500000', (string) $item->fresh()->avg_cost, 6));
    }

    /** Spec 08 §5: the barcode endpoint the prior art never wired in. */
    public function test_a_barcode_resolves_to_an_item_and_the_scanned_unit(): void
    {
        $item = $this->createItem();

        DB::table('item_barcodes')->insert([
            'item_id' => $item->id,
            'barcode' => '4800016641107',
            'packaging_level' => 'CASE',
            'uom_id' => $this->caseUom(),
        ]);

        $this->getJson(route('items.barcode', '4800016641107'))
            ->assertOk()
            ->assertJsonPath('code', 'ITM-001')
            ->assertJsonPath('uom_id', $this->caseUom());

        $this->getJson(route('items.barcode', '0000000000000'))->assertNotFound();
    }

    /** The UoM cascade is resolved SERVER-side from the item's own factor. */
    public function test_receiving_in_cases_converts_to_stock_units_server_side(): void
    {
        $item = $this->createItem();
        $vendor = Partner::create([
            'code' => 'V-600', 'is_vendor' => true,
            'registered_name' => 'Supplier Inc.', 'is_vat_registered' => true,
        ]);

        $this->post(route('receipts.store'), [
            'partner_id' => $vendor->id,
            'location_id' => $this->locationId,
            'received_date' => CarbonImmutable::now()->toDateString(),
            'vendor_reference' => 'DR-1001',
            'lines' => [[
                'item_id' => $item->id,
                'qty_entered' => 2,
                'entered_uom_id' => $this->caseUom(),
                // No factor sent: the server derives 48 from the item.
                'unit_cost' => 12.5,
            ]],
        ])->assertRedirect();

        $line = DB::table('goods_receipt_lines')->firstOrFail();

        $this->assertSame(0, bccomp('48', (string) $line->conversion_factor, 5));
        $this->assertSame(0, bccomp('96', (string) $line->qty_stock, 5));
        $this->assertSame(120_000, (int) $line->line_cost_centavos);

        $this->assertSame(0, bccomp('96', app(StockLedger::class)->onHand($item->id, $this->locationId), 5));
    }

    /** A price far off the last cost is flagged while the operator is still looking. */
    public function test_a_wildly_different_unit_cost_is_flagged_at_encode_time(): void
    {
        $item = $this->createItem();
        DB::table('items')->where('id', $item->id)->update(['last_cost' => '12.500000']);

        $this->post(route('receipts.store'), [
            'location_id' => $this->locationId,
            'received_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'qty_entered' => 1,
                'entered_uom_id' => $this->pieceUom(),
                'conversion_factor' => 1,
                'unit_cost' => 125,          // a decimal-point slip
            ]],
        ])->assertRedirect();

        $note = DB::table('goods_receipt_lines')->value('variance_note');

        $this->assertNotNull($note);
        $this->assertStringContainsString('above', $note);
        $this->assertStringContainsString('keying error', $note);
    }

    /** A lot-tracked line without its lot data is rejected before any write. */
    public function test_a_lot_tracked_line_without_a_lot_code_writes_nothing(): void
    {
        $item = $this->createItem([
            'code' => 'MILK-1L', 'name' => 'Fresh Milk 1L',
            'tracking' => 'lot', 'track_expiry' => true, 'require_expiry' => true,
        ]);

        $this->post(route('receipts.store'), [
            'location_id' => $this->locationId,
            'received_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'qty_entered' => 5,
                'entered_uom_id' => $this->pieceUom(),
                'conversion_factor' => 1,
                'unit_cost' => 40,
            ]],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('goods_receipts')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_a_receipt_opens_the_lot_it_declares(): void
    {
        $item = $this->createItem([
            'code' => 'MILK-1L', 'name' => 'Fresh Milk 1L',
            'tracking' => 'lot', 'track_expiry' => true, 'require_expiry' => true,
        ]);

        $this->post(route('receipts.store'), [
            'location_id' => $this->locationId,
            'received_date' => CarbonImmutable::now()->toDateString(),
            'lines' => [[
                'item_id' => $item->id,
                'qty_entered' => 5,
                'entered_uom_id' => $this->pieceUom(),
                'conversion_factor' => 1,
                'unit_cost' => 40,
                'lot_code' => 'L-2026-07',
                'expiry_date' => CarbonImmutable::now()->addDays(14)->toDateString(),
            ]],
        ])->assertRedirect();

        $lot = InventoryLot::query()->firstOrFail();
        $this->assertSame('L-2026-07', $lot->lot_code);
    }

    /** A blind count must not leak the system quantity to the counter. */
    public function test_a_blind_count_hides_the_system_quantity_while_counting(): void
    {
        $item = $this->createItem();
        app(StockLedger::class)->record($item->id, $this->locationId, 'receive', '40', '12.500000');

        $this->post(route('counts.store'), ['location_id' => $this->locationId, 'is_blind' => true])
            ->assertRedirect();

        $count = DB::table('stock_counts')->firstOrFail();

        $this->get(route('counts.show', $count->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Counts/Show')
                ->where('hideSnapshot', true)
                ->missing('lines.0.snapshot_qty')
            );
    }

    /** Once counting is done the variance is shown, valued. */
    public function test_the_review_screen_shows_the_variance_after_submission(): void
    {
        $item = $this->createItem();
        app(StockLedger::class)->record($item->id, $this->locationId, 'receive', '40', '12.500000');

        $this->post(route('counts.store'), ['location_id' => $this->locationId, 'is_blind' => true]);
        $count = DB::table('stock_counts')->firstOrFail();

        $this->post(route('counts.record', $count->id), ['item_id' => $item->id, 'counted_qty' => 37]);
        $this->post(route('counts.review', $count->id))->assertRedirect();

        $this->get(route('counts.show', $count->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hideSnapshot', false)
                ->where('lines.0.variance_centavos', -3_750)   // 3 × 12.50 short
            );

        $this->post(route('counts.approve', $count->id))->assertRedirect();
        $this->assertSame('approved', DB::table('stock_counts')->value('status'));
        $this->assertSame(0, bccomp('37', app(StockLedger::class)->onHand($item->id, $this->locationId), 5));
    }

    public function test_an_item_is_deactivated_never_deleted(): void
    {
        $item = $this->createItem();

        $this->delete(route('items.destroy', $item))->assertRedirect();

        $this->assertDatabaseHas('items', ['id' => $item->id, 'is_active' => false]);
    }
}
