<?php

namespace Tests\Ledger;

use App\Domain\Ledger\OpeningBalanceService;
use App\Domain\Ledger\PeriodCloseService;
use App\Domain\Ledger\TrialBalanceService;
use App\Domain\Ledger\YearEndCloseService;
use App\Domain\Reports\FinancialStatements;
use App\Domain\Reports\GeneralLedgerReport;
use App\Domain\Reports\JournalReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Phase-3 exit criteria (docs/specs/05):
 *   1. Every report ties to the trial balance to the centavo.
 *   2. Year-end close yields a correct POST-CLOSING balance sheet.
 */
class FinancialStatementsTest extends LedgerTestCase
{
    private function currentPeriodId(): int
    {
        return (int) DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', CarbonImmutable::now()->toDateString())
            ->whereDate('end_date', '>=', CarbonImmutable::now()->toDateString())
            ->where('period_no', '<=', 12)
            ->value('id');
    }

    /** Capital 500k, a 300k sale, a 120k expense — hand-verifiable. */
    private function seedTrading(): void
    {
        app(OpeningBalanceService::class)->post(
            ['1000' => 500_000, '3000' => 500_000],
            CarbonImmutable::now()->startOfYear()
        );

        $this->posting()->post($this->draft([
            ['1100', 300_000, 0],
            ['4000', 0, 300_000],
        ], ['journalBook' => 'sales']));

        $this->posting()->post($this->draft([
            ['5000', 120_000, 0],
            ['1000', 0, 120_000],
        ], ['journalBook' => 'cash_disbursements']));
    }

    public function test_the_balance_sheet_and_income_statement_tie_to_the_trial_balance(): void
    {
        $this->seedTrading();
        $periodId = $this->currentPeriodId();

        $tie = app(FinancialStatements::class)->tieOut($periodId);

        $this->assertTrue($tie['trial_balanced'], 'The trial balance itself must net to zero.');
        $this->assertTrue($tie['balance_sheet_balanced'], 'Assets must equal liabilities + equity + net income.');
        $this->assertSame(
            $tie['net_income_statement'],
            $tie['net_income_balance_sheet'],
            'The P&L bottom line must be the figure carried into equity.'
        );
        $this->assertTrue($tie['ties']);
    }

    public function test_the_statements_report_the_hand_verified_figures(): void
    {
        $this->seedTrading();
        $periodId = $this->currentPeriodId();

        $statements = app(FinancialStatements::class);
        $profitAndLoss = $statements->incomeStatement($periodId);
        $sheet = $statements->balanceSheet($periodId);

        $this->assertSame(300_000, $profitAndLoss['sections']['income']['total']);
        $this->assertSame(120_000, $profitAndLoss['sections']['expenses']['total']);
        $this->assertSame(180_000, $profitAndLoss['net_income']);

        // Cash 500,000 − 120,000 = 380,000; A/R 300,000.
        $this->assertSame(680_000, $sheet['sections']['assets']['total']);
        $this->assertSame(0, $sheet['sections']['liabilities']['total']);
        $this->assertSame(500_000, $sheet['sections']['equity']['total']);
        $this->assertSame(180_000, $sheet['net_income']);
        $this->assertSame(680_000, $sheet['total_liabilities_and_equity']);
        $this->assertTrue($sheet['balanced']);
    }

    /** A contra-asset reduces assets without any special handling. */
    public function test_a_contra_asset_reduces_total_assets(): void
    {
        $this->seedTrading();

        $this->posting()->post($this->draft([
            ['5100', 50_000, 0],   // Depreciation Expense
            ['1590', 0, 50_000],   // Accumulated Depreciation (contra-asset)
        ]));

        $sheet = app(FinancialStatements::class)->balanceSheet($this->currentPeriodId());

        // 680,000 − 50,000 of accumulated depreciation.
        $this->assertSame(630_000, $sheet['sections']['assets']['total']);
        $this->assertTrue($sheet['balanced']);
        $this->assertTrue(app(FinancialStatements::class)->tieOut($this->currentPeriodId())['ties']);
    }

