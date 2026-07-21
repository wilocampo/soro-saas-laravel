<?php

namespace App\Domain\Ledger;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * account_period_balances maintenance (docs/specs/01 §4). A rebuildable
 * CACHE, never truth — posted entries are append-only so aggregates only
 * ever grow; reversals append opposite-sign rows and self-correct.
 */
class BalanceCache
{
    /** Incremental upsert at post time (01 §4.1). */
    public function applyEntry(int $journalEntryId): void
    {
        $sums = DB::table('journal_lines')
            ->select('account_id', 'fiscal_period_id')
            ->selectRaw('SUM(debit_centavos) AS d, SUM(credit_centavos) AS c')
            ->where('journal_entry_id', $journalEntryId)
            ->groupBy('account_id', 'fiscal_period_id')
            ->get();

        foreach ($sums as $row) {
            $this->increment((int) $row->account_id, (int) $row->fiscal_period_id, (int) $row->d, (int) $row->c);
        }
    }

    private function increment(int $accountId, int $periodId, int $debits, int $credits): void
    {
        $updated = DB::table('account_period_balances')
            ->where('account_id', $accountId)
            ->where('fiscal_period_id', $periodId)
            ->update([
                'period_debits' => DB::raw('period_debits + '.$debits),
                'period_credits' => DB::raw('period_credits + '.$credits),
            ]);

        if ($updated === 0) {
            try {
                DB::table('account_period_balances')->insert([
                    'account_id' => $accountId,
                    'fiscal_period_id' => $periodId,
                    'period_debits' => $debits,
                    'period_credits' => $credits,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent insert won the race — fall back to increment.
                $this->increment($accountId, $periodId, $debits, $credits);
            }
        }
    }

    /**
     * Full rebuild (01 §4): truncate + one grouped scan of journal_lines
     * (posted + void entries — void pairs net to zero), then roll forward.
     * Proves the table is a cache, not truth.
     */
    public function rebuild(): void
    {
        DB::transaction(function (): void {
            DB::table('account_period_balances')->delete();

            DB::table('journal_lines as jl')
                ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
                ->whereIn('je.status', ['posted', 'void'])
                ->select('jl.account_id', 'jl.fiscal_period_id')
                ->selectRaw('SUM(jl.debit_centavos) AS d, SUM(jl.credit_centavos) AS c')
                ->groupBy('jl.account_id', 'jl.fiscal_period_id')
                ->orderBy('jl.account_id')
                ->chunk(500, function ($rows): void {
                    DB::table('account_period_balances')->insert(
                        $rows->map(fn ($r) => [
                            'account_id' => $r->account_id,
                            'fiscal_period_id' => $r->fiscal_period_id,
                            'period_debits' => $r->d,
                            'period_credits' => $r->c,
                            'rebuilt_at' => now(),
                        ])->all()
                    );
                });

            $this->rollForward();
        });
    }

    /**
     * Chain opening/closing per account across periods ordered by
     * (fiscal year start, period_no). Signed per the account's
     * normal_balance (already flipped for contra — 01 §1.2).
     */
    public function rollForward(): void
    {
        $periods = DB::table('fiscal_periods as fp')
            ->join('fiscal_years as fy', 'fy.id', '=', 'fp.fiscal_year_id')
            ->orderBy('fy.start_date')->orderBy('fp.period_no')
            ->get(['fp.id']);

        $accounts = DB::table('accounts')
            ->whereIn('id', fn ($q) => $q->select('account_id')->from('account_period_balances'))
            ->get(['id', 'normal_balance']);

        foreach ($accounts as $account) {
            $sign = $account->normal_balance === 'debit' ? 1 : -1;
            $running = 0;

            foreach ($periods as $period) {
                $row = DB::table('account_period_balances')
                    ->where('account_id', $account->id)
                    ->where('fiscal_period_id', $period->id)
                    ->first(['period_debits', 'period_credits']);

                $movement = $row === null
                    ? 0
                    : $sign * ((int) $row->period_debits - (int) $row->period_credits);

                $closing = $running + $movement;

                if ($row !== null || $running !== 0) {
                    DB::table('account_period_balances')->updateOrInsert(
                        ['account_id' => $account->id, 'fiscal_period_id' => $period->id],
                        ['opening_signed' => $running, 'closing_signed' => $closing],
                    );
                }

                $running = $closing;
            }
        }
    }
}
