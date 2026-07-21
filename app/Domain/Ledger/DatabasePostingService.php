<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Exceptions\AccountNotPostable;
use App\Domain\Ledger\Exceptions\InvalidDraft;
use App\Domain\Ledger\Exceptions\PeriodClosed;
use App\Domain\Ledger\Exceptions\PostingLocked;
use App\Domain\Ledger\Exceptions\ReversalNotAllowed;
use App\Domain\Ledger\Exceptions\UnbalancedEntry;
use App\Domain\Ledger\Exceptions\VoidNotAllowed;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Posting\JournalDraft;
use App\Domain\Ledger\Posting\JournalLineDraft;
use App\Domain\Ledger\Posting\ReversalOf;
use App\Domain\Ledger\Posting\SourceRef;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The posting engine (docs/specs/01 §2.1, 02 §1). App layer is the PRIMARY
 * enforcer; the T1–T5 triggers and CHECK constraints are the DB safety net.
 * Lock order everywhere: accounts (FOR SHARE) → period (FOR SHARE) →
 * sequence (FOR UPDATE) — fixed to prevent deadlocks (02 §6).
 */
final class DatabasePostingService implements PostingService
{
    /** journal_book → document series (01 §3). */
    private const BOOK_SERIES = [
        'general' => 'GJ',
        'sales' => 'SJ',
        'purchase' => 'PJ',
        'cash_receipts' => 'CRJ',
        'cash_disbursements' => 'CDJ',
        'opening_balance' => 'OB',
        'year_end_close' => 'YEC',
        'reversal' => 'REV',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BalanceCache $balances,
    ) {}

