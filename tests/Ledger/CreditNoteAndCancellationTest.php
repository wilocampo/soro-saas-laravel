<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentBalances;
use App\Domain\Documents\DocumentCanceller;
use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Exceptions\CannotCancel;
use App\Domain\Documents\Models\CreditNote;
use App\Domain\Documents\Models\CreditNoteLine;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\PaymentAllocation;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Ledger\PeriodCloseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The adjustment path (docs/specs/03 §4): cancel while the period is open,
 * credit-note once the figures are in a filed return. Nothing is ever edited
 * or deleted, and a cancelled document keeps its serial.
 */
class CreditNoteAndCancellationTest extends LedgerTestCase
{
    private function customer(): Partner
    {
        return Partner::create([
            'code' => 'C-100',
            'is_customer' => true,
            'registered_name' => 'Acme Trading Corp.',
            'tin' => '123456789',
            'is_vat_registered' => true,
        ]);
    }

    /** Net 10,000.00 + 12% VAT = 11,200.00, serial drawn at post time. */
    private function invoice(Partner $customer, ?string $date = null): SalesInvoice
    {
        $invoice = SalesInvoice::create([
            'partner_id' => $customer->id,
            'invoice_date' => $date ?? CarbonImmutable::now()->toDateString(),
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

        return $invoice;
    }

    private function creditNote(Partner $customer, SalesInvoice $invoice, int $net, int $vat): CreditNote
    {
        $note = CreditNote::create([
            'side' => 'customer',
            'type' => 'credit',
            'partner_id' => $customer->id,
            'note_date' => CarbonImmutable::now()->toDateString(),
            'applies_to_type' => 'sales_invoice',
            'applies_to_id' => $invoice->id,
            'reason' => 'Goods returned — damaged in transit',
        ]);

        CreditNoteLine::create([
            'credit_note_id' => $note->id,
            'line_no' => 1,
            'description' => 'Return of consulting services',
            'net_centavos' => $net,
            'vat_centavos' => $vat,
            'account_id' => $this->accountId('4100'),   // Sales Returns & Allowances
        ]);

        $note->load('lines');
        $note->recalculateTotals();
        $note->save();

        return $note;
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

    public function test_documents_draw_a_continuous_serial_at_post_time(): void
    {
        $customer = $this->customer();

        $first = $this->invoice($customer);
        $second = $this->invoice($customer);

        app(DocumentPoster::class)->post($first);
        app(DocumentPoster::class)->post($second);

        // Continuous series: no year label, no reset (RMC 77-2024).
        $this->assertSame('INV-000001', $first->fresh()->invoice_number);
        $this->assertSame('INV-000002', $second->fresh()->invoice_number);
    }

    public function test_reposting_a_document_does_not_draw_a_second_serial(): void
    {
        $invoice = $this->invoice($this->customer());
        $poster = app(DocumentPoster::class);

        $poster->post($invoice);
        $number = $invoice->fresh()->invoice_number;

        // Idempotent post: same entry back, same number.
        $poster->post($invoice->fresh());

        $this->assertSame($number, $invoice->fresh()->invoice_number);
        $this->assertSame(1, DB::table('journal_entries')->where('journal_book', 'sales')->count());
    }

    /** A customer credit note reverses revenue and output VAT, and clears A/R. */
    public function test_customer_credit_note_posts_contra_revenue_and_reverses_output_vat(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        app(DocumentPoster::class)->post($invoice);

        $note = $this->creditNote($customer, $invoice, 1_000_000, 120_000);
        $entry = app(DocumentPoster::class)->post($note);

        $this->assertNotNull($entry);
        $this->assertSame('CM-000001', $note->fresh()->note_number);
        $this->assertSame('issued', $note->fresh()->status);

        $lines = $this->linesByCode($entry->id);
        $this->assertSame(1_000_000, $lines['4100']['debit']);   // Sales Returns
        $this->assertSame(120_000, $lines['2100']['debit']);     // Output VAT reversed
        $this->assertSame(1_120_000, $lines['1100']['credit']);  // A/R cleared

        // Gross sales survive intact — the return sits in its own account.
        $this->assertSame(1_000_000, $lines['4100']['debit']);
        $this->assertArrayNotHasKey('4000', $lines);

        $this->assertTrialBalanceZero();
    }

    public function test_a_full_credit_note_settles_the_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        app(DocumentPoster::class)->post($invoice);

        $this->assertSame(1_120_000, app(DocumentBalances::class)->outstandingCentavos($invoice));

        app(DocumentPoster::class)->post($this->creditNote($customer, $invoice, 1_000_000, 120_000));

        $this->assertSame(0, app(DocumentBalances::class)->outstandingCentavos($invoice->fresh()));
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    /** A debit note moves the same accounts the other way. */
    public function test_customer_debit_note_increases_the_receivable(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        app(DocumentPoster::class)->post($invoice);

        $note = $this->creditNote($customer, $invoice, 100_000, 12_000);
        $note->update(['type' => 'debit', 'reason' => 'Under-billed freight']);

        $entry = app(DocumentPoster::class)->post($note->fresh()->load('lines'));

        $lines = $this->linesByCode($entry->id);
        $this->assertSame(112_000, $lines['1100']['debit']);   // A/R grows
        $this->assertSame(100_000, $lines['4100']['credit']);
        $this->assertSame(12_000, $lines['2100']['credit']);   // more output VAT

        $this->assertSame('DM-000001', $note->fresh()->note_number);
        $this->assertSame(1_232_000, app(DocumentBalances::class)->outstandingCentavos($invoice->fresh()));
        $this->assertTrialBalanceZero();
    }

    /** Cancelling in an open period voids the entry and KEEPS the serial. */
    public function test_cancelling_in_an_open_period_voids_the_entry_and_keeps_the_serial(): void
    {
        $invoice = $this->invoice($this->customer());
        $entry = app(DocumentPoster::class)->post($invoice);
        $number = $invoice->fresh()->invoice_number;

        $mirror = app(DocumentCanceller::class)->cancel($invoice, 'keyed twice');

        $this->assertNotNull($mirror);
        $this->assertSame('cancelled', $invoice->fresh()->status);
        // The burned number is retained — never reused, never nulled (BIR).
        $this->assertSame($number, $invoice->fresh()->invoice_number);
        $this->assertSame('void', DB::table('journal_entries')->where('id', $entry->id)->value('status'));
        $this->assertTrialBalanceZero();
    }

    public function test_cancelling_a_closed_period_document_demands_a_credit_note(): void
    {
        $invoice = $this->invoice($this->customer());
        app(DocumentPoster::class)->post($invoice);

        // Periods close in order, so walk up to (and including) the one the
        // invoice sits in — that is what a real month-end run does.
        $through = (int) DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', CarbonImmutable::now()->toDateString())
            ->whereDate('end_date', '>=', CarbonImmutable::now()->toDateString())
            ->where('period_no', '<=', 12)
            ->value('period_no');

        $closer = app(PeriodCloseService::class);
        foreach (DB::table('fiscal_periods')->where('period_no', '<=', $through)
            ->orderBy('period_no')->pluck('id') as $periodId) {
            $closer->close((int) $periodId);
        }

        $this->expectException(CannotCancel::class);
        $this->expectExceptionMessageMatches('/credit note/i');

        app(DocumentCanceller::class)->cancel($invoice->fresh(), 'too late');
    }

    public function test_cancelling_a_settled_invoice_is_refused(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        app(DocumentPoster::class)->post($invoice);

        $payment = Payment::create([
            'direction' => 'received',
            'partner_id' => $customer->id,
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'amount_centavos' => 1_120_000,
            'cash_account_id' => $this->accountId('1000'),
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'allocatable_type' => 'sales_invoice',
            'allocatable_id' => $invoice->id,
            'applied_centavos' => 1_120_000,
        ]);
        app(DocumentPoster::class)->post($payment->load('allocations'));

        // The payment settled it — the cached column proves the refresh ran.
        $this->assertSame(1_120_000, (int) $invoice->fresh()->amount_paid_centavos);
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->expectException(CannotCancel::class);
        $this->expectExceptionMessageMatches('/payment applied/i');

        app(DocumentCanceller::class)->cancel($invoice->fresh(), 'changed my mind');
    }

    public function test_cancellation_requires_a_reason(): void
    {
        $invoice = $this->invoice($this->customer());
        app(DocumentPoster::class)->post($invoice);

        $this->expectException(CannotCancel::class);
        app(DocumentCanceller::class)->cancel($invoice, '   ');
    }
}
