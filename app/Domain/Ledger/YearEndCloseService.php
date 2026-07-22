<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Ledger\Exceptions\PeriodClosed;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Year-end close (docs/specs/01 §5, fixture S8): zero the nominal accounts
 * into **Income Summary**, then Income Summary into **Retained Earnings**,
 * as one balanced JE dated the FY end and routed explicitly to the
 * period-13 adjustment window (01 §7). Then the fiscal year closes.
 *
 * CPA sign-off pending (spec 06): Income Summary vs direct-to-RE,
 * drawings/dividends handling, book-vs-tax (MCIT/NOLCO).
 */
class YearEndCloseService
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
        private readonly BalanceCache $balances,
    ) {}

    public function close(int $fiscalYearId, ?int $actorId = null, ?string $actorName = null): ?JournalEntry
    {
        $year = DB::table('fiscal_years')->where('id', $fiscalYearId)->first();

        if ($year === null || $year->status !== 'open') {
            throw new PeriodClosed('Only an open fiscal year can be closed.');
        }

        $settings = DB::table('ledger_settings')->where('id', 1)->first();
        if ($settings === null || $settings->income_summary_account_id === null || $settings->retained_earnings_account_id === null) {
            throw new InvalidDraft('Income Summary and Retained Earnings accounts must be configured.');
        }

        // Period 13 is the adjustment window (end_date..end_date) and may be
        // open while 1–12 are closed — this is why it is routed explicitly.
        $period13 = DB::table('fiscal_periods')
            ->where('fiscal_year_id', $fiscalYearId)->where('period_no', 13)->first();

        if ($period13 === null || $period13->status !== 'open') {
            throw new PeriodClosed('The period-13 adjustment window must be open to post the closing entry.');
        }

        // Nominal balances for the year, signed on each account's normal side.
        $nominals = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('account_types as at', 'at.id', '=', 'a.account_type_id')
            ->join('fiscal_periods as fp', 'fp.id', '=', 'jl.fiscal_period_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->where('fp.fiscal_year_id', $fiscalYearId)
            ->whereIn('at.code', ['income', 'expense'])
            ->groupBy('jl.account_id', 'at.code', 'a.normal_balance')
            ->select('jl.account_id', 'at.code as type_code', 'a.normal_balance')
            ->selectRaw('SUM(jl.debit_centavos) AS d, SUM(jl.credit_centavos) AS c')
            ->get();

        $lines = [];
        $incomeTotal = 0;   // credit-side net
        $expenseTotal = 0;  // debit-side net

        foreach ($nominals as $row) {
            $net = (int) $row->d - (int) $row->c;   // + = debit balance

            if ($net === 0) {
                continue;
            }

            // Close the account by posting its mirror image.
            $lines[] = new JournalLineDraft(
                accountId: (int) $row->account_id,
                debitCentavos: $net < 0 ? abs($net) : 0,
                creditCentavos: $net > 0 ? $net : 0,
                memo: 'Year-end close',
            );

            $row->type_code === 'income' ? $incomeTotal += -$net : $expenseTotal += $net;
        }

        if ($lines === []) {
            $this->markYearClosed($fiscalYearId, null, $actorId, $actorName);

            return null;   // nothing to close — a year with no nominal activity
        }

        // Income Summary absorbs both sides, then nets to Retained Earnings.
        if ($incomeTotal !== 0) {
            $lines[] = new JournalLineDraft(
                accountId: (int) $settings->income_summary_account_id,
                debitCentavos: $incomeTotal < 0 ? abs($incomeTotal) : 0,
                creditCentavos: $incomeTotal > 0 ? $incomeTotal : 0,
                memo: 'Revenue closed to Income Summary',
            );
        }
        if ($expenseTotal !== 0) {
            $lines[] = new JournalLineDraft(
                accountId: (int) $settings->income_summary_account_id,
                debitCentavos: $expenseTotal > 0 ? $expenseTotal : 0,
                creditCentavos: $expenseTotal < 0 ? abs($expenseTotal) : 0,
                memo: 'Expenses closed to Income Summary',
            );
        }

        $netIncome = $incomeTotal - $expenseTotal;   // + = profit
        if ($netIncome !== 0) {
            $lines[] = new JournalLineDraft(
                accountId: (int) $settings->income_summary_account_id,
                debitCentavos: $netIncome > 0 ? $netIncome : 0,
                creditCentavos: $netIncome < 0 ? abs($netIncome) : 0,
                memo: 'Income Summary closed to Retained Earnings',
            );
            $lines[] = new JournalLineDraft(
                accountId: (int) $settings->retained_earnings_account_id,
                debitCentavos: $netIncome < 0 ? abs($netIncome) : 0,
                creditCentavos: $netIncome > 0 ? $netIncome : 0,
                memo: 'Net '.($netIncome > 0 ? 'income' : 'loss').' for '.$year->year_label,
            );
        }

        $entry = $this->posting->post(new JournalDraft(
            journalBook: 'year_end_close',
            entryDate: CarbonImmutable::parse($year->end_date),
            memo: "Year-end close {$year->year_label}",
            source: SourceRef::none(),
            idempotencyKey: "fiscal_year:{$fiscalYearId}:year_end_close:1",
            lines: $lines,
            fiscalPeriodId: (int) $period13->id,
        ));

        $this->markYearClosed($fiscalYearId, $entry?->entry_number, $actorId, $actorName);

        return $entry;
    }

    private function markYearClosed(int $fiscalYearId, ?string $entryNumber, ?int $actorId, ?string $actorName): void
    {
        DB::table('fiscal_years')->where('id', $fiscalYearId)->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $actorId,
        ]);

        DB::table('fiscal_periods')->where('fiscal_year_id', $fiscalYearId)
            ->where('status', 'open')
            ->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actorId]);

        // A closed period is read straight from `closing_signed`, so the
        // cache MUST be rolled forward before the flip is visible — closing
        // the year without this made every report read zero.
        $this->balances->rollForward();

        $this->audit->record(
            event: 'fiscal_year.closed',
            auditableType: 'fiscal_year',
            auditableId: $fiscalYearId,
            documentNumber: $entryNumber,
            after: ['status' => 'closed', 'closing_entry' => $entryNumber],
            actorId: $actorId,
            actorName: $actorName,
        );
    }
}
