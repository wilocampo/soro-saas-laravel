<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Exceptions\InvalidDraft;
use Illuminate\Support\Facades\DB;
use Throwable;

class GaplessNumberingTest extends LedgerTestCase
{
    public function test_numbers_are_sequential_per_book_and_embed_series_and_year(): void
    {
        $year = now()->year;

        for ($i = 1; $i <= 3; $i++) {
            $entry = $this->posting()->post($this->draft([
                ['1000', 100 * $i, 0],
                ['4000', 0, 100 * $i],
            ]));
            $this->assertSame(sprintf('GJ-%d-%06d', $year, $i), $entry->entry_number);
        }

        // A different book draws from its own series.
        $sales = $this->posting()->post($this->draft([
            ['1100', 500, 0],
            ['4000', 0, 500],
        ], ['journalBook' => 'sales']));
        $this->assertSame(sprintf('SJ-%d-%06d', $year, 1), $sales->entry_number);
    }

    public function test_a_rolled_back_post_consumes_no_number(): void
    {
        $first = $this->posting()->post($this->draft([
            ['1000', 100, 0],
            ['4000', 0, 100],
        ]));

        // Simulate a failure AFTER a post inside a broader transaction that
        // rolls back — the sequence increment must roll back with it.
        try {
            DB::transaction(function (): void {
                $this->posting()->post($this->draft([
                    ['1000', 200, 0],
                    ['4000', 0, 200],
                ]));
                throw new \RuntimeException('boom — roll it all back');
            });
        } catch (Throwable) {
        }

        $next = $this->posting()->post($this->draft([
            ['1000', 300, 0],
            ['4000', 0, 300],
        ]));

        // No gap: the rolled-back number was reused.
        $numbers = [$first->entry_number, $next->entry_number];
        $year = now()->year;
        $this->assertSame([sprintf('GJ-%d-000001', $year), sprintf('GJ-%d-000002', $year)], $numbers);
        $this->assertSame(2, (int) DB::table('document_sequences')->where('document_type', 'GJ')->value('last_value'));
    }

    public function test_an_empty_sequence_prefix_is_rejected(): void
    {
        DB::table('document_sequences')->where('document_type', 'GJ')->update(['prefix' => '']);

        $this->expectException(InvalidDraft::class);

        $this->posting()->post($this->draft([
            ['1000', 100, 0],
            ['4000', 0, 100],
        ]));
    }

    public function test_void_burns_and_keeps_the_number_and_the_mirror_draws_its_own(): void
    {
        $original = $this->posting()->post($this->draft([
            ['1000', 900, 0],
            ['4000', 0, 900],
        ]));

        $mirror = $this->posting()->void($original, 'entered twice');

        $original->refresh();
        $this->assertSame('void', $original->status);
        $this->assertNotNull($original->entry_number, 'Voided documents keep their burned number (BIR).');
        $this->assertStringStartsWith('REV-', $mirror->entry_number);
    }
}
