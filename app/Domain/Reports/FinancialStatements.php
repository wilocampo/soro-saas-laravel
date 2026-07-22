<?php

namespace App\Domain\Reports;

use App\Domain\Ledger\TrialBalanceService;
use Illuminate\Support\Facades\DB;

/**
 * Balance sheet and income statement (docs/specs, Phase 3).
 *
 * Both are built as a PARTITION of one trial balance rather than from
 * independent queries. That is deliberate: the Phase-3 exit criterion is
 * that every report ties to the trial balance to the centavo, and a
 * partition ties by construction — there is no second source of truth to
 * drift from.
 *
 * The subtlety that makes a balance sheet balance mid-year: nominal
 * accounts are not closed until year end, so the period's net income has to
 * appear in equity as its own line. After `YearEndCloseService` runs, those
 * accounts are zero and the same arithmetic yields a net income of zero —
 * so the post-closing balance sheet balances without a special case.
 */
class FinancialStatements
{
    /**
     * The side each section naturally sits on. A row whose own normal
     * balance differs from its section's — i.e. a CONTRA account — must be
     * subtracted: accumulated depreciation reduces assets, and sales returns
     * reduce revenue. Reading `signed` straight would add both.
     */
    private const NATURAL_SIDE = [
        'asset' => 'debit',
        'expense' => 'debit',
        'liability' => 'credit',
        'equity' => 'credit',
        'income' => 'credit',
    ];

    public function __construct(private readonly TrialBalanceService $trialBalance) {}

    /**
     * @return array{
     *   period:array<string,mixed>, sections:array<string,mixed>,
     *   net_income:int, total_assets:int, total_liabilities_and_equity:int, balanced:bool
     * }
     */
    public function balanceSheet(int $periodId): array
    {
        $period = $this->period($periodId);
        $trial = $this->trialBalance->asOfPeriod($periodId);

        $assets = $this->section($trial['rows'], 'asset');
        $liabilities = $this->section($trial['rows'], 'liability');
        $equity = $this->section($trial['rows'], 'equity');

        // Undistributed earnings: everything on the income statement, still
        // open. Zero once the year has been closed.
        $netIncome = $this->netIncomeToDate($trial['rows']);

        $totalAssets = $assets['total'];
        $totalLiabilitiesAndEquity = $liabilities['total'] + $equity['total'] + $netIncome;

        return [
            'period' => (array) $period,
            'sections' => [
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
            ],
            'net_income' => $netIncome,
            'total_assets' => $totalAssets,
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'balanced' => $totalAssets === $totalLiabilitiesAndEquity,
        ];
    }

    /**
     * Period or year-to-date movement on the nominal accounts.
     *
     * A P&L is a MOVEMENT report, not a balance: the balance cache holds
     * cumulative figures, so this reads the lines for the requested range.
     *
     * @return array{
     *   period:array<string,mixed>, from:string, to:string, year_to_date:bool,
     *   sections:array<string,mixed>, net_income:int
     * }
     */
    public function incomeStatement(int $periodId, bool $yearToDate = true): array
    {
        $period = $this->period($periodId);

        $from = $yearToDate
            ? (string) DB::table('fiscal_years')->where('id', $period->fiscal_year_id)->value('start_date')
            : (string) $period->start_date;

        $movement = $this->nominalMovement($from, (string) $period->end_date);

        $income = $this->movementSection($movement, 'income');
        $expense = $this->movementSection($movement, 'expense');

        return [
            'period' => (array) $period,
            'from' => $from,
            'to' => (string) $period->end_date,
            'year_to_date' => $yearToDate,
            'sections' => ['income' => $income, 'expenses' => $expense],
            'net_income' => $income['total'] - $expense['total'],
        ];
    }

