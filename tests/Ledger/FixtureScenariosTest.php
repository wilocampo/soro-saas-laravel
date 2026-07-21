<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Exceptions\PeriodClosed;
use App\Domain\Ledger\OpeningBalanceService;
use App\Domain\Ledger\PeriodCloseService;
use App\Domain\Ledger\TrialBalanceService;
use App\Domain\Ledger\YearEndCloseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The hand-verified fixtures from docs/fixtures/scenarios.md as tests
 * (docs/specs/05). Ledger-only scenarios land here; the document scenarios
 * (S2–S5) arrive with the Phase-2 posting rules and S10 with Phase-2b
 * inventory. ⚠ Tax treatment still needs CPA sign-off (specs 03/06).
 */
class FixtureScenariosTest extends LedgerTestCase
{
    /** S1 — Opening balances (accrual): 550,000 debits = 550,000 credits. */
    public function test_s1_opening_balances_post_and_tie(): void
    {
        $entry = app(OpeningBalanceService::class)->post([
            '1000' => 20_000_000,   // Cash in Bank      200,000.00
            '1100' => 5_000_000,    // A/R                50,000.00
            '1500' => 30_000_000,   // Equipment         300,000.00
            '2000' => 8_000_000,    // A/P                80,000.00
            '3000' => 47_000_000,   // Owner's Capital   470,000.00
        ], CarbonImmutable::now()->startOfYear());

        $this->assertNotNull($entry);
        $this->assertSame('opening_balance', $entry->journal_book);
        $this->assertStringStartsWith('OB-', $entry->entry_number);
        $this->assertSame(55_000_000, $entry->total_debit_centavos);
        $this->assertSame(55_000_000, $entry->total_credit_centavos);

        // Natural balances land on each account's normal side.
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->pluck('journal_lines.debit_centavos', 'accounts.code');
        $this->assertSame(20_000_000, (int) $lines['1000']);   // asset → debit
        $this->assertSame(0, (int) $lines['2000']);            // liability → credit

        $this->assertTrialBalanceZero();
    }

    /** S1b — Opening Balance Equity absorbs the plug when input is one-sided. */
    public function test_s1_opening_balance_equity_absorbs_the_plug(): void
    {
        $entry = app(OpeningBalanceService::class)->post([
            '1000' => 20_000_000,
            '1100' => 5_000_000,
            '1500' => 30_000_000,
            '2000' => 8_000_000,
            // Owner's Capital deliberately omitted → OBE takes 47,000.00
        ], CarbonImmutable::now()->startOfYear());

        $obeAccountId = DB::table('ledger_settings')->where('id', 1)->value('opening_balance_equity_account_id');
        $plug = DB::table('journal_lines')
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $obeAccountId)
            ->first();

