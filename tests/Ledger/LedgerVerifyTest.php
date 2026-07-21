<?php

namespace Tests\Ledger;

use Illuminate\Support\Facades\DB;

/**
 * ledger:verify must pass clean on honest data and CATCH tampering.
 * The tampering cases run on sqlite (no triggers to stop the raw writes) —
 * proving detection; MariaDB proves prevention (ImmutabilityTest).
 */
class LedgerVerifyTest extends LedgerTestCase
{
    private function postSome(int $count = 3): array
    {
        $entries = [];
        for ($i = 1; $i <= $count; $i++) {
            $entries[] = $this->posting()->post($this->draft([
                ['1000', 1_000 * $i, 0],
                ['4000', 0, 1_000 * $i],
            ]));
        }

        return $entries;
    }

    public function test_verify_is_clean_on_honestly_posted_data(): void
    {
        $this->postSome();
        $this->posting()->reverse($this->postSome(1)[0], 'and a reversal');

        $this->artisan('ledger:verify')->assertSuccessful();
    }

    public function test_verify_catches_an_altered_line(): void
    {
        if (! $this->inMemorySqlite()) {
            $this->markTestSkipped('Tamper-by-raw-SQL only works where triggers cannot block it (sqlite).');
        }

        [$entry] = $this->postSome(1);

        DB::table('journal_lines')->where('journal_entry_id', $entry->id)
            ->where('debit_centavos', '>', 0)
            ->update(['debit_centavos' => 999_999]);

        $this->artisan('ledger:verify')->assertFailed();
    }

    public function test_verify_catches_an_audit_chain_break(): void
    {
        if (! $this->inMemorySqlite()) {
            $this->markTestSkipped('Tamper-by-raw-SQL only works where triggers cannot block it (sqlite).');
        }

        $this->postSome(2);

        DB::table('audit_log')->orderBy('id')->limit(1)->update(['event' => 'entry.laundered']);

        $this->artisan('ledger:verify')->assertFailed();
    }

    public function test_rebuild_command_repairs_a_drifted_cache(): void
    {
        $this->postSome(2);

        // Simulate cache drift (the cache is not truth).
        DB::table('account_period_balances')->limit(1)->update(['period_debits' => 123]);
        $this->artisan('ledger:verify')->assertFailed();

        $this->artisan('ledger:rebuild-balances')->assertSuccessful();
        $this->artisan('ledger:verify')->assertSuccessful();
    }
}
