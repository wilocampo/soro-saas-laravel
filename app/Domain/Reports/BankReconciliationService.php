<?php

namespace App\Domain\Reports;

use App\Domain\Ledger\AuditLogger;
use App\Domain\Ledger\Exceptions\InvalidDraft;
use Illuminate\Support\Facades\DB;

/**
 * Manual bank reconciliation (Phase 3; bank feeds are v2).
 *
 *   adjusted bank = statement closing
 *                 + deposits in transit   (book debits not yet on the statement)
 *                 − outstanding cheques   (book credits not yet on the statement)
 *
 * and that must equal the book balance. If it does not, the difference is a
 * bank-only item — a service charge, interest credited — that has not been
 * booked yet. The answer is to POST it, so `complete()` refuses while a
 * difference remains. There is deliberately no adjustment field: a
 * reconciliation that can be plugged reconciles nothing.
 *
 * A completed reconciliation is frozen, like everything else in this system.
 */
class BankReconciliationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function open(int $cashAccountId, string $statementDate, int $statementClosingCentavos): int
    {
        $account = DB::table('accounts')->where('id', $cashAccountId)->first(['id', 'code', 'name', 'is_postable']);

        if ($account === null || ! $account->is_postable) {
            throw new InvalidDraft('A reconciliation needs a postable cash account.');
        }

        $existing = DB::table('bank_reconciliations')
            ->where('cash_account_id', $cashAccountId)
            ->where('statement_date', $statementDate)
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'completed') {
                throw new InvalidDraft("{$account->code} is already reconciled to {$statementDate}.");
            }

            return (int) $existing->id;
        }

        return (int) DB::table('bank_reconciliations')->insertGetId([
            'cash_account_id' => $cashAccountId,
            'statement_date' => $statementDate,
            'statement_closing_centavos' => $statementClosingCentavos,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function setCleared(int $reconciliationId, int $journalLineId, bool $cleared): void
    {
        $this->assertDraft($reconciliationId);

        if (! $cleared) {
            DB::table('bank_reconciliation_lines')
                ->where('bank_reconciliation_id', $reconciliationId)
                ->where('journal_line_id', $journalLineId)
                ->delete();

            return;
        }

        DB::table('bank_reconciliation_lines')->updateOrInsert(
            ['bank_reconciliation_id' => $reconciliationId, 'journal_line_id' => $journalLineId],
            ['cleared_at' => now()],
        );
    }

    /**
     * @return array{
     *   reconciliation:array<string,mixed>, account:array<string,mixed>,
     *   book_balance:int, statement_closing:int,
     *   deposits_in_transit:int, outstanding_cheques:int,
     *   adjusted_bank:int, difference:int, reconciled:bool,
     *   lines:list<array<string,mixed>>
     * }
     */
    public function summary(int $reconciliationId): array
    {
        $reconciliation = $this->find($reconciliationId);
        $account = DB::table('accounts')->where('id', $reconciliation->cash_account_id)
            ->first(['id', 'code', 'name', 'normal_balance']);

        $cleared = DB::table('bank_reconciliation_lines')
            ->where('bank_reconciliation_id', $reconciliationId)
            ->pluck('journal_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $lines = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $reconciliation->cash_account_id)
            ->whereIn('je.status', ['posted', 'void'])
            ->whereDate('je.entry_date', '<=', $reconciliation->statement_date)
            ->orderBy('je.entry_date')->orderBy('je.entry_number')
            ->get([
                'jl.id', 'jl.debit_centavos', 'jl.credit_centavos', 'jl.memo',
                'je.entry_number', 'je.entry_date', 'je.description',
            ]);

        $bookBalance = 0;
        $depositsInTransit = 0;
        $outstandingCheques = 0;
        $rows = [];

        foreach ($lines as $line) {
            $debit = (int) $line->debit_centavos;
            $credit = (int) $line->credit_centavos;
            $isCleared = in_array((int) $line->id, $cleared, true);

            // Cash is debit-normal; a debit is money in.
            $bookBalance += $debit - $credit;

            if (! $isCleared) {
                $depositsInTransit += $debit;
                $outstandingCheques += $credit;
            }

            $rows[] = [
                'journal_line_id' => (int) $line->id,
                'entry_number' => $line->entry_number,
                'entry_date' => $line->entry_date,
                'particulars' => $line->memo ?: $line->description,
                'debit' => $debit,
                'credit' => $credit,
                'cleared' => $isCleared,
            ];
        }

        $statementClosing = (int) $reconciliation->statement_closing_centavos;
        $adjustedBank = $statementClosing + $depositsInTransit - $outstandingCheques;

        return [
            'reconciliation' => (array) $reconciliation,
            'account' => (array) $account,
            'book_balance' => $bookBalance,
            'statement_closing' => $statementClosing,
            'deposits_in_transit' => $depositsInTransit,
            'outstanding_cheques' => $outstandingCheques,
            'adjusted_bank' => $adjustedBank,
            'difference' => $adjustedBank - $bookBalance,
            'reconciled' => $adjustedBank === $bookBalance,
            'lines' => $rows,
        ];
    }

    public function complete(int $reconciliationId, ?string $notes = null): void
    {
        $this->assertDraft($reconciliationId);
        $summary = $this->summary($reconciliationId);

        if (! $summary['reconciled']) {
            $difference = $summary['difference'];

            throw new InvalidDraft(
                "The reconciliation is out by {$difference} centavos. That difference is a bank-only item "
                .'— a charge or interest credit not yet in the books. Post it as a journal entry; '
                .'a reconciliation cannot be plugged.'
            );
        }

        $actorId = auth()->id();

        DB::transaction(function () use ($reconciliationId, $notes, $actorId, $summary) {
            DB::table('bank_reconciliations')->where('id', $reconciliationId)->update([
                'status' => 'completed',
                'notes' => $notes,
                'completed_at' => now(),
                'completed_by' => $actorId ?? $this->systemActorId(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                event: 'bank_reconciliation.completed',
                auditableType: 'bank_reconciliations',
                auditableId: $reconciliationId,
                documentNumber: $summary['account']['code'].' @ '.$summary['reconciliation']['statement_date'],
                after: [
                    'book_balance' => $summary['book_balance'],
                    'statement_closing' => $summary['statement_closing'],
                    'deposits_in_transit' => $summary['deposits_in_transit'],
                    'outstanding_cheques' => $summary['outstanding_cheques'],
                ],
            );
        });
    }

    private function assertDraft(int $reconciliationId): void
    {
        if ($this->find($reconciliationId)->status !== 'draft') {
            throw new InvalidDraft('This reconciliation is completed and can no longer be changed.');
        }
    }

    private function find(int $reconciliationId): object
    {
        $reconciliation = DB::table('bank_reconciliations')->where('id', $reconciliationId)->first();

        if ($reconciliation === null) {
            throw new InvalidDraft("No bank reconciliation [{$reconciliationId}].");
        }

        return $reconciliation;
    }

    private function systemActorId(): int
    {
        $id = DB::table('users')->where('is_system', true)->value('id');

        if ($id === null) {
            throw new InvalidDraft('No authenticated user and no system actor is seeded.');
        }

        return (int) $id;
    }
}
