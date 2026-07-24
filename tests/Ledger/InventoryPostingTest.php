<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\InventoryException;
use App\Domain\Inventory\Models\GoodsReceipt;
use App\Domain\Inventory\Models\GoodsReceiptLine;
use App\Domain\Inventory\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Phase-2b exit criterion (docs/specs/08): a receive→sell round-trip
 * posts correct journal entries and `inventory:verify` ties the subledger to
 * the GL to the centavo.
 */
class InventoryPostingTest extends LedgerTestCase
{
    private int $itemId;

    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locationId = (int) DB::table('locations')->where('is_default', true)->value('id');
        $this->itemId = (int) DB::table('items')->insertGetId([
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
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Memoised: several receipts in one test share the same supplier. */
    private function vendor(): Partner
    {
        return Partner::firstOrCreate(['code' => 'V-900'], [
            'is_vendor' => true,
            'registered_name' => 'Supplier Inc.',
            'is_vat_registered' => true,
        ]);
    }

    private function customer(): Partner
    {
        return Partner::firstOrCreate(['code' => 'C-900'], [
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'is_vat_registered' => true,
        ]);
    }

    /** Receive 2 cases of 48 at ₱12.50 each = 96 pcs, ₱1,200.00. */
    private function receive(int $qtyStock = 96, string $unitCost = '12.500000'): GoodsReceipt
    {
        $receipt = GoodsReceipt::create([
            'partner_id' => $this->vendor()->id,
            'location_id' => $this->locationId,
            'received_date' => CarbonImmutable::now()->toDateString(),
            'vendor_reference' => 'DR-4471',
            'status' => 'posted',
        ]);

        GoodsReceiptLine::create([
            'goods_receipt_id' => $receipt->id,
            'line_no' => 1,
            'item_id' => $this->itemId,
            'qty_entered' => bcdiv((string) $qtyStock, '48', 5),
            'entered_uom_id' => DB::table('uoms')->where('symbol', 'case')->value('id'),
            'conversion_factor' => 48,
            'qty_stock' => $qtyStock,
            'unit_cost' => $unitCost,
            'line_cost_centavos' => (int) round($qtyStock * (float) $unitCost * 100),
        ]);

        $receipt->load('lines');
        $receipt->recalculateTotals();
        $receipt->save();

        app(DocumentPoster::class)->post($receipt);

        return $receipt->fresh()->load('lines');
    }

    private function sell(int $qty, int $netCentavos, int $vatCentavos): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'partner_id' => $this->customer()->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'issued',
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Canned Sardines 155g',
            'item_id' => $this->itemId,
            'location_id' => $this->locationId,
            'quantity' => $qty,
            'unit_price' => $netCentavos / 100 / $qty,
            'net_centavos' => $netCentavos,
            'vat_centavos' => $vatCentavos,
            'account_id' => $this->accountId('4000'),
        ]);

        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        app(DocumentPoster::class)->post($invoice);

