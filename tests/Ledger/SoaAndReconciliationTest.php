<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentCanceller;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\PaymentAllocation;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Reports\BankReconciliationService;
use App\Domain\Reports\StatementOfAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Customer SOA and manual bank reconciliation (Phase 3). */
class SoaAndReconciliationTest extends LedgerTestCase
{
    private function customer(): Partner
    {
        return Partner::create([
            'code' => 'C-700',
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'address' => '9 Bonifacio High Street, Taguig',
            'tin' => '123456789',
            'is_vat_registered' => true,
        ]);
    }

    private function invoice(Partner $customer, string $date, int $net, int $vat): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'partner_id' => $customer->id,
            'invoice_date' => $date,
            'due_date' => $date,
            'status' => 'issued',
        ]);

        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Consulting services',
            'net_centavos' => $net,
            'vat_centavos' => $vat,
            'account_id' => $this->accountId('4000'),
        ]);

        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        app(DocumentPoster::class)->post($invoice);

        return $invoice->fresh();
    }

    public function test_the_statement_of_account_runs_a_correct_balance(): void
    {
        $today = CarbonImmutable::now();
        $customer = $this->customer();

        $first = $this->invoice($customer, $today->subDays(40)->toDateString(), 1_000_000, 120_000);
        $this->invoice($customer, $today->subDays(5)->toDateString(), 500_000, 60_000);

        // Collect the first invoice in full: 1,100,000 cash + 20,000 withheld.
        $payment = Payment::create([
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => $today->subDays(3)->toDateString(),
            'amount_centavos' => 1_100_000,
            'ewt_centavos' => 20_000,
            'atc_code' => 'WC160',
            'cash_account_id' => $this->accountId('1000'),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $first->id,
            'applied_centavos' => 1_120_000,
        ]);
        app(DocumentPoster::class)->post($payment->load('allocations'));

        $soa = app(StatementOfAccount::class)->forCustomer(
            $customer->id,
            $today->subDays(60)->toDateString(),
            $today->toDateString(),
        );

        $this->assertSame(0, $soa['opening_centavos']);
        // 1,120,000 + 560,000 charged, 1,120,000 settled.
        $this->assertSame(560_000, $soa['closing_centavos']);
        $this->assertCount(3, $soa['rows']);

        // The withheld tax settled the debt just as the cash did.
        $receipt = collect($soa['rows'])->firstWhere('particulars', fn ($p) => str_contains($p, 'Payment received'))
            ?? collect($soa['rows'])->last();
        $this->assertSame(1_120_000, $receipt['payment']);
        $this->assertStringContainsString('WC160', $receipt['particulars']);

        $this->assertSame(560_000, array_sum($soa['aging']));
    }

    public function test_a_cancelled_invoice_appears_on_the_statement_at_zero(): void
    {
        $today = CarbonImmutable::now();
        $customer = $this->customer();
        $invoice = $this->invoice($customer, $today->toDateString(), 200_000, 24_000);

        app(DocumentCanceller::class)->cancel($invoice, 'keyed twice');

        $soa = app(StatementOfAccount::class)->forCustomer(
            $customer->id,
            $today->subDays(5)->toDateString(),
            $today->toDateString(),
        );

        $row = collect($soa['rows'])->firstWhere('reference', $invoice->invoice_number);

        // Shown, not hidden: the customer may hold a copy and must see it
        // was voided. But it charges nothing.
        $this->assertNotNull($row);
        $this->assertSame(0, $row['charge']);
        $this->assertStringContainsString('cancelled', $row['particulars']);
        $this->assertSame(0, $soa['closing_centavos']);
    }

    /** Deposits in transit and outstanding cheques reconcile the two sides. */
    public function test_a_bank_reconciliation_balances_once_the_cleared_items_are_marked(): void
    {
        $today = CarbonImmutable::now();
        $cashId = $this->accountId('1000');

        // Three movements; the bank has only seen the first two.
        $deposit = $this->posting()->post($this->draft([['1000', 500_000, 0], ['3000', 0, 500_000]]));
        $cheque = $this->posting()->post($this->draft([['5000', 120_000, 0], ['1000', 0, 120_000]]));
        $this->posting()->post($this->draft([['1000', 75_000, 0], ['4900', 0, 75_000]]));

        // Statement closing = 500,000 − 120,000 = 380,000.
        $service = app(BankReconciliationService::class);
        $reconciliationId = $service->open($cashId, $today->toDateString(), 380_000);

        $before = $service->summary($reconciliationId);
        $this->assertSame(455_000, $before['book_balance']);
        // Nothing marked yet: all three sit in transit / outstanding.
        $this->assertSame(575_000, $before['deposits_in_transit']);
        $this->assertSame(120_000, $before['outstanding_cheques']);
        $this->assertFalse($before['reconciled']);

        foreach ([$deposit->id, $cheque->id] as $entryId) {
            $lineId = (int) DB::table('journal_lines')
                ->where('journal_entry_id', $entryId)
                ->where('account_id', $cashId)
                ->value('id');

            $service->setCleared($reconciliationId, $lineId, true);
        }

        $after = $service->summary($reconciliationId);

        $this->assertSame(75_000, $after['deposits_in_transit'], 'Only the uncleared deposit remains.');
        $this->assertSame(0, $after['outstanding_cheques']);
        $this->assertSame(455_000, $after['adjusted_bank']);
        $this->assertSame(0, $after['difference']);
        $this->assertTrue($after['reconciled']);

        $service->complete($reconciliationId, 'July statement');

        $this->assertSame('completed', DB::table('bank_reconciliations')->where('id', $reconciliationId)->value('status'));
        $this->assertSame(
            1,
            DB::table('audit_log')->where('event', 'bank_reconciliation.completed')->count(),
            'Sign-off must be audited.'
        );
    }

    /** A difference is a bank-only item to POST, never something to plug. */
    public function test_an_unreconciled_difference_cannot_be_completed(): void
    {
        $today = CarbonImmutable::now();
        $this->posting()->post($this->draft([['1000', 500_000, 0], ['3000', 0, 500_000]]));

        $service = app(BankReconciliationService::class);
        // The bank charged 1,500 that has not been booked.
        $reconciliationId = $service->open($this->accountId('1000'), $today->toDateString(), 498_500);

        $lineId = (int) DB::table('journal_lines')->where('account_id', $this->accountId('1000'))->value('id');
        $service->setCleared($reconciliationId, $lineId, true);

        $summary = $service->summary($reconciliationId);
        $this->assertSame(-1_500, $summary['difference']);

        $this->expectException(InvalidDraft::class);
        $this->expectExceptionMessageMatches('/cannot be plugged/');

        $service->complete($reconciliationId);
    }

    public function test_a_completed_reconciliation_is_frozen(): void
    {
        $today = CarbonImmutable::now();
        $service = app(BankReconciliationService::class);
        $reconciliationId = $service->open($this->accountId('1000'), $today->toDateString(), 0);

        $service->complete($reconciliationId);

        $this->expectException(InvalidDraft::class);
        $this->expectExceptionMessageMatches('/no longer be changed/');

        $service->setCleared($reconciliationId, 1, true);
    }

    public function test_reopening_the_same_statement_returns_the_existing_draft(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $service = app(BankReconciliationService::class);

        // No book movement, so a zero statement is the reconciled state.
        $first = $service->open($this->accountId('1000'), $today, 0);
        $second = $service->open($this->accountId('1000'), $today, 0);

        $this->assertSame($first, $second);

        $service->complete($first);

        $this->expectException(InvalidDraft::class);
        $this->expectExceptionMessageMatches('/already reconciled/');

        $service->open($this->accountId('1000'), $today, 0);
    }
}
