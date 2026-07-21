<?php

namespace Tests\Ledger;

use App\Domain\Ledger\BalanceCache;
use App\Domain\Ledger\Exceptions\UnbalancedEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\LedgerGenerator;

/**
 * The generative invariants (docs/specs/05 items 1–4, 6): any random set of
 * valid postings keeps the trial balance at zero and the cache honest;
 * random invalid postings are rejected. Fixed seed for reproducibility.
 */
class GenerativeLedgerTest extends LedgerTestCase
{
    private function generator(): LedgerGenerator
    {
        $accountIds = DB::table('accounts')
            ->where('is_postable', true)->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return new LedgerGenerator(
            accountIds: $accountIds,
            from: CarbonImmutable::now()->startOfYear(),
            to: CarbonImmutable::now()->endOfMonth(),
            seed: 4242,
        );
    }

    public function test_random_valid_postings_keep_the_trial_balance_at_zero(): void
    {
        $generator = $this->generator();

        for ($i = 1; $i <= 25; $i++) {
            $entry = $this->posting()->post($generator->validDraft($i));
            $this->assertSame('posted', $entry->status);
        }

        $this->assertTrialBalanceZero();

        // Per-account: cache movement equals a fresh recompute of its lines.
        $fresh = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.status', 'posted')
            ->selectRaw('jl.account_id, jl.fiscal_period_id, SUM(jl.debit_centavos) d, SUM(jl.credit_centavos) c')
            ->groupBy('jl.account_id', 'jl.fiscal_period_id')
            ->get();

        foreach ($fresh as $row) {
            $cache = DB::table('account_period_balances')
                ->where('account_id', $row->account_id)
                ->where('fiscal_period_id', $row->fiscal_period_id)
                ->first();
            $this->assertNotNull($cache);
            $this->assertSame((int) $row->d, (int) $cache->period_debits);
            $this->assertSame((int) $row->c, (int) $cache->period_credits);
        }
    }

    public function test_rebuild_reproduces_the_incrementally_maintained_cache(): void
    {
        $generator = $this->generator();
        for ($i = 1; $i <= 15; $i++) {
            $this->posting()->post($generator->validDraft($i));
        }

        $movement = fn () => DB::table('account_period_balances')
            ->where(fn ($q) => $q->where('period_debits', '>', 0)->orWhere('period_credits', '>', 0))
            ->orderBy('account_id')->orderBy('fiscal_period_id')
            ->get(['account_id', 'fiscal_period_id', 'period_debits', 'period_credits'])
            ->map(fn ($r) => (array) $r)->all();

        $incremental = $movement();

        app(BalanceCache::class)->rebuild();

        // Roll-forward adds opening/closing carry rows with zero movement —
        // compare the movement rows only.
        $rebuilt = $movement();

        $this->assertSame(
            array_map(fn ($r) => array_map('intval', $r), $incremental),
            array_map(fn ($r) => array_map('intval', $r), $rebuilt),
            'ledger:rebuild-balances must reproduce the incrementally maintained cache exactly.'
        );
    }

    public function test_random_unbalanced_postings_are_always_rejected(): void
    {
        $generator = $this->generator();
        $rejected = 0;

        for ($i = 1; $i <= 10; $i++) {
            try {
                $this->posting()->post($generator->unbalancedDraft($i));
            } catch (UnbalancedEntry) {
                $rejected++;
            }
        }

        $this->assertSame(10, $rejected);
        $this->assertSame(0, DB::table('journal_entries')->count(), 'No unbalanced draft may leave residue.');
    }

    public function test_random_reversals_net_to_zero(): void
    {
        $generator = $this->generator();
        $entries = [];
        for ($i = 1; $i <= 8; $i++) {
            $entries[] = $this->posting()->post($generator->validDraft($i));
        }

        foreach ($entries as $entry) {
            $this->posting()->reverse($entry, 'generative reversal');
        }

        // Every account nets to exactly zero across the whole ledger.
        $nets = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('je.status', ['posted', 'void'])
            ->selectRaw('jl.account_id, SUM(jl.debit_centavos) - SUM(jl.credit_centavos) AS net')
            ->groupBy('jl.account_id')
            ->pluck('net');

        foreach ($nets as $net) {
            $this->assertSame(0, (int) $net);
        }
    }
}