        return $invoice->fresh();
    }

    /** @return array<string, array{debit:int, credit:int}> */
    private function linesByCode(int $entryId): array
    {
        return DB::table('journal_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.journal_entry_id', $entryId)
            ->get(['a.code', 'jl.debit_centavos', 'jl.credit_centavos'])
            ->mapWithKeys(fn ($l) => [$l->code => [
                'debit' => (int) $l->debit_centavos, 'credit' => (int) $l->credit_centavos,
            ]])->all();
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

    /** A receipt capitalises to Inventory and credits GRNI, not A/P (D15). */
    public function test_a_goods_receipt_debits_inventory_and_credits_grni(): void
    {
        $receipt = $this->receive();
        $entry = DB::table('journal_entries')->where('id', $receipt->journal_entry_id)->first();

        $this->assertNotNull($entry);
        $lines = $this->linesByCode((int) $entry->id);

        $this->assertSame(120_000, $lines['1400']['debit'], 'Inventory at actual cost.');
        $this->assertSame(120_000, $lines['2050']['credit'], 'GRNI, not A/P — no bill has arrived.');
        $this->assertArrayNotHasKey('2000', $lines);

        $this->assertSame('GR-000001', $receipt->reference);
        $this->assertSame(0, bccomp('96', app(StockLedger::class)->onHand($this->itemId, $this->locationId), 5));
        $this->assertTrialBalanceZero();
    }

    /**
     * The round trip: receive at 12.50, sell 20 at 30.00 + VAT.
     * COGS = 20 × 12.50 = 250.00
     */
    public function test_a_sale_posts_revenue_and_cogs_in_one_entry_and_relieves_stock(): void
    {
        $this->receive();
        $invoice = $this->sell(qty: 20, netCentavos: 60_000, vatCentavos: 7_200);

        $lines = $this->linesByCode((int) $invoice->journal_entry_id);

        $this->assertSame(67_200, $lines['1100']['debit']);   // A/R
        $this->assertSame(60_000, $lines['4000']['credit']);  // revenue
        $this->assertSame(7_200, $lines['2100']['credit']);   // output VAT
        $this->assertSame(25_000, $lines['5200']['debit']);   // COGS at average
        $this->assertSame(25_000, $lines['1400']['credit']);  // inventory relieved

        // Stock fell, and the cost snapshot was written back to the line.
        $this->assertSame(0, bccomp('76', app(StockLedger::class)->onHand($this->itemId, $this->locationId), 5));
        $this->assertSame(25_000, (int) DB::table('sales_invoice_lines')->value('cogs_centavos'));

        $this->assertTrialBalanceZero();
    }

    /** COGS uses the MOVING average, not the last cost paid. */
    public function test_cogs_uses_the_moving_average_across_two_receipts(): void
    {
        $this->receive(qtyStock: 100, unitCost: '12.500000');
        $this->receive(qtyStock: 50, unitCost: '20.000000');   // average → 15.00

        $invoice = $this->sell(qty: 30, netCentavos: 90_000, vatCentavos: 0);
        $lines = $this->linesByCode((int) $invoice->journal_entry_id);

        // 30 × 15.00 = 450.00, not 30 × 20.00 (last cost).
        $this->assertSame(45_000, $lines['5200']['debit']);
        $this->assertSame(45_000, $lines['1400']['credit']);
        $this->assertTrialBalanceZero();
    }

    /** THE exit criterion: the subledger ties to the GL to the centavo. */
    public function test_the_stock_subledger_ties_to_the_inventory_control_account(): void
    {
        $this->receive(qtyStock: 100, unitCost: '12.500000');
        $this->receive(qtyStock: 50, unitCost: '20.000000');
        $this->sell(qty: 30, netCentavos: 90_000, vatCentavos: 0);

        $valuation = app(StockLedger::class)->valuationCentavos();

        // 120 remaining × 15.00 = 1,800.00
        $this->assertSame(180_000, $valuation);
        $this->assertSame($valuation, $this->accountBalance('1400'), 'Subledger and GL must agree exactly.');

        $this->artisan('inventory:verify')->assertSuccessful();
    }

    /**
     * D38 (draft answers 2026-07-24; licensed sign-off pending) — an
     * inventory-carrying taxpayer cannot keep cash-basis books, so the
     * combination is REFUSED at the stock ledger.
     *
     * This test previously asserted the opposite: that the cost parked in
     * Deferred COGS while revenue waited for collection. That was a
     * carefully-built answer to a question the research draft has since ruled
     * out of existence, and the honest thing is to assert the refusal rather
     * than keep testing a mode nobody may use.
     */
    public function test_cash_basis_and_inventory_cannot_coexist(): void
    {
        $this->receive();
        DB::table('ledger_settings')->where('id', 1)->update(['accounting_basis' => 'cash']);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('cannot');

        $this->sell(qty: 20, netCentavos: 60_000, vatCentavos: 7_200);
    }

    /** The guard bites on every movement type, not just sales (D38). */
    public function test_the_cash_basis_guard_covers_receipts_too(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['accounting_basis' => 'cash']);

        $this->expectException(InventoryException::class);

        $this->receive();
    }

    /** A services invoice on cash basis still posts nothing at all (S3). */
    public function test_a_cash_basis_services_invoice_still_posts_no_entry(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['accounting_basis' => 'cash']);

        $invoice = SalesInvoice::create([
            'partner_id' => $this->customer()->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'issued',
        ]);
        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Consulting services',
            'net_centavos' => 1_000_000,
            'vat_centavos' => 120_000,
            'account_id' => $this->accountId('4000'),
        ]);
        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        $this->assertNull(app(DocumentPoster::class)->post($invoice));
    }

    public function test_selling_more_than_is_on_hand_is_refused_and_nothing_posts(): void
    {
        $this->receive(qtyStock: 10, unitCost: '5.000000');

        try {
            $this->sell(qty: 25, netCentavos: 100_000, vatCentavos: 0);
            $this->fail('Selling beyond on-hand must be refused under the block policy.');
        } catch (InsufficientStock $e) {
            $this->addToAssertionCount(1);
        }

        // The whole post unwound: no sale entry, no movement, stock intact.
        $this->assertSame(0, DB::table('journal_entries')->where('journal_book', 'sales')->count());
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'sale')->count());
        $this->assertSame(0, bccomp('10', app(StockLedger::class)->onHand($this->itemId, $this->locationId), 5));
        $this->assertTrialBalanceZero();
    }

    /*
     * REMOVED 2026-07-24: test_collection_recognises_the_deferred_cost().
     *
     * It asserted that collecting a cash-basis stocked sale moved the cost
     * out of Deferred COGS (1450) and into COGS. D38 makes that combination
     * unreachable — a tenant carrying inventory may not keep cash-basis
     * books — so the test could only ever have been made to pass by
     * disabling the guard it now contradicts.
     *
     * The Deferred COGS branch of `CostOfSales` and account 1450 are dead
     * for the same reason and are scheduled for removal. They are left in
     * place for now so the removal is one reviewable change rather than
     * noise inside this one.
     */
}
