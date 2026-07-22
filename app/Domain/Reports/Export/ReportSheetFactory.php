<?php

namespace App\Domain\Reports\Export;

use App\Domain\Compliance\ReportHeader;
use App\Domain\Ledger\TrialBalanceService;
use App\Domain\Reports\FinancialStatements;
use App\Domain\Reports\GeneralLedgerReport;
use App\Domain\Reports\JournalReport;
use Illuminate\Support\Facades\DB;

/**
 * Turns each report into the one tabular shape the exporters understand.
 *
 * Every sheet is built here rather than inside the report services, so the
 * services stay about accounting and the presentation stays in one place —
 * and every sheet picks up the mandatory BIR header the same way.
 */
class ReportSheetFactory
{
    public function __construct(
        private readonly ReportHeader $header,
        private readonly TrialBalanceService $trialBalance,
        private readonly FinancialStatements $statements,
        private readonly GeneralLedgerReport $generalLedger,
        private readonly JournalReport $journal,
    ) {}

    public function trialBalance(int $periodId): ReportSheet
    {
        $report = $this->trialBalance->asOfPeriod($periodId);

        return new ReportSheet(
            title: 'Trial Balance',
            subtitle: $this->periodLabel($periodId),
            header: $this->header->for('Trial Balance'),
            columns: [
                new ReportColumn('code', 'Account code'),
                new ReportColumn('name', 'Account name'),
                new ReportColumn('debit', 'Debit', ReportColumn::MONEY),
                new ReportColumn('credit', 'Credit', ReportColumn::MONEY),
            ],
            rows: $report['rows'],
            totals: [[
                'code' => '',
                'name' => 'TOTAL',
                'debit' => $report['total_debit'],
                'credit' => $report['total_credit'],
            ]],
        );
    }

    public function balanceSheet(int $periodId): ReportSheet
    {
        $report = $this->statements->balanceSheet($periodId);
        $rows = [];

        foreach (['assets' => 'ASSETS', 'liabilities' => 'LIABILITIES', 'equity' => 'EQUITY'] as $key => $label) {
            $rows[] = ['code' => '', 'name' => $label, 'amount' => null];

            foreach ($report['sections'][$key]['rows'] as $row) {
                $rows[] = ['code' => $row['code'], 'name' => '  '.$row['name'], 'amount' => $row['amount']];
            }

            $rows[] = ['code' => '', 'name' => "Total {$label}", 'amount' => $report['sections'][$key]['total']];
        }

        // Undistributed earnings sit in equity until the year is closed.
        $rows[] = ['code' => '', 'name' => '  Net income for the period', 'amount' => $report['net_income']];

        return new ReportSheet(
            title: 'Balance Sheet',
            subtitle: $this->periodLabel($periodId),
            header: $this->header->for('Balance Sheet'),
            columns: [
                new ReportColumn('code', 'Account code'),
                new ReportColumn('name', 'Account'),
                new ReportColumn('amount', 'Amount', ReportColumn::MONEY),
            ],
            rows: $rows,
            totals: [
                ['code' => '', 'name' => 'TOTAL ASSETS', 'amount' => $report['total_assets']],
                ['code' => '', 'name' => 'TOTAL LIABILITIES AND EQUITY', 'amount' => $report['total_liabilities_and_equity']],
            ],
        );
    }

    public function incomeStatement(int $periodId, bool $yearToDate = true): ReportSheet
    {
        $report = $this->statements->incomeStatement($periodId, $yearToDate);
        $rows = [];

        foreach (['income' => 'INCOME', 'expenses' => 'EXPENSES'] as $key => $label) {
            $rows[] = ['code' => '', 'name' => $label, 'amount' => null];

            foreach ($report['sections'][$key]['rows'] as $row) {
                $rows[] = ['code' => $row['code'], 'name' => '  '.$row['name'], 'amount' => $row['amount']];
            }

            $rows[] = ['code' => '', 'name' => "Total {$label}", 'amount' => $report['sections'][$key]['total']];
        }

        return new ReportSheet(
            title: 'Income Statement',
            subtitle: ($yearToDate ? 'Year to date, ' : 'Period, ').$report['from'].' to '.$report['to'],
            header: $this->header->for('Income Statement'),
            columns: [
                new ReportColumn('code', 'Account code'),
                new ReportColumn('name', 'Account'),
                new ReportColumn('amount', 'Amount', ReportColumn::MONEY),
            ],
            rows: $rows,
            totals: [['code' => '', 'name' => 'NET INCOME', 'amount' => $report['net_income']]],
        );
    }