    /**
     * The exit criterion: after the year is closed the nominal accounts are
     * empty, retained earnings holds the result, and the balance sheet still
     * balances — with a net income of zero, and no special case for it.
     */
    public function test_year_end_close_yields_a_correct_post_closing_balance_sheet(): void
    {
        $this->seedTrading();
        $periodId = $this->currentPeriodId();

        $before = app(FinancialStatements::class)->balanceSheet($periodId);
        $this->assertSame(180_000, $before['net_income']);
        $this->assertSame(0, $this->balanceOf('3200'), 'Retained earnings is untouched until the close.');

        app(YearEndCloseService::class)->close((int) DB::table('fiscal_years')->value('id'));

        $adjustmentPeriodId = (int) DB::table('fiscal_periods')->where('period_no', 13)->value('id');
        $after = app(FinancialStatements::class)->balanceSheet($adjustmentPeriodId);

        $this->assertSame(0, $after['net_income'], 'Nominal accounts are zero after the close.');
        $this->assertSame(180_000, $this->balanceOf('3200'), 'Retained earnings now carries the result.');
        $this->assertSame($before['total_assets'], $after['total_assets'], 'Closing moves nothing on the asset side.');
        $this->assertTrue($after['balanced']);

        // Equity absorbed exactly what net income used to represent.
        $this->assertSame(680_000, $after['sections']['equity']['total']);
        $this->assertTrue(app(FinancialStatements::class)->tieOut($adjustmentPeriodId)['ties']);
    }

    /** The P&L is a movement report: a closed prior period must not leak in. */
    public function test_a_period_income_statement_excludes_earlier_periods(): void
    {
        $today = CarbonImmutable::now();
        $lastMonth = $today->subMonthNoOverflow();

        $this->posting()->post($this->draft([
            ['1000', 100_000, 0],
            ['4000', 0, 100_000],
        ], ['entryDate' => $lastMonth]));

        $this->posting()->post($this->draft([
            ['1000', 250_000, 0],
            ['4000', 0, 250_000],
        ], ['entryDate' => $today]));

        $periodId = $this->currentPeriodId();
        $statements = app(FinancialStatements::class);

        $this->assertSame(250_000, $statements->incomeStatement($periodId, yearToDate: false)['net_income']);
        $this->assertSame(350_000, $statements->incomeStatement($periodId, yearToDate: true)['net_income']);
    }

    /** The general ledger closes where the trial balance says it should. */
    public function test_the_general_ledger_ties_to_the_trial_balance_for_an_account(): void
    {
        $this->seedTrading();
        $periodId = $this->currentPeriodId();
        $year = CarbonImmutable::now();

        $ledger = app(GeneralLedgerReport::class)->forAccount(
            $this->accountId('1000'),
            $year->startOfYear()->toDateString(),
            $year->endOfYear()->toDateString(),
        );

        $this->assertTrue($ledger['ties'], 'opening + debits − credits must equal the closing balance.');
        $this->assertSame(380_000, $ledger['closing_signed']);

        $trialRow = collect(app(TrialBalanceService::class)->asOfPeriod($periodId)['rows'])
            ->firstWhere('code', '1000');

        $this->assertSame($trialRow['signed'], $ledger['closing_signed']);

        // The reader can see the other side of each entry.
        $this->assertContains('5000', $ledger['rows'][array_key_last($ledger['rows'])]['contra_accounts']);
    }

    /** A journal range balances because each entry within it does. */
    public function test_the_journal_report_balances_and_shows_voided_entries(): void
    {
        $this->seedTrading();
        $year = CarbonImmutable::now();
        $from = $year->startOfYear()->toDateString();
        $to = $year->endOfYear()->toDateString();

        $entry = $this->posting()->post($this->draft([
            ['1000', 75_000, 0],
            ['4900', 0, 75_000],
        ]));
        $this->posting()->void($entry, 'keyed twice');

        $report = app(JournalReport::class)->entries($from, $to);

        $this->assertTrue($report['balanced']);

        $numbers = array_column($report['entries'], 'entry_number');
        $this->assertContains($entry->entry_number, $numbers, 'A voided entry stays visible — hiding it would be suppression.');

        $voided = collect($report['entries'])->firstWhere('entry_number', $entry->entry_number);
        $this->assertSame('void', $voided['status']);
        $this->assertTrue($voided['is_reversed']);

        // Filtering by book returns only that book.
        $sales = app(JournalReport::class)->entries($from, $to, 'sales');
        $this->assertNotEmpty($sales['entries']);
        foreach ($sales['entries'] as $salesEntry) {
            $this->assertSame('sales', $salesEntry['journal_book']);
        }
        $this->assertTrue($sales['balanced']);
    }

