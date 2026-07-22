<?php

namespace Tests\Ledger;

use App\Domain\Documents\DocumentPoster;
use App\Domain\Documents\Models\Partner;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\SalesInvoiceLine;
use App\Domain\Ledger\OpeningBalanceService;
use App\Domain\Reports\DashboardSummary;
use App\Domain\Reports\FinancialStatements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard-lite (Phase 3). The load-bearing property: it is derived from
 * the same services the statements use, so it cannot tell a different story
 * from the reports.
 */
class DashboardSummaryTest extends LedgerTestCase
{
    private function currentPeriodId(): int
    {
        return (int) DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', CarbonImmutable::now()->toDateString())
            ->whereDate('end_date', '>=', CarbonImmutable::now()->toDateString())
            ->where('period_no', '<=', 12)
            ->value('id');
    }

    private function seedLedgerData(): void
    {
        app(OpeningBalanceService::class)->post(
            ['1000' => 500_000, '3000' => 500_000],
            CarbonImmutable::now()->startOfYear()
        );

        $this->posting()->post($this->draft([
            ['5000', 120_000, 0],
            ['1000', 0, 120_000],
        ]));

        $customer = Partner::create([
            'code' => 'C-800',
            'is_customer' => true,
            'registered_name' => 'Overdue Corp.',
            'is_vat_registered' => true,
        ]);

        $today = CarbonImmutable::now();
        $invoice = SalesInvoice::create([
            'partner_id' => $customer->id,
            'invoice_date' => $today->subDays(45)->toDateString(),
            'due_date' => $today->subDays(20)->toDateString(),   // overdue
            'status' => 'issued',
        ]);
        SalesInvoiceLine::create([
            'sales_invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Services',
            'net_centavos' => 300_000,
            'vat_centavos' => 0,
            'account_id' => $this->accountId('4000'),
        ]);
        $invoice->load('lines');
        $invoice->recalculateTotals();
        $invoice->save();

        app(DocumentPoster::class)->post($invoice);
    }

    public function test_the_dashboard_agrees_with_the_financial_statements(): void
    {
        $this->seedLedgerData();
        $summary = app(DashboardSummary::class)->forToday();
        $statements = app(FinancialStatements::class);
        $periodId = $this->currentPeriodId();

        // Cash: 500,000 opening less the 120,000 expense.
        $this->assertSame(380_000, $summary['cash']['total']);

        // The P&L snapshot is the income statement, not a second derivation.
        $this->assertSame(
            $statements->incomeStatement($periodId, yearToDate: true)['net_income'],
            $summary['profit_and_loss']['year_to_date']['net_income'],
        );

        $this->assertSame(180_000, $summary['profit_and_loss']['year_to_date']['net_income']);
    }

    public function test_overdue_receivables_are_separated_from_current(): void
    {
        $this->seedLedgerData();
        $summary = app(DashboardSummary::class)->forToday();

        $this->assertSame(300_000, $summary['receivables']['total']);
        $this->assertSame(300_000, $summary['receivables']['overdue'], 'Due 20 days ago.');
        $this->assertSame(0, $summary['receivables']['current']);
        $this->assertSame('Overdue Corp.', $summary['receivables']['worst'][0]['registered_name']);
    }

    /** The filing calendar is statutory dates, not typed-in reminders. */
    public function test_the_filing_calendar_lists_the_quarterly_returns(): void
    {
        // 15 May 2026: Q2, so 2550Q covers Apr–Jun and is due 25 Jul.
        $summary = app(DashboardSummary::class)->forToday(CarbonImmutable::parse('2026-05-15'));

        $forms = array_column($summary['deadlines'], 'form');
        $this->assertContains('2550Q', $forms);
        $this->assertContains('1601EQ', $forms);
        // May is not a quarter-end month, so the monthly 0619E applies.
        $this->assertContains('0619E', $forms);

        $vat = collect($summary['deadlines'])->firstWhere('form', '2550Q');
        $this->assertSame('2026-07-25', $vat['due'], 'Quarter end plus 25 days.');
        $this->assertSame('2026-04-01 to 2026-06-30', $vat['covers']);
        $this->assertGreaterThan(0, $vat['days_remaining']);

        // TRAIN removed the monthly VAT return; it must never be listed.
        $this->assertNotContains('2550M', $forms);
    }

    public function test_a_quarter_end_month_has_no_monthly_remittance(): void
    {
        $summary = app(DashboardSummary::class)->forToday(CarbonImmutable::parse('2026-06-15'));

        $this->assertNotContains('0619E', array_column($summary['deadlines'], 'form'));
    }
}