    public function generalLedger(int $accountId, string $from, string $to): ReportSheet
    {
        $report = $this->generalLedger->forAccount($accountId, $from, $to);
        $account = $report['account'];

        $rows = [[
            'entry_date' => $from,
            'entry_number' => '',
            'particulars' => 'Balance brought forward',
            'contra_accounts' => '',
            'debit' => null,
            'credit' => null,
            'running_signed' => $report['opening_signed'],
        ]];

        foreach ($report['rows'] as $row) {
            $rows[] = $row;
        }

        return new ReportSheet(
            title: 'General Ledger',
            subtitle: "{$account['code']} — {$account['name']}, {$from} to {$to}",
            header: $this->header->for('General Ledger'),
            columns: [
                new ReportColumn('entry_date', 'Date', ReportColumn::DATE),
                new ReportColumn('entry_number', 'Entry no.'),
                new ReportColumn('particulars', 'Particulars'),
                new ReportColumn('contra_accounts', 'Contra accounts'),
                new ReportColumn('debit', 'Debit', ReportColumn::MONEY),
                new ReportColumn('credit', 'Credit', ReportColumn::MONEY),
                new ReportColumn('running_signed', 'Balance', ReportColumn::MONEY),
            ],
            rows: $rows,
            totals: [[
                'entry_date' => '',
                'entry_number' => '',
                'particulars' => 'TOTAL',
                'contra_accounts' => '',
                'debit' => $report['total_debits'],
                'credit' => $report['total_credits'],
                'running_signed' => $report['closing_signed'],
            ]],
        );
    }

    public function journal(string $from, string $to, ?string $book = null): ReportSheet
    {
        $report = $this->journal->entries($from, $to, $book);
        $rows = [];

        // One row per LINE, with the entry repeated — the columnar shape the
        // BIR books use, and what a spreadsheet can be filtered on.
        foreach ($report['entries'] as $entry) {
            foreach ($entry['lines'] as $line) {
                $rows[] = [
                    'entry_date' => $entry['entry_date'],
                    'entry_number' => $entry['entry_number'],
                    'book_name' => $entry['book_name'],
                    'status' => $entry['status'],
                    'code' => $line['code'],
                    'name' => $line['name'],
                    'particulars' => $line['memo'] ?: $entry['memo'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ];
            }
        }

        return new ReportSheet(
            title: $report['book_name'],
            subtitle: "{$from} to {$to}",
            header: $this->header->for($report['book_name']),
            columns: [
                new ReportColumn('entry_date', 'Date', ReportColumn::DATE),
                new ReportColumn('entry_number', 'Entry no.'),
                new ReportColumn('book_name', 'Book'),
                new ReportColumn('status', 'Status'),
                new ReportColumn('code', 'Account code'),
                new ReportColumn('name', 'Account name'),
                new ReportColumn('particulars', 'Particulars'),
                new ReportColumn('debit', 'Debit', ReportColumn::MONEY),
                new ReportColumn('credit', 'Credit', ReportColumn::MONEY),
            ],
            rows: $rows,
            totals: [[
                'entry_date' => '', 'entry_number' => '', 'book_name' => '', 'status' => '',
                'code' => '', 'name' => '', 'particulars' => 'TOTAL',
                'debit' => $report['total_debits'],
                'credit' => $report['total_credits'],
            ]],
        );
    }

    private function periodLabel(int $periodId): string
    {
        $period = DB::table('fiscal_periods as fp')
            ->join('fiscal_years as fy', 'fy.id', '=', 'fp.fiscal_year_id')
            ->where('fp.id', $periodId)
            ->first(['fp.period_no', 'fp.end_date', 'fy.year_label']);

        return "as of {$period->end_date} (FY {$period->year_label}, period {$period->period_no})";
    }
}
