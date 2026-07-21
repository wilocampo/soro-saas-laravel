<?php

namespace App\Domain\Documents;

use App\Domain\Documents\Models\CreditNote;
use App\Domain\Documents\Models\Payment;
use App\Domain\Documents\Models\SalesInvoice;
use App\Domain\Documents\Models\VendorBill;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What a document still owes, DERIVED from its allocations and notes — the
 * same rule the ledger follows (CLAUDE.md #2): `amount_paid_centavos` is a
 * cache we can always rebuild, never the truth.
 *
 *   outstanding = total − cash applied − net credit-note adjustment
 *
 * A/R and A/P aging read this rather than the GL, which is what lets a
 * cash-basis registrant age its receivables with no A/R control account
 * in the books at all (docs/specs/02 §4.2).
 */
class DocumentBalances
{
    private const TYPES = [
        SalesInvoice::class => 'sales_invoice',
        VendorBill::class => 'vendor_bill',
    ];

    /** Recompute the cached columns for one document. */
    public function refresh(Model $document): void
    {
        $type = self::TYPES[$document::class] ?? null;

        if ($type === null) {
            return;
        }

        $id = (int) $document->getKey();
        $paid = $this->cashApplied($type, $id) + (int) $document->getAttribute('opening_paid_centavos');
        $total = (int) $document->getAttribute('total_centavos');
        $outstanding = $total - $paid - $this->noteAdjustment($type, $id);

        $status = $document->getAttribute('status');
        if ($status !== 'cancelled') {
            // "paid" the moment nothing is owed — a credit note can settle a
            // document as surely as cash can.
            $status = $outstanding <= 0
                ? 'paid'
                : ($document instanceof SalesInvoice ? 'issued' : 'open');
        }

        DB::table($document->getTable())->where('id', $id)->update([
            'amount_paid_centavos' => $paid,
            'status' => $status,
            'updated_at' => now(),
        ]);

        $document->setAttribute('amount_paid_centavos', $paid);
        $document->setAttribute('status', $status);
    }

    /** Refresh every document a payment touched. */
    public function refreshForPayment(Payment $payment): void
    {
        foreach ($payment->allocations as $allocation) {
            $this->refreshByRef($allocation->allocatable_type, (int) $allocation->allocatable_id);
        }
    }

    public function refreshForNote(CreditNote $note): void
    {
        if ($note->applies_to_type !== null) {
            $this->refreshByRef($note->applies_to_type, (int) $note->applies_to_id);
        }
    }

    public function outstandingCentavos(Model $document): int
    {
        $type = self::TYPES[$document::class] ?? null;

        if ($type === null) {
            return 0;
        }

        $id = (int) $document->getKey();

        return (int) $document->getAttribute('total_centavos')
            - $this->cashApplied($type, $id)
            // Part-paid before cutover: no allocation row exists to rebuild
            // this from, so it is carried, not derived.
            - (int) $document->getAttribute('opening_paid_centavos')
            - $this->noteAdjustment($type, $id);
    }

    private function refreshByRef(string $type, int $id): void
    {
        $model = match ($type) {
            'sales_invoice' => SalesInvoice::find($id),
            'vendor_bill' => VendorBill::find($id),
            default => null,
        };

        if ($model !== null) {
            $this->refresh($model);
        }
    }

    /** Cash + tax withheld applied by non-cancelled payments. */
    private function cashApplied(string $type, int $id): int
    {
        return (int) DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('pa.allocatable_type', $type)
            ->where('pa.allocatable_id', $id)
            ->where('p.status', '!=', 'cancelled')
            ->sum('pa.applied_centavos');
    }

    /** Credit notes reduce the balance; debit notes add to it. */
    private function noteAdjustment(string $type, int $id): int
    {
        $notes = DB::table('credit_notes')
            ->where('applies_to_type', $type)
            ->where('applies_to_id', $id)
            ->where('status', 'issued')
            ->get(['type', 'total_centavos']);

        return $notes->sum(fn ($n) => $n->type === 'credit'
            ? (int) $n->total_centavos
            : -(int) $n->total_centavos);
    }
}
