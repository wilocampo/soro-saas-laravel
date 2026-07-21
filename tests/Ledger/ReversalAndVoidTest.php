<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Exceptions\ReversalNotAllowed;
use App\Domain\Ledger\Exceptions\VoidNotAllowed;
use Illuminate\Support\Facades\DB;

class ReversalAndVoidTest extends LedgerTestCase
{
    public function test_reversal_nets_to_zero_on_every_account_and_links_both_entries(): void
    {
        $original = $this->posting()->post($this->draft([
            ['1100', 1_120_000, 0],
            ['4000', 0, 1_000_000],
            ['2100', 0, 120_000],
        ], ['journalBook' => 'sales']));

        $mirror = $this->posting()->reverse($original, 'wrong customer');

        $original->refresh();
        $this->assertSame('posted', $original->status, 'The original STAYS posted — reversed is a linkage, not a status.');
        $this->assertSame($mirror->id, $original->reversed_by_entry_id);
        $this->assertSame($original->id, $mirror->reverses_entry_id);
        $this->assertSame('reversal', $mirror->journal_book);

        // Net zero per account across the pair
        $net = DB::table('journal_lines')
            ->whereIn('journal_entry_id', [$original->id, $mirror->id])
            ->selectRaw('account_id, SUM(debit_centavos) - SUM(credit_centavos) AS net')
            ->groupBy('account_id')
            ->pluck('net');

        foreach ($net as $balance) {
            $this->assertSame(0, (int) $balance);
        }

        $this->assertTrialBalanceZero();
    }

    public function test_a_reversal_cannot_be_reversed_and_an_entry_reverses_only_once(): void
    {
        $original = $this->posting()->post($this->draft([
            ['1000', 400, 0],
            ['4000', 0, 400],
        ]));
        $mirror = $this->posting()->reverse($original, 'oops');

        try {
            $this->posting()->reverse($mirror, 'reverse the reversal');
            $this->fail('Reversal of a reversal was accepted.');
        } catch (ReversalNotAllowed) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->posting()->reverse($original->refresh(), 'again');
            $this->fail('Second reversal of the same entry was accepted.');
        } catch (ReversalNotAllowed) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_void_requires_an_open_period(): void
    {
        $original = $this->posting()->post($this->draft([
            ['1000', 800, 0],
            ['4000', 0, 800],
        ]));

        DB::table('fiscal_periods')->where('id', $original->fiscal_period_id)->update(['status' => 'closed']);

        $this->expectException(VoidNotAllowed::class);

        $this->posting()->void($original, 'too late — must reverse instead');
    }

    public function test_void_is_a_same_period_mirror_and_tags_both_void(): void
    {
        $original = $this->posting()->post($this->draft([
            ['1000', 650, 0],
            ['4000', 0, 650],
        ]));

        $mirror = $this->posting()->void($original, 'duplicate encoding');

        $original->refresh();
        $this->assertSame('void', $original->status);
        $this->assertSame('void', $mirror->status);
        $this->assertSame((int) $original->fiscal_period_id, (int) $mirror->fiscal_period_id);
        $this->assertSame($original->entry_date->toDateString(), $mirror->entry_date->toDateString());
        $this->assertNotNull($original->voided_at);

        $this->assertTrialBalanceZero();
    }

    public function test_reversal_into_the_open_period_when_the_original_period_closed(): void
    {
        $original = $this->posting()->post($this->draft([
            ['1000', 550, 0],
            ['4000', 0, 550],
        ]));

        DB::table('fiscal_periods')->where('id', $original->fiscal_period_id)->update(['status' => 'closed']);
        // Reopen a later period so the dating policy has somewhere to land.
        DB::table('fiscal_periods')->where('period_no', '<=', 12)
            ->where('id', '<>', $original->fiscal_period_id)
            ->update(['status' => 'open']);

        $mirror = $this->posting()->reverse($original->refresh(), 'found after close');

        $this->assertSame('posted', $mirror->status);
        $this->assertNotSame((int) $original->fiscal_period_id, (int) $mirror->fiscal_period_id);
        $this->assertTrialBalanceZero();
    }
}