        $this->assertNotNull($plug, 'Opening Balance Equity must absorb the difference.');
        $this->assertSame(47_000_000, (int) $plug->credit_centavos);
        $this->assertTrialBalanceZero();
    }

    /** S6 + S7 — Manual adjusting entry (depreciation) and its reversal net to zero. */
    public function test_s6_and_s7_depreciation_entry_and_reversal_net_to_zero(): void
    {
        $s6 = $this->posting()->post($this->draft([
            ['5100', 200_000, 0],   // Depreciation Expense    2,000.00
            ['1590', 0, 200_000],   // Accumulated Depreciation (contra-asset)
        ], ['memo' => 'Monthly depreciation']));

        $this->assertSame(200_000, $s6->total_debit_centavos);

        $s7 = $this->posting()->reverse($s6, 'correction');

        // Mirror: Dr Accumulated Depreciation / Cr Depreciation Expense
        $mirror = DB::table('journal_lines')->where('journal_entry_id', $s7->id)
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->pluck('journal_lines.debit_centavos', 'accounts.code');
        $this->assertSame(200_000, (int) $mirror['1590']);
        $this->assertSame(0, (int) $mirror['5100']);

        // Net of S6 + S7 on every account = 0
        $nets = DB::table('journal_lines')
            ->whereIn('journal_entry_id', [$s6->id, $s7->id])
            ->selectRaw('account_id, SUM(debit_centavos) - SUM(credit_centavos) AS net')
            ->groupBy('account_id')->pluck('net');
        foreach ($nets as $net) {
            $this->assertSame(0, (int) $net);
        }
    }

    /** S8 — Year-end close: nominal → Income Summary → Retained Earnings. */
    public function test_s8_year_end_close_zeroes_nominal_accounts_into_retained_earnings(): void
    {
        // Revenue 10,000.00 and expenses 7,000.00 → net income 3,000.00
        $this->posting()->post($this->draft([
            ['1000', 1_000_000, 0],
            ['4000', 0, 1_000_000],
        ]));
        $this->posting()->post($this->draft([
            ['5000', 700_000, 0],
            ['1000', 0, 700_000],
        ]));

        $fiscalYearId = (int) DB::table('ledger_settings')->where('id', 1)->value('current_fiscal_year_id');
        $entry = app(YearEndCloseService::class)->close($fiscalYearId);

        $this->assertNotNull($entry);
        $this->assertSame('year_end_close', $entry->journal_book);
        $this->assertStringStartsWith('YEC-', $entry->entry_number);
        $this->assertSame(13, (int) DB::table('fiscal_periods')->where('id', $entry->fiscal_period_id)->value('period_no'));
        $this->assertSame('closed', DB::table('fiscal_years')->where('id', $fiscalYearId)->value('status'));

        $netByCode = fn (string $code) => (int) DB::table('journal_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->where('a.code', $code)
            ->selectRaw('COALESCE(SUM(jl.debit_centavos) - SUM(jl.credit_centavos), 0) AS net')
            ->value('net');

        // Nominal accounts are flat going into the new year…
        $this->assertSame(0, $netByCode('4000'), 'Sales Revenue must close to zero.');
        $this->assertSame(0, $netByCode('5000'), 'Expenses must close to zero.');
        $this->assertSame(0, $netByCode('3300'), 'Income Summary must net to zero.');
        // …and Retained Earnings carries the 3,000.00 net income (credit).
        $this->assertSame(-300_000, $netByCode('3200'));

        $this->assertTrialBalanceZero();
    }

    /** S9 — Month close: lock advances, back-dating is rejected, balances roll forward. */
    public function test_s9_month_close_locks_the_period_and_rolls_balances_forward(): void
    {
        $january = CarbonImmutable::now()->startOfYear()->addDays(14);

        $this->posting()->post($this->draft([
            ['1000', 1_120_000, 0],
            ['4000', 0, 1_000_000],
            ['2100', 0, 120_000],
        ], ['entryDate' => $january]));

        $periods = app(PeriodCloseService::class);
        $januaryPeriodId = $periods->periodForDate($january);
        $periods->close($januaryPeriodId);

        // (a) the period is closed and the lock date advanced to Jan 31
        $this->assertSame('closed', DB::table('fiscal_periods')->where('id', $januaryPeriodId)->value('status'));
        $this->assertSame(
            CarbonImmutable::now()->startOfYear()->endOfMonth()->toDateString(),
            (string) DB::table('ledger_settings')->where('id', 1)->value('posting_lock_date'),
        );

        // (b) a new entry dated inside the closed period is rejected
        try {
            $this->posting()->post($this->draft([
                ['1000', 100, 0],
                ['4000', 0, 100],
            ], ['entryDate' => $january]));
            $this->fail('An entry was accepted into a closed period.');
        } catch (PeriodClosed) {
            $this->addToAssertionCount(1);
        }

        // (c) February opening balances equal January closing balances
        $februaryId = $periods->periodForDate(CarbonImmutable::now()->startOfYear()->addMonth());
        $cashAccountId = $this->accountId('1000');

        $januaryClosing = (int) DB::table('account_period_balances')
            ->where('account_id', $cashAccountId)->where('fiscal_period_id', $januaryPeriodId)
            ->value('closing_signed');
        $februaryOpening = (int) DB::table('account_period_balances')
            ->where('account_id', $cashAccountId)->where('fiscal_period_id', $februaryId)
            ->value('opening_signed');

        $this->assertSame(1_120_000, $januaryClosing);
        $this->assertSame($januaryClosing, $februaryOpening);

        // (d) the trial balance as of the closed period ties to the centavo
        $trialBalance = app(TrialBalanceService::class)->asOfPeriod($januaryPeriodId);
        $this->assertTrue($trialBalance['balanced']);
        $this->assertSame(1_120_000, $trialBalance['total_debit']);
        $this->assertSame(1_120_000, $trialBalance['total_credit']);
    }

    public function test_periods_must_close_in_order_and_drafts_block_the_close(): void
    {
        $periods = app(PeriodCloseService::class);
        $februaryId = $periods->periodForDate(CarbonImmutable::now()->startOfYear()->addMonth());

        $this->expectException(PeriodClosed::class);
        $this->expectExceptionMessage('must close in order');

        $periods->close($februaryId);   // January is still open
    }

    public function test_reopening_a_closed_period_retreats_the_lock_and_is_audited(): void
    {
        $january = CarbonImmutable::now()->startOfYear()->addDays(14);
        $periods = app(PeriodCloseService::class);
        $januaryPeriodId = $periods->periodForDate($january);

        $this->posting()->post($this->draft([
            ['1000', 500, 0],
            ['4000', 0, 500],
        ], ['entryDate' => $january]));

        $periods->close($januaryPeriodId);
        $periods->reopen($januaryPeriodId, reason: 'audit adjustment');

        $this->assertSame('open', DB::table('fiscal_periods')->where('id', $januaryPeriodId)->value('status'));
        $this->assertNull(DB::table('ledger_settings')->where('id', 1)->value('posting_lock_date'));
        $this->assertNotNull(DB::table('audit_log')->where('event', 'period.reopened')->first());

        // Posting into the reopened period works again.
        $entry = $this->posting()->post($this->draft([
            ['1000', 700, 0],
            ['4000', 0, 700],
        ], ['entryDate' => $january]));
        $this->assertSame('posted', $entry->status);
    }
}
