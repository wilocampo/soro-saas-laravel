<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\PaymentAllocation;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Documents\Models\VendorBillLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Document fixtures S2–S5 from docs/fixtures/scenarios.md, in BOTH bases
 * (docs/specs/02 §4, spec 05). ⚠ Tax treatment still needs CPA sign-off
 * (specs 03/06) — these encode the drafted treatment, not settled law.
 */
class FixtureDocumentsTest extends LedgerTestCase
{
    private function useBasis(string $basis): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['accounting_basis' => $basis]);
    }

    private function customer(): Partner
    {
        return Partner::create([
            'code' => 'C-001',
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'tin' => '123456789',
            'branch_code' => '000',
            'is_vat_registered' => true,
            'taxpayer_type' => 'juridical',
        ]);
    }

    private function vendor(): Partner
    {
        return Partner::create([
            'code' => 'V-001',
            'is_vendor' => true,
            'registered_name' => 'Supplier Inc.',
            'tin' => '987654321',
            'is_vat_registered' => true,
            'taxpayer_type' => 'juridical',
        ]);
    }

    /** Net 10,000.00 + 12% VAT 1,200.00 = 11,200.00 */
    private function invoice(Partner $customer): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'invoice_number' => 'INV-000001',
            'partner_id' => $customer->id,
            'invoice_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'issued',
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Consulting services',
            'quantity' => 1,
            'unit_price' => 10000,
            'net_centavos' => 1_000_000,
            'vat_centavos' => 120_000,
            'tax_code_id' => DB::table('tax_codes')->where('code', 'OV12')->value('id'),
            'account_id' => $this->accountId('4000'),
        ]);

        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        return $invoice;
    }

    /** @return array<string, array{debit:int, credit:int}> keyed by account code */
    private function linesByCode(int $entryId): array
    {
        return DB::table('journal_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.journal_entry_id', $entryId)
            ->get(['a.code', 'jl.debit_centavos', 'jl.credit_centavos'])
            ->mapWithKeys(fn ($l) => [$l->code => [
                'debit' => (int) $l->debit_centavos,
                'credit' => (int) $l->credit_centavos,
            ]])
            ->all();
    }

    /** S2 — VATable sales invoice, ACCRUAL. */
    public function test_s2_vatable_sales_invoice_accrual(): void
    {
        $this->useBasis('accrual');
        $invoice = $this->invoice($this->customer());

        $entry = app(DocumentPoster::class)->post($invoice);

        $this->assertNotNull($entry);
        $this->assertSame('sales', $entry->journal_book);
        $this->assertStringStartsWith('SJ-', $entry->entry_number);

        $lines = $this->linesByCode($entry->id);
        $this->assertSame(1_120_000, $lines['1100']['debit']);   // A/R
        $this->assertSame(1_000_000, $lines['4000']['credit']);  // Sales Revenue
        $this->assertSame(120_000, $lines['2100']['credit']);    // Output VAT
        $this->assertCount(3, $lines);

        // The subledger row now references the entry it produced.
        $this->assertSame($entry->id, (int) $invoice->fresh()->journal_entry_id);
        $this->assertTrialBalanceZero();
    }

    /** S3 — the same invoice on CASH basis posts nothing. */
    public function test_s3_vatable_sales_invoice_cash_basis_posts_no_entry(): void
    {
        $this->useBasis('cash');
        $invoice = $this->invoice($this->customer());

        $entry = app(DocumentPoster::class)->post($invoice);

        $this->assertNull($entry, 'A cash-basis invoice must not produce a journal entry.');
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertNull($invoice->fresh()->journal_entry_id);

        // The invoice still stands in the subledger for A/R aging.
        $this->assertSame(1_120_000, (int) $invoice->fresh()->total_centavos);
    }

    /** S4 — collection with 2% creditable EWT, ACCRUAL: settles the receivable. */
    public function test_s4_collection_with_ewt_accrual(): void
    {
        $this->useBasis('accrual');
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        app(DocumentPoster::class)->post($invoice);

        $payment = $this->collection($customer, $invoice);
        $entry = app(DocumentPoster::class)->post($payment);

        $this->assertSame('cash_receipts', $entry->journal_book);
        $this->assertStringStartsWith('CRJ-', $entry->entry_number);

        $lines = $this->linesByCode($entry->id);
        $this->assertSame(1_100_000, $lines['1000']['debit']);   // Cash in Bank
        $this->assertSame(20_000, $lines['1150']['debit']);      // Creditable Withholding Tax
        $this->assertSame(1_120_000, $lines['1100']['credit']);  // A/R cleared
        $this->assertCount(3, $lines);

        $this->assertTrialBalanceZero();
    }

    /** S4 (cash basis) — recognition happens at collection, from the snapshot. */
    public function test_s4_collection_with_ewt_cash_basis_recognizes_revenue_and_vat(): void
    {
        $this->useBasis('cash');
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        $this->assertNull(app(DocumentPoster::class)->post($invoice));

        $payment = $this->collection($customer, $invoice);
        $entry = app(DocumentPoster::class)->post($payment);

        $lines = $this->linesByCode($entry->id);
        $this->assertSame(1_100_000, $lines['1000']['debit']);   // Cash
        $this->assertSame(20_000, $lines['1150']['debit']);      // CWT
        $this->assertSame(1_000_000, $lines['4000']['credit']);  // Sales recognized NOW
        $this->assertSame(120_000, $lines['2100']['credit']);    // Output VAT recognized NOW
        $this->assertArrayNotHasKey('1100', $lines, 'A cash-basis GL has no A/R control account.');

        $this->assertTrialBalanceZero();
    }

    /** Partial collection on cash basis pro-rates net and VAT exactly. */
    public function test_partial_cash_basis_collection_prorates_by_largest_remainder(): void
    {
        $this->useBasis('cash');
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        app(DocumentPoster::class)->post($invoice);

        // Pay 1/3 of the invoice: 373,333 centavos (11,200.00 / 3 rounded).
        $applied = 373_333;
        $payment = Payment::create([
            'payment_number' => 'RC-000002',
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'amount_centavos' => $applied,
            'ewt_centavos' => 0,
            'cash_account_id' => $this->accountId('1000'),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $invoice->id,
            'applied_centavos' => $applied,
        ]);

        $entry = app(DocumentPoster::class)->post($payment->load('allocations'));
        $lines = $this->linesByCode($entry->id);

        // The parts must sum to the cash exactly — no rounding plug.
        $this->assertSame($applied, $lines['4000']['credit'] + $lines['2100']['credit']);
        $this->assertSame($applied, $lines['1000']['debit']);
        $this->assertTrialBalanceZero();
    }

    /** S5 — vendor bill with 2% EWT; the withholding accrues at BOOKING. */
    public function test_s5_vendor_bill_with_ewt_accrual(): void
    {
        $this->useBasis('accrual');
        $vendor = $this->vendor();

        $bill = VendorBill::create([
            'bill_number' => 'SUP-778',
            'reference' => 'BILL-000001',
            'partner_id' => $vendor->id,
            'bill_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'open',
            'ewt_centavos' => 10_000,        // 2% of 5,000.00
            'atc_code' => 'WC160',
        ]);
        VendorBillLine::create([
            'vendor_bill_id' => $bill->id,
            'line_no' => 1,
            'description' => 'Professional fees',
            'quantity' => 1,
            'unit_price' => 5000,
            'net_centavos' => 500_000,
            'input_vat_centavos' => 60_000,
            'tax_code_id' => DB::table('tax_codes')->where('code', 'IV12')->value('id'),
            'account_id' => $this->accountId('5000'),
        ]);
        $bill->load('lines');
        $bill->recalculateTotals();
        $bill->save();

        $this->assertSame(550_000, (int) $bill->total_centavos, 'Payable = net + input VAT − EWT.');

        $entry = app(DocumentPoster::class)->post($bill);

        $this->assertSame('purchase', $entry->journal_book);
        $lines = $this->linesByCode($entry->id);
        $this->assertSame(500_000, $lines['5000']['debit']);   // Operating Expense
        $this->assertSame(60_000, $lines['1200']['debit']);    // Input VAT
        $this->assertSame(550_000, $lines['2000']['credit']);  // A/P
        $this->assertSame(10_000, $lines['2150']['credit']);   // Withholding Tax Payable
        $this->assertCount(4, $lines);

        // The ATC rides on the withholding line for the Phase-4 1601EQ/QAP.
        $atc = DB::table('journal_lines')->where('journal_entry_id', $entry->id)
            ->whereNotNull('atc_code')->value('atc_code');
        $this->assertSame('WC160', $atc);

        $this->assertTrialBalanceZero();
    }

    /** Reversing a document entry unwinds it cleanly (Phase-2 exit criterion). */
    public function test_reversing_an_invoice_entry_unwinds_the_document(): void
    {
        $this->useBasis('accrual');
        $invoice = $this->invoice($this->customer());
        $entry = app(DocumentPoster::class)->post($invoice);

        $mirror = $this->posting()->reverse($entry, 'cancelled by customer');

        $nets = DB::table('journal_lines')
            ->whereIn('journal_entry_id', [$entry->id, $mirror->id])
            ->selectRaw('account_id, SUM(debit_centavos) - SUM(credit_centavos) AS net')
            ->groupBy('account_id')->pluck('net');

        foreach ($nets as $net) {
            $this->assertSame(0, (int) $net);
        }
        $this->assertTrialBalanceZero();
    }

    private function collection(Partner $customer, SalesInvoice $invoice): Payment
    {
        $payment = Payment::create([
            'payment_number' => 'RC-000001',
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'amount_centavos' => 1_100_000,   // cash received
            'ewt_centavos' => 20_000,         // 2% of the 10,000.00 net, ATC WC160
            'atc_code' => 'WC160',
            'cash_account_id' => $this->accountId('1000'),
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $invoice->id,
            'applied_centavos' => 1_120_000,   // cash + tax withheld
        ]);

        return $payment->load('allocations');
    }
}
