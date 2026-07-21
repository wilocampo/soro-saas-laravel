<?php

namespace App\Domain\Ledger;

use Illuminate\Support\Facades\DB;

/**
 * Trial balance from the derived cache (docs/specs/01 §4). Closed periods
 * read `closing_signed` (O(accounts)); an open period reads its opening
 * balance plus a scan bounded to that one period. Always tie-checked: a
 * trial balance that does not net to zero is a bug, not a report.
 */
class TrialBalanceService
{
    /**
     * @return array{
     *   rows: list<array{account_id:int, code:string, name:string, normal_balance:string, debit:int, credit:int, signed:int}>,
     *   total_debit:int, total_credit:int, balanced:bool
     * }
     */
    public function asOfPeriod(int $periodId): array
    {
        $period = DB::table('fiscal_periods')->where('id', $periodId)->firstOrFail();

        $accounts = DB::table('accounts')->orderBy('code')
            ->get(['id', 'code', 'name', 'normal_balance']);

        $rows = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $signed = $this->signedBalance((int) $account->id, $period);

            if ($signed === 0) {
                continue;
            }

            // Present on the account's normal side; the opposite side means
            // a negative balance, which is legitimate and shown as such.
            $isDebitNormal = $account->normal_balance === 'debit';
            $debit = 0;
            $credit = 0;
            if (($isDebitNormal && $signed > 0) || (! $isDebitNormal && $signed < 0)) {
                $debit = abs($signed);
            } else {
                $credit = abs($signed);
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            $rows[] = [
                'account_id' => (int) $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'normal_balance' => $account->normal_balance,
                'debit' => $debit,
                'credit' => $credit,
                'signed' => $signed,
            ];
        }

        return [
            'rows' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => $totalDebit === $totalCredit,
        ];
    }

    /** Signed centavos on the account's normal side, as of the period end. */
    private function signedBalance(int $accountId, object $period): int
    {
        $cached = DB::table('account_period_balances')
            ->where('account_id', $accountId)
            ->where('fiscal_period_id', $period->id)
            ->first();

        if ($period->status !== 'open' && $cached !== null) {
            return (int) $cached->closing_signed;   // closed → the cache is final
        }

        // Open period: opening + this period's live movement (bounded scan).
        $sign = DB::table('accounts')->where('id', $accountId)->value('normal_balance') === 'debit' ? 1 : -1;

        $movement = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->where('jl.account_id', $accountId)
            ->where('jl.fiscal_period_id', $period->id)
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) AS d, COALESCE(SUM(jl.credit_centavos),0) AS c')
            ->first();

        $opening = $cached === null ? $this->openingFromPriorPeriods($accountId, $period) : (int) $cached->opening_signed;

        return $opening + $sign * ((int) $movement->d - (int) $movement->c);
    }

    /** No cache row yet (nothing posted this period): carry the prior close. */
    private function openingFromPriorPeriods(int $accountId, object $period): int
    {
        $prior = DB::table('account_period_balances as apb')
            ->join('fiscal_periods as fp', 'fp.id', '=', 'apb.fiscal_period_id')
            ->join('fiscal_years as fy', 'fy.id', '=', 'fp.fiscal_year_id')
            ->where('apb.account_id', $accountId)
            ->where('fp.end_date', '<', $period->end_date)
            ->orderByDesc('fp.end_date')->orderByDesc('fp.period_no')
            ->value('apb.closing_signed');

        return (int) ($prior ?? 0);
    }
}
