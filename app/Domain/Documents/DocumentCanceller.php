<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Exceptions\CannotCancel;
use App\Domain\Documents\Models\CreditNote;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\VendorBill;
use App\Domain\Ledger\AuditLogger;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\PostingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cancellation flow (docs/specs/03 §4, CLAUDE.md #4).
 *
 * Two lawful outcomes, and the period decides which:
 *
 *   period still open  → CANCEL. The journal entry is voided in place
 *                        (a same-period reversal) and the document is
 *                        marked cancelled. The serial is RETAINED.
 *   period closed / locked → refuse. The figures are already in a filed
 *                        return, so the adjustment must be a CREDIT NOTE,
 *                        which is a document in its own right.
 *
 * Nothing is ever edited or deleted here. A cancelled document keeps its
 * number, its lines and its history — that is the whole point.
 */
class DocumentCanceller
{
    /** Documents whose settlement makes cancellation the wrong instrument. */
    private const SETTLEABLE = [SalesInvoice::class, VendorBill::class];

    public function __construct(
        private readonly PostingService $posting,
        private readonly AuditLogger $audit,
        private readonly DocumentBalances $balances,
    ) {}

    public function cancel(Model $document, string $reason): ?JournalEntry
    {
        if (trim($reason) === '') {
            throw new CannotCancel('A cancellation reason is required — BIR expects the audit trail to say why.');
        }
        if ($document->getAttribute('status') === 'cancelled') {
            throw new CannotCancel('This document is already cancelled.');
        }

        $this->assertNothingApplied($document);

        $entry = $this->entryFor($document);

        return DB::transaction(function () use ($document, $reason, $entry): ?JournalEntry {
            $mirror = null;

            if ($entry !== null) {
                $this->assertPeriodStillOpen($entry);
                // void() is a same-period reversal — it refuses on a closed
                // period, which is the second half of the same guard.
                $mirror = $this->posting->void($entry, $reason);
            }

            // Not every document table carries the same cancellation columns;
            // write only the ones that exist rather than assume a shape.
            $update = ['status' => 'cancelled', 'updated_at' => now()];
            if ($this->hasColumn($document, 'cancelled_at')) {
                $update['cancelled_at'] = now();
            }
            if ($this->hasColumn($document, 'cancellation_reason')) {
                $update['cancellation_reason'] = $reason;
            }

            DB::table($document->getTable())->where('id', $document->getKey())->update($update);

            $document->setAttribute('status', 'cancelled');

            $this->audit->record(
                event: 'document.cancelled',
                auditableType: $document->getTable(),
                auditableId: (int) $document->getKey(),
                documentNumber: $this->numberOf($document),
                after: ['reason' => $reason, 'voided_entry' => $entry?->entry_number],
            );

            if ($document instanceof Payment) {
                // Freeing the cash re-opens whatever it had settled.
                $this->balances->refreshForPayment($document->load('allocations'));
            }

            return $mirror;
        });
    }

    /**
     * A settled document must have its payments unapplied first. Cancelling
     * underneath applied cash would leave the payment pointing at a document
     * that no longer exists in the books.
     */
    private function assertNothingApplied(Model $document): void
    {
        if (! in_array($document::class, self::SETTLEABLE, true)) {
            return;
        }

        $type = $document instanceof SalesInvoice ? 'sales_invoice' : 'vendor_bill';

        $applied = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.allocatable_type', $type)
            ->where('pa.allocatable_id', $document->getKey())
            ->where('p.status', '!=', 'cancelled')
            ->sum('pa.applied_centavos');

        if ((int) $applied > 0) {
            throw new CannotCancel(
                "This document has {$applied} centavos of payment applied to it — cancel or unapply the payment first."
            );
        }
    }

    private function assertPeriodStillOpen(JournalEntry $entry): void
    {
        $status = DB::table('fiscal_periods')->where('id', $entry->fiscal_period_id)->value('status');

        if ($status !== 'open') {
            throw new CannotCancel(
                "Entry {$entry->entry_number} sits in a closed period — its figures are already in a filed return. "
                .'Issue a credit note instead of cancelling (RR 7-2024).'
            );
        }

        $lockDate = DB::table('ledger_settings')->where('id', 1)->value('posting_lock_date');

        if ($lockDate !== null && $entry->entry_date->toDateString() <= $lockDate) {
            throw new CannotCancel(
                "Entry {$entry->entry_number} is on or before the posting lock date {$lockDate} — issue a credit note instead."
            );
        }
    }

    private function entryFor(Model $document): ?JournalEntry
    {
        $id = $document->getAttribute('journal_entry_id');

        return $id === null ? null : JournalEntry::query()->find($id);
    }

    private function numberOf(Model $document): ?string
    {
        foreach (['invoice_number', 'reference', 'note_number', 'payment_number'] as $column) {
            if ($this->hasColumn($document, $column)) {
                return $document->getAttribute($column);
            }
        }

        return null;
    }

    private function hasColumn(Model $document, string $column): bool
    {
        return Schema::hasColumn($document->getTable(), $column);
    }

    /** Cancelling a note re-opens the document it was adjusting. */
    public function cancelNote(CreditNote $note, string $reason): ?JournalEntry
    {
        $mirror = $this->cancel($note, $reason);
        $this->balances->refreshForNote($note);

        return $mirror;
    }
}
