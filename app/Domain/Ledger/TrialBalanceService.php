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
     *   rows: list<array{account_id:int, code:string, name:string, normal_balance:string,
     *                    type_code:string, statement:string, debit:int, credit:int, signed:int}>,
     *   total_debit:int, total_credit:int, balanced:bool
     * }
     */
    public function asOfPeriod(int $periodId): array
    {
        $period = DB::table('fiscal_periods')->where('id', $periodId)->firstOrFail();

        // The type carries the statement each account belongs on, so the
        // financial statements are a partition of THIS list — which is what
        // makes them tie to the trial balance by construction (spec 05).
        $accounts = DB::table('accounts as a')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->orderBy('a.code')
            ->get(['a.id', 'a.code', 'a.name', 'a.normal_balance', 't.code as type_code', 't.statement']);

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
                'type_code' => $account->type_code,
                'statement' => $account->statement,
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

        $sign = DB::table('accounts')->where('id', $accountId)->value('normal_balance') === 'debit' ? 1 : -1;

        // `opening_signed` is only authoritative once the PRECEDING period
        // has been rolled forward, which happens at close. On a row created
        // incrementally by a posting it is still zero — so trusting it would
        // silently drop everything posted in earlier periods that are also
        // still open. Take the last CLOSED period as the base instead, and
        // scan the open tail after it.
        [$base, $periodIds] = $this->baseAndOpenTail($accountId, $period);

        if ($periodIds === []) {
            return $base;
        }

        $movement = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->where('jl.account_id', $accountId)
            ->whereIn('jl.fiscal_period_id', $periodIds)
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) AS d, COALESCE(SUM(jl.credit_centavos),0) AS c')
            ->first();

        return $base + $sign * ((int) $movement->d - (int) $movement->c);
    }

    /**
     * The closing balance of the newest closed period at or before $period,
     * plus the ids of every period after it up to and including $period —
     * i.e. the still-open tail whose movement has to be read live.
     *
     * @return array{0:int, 1:list<int>}
     */
    private function baseAndOpenTail(int $accountId, object $period): array
    {
        // Chronological order; period 13 shares period 12's end date, so
        // period_no breaks the tie the same way it does everywhere else.
        $periods = DB::table('fiscal_periods')
            ->orderBy('end_date')->orderBy('period_no')
            ->get(['id', 'status']);

        $upTo = [];
        foreach ($periods as $candidate) {
            $upTo[] = $candidate;
            if ((int) $candidate->id === (int) $period->id) {
                break;
            }
        }

        $cached = DB::table('account_period_balances')
            ->where('account_id', $accountId)
            ->pluck('closing_signed', 'fiscal_period_id');

        $base = 0;
        $tail = [];

        // Walk backwards to the newest closed period that has a cache row:
        // everything from there forward is read live.
        for ($index = count($upTo) - 1; $index >= 0; $index--) {
            $candidate = $upTo[$index];

            if ($candidate->status !== 'open'
                && (int) $candidate->id !== (int) $period->id
                && $cached->has($candidate->id)) {
                $base = (int) $cached->get($candidate->id);
                break;
            }

            $tail[] = (int) $candidate->id;
        }

        return [$base, array_reverse($tail)];
    }
}
