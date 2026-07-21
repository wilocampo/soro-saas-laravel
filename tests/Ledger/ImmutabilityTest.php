<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Exceptions\ImmutableEntry;
use App\Domain\Ledger\Models\JournalEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The DB safety net (docs/specs/01 §2.2) — MariaDB-only: sqlite has no
 * trigger/CHECK enforcement, which is exactly why the app is primary.
 */
class ImmutabilityTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresMariaDb();
    }

    private function postOne(): JournalEntry
    {
        return $this->posting()->post($this->draft([
            ['1000', 1_000, 0],
            ['4000', 0, 1_000],
        ]));
    }

    public function test_updating_a_posted_line_is_rejected_by_trigger(): void
    {
        $entry = $this->postOne();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('immutable');

        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->update(['debit_centavos' => 999]);
    }

    public function test_deleting_a_posted_line_is_rejected_by_trigger(): void
    {
        $entry = $this->postOne();

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->where('journal_entry_id', $entry->id)->delete();
    }

    public function test_deleting_a_posted_entry_is_rejected_by_trigger(): void
    {
        $entry = $this->postOne();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('reversal');

        DB::table('journal_entries')->where('id', $entry->id)->delete();
    }

    public function test_editing_frozen_header_columns_is_rejected_by_trigger(): void
    {
        $entry = $this->postOne();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('immutable');

        DB::table('journal_entries')->where('id', $entry->id)->update(['entry_date' => now()->subYear()->toDateString()]);
    }

    public function test_a_both_sides_line_is_rejected_by_the_check_constraint(): void
    {
        // Bypass the app entirely: raw insert violating (d>0) XOR (c>0).
        $headerId = DB::table('journal_entries')->insertGetId([
            'status' => 'draft',
            'entry_date' => now()->toDateString(),
            'fiscal_period_id' => DB::table('fiscal_periods')->where('period_no', 1)->value('id'),
            'created_by' => DB::table('users')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $headerId,
            'account_id' => $this->accountId('1000'),
            'line_no' => 1,
            'debit_centavos' => 100,
            'credit_centavos' => 100,
            'entry_date' => now()->toDateString(),
            'fiscal_period_id' => DB::table('fiscal_periods')->where('period_no', 1)->value('id'),
        ]);
    }

    public function test_flipping_an_unbalanced_draft_to_posted_is_rejected_by_t4(): void
    {
        $periodId = DB::table('fiscal_periods')->where('period_no', (int) now()->format('n'))->value('id');
        $headerId = DB::table('journal_entries')->insertGetId([
            'status' => 'draft',
            'entry_date' => now()->toDateString(),
            'fiscal_period_id' => $periodId,
            'created_by' => DB::table('users')->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('journal_lines')->insert([
            ['journal_entry_id' => $headerId, 'account_id' => $this->accountId('1000'), 'line_no' => 1, 'debit_centavos' => 500, 'credit_centavos' => 0, 'entry_date' => now()->toDateString(), 'fiscal_period_id' => $periodId],
            ['journal_entry_id' => $headerId, 'account_id' => $this->accountId('4000'), 'line_no' => 2, 'debit_centavos' => 0, 'credit_centavos' => 400, 'entry_date' => now()->toDateString(), 'fiscal_period_id' => $periodId],
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('balance');

        DB::table('journal_entries')->where('id', $headerId)->update([
            'status' => 'posted',
            'entry_number' => 'GJ-X-000099',
            'posted_by' => DB::table('users')->value('id'),
            'posted_at' => now(),
        ]);
    }

    public function test_adding_lines_to_a_posted_entry_is_rejected_by_t1(): void
    {
        $entry = $this->postOne();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('draft');

        DB::table('journal_lines')->insert([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->accountId('1000'),
            'line_no' => 99,
            'debit_centavos' => 100,
            'credit_centavos' => 0,
            'entry_date' => now()->toDateString(),
            'fiscal_period_id' => $entry->fiscal_period_id,
        ]);
    }

    public function test_audit_log_is_append_only(): void
    {
        $this->postOne();

        try {
            DB::table('audit_log')->limit(1)->update(['event' => 'tampered']);
            $this->fail('audit_log UPDATE was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            DB::table('audit_log')->limit(1)->delete();
            $this->fail('audit_log DELETE was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function test_eloquent_writes_to_the_journal_are_structurally_disabled(): void
    {
        $entry = $this->postOne();

        $this->expectException(ImmutableEntry::class);

        $model = JournalEntry::query()->findOrFail($entry->id);
        $model->description = 'sneaky edit';
        $model->save();
    }
}