    public function post(JournalDraft $draft): ?JournalEntry
    {
        if ($draft->isEmpty()) {
            return null; // cash-basis no-op documents
        }

        $this->assertStructure($draft);

        return DB::transaction(function () use ($draft): JournalEntry {
            $actorId = $this->resolveActorId();
            $periodId = $draft->fiscalPeriodId ?? $this->periodIdForDate($draft->entryDate);

            // B. idempotency CLAIM first — a duplicate fails here, before any
            // sequence work, so it never consumes a number (02 §1).
            try {
                $headerId = DB::table('journal_entries')->insertGetId([
                    'status' => 'draft',
                    'entry_date' => $draft->entryDate->toDateString(),
                    'fiscal_period_id' => $periodId,
                    'journal_book' => $draft->journalBook,
                    'description' => $draft->memo,
                    'source_type' => $draft->source->type,
                    'source_id' => $draft->source->id,
                    'idempotency_key' => $draft->idempotencyKey,
                    'reverses_entry_id' => $draft->reverses?->journalEntryId,
                    'reversal_reason' => $draft->reverses?->reason,
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Locking read: the winner committed before our insert failed.
                return JournalEntry::query()
                    ->where('idempotency_key', $draft->idempotencyKey)
                    ->lock('lock in share mode')
                    ->firstOrFail();
            }

            // C. account + period validity (lock order: accounts → period).
            $this->assertAccountsPostable($draft);
            $period = $this->assertPeriodOpen($periodId, $draft->entryDate);

            // D. materialize lines FIRST so the sequence lock is held minimally.
            $lineNo = 0;
            foreach ($draft->lines as $line) {
                $lineNo++;
                DB::table('journal_lines')->insert([
                    'journal_entry_id' => $headerId,
                    'account_id' => $line->accountId,
                    'line_no' => $lineNo,
                    'debit_centavos' => $line->debitCentavos,
                    'credit_centavos' => $line->creditCentavos,
                    'memo' => $line->memo,
                    'entry_date' => $draft->entryDate->toDateString(),
                    'fiscal_period_id' => $periodId,
                    'partner_type' => $line->party?->type,
                    'partner_id' => $line->party?->id,
                    'tax_code_id' => $line->taxCodeId,
                    'tax_base_centavos' => $line->taxBaseCentavos,
                    'atc_code' => $line->atcCode,
                    'source_line_ref' => $line->sourceLineRef,
                ]);
            }

            // E. gapless number LAST (FOR UPDATE held only across the flip).
            $entryNumber = $this->drawNumber($draft->journalBook, (int) $period->fiscal_year_id);

            DB::table('journal_entries')->where('id', $headerId)->where('status', 'draft')->update([
                'status' => 'posted',
                'entry_number' => $entryNumber,
                'posted_by' => $actorId,
                'posted_at' => now(),
                'total_debit_centavos' => $draft->totalDebit(),
                'total_credit_centavos' => $draft->totalCredit(),
                'posting_hash' => $this->postingHash($headerId),
                'updated_at' => now(),
            ]); // fires T4 (balance + period re-check) on MariaDB

            $this->balances->applyEntry($headerId);

            $this->audit->record(
                event: 'entry.posted',
                auditableType: 'journal_entry',
                auditableId: $headerId,
                documentNumber: $entryNumber,
                after: [
                    'entry_number' => $entryNumber,
                    'entry_date' => $draft->entryDate->toDateString(),
                    'journal_book' => $draft->journalBook,
                    'total_debit_centavos' => $draft->totalDebit(),
                    'total_credit_centavos' => $draft->totalCredit(),
                    'lines' => count($draft->lines),
                ],
                actorId: $actorId,
                actorName: $this->actorName($actorId),
            );

            return JournalEntry::query()->findOrFail($headerId);
        });
    }

    public function reverse(JournalEntry $original, string $reason, ?CarbonImmutable $date = null): JournalEntry
    {
        if ($original->status !== 'posted') {
            throw new ReversalNotAllowed("Only posted entries can be reversed (entry is {$original->status}).");
        }
        if ($original->reverses_entry_id !== null) {
            throw new ReversalNotAllowed('A reversal cannot itself be reversed.');
        }
        if ($original->reversed_by_entry_id !== null) {
            throw new ReversalNotAllowed('This entry has already been reversed.');
        }

        $entryDate = $date ?? $this->reversalDate($original);

        return DB::transaction(function () use ($original, $reason, $entryDate): JournalEntry {
            $mirror = $this->post(new JournalDraft(
                journalBook: 'reversal',
                entryDate: $entryDate,
                memo: "Reversal of {$original->entry_number}: {$reason}",
                source: new SourceRef($original->source_type, $original->source_id),
                idempotencyKey: "journal_entry:{$original->id}:reverse:1",
                lines: $this->mirrorLines($original),
                reverses: new ReversalOf($original->id, $reason),
            ));

            // T4 explicitly permits exactly this update (01 §2.2): the
            // original stays posted; "reversed" = reversed_by_entry_id set.
            DB::table('journal_entries')->where('id', $original->id)->update([
                'reversed_by_entry_id' => $mirror->id,
                'reversal_reason' => $reason,
                'updated_at' => now(),
            ]);

            $this->audit->record(
                event: 'entry.reversed',
                auditableType: 'journal_entry',
                auditableId: $original->id,
                documentNumber: $original->entry_number,
                after: ['reversed_by' => $mirror->entry_number, 'reason' => $reason],
                actorId: $this->resolveActorId(),
                actorName: $this->actorName($this->resolveActorId()),
            );

            return $mirror;
        });
    }

    public function void(JournalEntry $original, string $reason): JournalEntry
    {
        if ($original->status !== 'posted') {
            throw new VoidNotAllowed("Only posted entries can be voided (entry is {$original->status}).");
        }
        if ($original->reverses_entry_id !== null) {
            throw new VoidNotAllowed('Reversal entries cannot be voided.');
        }
        if ($original->reversed_by_entry_id !== null) {
            throw new VoidNotAllowed('This entry has already been reversed.');
        }

        $periodStatus = DB::table('fiscal_periods')->where('id', $original->fiscal_period_id)->value('status');
        if ($periodStatus !== 'open') {
            throw new VoidNotAllowed('The period is closed — use reverse() into the current period instead.');
        }

        $actorId = $this->resolveActorId();

        return DB::transaction(function () use ($original, $reason, $actorId): JournalEntry {
            $mirror = $this->post(new JournalDraft(
                journalBook: 'reversal',
                entryDate: CarbonImmutable::parse($original->entry_date),
                memo: "Void of {$original->entry_number}: {$reason}",
                source: new SourceRef($original->source_type, $original->source_id),
                idempotencyKey: "journal_entry:{$original->id}:void:1",
                lines: $this->mirrorLines($original),
                reverses: new ReversalOf($original->id, $reason),
                fiscalPeriodId: (int) $original->fiscal_period_id, // same-period by definition
            ));

            // Void tags BOTH entries; the burned numbers are retained (BIR).
            DB::table('journal_entries')->where('id', $original->id)->update([
                'status' => 'void',
                'voided_by' => $actorId,
                'voided_at' => now(),
                'reversed_by_entry_id' => $mirror->id,
                'reversal_reason' => $reason,
                'updated_at' => now(),
            ]);
            DB::table('journal_entries')->where('id', $mirror->id)->update([
                'status' => 'void',
                'voided_by' => $actorId,
                'voided_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                event: 'entry.voided',
                auditableType: 'journal_entry',
                auditableId: $original->id,
                documentNumber: $original->entry_number,
                after: ['voided_via' => $mirror->entry_number, 'reason' => $reason],
                actorId: $actorId,
                actorName: $this->actorName($actorId),
            );

            return $mirror->refresh();
        });
    }

    public function preview(JournalDraft $draft): JournalDraft
    {
        if (! $draft->isEmpty()) {
            $this->assertStructure($draft);
        }

        return $draft;
    }

    // ---------------------------------------------------------------- guts

    /** A. pure structural validation — no DB (02 §1). */
    private function assertStructure(JournalDraft $draft): void
    {
        if (count($draft->lines) < 2) {
            throw new InvalidDraft('A journal entry needs at least two lines.');
        }

        $hasDebit = false;
        $hasCredit = false;
        foreach ($draft->lines as $line) {
            if ($line->debitCentavos < 0 || $line->creditCentavos < 0) {
                throw new InvalidDraft('Line amounts must be non-negative centavos.');
            }
            if (($line->debitCentavos > 0) === ($line->creditCentavos > 0)) {
                throw new InvalidDraft('Each line must have exactly one of debit or credit positive.');
            }
            $hasDebit = $hasDebit || $line->debitCentavos > 0;
            $hasCredit = $hasCredit || $line->creditCentavos > 0;
        }
        if (! $hasDebit || ! $hasCredit) {
            throw new InvalidDraft('An entry needs at least one debit and one credit line.');
        }

        if (! $draft->isBalanced()) {
            throw new UnbalancedEntry(sprintf(
                'Draft does not balance: debits %d ≠ credits %d centavos.',
                $draft->totalDebit(),
                $draft->totalCredit(),
            ));
        }

        if (! isset(self::BOOK_SERIES[$draft->journalBook])) {
            throw new InvalidDraft("Unknown journal book [{$draft->journalBook}].");
        }
        if ($draft->idempotencyKey === '') {
            throw new InvalidDraft('An idempotency key is required.');
        }
    }

    private function assertAccountsPostable(JournalDraft $draft): void
    {
        $ids = array_values(array_unique(array_map(fn (JournalLineDraft $l) => $l->accountId, $draft->lines)));

        $accounts = DB::table('accounts')->whereIn('id', $ids)->sharedLock()
            ->get(['id', 'is_postable', 'is_active'])->keyBy('id');

        foreach ($ids as $id) {
            $account = $accounts->get($id);
            if ($account === null) {
                throw new AccountNotPostable("Account [{$id}] does not exist.");
            }
            if (! $account->is_postable || ! $account->is_active) {
                throw new AccountNotPostable("Account [{$id}] is not postable/active.");
            }
        }
    }

    private function periodIdForDate(CarbonImmutable $date): int
    {
        // Period 13 (end_date..end_date) is only reachable via an explicit
        // fiscalPeriodId — date lookup is ambiguous on the FY end date (01 §7).
        $id = DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->where('period_no', '<=', 12)
            ->orderBy('period_no')
            ->value('id');

        if ($id === null) {
            throw new PeriodClosed("No fiscal period covers {$date->toDateString()}.");
        }

        return (int) $id;
    }

    private function assertPeriodOpen(int $periodId, CarbonImmutable $entryDate): object
    {
        $period = DB::table('fiscal_periods')->where('id', $periodId)->sharedLock()->first();

        if ($period === null || $period->status !== 'open') {
            throw new PeriodClosed('The fiscal period is not open for posting.');
        }

        $lockDate = DB::table('ledger_settings')->where('id', 1)->value('posting_lock_date');
        if ($lockDate !== null && $entryDate->toDateString() <= $lockDate && (int) $period->period_no !== 13) {
            throw new PostingLocked("Entry date {$entryDate->toDateString()} is on or before the posting lock date {$lockDate}.");
        }

        return $period;
    }

    /** Gapless allocation under FOR UPDATE (01 §3). */
    private function drawNumber(string $journalBook, int $fiscalYearId): string
    {
        $series = self::BOOK_SERIES[$journalBook];

        $sequence = DB::table('document_sequences')
            ->where('document_type', $series)
            ->where('fiscal_year', $fiscalYearId)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new InvalidDraft("No document sequence for series [{$series}] in fiscal year [{$fiscalYearId}].");
        }
        if ($sequence->prefix === '') {
            throw new InvalidDraft("Sequence [{$series}] has an empty prefix — it must embed series and year label (01 §3).");
        }

        DB::table('document_sequences')->where('id', $sequence->id)
            ->update(['last_value' => DB::raw('last_value + 1')]);

        $number = $sequence->prefix.str_pad((string) ($sequence->last_value + 1), $sequence->pad_width, '0', STR_PAD_LEFT);

        $yearLabel = DB::table('fiscal_years')->where('id', $fiscalYearId)->value('year_label');
        if (! str_contains($number, $series) || ! str_contains($number, (string) $yearLabel)) {
            throw new InvalidDraft("Generated number [{$number}] must embed the series and year label (01 §3).");
        }

        return $number;
    }

    /** sha256 of canonical lines — tamper check re-derived by ledger:verify. */
    private function postingHash(int $headerId): string
    {
        $lines = DB::table('journal_lines')->where('journal_entry_id', $headerId)
            ->orderBy('line_no')
            ->get(['line_no', 'account_id', 'debit_centavos', 'credit_centavos'])
            ->map(fn ($l) => [(int) $l->line_no, (int) $l->account_id, (int) $l->debit_centavos, (int) $l->credit_centavos]);

        return hash('sha256', json_encode($lines));
    }

    /** @return JournalLineDraft[] mirror (debit↔credit swapped, tax refs kept) */
    private function mirrorLines(JournalEntry $original): array
    {
        return DB::table('journal_lines')->where('journal_entry_id', $original->id)
            ->orderBy('line_no')->get()
            ->map(fn ($l) => new JournalLineDraft(
                accountId: (int) $l->account_id,
                debitCentavos: (int) $l->credit_centavos,
                creditCentavos: (int) $l->debit_centavos,
                memo: $l->memo,
                taxCodeId: $l->tax_code_id === null ? null : (int) $l->tax_code_id,
                taxBaseCentavos: $l->tax_base_centavos === null ? null : (int) $l->tax_base_centavos,
                atcCode: $l->atc_code,
                party: $l->partner_type === null ? null : new Posting\PartyRef($l->partner_type, (int) $l->partner_id),
                sourceLineRef: $l->source_line_ref,
            ))
            ->all();
    }

    /** Dating policy (02 §5): original date if its period is still open, else the first open date. */
    private function reversalDate(JournalEntry $original): CarbonImmutable
    {
        $periodStatus = DB::table('fiscal_periods')->where('id', $original->fiscal_period_id)->value('status');

        if ($periodStatus === 'open') {
            return CarbonImmutable::parse($original->entry_date);
        }

        $today = CarbonImmutable::now();
        $todayPeriod = DB::table('fiscal_periods')
            ->whereDate('start_date', '<=', $today->toDateString())
            ->whereDate('end_date', '>=', $today->toDateString())
            ->where('period_no', '<=', 12)
            ->value('status');

        if ($todayPeriod === 'open') {
            return $today;
        }

        $firstOpen = DB::table('fiscal_periods')->where('status', 'open')->where('period_no', '<=', 12)
            ->orderBy('start_date')->first(['start_date']);

        if ($firstOpen === null) {
            throw new PeriodClosed('No open period is available for the reversal.');
        }

        return CarbonImmutable::parse($firstOpen->start_date);
    }

    /** Interactive actor, else the seeded system user (01 §7 system actor). */
    private function resolveActorId(): int
    {
        $id = auth()->id();
        if ($id !== null) {
            return (int) $id;
        }

        $system = DB::table('users')->where('is_system', true)->value('id');
        if ($system === null) {
            throw new InvalidDraft('No authenticated user and no system actor is seeded.');
        }

        return (int) $system;
    }

    private function actorName(int $actorId): string
    {
        return (string) (DB::table('users')->where('id', $actorId)->value('name') ?? 'system');
    }
}