    /** Closing a period must not move any reported figure. */
    public function test_closing_a_period_leaves_the_statements_unchanged(): void
    {
        $this->seedTrading();
        $periodId = $this->currentPeriodId();

        $before = app(FinancialStatements::class)->balanceSheet($periodId);

        $closer = app(PeriodCloseService::class);
        foreach (DB::table('fiscal_periods')->where('period_no', '<=', DB::table('fiscal_periods')
            ->where('id', $periodId)->value('period_no'))->orderBy('period_no')->pluck('id') as $id) {
            $closer->close((int) $id);
        }

        $after = app(FinancialStatements::class)->balanceSheet($periodId);

        // Closed periods read the cache instead of scanning; both paths must
        // produce the identical figure.
        $this->assertSame($before['total_assets'], $after['total_assets']);
        $this->assertSame($before['net_income'], $after['net_income']);
        $this->assertTrue($after['balanced']);
    }

    /**
     * Regression: `opening_signed` on a cache row created by a posting is
     * zero until the preceding period is rolled forward at close. Reading it
     * anyway made a trial balance in July report only July's movement.
     */
    public function test_the_trial_balance_spans_several_still_open_periods(): void
    {
        $year = CarbonImmutable::now()->startOfYear();

        // One entry per month across the first half of the year, none closed.
        for ($month = 0; $month < 6; $month++) {
            $this->posting()->post($this->draft([
                ['1000', 10_000, 0],
                ['4000', 0, 10_000],
            ], ['entryDate' => $year->addMonths($month)->addDays(5)]));
        }

        $sixthPeriodId = (int) DB::table('fiscal_periods')->where('period_no', 6)->value('id');
        $trial = app(TrialBalanceService::class)->asOfPeriod($sixthPeriodId);
        $cash = collect($trial['rows'])->firstWhere('code', '1000');

        $this->assertSame(60_000, $cash['signed'], 'Every open period before this one must still count.');
        $this->assertTrue($trial['balanced']);
    }

    /**
     * Regression: a contra-revenue account is debit-normal, so its `signed`
     * balance is positive — summing it as income turned a sales return into
     * extra revenue.
     */
    public function test_contra_revenue_reduces_income_rather_than_increasing_it(): void
    {
        $this->seedTrading();

        // A customer returns 80,000 of the 300,000 sale.
        $this->posting()->post($this->draft([
            ['4100', 80_000, 0],   // Sales Returns and Allowances (contra-revenue)
            ['1100', 0, 80_000],
        ], ['journalBook' => 'sales']));

        $periodId = $this->currentPeriodId();
        $profitAndLoss = app(FinancialStatements::class)->incomeStatement($periodId);

        $this->assertSame(220_000, $profitAndLoss['sections']['income']['total'], 'Gross 300,000 less 80,000 returned.');
        $this->assertSame(100_000, $profitAndLoss['net_income']);
        $this->assertTrue(app(FinancialStatements::class)->tieOut($periodId)['ties']);

        // Gross sales survive as their own line — the netting is presentation.
        $gross = collect($profitAndLoss['sections']['income']['rows'])->firstWhere('code', '4000');
        $returns = collect($profitAndLoss['sections']['income']['rows'])->firstWhere('code', '4100');
        $this->assertSame(300_000, $gross['amount']);
        $this->assertSame(-80_000, $returns['amount']);
        $this->assertTrue($returns['is_contra']);
    }

    private function balanceOf(string $code): int
    {
        return (int) DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $this->accountId($code))
            ->whereIn('je.status', ['posted', 'void'])
            ->selectRaw('COALESCE(SUM(jl.credit_centavos),0) - COALESCE(SUM(jl.debit_centavos),0) AS net')
            ->value('net');
    }
}