    /**
     * Proof for the operator (and for the tests) that the statements are the
     * trial balance rearranged, not a re-derivation of it.
     *
     * @return array{trial_balanced:bool, balance_sheet_balanced:bool,
     *               net_income_statement:int, net_income_balance_sheet:int, ties:bool}
     */
    public function tieOut(int $periodId): array
    {
        $trial = $this->trialBalance->asOfPeriod($periodId);
        $sheet = $this->balanceSheet($periodId);
        $statement = $this->incomeStatement($periodId, yearToDate: true);

        return [
            'trial_balanced' => $trial['balanced'],
            'balance_sheet_balanced' => $sheet['balanced'],
            'net_income_statement' => $statement['net_income'],
            'net_income_balance_sheet' => $sheet['net_income'],
            'ties' => $trial['balanced']
                && $sheet['balanced']
                && $statement['net_income'] === $sheet['net_income'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    private function section(array $rows, string $typeCode): array
    {
        $section = [];
        $total = 0;

        foreach ($rows as $row) {
            if ($row['type_code'] !== $typeCode) {
                continue;
            }

            $amount = $this->onNaturalSide($row['signed'], $row['normal_balance'], $typeCode);

            $section[] = [
                'account_id' => $row['account_id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'is_contra' => $amount !== $row['signed'] || $row['signed'] === 0
                    ? $row['normal_balance'] !== self::NATURAL_SIDE[$typeCode]
                    : false,
                'amount' => $amount,
            ];
            $total += $amount;
        }

        return ['rows' => $section, 'total' => $total];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function netIncomeToDate(array $rows): int
    {
        $net = 0;

        foreach ($rows as $row) {
            if ($row['statement'] !== 'income_statement') {
                continue;
            }

            $amount = $this->onNaturalSide($row['signed'], $row['normal_balance'], $row['type_code']);

            // Both sections are positive when normal, so income adds and
            // expense subtracts; contra rows are already negated above.
            $net += $row['type_code'] === 'income' ? $amount : -$amount;
        }

        return $net;
    }

    /** Negate a contra account so it reduces its section instead of growing it. */
    private function onNaturalSide(int $signed, string $normalBalance, string $typeCode): int
    {
        return $normalBalance === (self::NATURAL_SIDE[$typeCode] ?? $normalBalance) ? $signed : -$signed;
    }

    /**
     * Movement per nominal account over a date range, signed onto each
     * account's normal side.
     *
     * @return list<array<string, mixed>>
     */
    private function nominalMovement(string $from, string $to): array
    {
        return DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('t.statement', 'income_statement')
            // Void entries keep their mirror, so both sides net out and the
            // pair contributes nothing — the same rule the ledger follows.
            ->whereIn('je.status', ['posted', 'void'])
            ->whereBetween('je.entry_date', [$from, $to])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.normal_balance', 't.code')
            ->orderBy('a.code')
            ->selectRaw(
                'a.id AS account_id, a.code, a.name, a.normal_balance, t.code AS type_code, '
                .'COALESCE(SUM(jl.debit_centavos),0) AS debits, COALESCE(SUM(jl.credit_centavos),0) AS credits'
            )
            ->get()
            ->map(fn ($row) => [
                'account_id' => (int) $row->account_id,
                'code' => $row->code,
                'name' => $row->name,
                'type_code' => $row->type_code,
                'is_contra' => $row->normal_balance !== (self::NATURAL_SIDE[$row->type_code] ?? $row->normal_balance),
                // Normalised onto the section's natural side, so a contra
                // account (sales returns) reduces its section.
                'amount' => $this->onNaturalSide(
                    $row->normal_balance === 'debit'
                        ? (int) $row->debits - (int) $row->credits
                        : (int) $row->credits - (int) $row->debits,
                    $row->normal_balance,
                    $row->type_code,
                ),
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $movement
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    private function movementSection(array $movement, string $typeCode): array
    {
        $rows = array_values(array_filter($movement, fn (array $row) => $row['type_code'] === $typeCode));

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount'))];
    }

    private function period(int $periodId): object
    {
        return DB::table('fiscal_periods as fp')
            ->join('fiscal_years as fy', 'fy.id', '=', 'fp.fiscal_year_id')
            ->where('fp.id', $periodId)
            ->select([
                'fp.id', 'fp.period_no', 'fp.start_date', 'fp.end_date', 'fp.status',
                'fp.fiscal_year_id', 'fy.year_label',
            ])
            ->firstOrFail();
    }
}
