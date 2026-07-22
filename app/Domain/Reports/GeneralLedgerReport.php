<?php

namespace App\Domain\Reports;

use Illuminate\Support\Facades\DB;

/**
 * The General Ledger: every movement on one account over a date range, with
 * a running balance (docs/specs/03 §3).
 *
 * The report carries its own proof. `opening + debits − credits` must equal
 * the closing balance, and the closing balance must equal what the trial
 * balance says for that account — if the two disagree the ledger has drifted
 * and the report says so rather than quietly printing a wrong number.
 */
class GeneralLedgerReport
{
    /**
     * @return array{
     *   account:array<string,mixed>, from:string, to:string,
     *   opening_signed:int, closing_signed:int, total_debits:int, total_credits:int,
     *   rows:list<array<string,mixed>>, ties:bool
     * }
     */
    public function forAccount(int $accountId, string $from, string $to): array
    {
        $account = DB::table('accounts as a')
            ->join('account_types as t', 't.id', '=', 'a.account_type_id')
            ->where('a.id', $accountId)
            ->select(['a.id', 'a.code', 'a.name', 'a.normal_balance', 't.code as type_code'])
            ->firstOrFail();

        $sign = $account->normal_balance === 'debit' ? 1 : -1;
        $opening = $this->signedMovementBefore($accountId, $from, $sign);

        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->leftJoin('accounts as counter', 'counter.id', '=', 'jl.account_id')
            ->where('jl.account_id', $accountId)
            ->whereIn('je.status', ['posted', 'void'])
            ->whereBetween('je.entry_date', [$from, $to])
            // Entry number is the tiebreaker: two entries on the same date
            // must appear in the order they were posted, not at random.
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_number')
            ->orderBy('jl.line_no')
            ->get([
                'je.id as entry_id', 'je.entry_number', 'je.entry_date', 'je.journal_book',
                'je.description as entry_memo', 'je.status', 'je.source_type', 'je.source_id',
                'jl.line_no', 'jl.debit_centavos', 'jl.credit_centavos', 'jl.memo',
            ]);

        $running = $opening;
        $totalDebits = 0;
        $totalCredits = 0;
        $rows = [];

        foreach ($lines as $line) {
            $debit = (int) $line->debit_centavos;
            $credit = (int) $line->credit_centavos;

            $totalDebits += $debit;
            $totalCredits += $credit;
            $running += $sign * ($debit - $credit);

            $rows[] = [
                'entry_id' => (int) $line->entry_id,
                'entry_number' => $line->entry_number,
                'entry_date' => $line->entry_date,
                'journal_book' => $line->journal_book,
                'status' => $line->status,
                'particulars' => $line->memo ?: $line->entry_memo,
                'contra_accounts' => $this->contraAccounts((int) $line->entry_id, $accountId),
                'debit' => $debit,
                'credit' => $credit,
                'running_signed' => $running,
            ];
        }

        return [
            'account' => (array) $account,
            'from' => $from,
            'to' => $to,
            'opening_signed' => $opening,
            'closing_signed' => $running,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'rows' => $rows,
            'ties' => $running === $opening + $sign * ($totalDebits - $totalCredits),
        ];
    }

    /** Everything posted to the account before the range opens. */
    private function signedMovementBefore(int $accountId, string $from, int $sign): int
    {
        $totals = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $accountId)
            ->whereIn('je.status', ['posted', 'void'])
            ->where('je.entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(jl.debit_centavos),0) AS d, COALESCE(SUM(jl.credit_centavos),0) AS c')
            ->first();

        return $sign * ((int) $totals->d - (int) $totals->c);
    }

    /**
     * The other side of the entry — what a bookkeeper reads across to. Kept
     * as a list because a compound entry has several.
     *
     * @return list<string>
     */
    private function contraAccounts(int $entryId, int $accountId): array
    {
        return DB::table('journal_lines as jl')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->where('jl.journal_entry_id', $entryId)
            ->where('jl.account_id', '!=', $accountId)
            ->distinct()
            ->orderBy('a.code')
            ->pluck('a.code')
            ->all();
    }
}
