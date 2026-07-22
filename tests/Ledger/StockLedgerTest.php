<?php

namespace Tests\Ledger;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\StockLedger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The stock ledger (docs/specs/08). It holds the same invariants as the
 * journal: append-only movements, derived on-hand, and a moving average
 * that cannot be raced.
 */
class StockLedgerTest extends LedgerTestCase
{
    private function locationId(): int
    {
        return (int) DB::table('locations')->where('is_default', true)->value('id');
    }

    private function item(array $overrides = []): int
    {
        return (int) DB::table('items')->insertGetId(array_merge([
            'code' => 'ITM-001',
            'name' => 'Canned Sardines 155g',
            'item_type' => 'inventory',
            'stock_uom_id' => DB::table('uoms')->where('symbol', 'pc')->value('id'),
            'purchase_uom_id' => DB::table('uoms')->where('symbol', 'case')->value('id'),
            'purchase_to_stock_factor' => 48,
            'inventory_account_id' => $this->accountId('1400'),
            'income_account_id' => $this->accountId('4000'),
            'cogs_account_id' => $this->accountId('5200'),
            'adjustment_account_id' => $this->accountId('5300'),
            'avg_cost' => 0,
            'last_cost' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function avgCost(int $itemId): string
    {
        return (string) DB::table('items')->where('id', $itemId)->value('avg_cost');
    }

    public function test_a_receipt_sets_on_hand_and_the_average_cost(): void
    {
        $stock = app(StockLedger::class);
        $itemId = $this->item();

        $stock->record($itemId, $this->locationId(), 'receive', '100', '12.500000');

        $this->assertSame(0, bccomp('100', $stock->onHand($itemId, $this->locationId()), 5));
        $this->assertSame(0, bccomp('12.500000', $this->avgCost($itemId), 6));
    }

    /**
     * The core costing rule (08 §2):
     *   (100 × 12.50 + 50 × 20.00) / 150 = 15.00
     */
    public function test_a_second_receipt_moves_the_weighted_average(): void
    {
        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $locationId = $this->locationId();

        $stock->record($itemId, $locationId, 'receive', '100', '12.500000');
        $stock->record($itemId, $locationId, 'receive', '50', '20.000000');

        $this->assertSame(0, bccomp('150', $stock->onHand($itemId, $locationId), 5));
        $this->assertSame(0, bccomp('15.000000', $this->avgCost($itemId), 6), 'Weighted, not simple, average.');

        // last_cost is informational and must NOT drive valuation (08 §2).
        $this->assertSame(0, bccomp('20.000000', (string) DB::table('items')->where('id', $itemId)->value('last_cost'), 6));
    }

    /** An issue draws at the average and leaves the average alone. */
    public function test_a_sale_reduces_on_hand_without_moving_the_average(): void
    {
        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $locationId = $this->locationId();

        $stock->record($itemId, $locationId, 'receive', '100', '12.500000');
        $stock->record($itemId, $locationId, 'receive', '50', '20.000000');
        $stock->record($itemId, $locationId, 'sale', '-30');

        $this->assertSame(0, bccomp('120', $stock->onHand($itemId, $locationId), 5));
        $this->assertSame(0, bccomp('15.000000', $this->avgCost($itemId), 6));

        // The movement captured the cost context it was issued at.
        $cost = DB::table('stock_movements')->where('movement_type', 'sale')->value('unit_cost');
        $this->assertSame(0, bccomp('15.000000', (string) $cost, 6));
    }

    public function test_selling_more_than_is_on_hand_is_blocked_by_default(): void
    {
        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $stock->record($itemId, $this->locationId(), 'receive', '10', '5.000000');

        $this->expectException(InsufficientStock::class);
        // sqlite renders the decimal as `10`, MariaDB as `10.00000`.
        $this->expectExceptionMessageMatches('/has 10(\.0+)? on hand.*11 was requested/');

        $stock->record($itemId, $this->locationId(), 'sale', '-11');
    }

    /** D17: a `warn` tenant accepts the negative deliberately. */
    public function test_the_warn_policy_allows_negative_stock(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['negative_stock_policy' => 'warn']);

        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $stock->record($itemId, $this->locationId(), 'receive', '10', '5.000000');
        $stock->record($itemId, $this->locationId(), 'sale', '-11');

        $this->assertSame(0, bccomp('-1', $stock->onHand($itemId, $this->locationId()), 5));
    }

    /** Receiving into negative stock resets the average to the incoming cost. */
    public function test_receiving_into_negative_stock_resets_the_average(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['negative_stock_policy' => 'warn']);

        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $locationId = $this->locationId();

        $stock->record($itemId, $locationId, 'receive', '10', '5.000000');
        $stock->record($itemId, $locationId, 'sale', '-15');       // on-hand −5
        $stock->record($itemId, $locationId, 'receive', '20', '8.000000');

        // Averaging against a negative denominator is meaningless, so the
        // incoming cost simply becomes the new average (08 §2).
        $this->assertSame(0, bccomp('8.000000', $this->avgCost($itemId), 6));
    }

    public function test_a_service_item_cannot_carry_stock(): void
    {
        $itemId = $this->item(['code' => 'SVC-001', 'item_type' => 'service']);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/carries no stock/');

        app(StockLedger::class)->record($itemId, $this->locationId(), 'receive', '1', '10.000000');
    }

    public function test_a_zero_movement_is_refused(): void
    {
        $itemId = $this->item();

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/records nothing/');

        app(StockLedger::class)->record($itemId, $this->locationId(), 'receive', '0', '10.000000');
    }

    public function test_movement_direction_must_match_its_type(): void
    {
        $itemId = $this->item();
        app(StockLedger::class)->record($itemId, $this->locationId(), 'receive', '10', '5.000000');

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessageMatches('/must be negative/');

        app(StockLedger::class)->record($itemId, $this->locationId(), 'sale', '5');
    }

    /** The cache is a cache: a rebuild must reproduce it exactly. */
    public function test_rebuilding_the_cache_reproduces_it_from_the_movements(): void
    {
        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $second = $this->item(['code' => 'ITM-002', 'name' => 'Instant Noodles']);
        $locationId = $this->locationId();

        $stock->record($itemId, $locationId, 'receive', '100', '12.500000');
        $stock->record($itemId, $locationId, 'sale', '-30');
        $stock->record($second, $locationId, 'receive', '7.25000', '3.200000');

        $before = DB::table('item_location_balances')->orderBy('item_id')->pluck('qty_on_hand', 'item_id')->all();

        // Corrupt the cache, then prove the rebuild repairs it.
        DB::table('item_location_balances')->where('item_id', $itemId)->update(['qty_on_hand' => '999']);
        $stock->rebuildBalances();

        $after = DB::table('item_location_balances')->orderBy('item_id')->pluck('qty_on_hand', 'item_id')->all();

        foreach ($before as $id => $qty) {
            $this->assertSame(0, bccomp((string) $qty, (string) $after[$id], 5));
        }
    }

    /** Valuation is what `inventory:verify` ties to the GL. */
    public function test_valuation_is_on_hand_times_the_average_cost_in_centavos(): void
    {
        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $locationId = $this->locationId();

        $stock->record($itemId, $locationId, 'receive', '100', '12.500000');
        $stock->record($itemId, $locationId, 'receive', '50', '20.000000');
        $stock->record($itemId, $locationId, 'sale', '-30');

        // 120 × 15.00 = 1,800.00
        $this->assertSame(180_000, $stock->valuationCentavos());
    }

    /** Stock at several locations sums into one valuation. */
    public function test_on_hand_is_tracked_per_location_and_valued_across_them(): void
    {
        $stock = app(StockLedger::class);
        $itemId = $this->item();
        $main = $this->locationId();

        $branch = (int) DB::table('locations')->insertGetId([
            'code' => 'BR-01', 'name' => 'Branch', 'type' => 'store',
            'is_default' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $stock->record($itemId, $main, 'receive', '100', '10.000000');
        $stock->record($itemId, $branch, 'receive', '40', '10.000000');

        $this->assertSame(0, bccomp('100', $stock->onHand($itemId, $main), 5));
        $this->assertSame(0, bccomp('40', $stock->onHand($itemId, $branch), 5));
        $this->assertSame(0, bccomp('140', $stock->onHandEverywhere($itemId), 5));
        $this->assertSame(140_000, $stock->valuationCentavos());
    }

    /** Append-only, enforced by the database itself. */
    public function test_stock_movements_cannot_be_updated_or_deleted(): void
    {
        $this->requiresMariaDb();

        $itemId = $this->item();
        app(StockLedger::class)->record($itemId, $this->locationId(), 'receive', '10', '5.000000');
        $movementId = (int) DB::table('stock_movements')->value('id');

        try {
            DB::table('stock_movements')->where('id', $movementId)->update(['qty_delta' => '999']);
            $this->fail('A stock movement must not be updatable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('stock_movements')->where('id', $movementId)->delete();
            $this->fail('A stock movement must not be deletable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }
}
