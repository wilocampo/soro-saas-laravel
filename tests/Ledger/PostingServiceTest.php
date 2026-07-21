<?php

namespace Tests\Ledger;

use App\Domain\Ledger\Exceptions\AccountNotPostable;
use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Ledger\Exceptions\PeriodClosed;
use App\Domain\Ledger\Exceptions\PostingLocked;
use App\Domain\Ledger\Exceptions\UnbalancedEntry;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PostingServiceTest extends LedgerTestCase
{
    public function test_posts_a_balanced_entry_with_number_totals_hash_audit_and_balances(): void
    {
        $entry = $this->posting()->post($this->draft([
            ['1000', 1_120_000, 0],
            ['4000', 0, 1_000_000],
            ['2100', 0, 120_000],
        ]));

        $this->assertSame('posted', $entry->status);
        $year = now()->year;
        $this->assertSame("GJ-{$year}-000001", $entry->entry_number);
        $this->assertSame(1_120_000, $entry->total_debit_centavos);
        $this->assertSame(1_120_000, $entry->total_credit_centavos);
        $this->assertNotNull($entry->posting_hash);
        $this->assertNotNull($entry->posted_at);

        // Lines denormalized with header date/period
        $this->assertSame(3, DB::table('journal_lines')->where('journal_entry_id', $entry->id)->count());

        // Audit row in the same transaction, hash-chained
        $audit = DB::table('audit_log')->where('event', 'entry.posted')->first();
        $this->assertNotNull($audit);
        $this->assertSame($entry->entry_number, $audit->document_number);

        // Balance cache upserted
        $cash = DB::table('account_period_balances')->where('account_id', $this->accountId('1000'))->first();
        $this->assertSame(1_120_000, (int) $cash->period_debits);

        $this->assertTrialBalanceZero();
    }

    public function test_unbalanced_draft_is_rejected(): void
    {
        $this->expectException(UnbalancedEntry::class);

        $this->posting()->post($this->draft([
            ['1000', 1_000_000, 0],
            ['4000', 0, 999_999],
        ]));
    }

    public function test_single_line_and_single_sided_drafts_are_rejected(): void
    {
        try {
            $this->posting()->post($this->draft([['1000', 100, 0]]));
            $this->fail('Single-line draft was accepted.');
        } catch (InvalidDraft) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->posting()->post($this->draft([
                ['1000', 100, 0],
                ['1010', 100, 0],
            ]));
            $this->fail('Debit-only draft was accepted.');
        } catch (InvalidDraft) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_line_with_both_or_neither_side_is_rejected(): void
    {
        try {
            $this->posting()->post($this->draft([
                ['1000', 100, 100],
                ['4000', 0, 100],
            ]));
            $this->fail('Both-sides line was accepted.');
        } catch (InvalidDraft) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->posting()->post($this->draft([
                ['1000', 0, 0],
                ['4000', 0, 0],
            ]));
            $this->fail('Zero-zero lines were accepted.');
        } catch (InvalidDraft) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_inactive_or_rollup_accounts_are_rejected(): void
    {
        DB::table('accounts')->where('code', '1010')->update(['is_active' => false]);

        $this->expectException(AccountNotPostable::class);

        $this->posting()->post($this->draft([
            ['1010', 100, 0],
            ['4000', 0, 100],
        ]));
    }

    public function test_posting_into_a_closed_period_is_rejected(): void
    {
        DB::table('fiscal_periods')->where('period_no', (int) now()->format('n'))->update(['status' => 'closed']);

        $this->expectException(PeriodClosed::class);

        $this->posting()->post($this->draft([
            ['1000', 100, 0],
            ['4000', 0, 100],
        ]));
    }

    public function test_posting_on_or_before_the_lock_date_is_rejected(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['posting_lock_date' => now()->toDateString()]);

        $this->expectException(PostingLocked::class);

        $this->posting()->post($this->draft([
            ['1000', 100, 0],
            ['4000', 0, 100],
        ]));
    }

    public function test_adjustment_period_13_bypasses_the_lock_date_via_explicit_routing(): void
    {
        DB::table('ledger_settings')->where('id', 1)->update(['posting_lock_date' => now()->endOfYear()->toDateString()]);
        $period13 = DB::table('fiscal_periods')->where('period_no', 13)->value('id');

        $entry = $this->posting()->post($this->draft(
            [
                ['5000', 500, 0],
                ['1000', 0, 500],
            ],
            [
                'journalBook' => 'year_end_close',
                'entryDate' => CarbonImmutable::now()->endOfYear(),
                'fiscalPeriodId' => (int) $period13,
            ],
        ));

        $this->assertSame('posted', $entry->status);
        $this->assertSame((int) $period13, (int) $entry->fiscal_period_id);
        $this->assertStringStartsWith('YEC-', $entry->entry_number);
    }

    public function test_idempotency_same_key_posts_exactly_once(): void
    {
        $draft = $this->draft([
            ['1000', 700, 0],
            ['4000', 0, 700],
        ], ['idempotencyKey' => 'doc:42:post:1']);

        $first = $this->posting()->post($draft);
        $second = $this->posting()->post($draft);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('journal_entries')->where('idempotency_key', 'doc:42:post:1')->count());
    }

    public function test_empty_draft_returns_null(): void
    {
        $result = $this->posting()->post(new JournalDraft(
            journalBook: 'sales',
            entryDate: CarbonImmutable::now(),
            memo: 'cash-basis no-op',
            source: SourceRef::none(),
            idempotencyKey: 'noop:1',
            lines: [],
        ));

        $this->assertNull($result);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }
}
