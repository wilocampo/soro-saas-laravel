<?php

namespace Tests\Ledger;

use App\Domain\Documents\AgingService;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\PaymentAllocation;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Documents\Models\VendorBillLine;
use App\Domain\Ledger\Exceptions\InvalidDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A/R–A/P aging from the subledger, unapplied cash as a deposit, and the
 * NON-VAT registrant variant (docs/specs/02 §4.2, spec 03 §5).
 */
class AgingAndNonVatTest extends LedgerTestCase
{
    private function customer(): Partner
    {
        return Partner::create([
            'code' => 'C-200',
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'is_vat_registered' => true,
        ]);
    }

    private function vendor(): Partner
    {
        return Partner::create([
            'code' => 'V-200',
            'is_vendor' => true,
            'registered_name' => 'Supplier Inc.',
            'is_vat_registered' => true,
        ]);
    }

    private function invoice(Partner $customer, string $invoiceDate, string $dueDate, int $net, int $vat = 0): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'partner_id' => $customer->id,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'status' => 'issued',
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Services',
            'net_centavos' => $net,
            'vat_centavos' => $vat,
            'account_id' => $this->accountId('4000'),
        ]);

        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        return $invoice;
    }

    /** @return array<string, array{debit:int, credit:int}> */
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

    public function test_receivables_age_into_the_right_buckets(): void
    {
        $today = CarbonImmutable::now();
        $customer = $this->customer();
        $poster = app(DocumentPoster::class);

        // Not due yet, 10 days late, 45 days late, 150 days late. Every date
        // stays inside the seeded fiscal year — posting outside it is a
        // different (already tested) rejection.
        foreach ([
            [$today->subDays(5), $today->addDays(15), 100_000],
            [$today->subDays(20), $today->subDays(10), 200_000],
            [$today->subDays(60), $today->subDays(45), 300_000],
            [$today->subDays(170), $today->subDays(150), 400_000],
        ] as [$invoiceDate, $dueDate, $net]) {
            $poster->post($this->invoice($customer, $invoiceDate->toDateString(), $dueDate->toDateString(), $net));
        }

        $aging = app(AgingService::class)->receivables($today);

        $this->assertSame(100_000, $aging['totals']['current']);
        $this->assertSame(200_000, $aging['totals']['1_30']);
        $this->assertSame(300_000, $aging['totals']['31_60']);
        $this->assertSame(400_000, $aging['totals']['over_90']);
        $this->assertSame(1_000_000, $aging['totals']['total']);

        $this->assertCount(1, $aging['partners']);
        $this->assertSame('Acme Trading Corp.', $aging['partners'][0]['registered_name']);
    }

    /** On accrual the aging total must equal the A/R control account. */
    public function test_receivables_aging_ties_to_the_ar_control_account(): void
    {
        $today = CarbonImmutable::now();
        $customer = $this->customer();
        $poster = app(DocumentPoster::class);

        $paid = $this->invoice($customer, $today->subDays(30)->toDateString(), $today->subDays(15)->toDateString(), 500_000);
        $open = $this->invoice($customer, $today->subDays(10)->toDateString(), $today->addDays(5)->toDateString(), 700_000);
        $poster->post($paid);
        $poster->post($open);

        // Settle the first one in full.
        $payment = Payment::create([
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => $today->toDateString(),
            'amount_centavos' => 500_000,
            'cash_account_id' => $this->accountId('1000'),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $paid->id,
            'applied_centavos' => 500_000,
        ]);
        $poster->post($payment->load('allocations'));

        $control = (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $this->accountId('1100'))
            ->where('je.status', 'posted')
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) - COALESCE(SUM(jl.credit_centavos),0) AS net')
            ->value('net');

        $this->assertSame(700_000, $control);
        $this->assertSame($control, app(AgingService::class)->receivables($today)['totals']['total']);
    }

    /** Payables age off the bill's own subledger rows. */
    public function test_payables_age_from_vendor_bills(): void
    {
        $today = CarbonImmutable::now();
        $vendor = $this->vendor();

        $bill = VendorBill::create([
            'bill_number' => 'SUP-900',
            'partner_id' => $vendor->id,
            'bill_date' => $today->subDays(50)->toDateString(),
            'due_date' => $today->subDays(40)->toDateString(),
            'status' => 'open',
        ]);
        VendorBillLine::create([
            'vendor_bill_id' => $bill->id,
            'line_no' => 1,
            'description' => 'Supplies',
            'net_centavos' => 250_000,
            'account_id' => $this->accountId('5000'),
        ]);
        $bill->load('lines');
        $bill->recalculateTotals();
        $bill->save();

        app(DocumentPoster::class)->post($bill);

        $aging = app(AgingService::class)->payables($today);

        $this->assertSame(250_000, $aging['totals']['31_60']);
        $this->assertSame(250_000, $aging['totals']['total']);
    }

    /** Unapplied cash is a liability, not revenue and not negative A/R. */
    public function test_unapplied_receipt_posts_to_customer_deposits(): void
    {
        $customer = $this->customer();

        $payment = Payment::create([
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'amount_centavos' => 300_000,
            'cash_account_id' => $this->accountId('1000'),
        ]);

        $entry = app(DocumentPoster::class)->post($payment->load('allocations'));

        $lines = $this->linesByCode($entry->id);
        $this->assertSame(300_000, $lines['1000']['debit']);   // Cash
        $this->assertSame(300_000, $lines['2200']['credit']);  // Customer Deposits
        $this->assertArrayNotHasKey('1100', $lines, 'An advance must not touch A/R.');
        $this->assertSame('RC-000001', $payment->fresh()->payment_number);

        $this->assertTrialBalanceZero();
    }

    /** Part-applied cash splits between the receivable and the deposit. */
    public function test_partly_applied_receipt_splits_between_ar_and_deposit(): void
    {
        $today = CarbonImmutable::now();
        $customer = $this->customer();
        $invoice = $this->invoice($customer, $today->toDateString(), $today->addDays(30)->toDateString(), 200_000);
        app(DocumentPoster::class)->post($invoice);

        $payment = Payment::create([
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => $today->toDateString(),
            'amount_centavos' => 500_000,
            'cash_account_id' => $this->accountId('1000'),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $invoice->id,
            'applied_centavos' => 200_000,
        ]);

        $entry = app(DocumentPoster::class)->post($payment->load('allocations'));

        $lines = $this->linesByCode($entry->id);
        $this->assertSame(500_000, $lines['1000']['debit']);
        $this->assertSame(200_000, $lines['1100']['credit']);  // only what it settled
        $this->assertSame(300_000, $lines['2200']['credit']);  // the rest is held
        $this->assertTrialBalanceZero();
    }

    public function test_over_applying_a_payment_is_refused(): void
    {
        $today = CarbonImmutable::now();
        $customer = $this->customer();
        $invoice = $this->invoice($customer, $today->toDateString(), $today->toDateString(), 200_000);
        app(DocumentPoster::class)->post($invoice);

        $payment = Payment::create([
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => $today->toDateString(),
            'amount_centavos' => 100_000,
            'cash_account_id' => $this->accountId('1000'),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $invoice->id,
            'applied_centavos' => 200_000,   // more than the cash received
        ]);

        $this->expectException(InvalidDraft::class);
        $this->expectExceptionMessageMatches('/over-applied/');

        app(DocumentPoster::class)->post($payment->load('allocations'));
    }

    /** A NON-VAT registrant may not shift VAT to a customer. */
    public function test_non_vat_registrant_cannot_issue_an_invoice_carrying_vat(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['is_vat_registered' => false]);

        $today = CarbonImmutable::now();
        $invoice = $this->invoice($this->customer(), $today->toDateString(), $today->toDateString(), 1_000_000, 120_000);

        $this->expectException(InvalidDraft::class);
        $this->expectExceptionMessageMatches('/NON-VAT/');

        app(DocumentPoster::class)->post($invoice);
    }

    public function test_non_vat_registrant_posts_a_clean_invoice_with_no_vat_line(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['is_vat_registered' => false]);

        $today = CarbonImmutable::now();
        $invoice = $this->invoice($this->customer(), $today->toDateString(), $today->toDateString(), 1_000_000);

        $entry = app(DocumentPoster::class)->post($invoice);
        $lines = $this->linesByCode($entry->id);

        $this->assertSame(1_000_000, $lines['1100']['debit']);
        $this->assertSame(1_000_000, $lines['4000']['credit']);
        $this->assertArrayNotHasKey('2100', $lines, 'A non-VAT registrant has no output VAT.');
        $this->assertTrialBalanceZero();
    }

    /** A non-VAT buyer cannot claim input tax — the VAT becomes cost. */
    public function test_non_vat_registrant_folds_supplier_vat_into_cost(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['is_vat_registered' => false]);

        $vendor = $this->vendor();
        $bill = VendorBill::create([
            'bill_number' => 'SUP-901',
            'partner_id' => $vendor->id,
            'bill_date' => CarbonImmutable::now()->toDateString(),
            'status' => 'open',
        ]);
        VendorBillLine::create([
            'vendor_bill_id' => $bill->id,
            'line_no' => 1,
            'description' => 'Supplies',
            'net_centavos' => 500_000,
            'input_vat_centavos' => 60_000,
            'account_id' => $this->accountId('5000'),
        ]);
        $bill->load('lines');
        $bill->recalculateTotals();
        $bill->save();

        $entry = app(DocumentPoster::class)->post($bill);
        $lines = $this->linesByCode($entry->id);

        $this->assertSame(560_000, $lines['5000']['debit'], 'VAT is part of the cost, not a claimable asset.');
        $this->assertArrayNotHasKey('1200', $lines, 'A non-VAT registrant has no Input VAT account movement.');
        $this->assertSame(560_000, $lines['2000']['credit']);
        $this->assertTrialBalanceZero();
    }
}
